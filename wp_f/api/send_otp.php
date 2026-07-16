<?php
// =========================================================
// SEND OTP
// POST /api/send_otp.php
// Body (JSON):
//   employee_code  string
//   device_serial  string   ← required UNLESS employee has otp_device_bypass = 1
//   device_type    string
//   location_id    int
//   punch_type     IN | OUT
//
// Hardening (vs prior version):
//   - Per-employee send-rate cap (OtpMinSendIntervalSeconds, default 60).
//   - OtpLength default raised to 6.
//   - Returns absolute expires_at (server clock) alongside expires_in.
//   - PHPMailer & MSG91 SSL peer verification ENABLED.
//   - 500-class errors no longer leak PDO/exception messages.
// OTPs continue to be stored plaintext in otp_logs.otp_hash; protection
// is rate-limit + per-employee verify lockout (handled in verify_otp.php).
// =========================================================

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/PHPMailer/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

api_requireApiKey();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_jsonFail('Method not allowed', 405);

$body         = api_jsonBody();
$employeeCode = trim($body['employee_code'] ?? '');
$deviceSerial = trim($body['device_serial'] ?? '');
$deviceType   = mb_strtoupper(trim($body['device_type'] ?? ''));
$locationId   = (int)($body['location_id']  ?? 0);
$punchType    = trim($body['punch_type']    ?? 'IN');

if ($employeeCode === '') api_jsonFail('employee_code is required');
if ($locationId   === 0)  api_jsonFail('location_id is required');

try {
    $db = api_getDb();

    // ── Fetch employee first — needed for bypass flag ─────
    $st = $db->prepare('
        SELECT full_name, otp_channel, otp_device_bypass
        FROM   employees
        WHERE  employee_code = ? AND is_active = 1
    ');
    $st->execute([$employeeCode]);
    $emp = $st->fetch();

    if (!$emp)                                         api_jsonFail('Employee not found', 404);
    if (($emp['otp_channel'] ?? 'none') === 'none')    api_jsonFail('OTP not enabled for this employee', 403);

    $bypassDevice = (bool)$emp['otp_device_bypass'];

    // ── Device check ──────────────────────────────────────
    if (!$bypassDevice) {
        if ($deviceSerial === '') api_jsonFail('device_serial is required');

        $devSt = $db->prepare(
            'SELECT device_id, location_id FROM devices
              WHERE device_serial = ? AND is_active = 1 LIMIT 1');
        $devSt->execute([$deviceSerial]);
        $device = $devSt->fetch();

        if (!$device) {
            $db->prepare("
                INSERT INTO failed_punch_logs
                    (employee_code, device_serial, device_type, location_id,
                     punch_type, punch_method, fail_reason, attempted_at)
                VALUES (?, ?, ?, ?, ?, 'otp', ?, NOW())
            ")->execute([
                $employeeCode ?: null,
                $deviceSerial,
                $deviceType ?: 'MFS500',
                $locationId > 0 ? $locationId : null,
                mb_strtoupper($punchType) ?: null,
                'unregistered_device',
            ]);
            api_jsonFail('Device not registered or inactive', 401);
        }

        $registeredLocationId = (int)$device['location_id'];

        if ($locationId !== $registeredLocationId) {
            $db->prepare("
                INSERT INTO failed_punch_logs
                    (employee_code, device_serial, device_type, location_id,
                     punch_type, punch_method, fail_reason, attempted_at)
                VALUES (?, ?, ?, ?, ?, 'otp', ?, NOW())
            ")->execute([
                $employeeCode ?: null,
                $deviceSerial,
                $deviceType ?: 'MFS500',
                $registeredLocationId,
                mb_strtoupper($punchType) ?: null,
                'location_id_mismatch',
            ]);
            api_jsonFail(
                "Location mismatch — device {$deviceSerial} is registered at location " .
                "{$registeredLocationId}, app sent {$locationId}. Update App.config.",
                409
            );
        }

    } else {
        // Bypass — still validate the location exists and is active.
        $locStmt = $db->prepare('SELECT 1 FROM locations WHERE location_id = ? AND is_active = 1');
        $locStmt->execute([$locationId]);
        if (!$locStmt->fetchColumn()) {
            api_jsonFail('Invalid location for bypass employee', 400);
        }
        $registeredLocationId = $locationId;
    }

    // ── Send-rate cap (per employee) ──────────────────────
    $minInterval = (int)api_getSetting('OtpMinSendIntervalSeconds', '60');
    if ($minInterval > 0) {
        $rateSt = $db->prepare("
            SELECT COUNT(*) FROM otp_logs
             WHERE employee_code = ?
               AND sent_at > NOW() - INTERVAL ? SECOND
        ");
        $rateSt->execute([$employeeCode, $minInterval]);
        if ((int)$rateSt->fetchColumn() > 0) {
            api_jsonFail("Please wait {$minInterval} seconds between OTP requests", 429);
        }
    }

    // ── OTP channel ───────────────────────────────────────
    $channel = $emp['otp_channel'];
    if (!in_array($channel, ['email', 'sms'], true))
        api_jsonFail('OTP channel not configured for this employee', 403);

    // ── Location contact ──────────────────────────────────
    $st = $db->prepare('SELECT location_name, contact_email, contact_phone
                        FROM locations WHERE location_id = ? AND is_active = 1');
    $st->execute([$registeredLocationId]);
    $loc = $st->fetch();
    if (!$loc) api_jsonFail('Location not found or inactive', 404);

    if ($channel === 'email' && empty($loc['contact_email']))
        api_jsonFail('Location has no contact email. Superadmin must configure it.', 400);
    if ($channel === 'sms' && empty($loc['contact_phone']))
        api_jsonFail('Location has no contact phone. Superadmin must configure it.', 400);

    $sendTo   = $channel === 'email' ? $loc['contact_email'] : $loc['contact_phone'];
    $maskedTo = $channel === 'email' ? maskEmail($sendTo) : maskPhone($sendTo);

    // ── Generate OTP ──────────────────────────────────────
    $otpLength = (int)api_getSetting('OtpLength', '6');
    $otpExpiry = (int)api_getSetting('OtpExpiryMinutes', '10');
    if ($otpLength < 4 || $otpLength > 10) $otpLength = 6;
    if ($otpExpiry <= 0)                   $otpExpiry = 10;

    $otp = str_pad(
        (string)random_int(0, (int)str_repeat('9', $otpLength)),
        $otpLength, '0', STR_PAD_LEFT
    );

    // ── Invalidate any existing unused OTPs ───────────────
    $db->prepare('UPDATE otp_logs SET is_used = 1, used_at = NOW()
                  WHERE employee_code = ? AND is_used = 0')
       ->execute([$employeeCode]);

    // ── Store new OTP (plaintext column kept under the legacy name `otp_hash`). ─
    $db->prepare('INSERT INTO otp_logs (employee_code, otp_hash, channel, sent_at, expires_at)
                  VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE))')
       ->execute([$employeeCode, $otp, $channel, $otpExpiry]);

    // Compute expires_at from server clock so the client can run a precise countdown.
    $expRow = $db->query("SELECT DATE_ADD(NOW(), INTERVAL {$otpExpiry} MINUTE) AS exp")->fetch();
    $expiresAt = $expRow['exp'];

    // ── Send ──────────────────────────────────────────────
    if ($channel === 'sms')
        sendSms($sendTo, $otp, $emp['full_name'], $employeeCode, $loc['location_name'], $punchType);
    else
        sendEmail($sendTo, $loc['location_name'], $emp['full_name'], $employeeCode, $punchType, $otp, $otpExpiry);

    api_jsonOk([
        'channel'              => $channel,
        'expires_in'           => $otpExpiry,
        'expires_at'           => $expiresAt,
        'masked_to'            => $maskedTo,
        'device_bypass_active' => $bypassDevice,
    ], 'OTP sent to location contact');

} catch (Throwable $e) {
    api_dbFail($e);
}

// ── SMS via MSG91 v5 Flow ─────────────────────────────────
// template_id comes from the Msg91OtpFlowId setting; the flow's variable
// must be named ##OTP##. authkey from Msg91AuthKey.
function sendSms(string $phone, string $otp, string $empName, string $empCode, string $locName, string $punchType): void {
    // Normalise to 91XXXXXXXXXX.
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    if (strpos($phone, '+') === 0) $phone = ltrim($phone, '+');
    if (strlen($phone) === 10)     $phone = '91' . $phone;

    $flowId  = api_getSetting('Msg91OtpFlowId');
    $payload = json_encode([
        'template_id' => $flowId,
        'short_url'   => '0',
        'recipients'  => [[ 'mobiles' => $phone, 'OTP' => $otp ]],
    ]);
    $ch = curl_init(api_getSetting('Msg91V5Url', 'https://control.msg91.com/api/v5/flow/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/JSON',
            'Accept: application/json',
            'authkey: ' . api_getSetting('Msg91AuthKey'),
        ],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $resp = curl_exec($ch); $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    @file_put_contents(__DIR__ . '/../uploads/sms_debug.log',
        date('Y-m-d H:i:s') . ' API-OTP-FLOW to=' . $phone . ' tpl=' . $flowId
        . ' http=' . $code . ($err ? ' curlErr=' . $err : '') . ' resp=' . substr((string)$resp, 0, 300) . "\n",
        FILE_APPEND);
    if ($err)                                                           throw new Exception('SMS failed: ' . $err);
    if ((int)$code >= 400 || stripos((string)$resp, 'error') !== false) throw new Exception('MSG91: ' . $resp);
}

// ── Email via PHPMailer SMTP ─────────────────────────────
function sendEmail(string $toEmail, string $toName, string $empName, string $empCode, string $punchType, string $otp, int $expiry): void {
    $host     = api_getSetting('SmtpHost');
    $port     = (int)api_getSetting('SmtpPort', '465');
    $user     = api_getSetting('SmtpUser');
    $pass     = api_getSetting('SmtpPass');
    $from     = api_getSetting('SmtpFromEmail');
    $fromName = api_getSetting('SmtpFromName');

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $host;
    $mail->SMTPAuth   = true;
    $mail->Username   = $user;
    $mail->Password   = $pass;
    $mail->SMTPSecure = ($port == 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = $port;
    $mail->Timeout    = 15;
    // Peer verification ON — was previously disabled.
    $mail->SMTPOptions = ['ssl' => [
        'verify_peer'       => true,
        'verify_peer_name'  => true,
        'allow_self_signed' => false,
    ]];

    $mail->setFrom($from, $fromName);
    $mail->addAddress($toEmail, $toName);

    $mail->isHTML(false);
    $mail->CharSet = 'UTF-8';
    $mail->Subject = "Attendance OTP — {$empName} ({$empCode}) {$punchType}";
    $mail->Body    = "Dear {$toName},\r\n\r\nOTP for employee {$empName} ({$empCode}) — {$punchType}:\r\n\r\n  {$otp}\r\n\r\nValid for {$expiry} minutes.\r\n\r\n— {$fromName}";
    $mail->XMailer = 'WorkPulse/1.0';

    if (!$mail->send()) {
        throw new Exception('SMTP failed: ' . $mail->ErrorInfo);
    }
}

function maskEmail(string $e): string { [$l, $d] = explode('@', $e, 2); return substr($l, 0, 2) . str_repeat('*', max(0, strlen($l) - 2)) . '@' . $d; }
function maskPhone(string $p): string { $c = preg_replace('/[^0-9]/', '', $p); return str_repeat('*', max(0, strlen($c) - 4)) . substr($c, -4); }
