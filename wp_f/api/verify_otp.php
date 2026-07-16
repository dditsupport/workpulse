<?php
// =========================================================
// VERIFY OTP
// POST /api/verify_otp.php
// Body (JSON):
//   employee_code  string
//   otp            string  (plain code from employee)
//   device_serial  string  ← required UNLESS employee has otp_device_bypass = 1
//   device_type    string
//   location_id    int
//   punch_type     IN | OUT
//
// Hardening:
//   - Per-employee verify-attempt lockout (OtpMaxVerifyAttempts, default 5).
//     After lockout, all unused OTPs for that employee are invalidated and
//     a new send_otp call is required.
//   - PHP-side hash_equals() for the OTP equality check (no SQL string
//     compare on user-controlled input).
//   - Read+update wrapped in a transaction.
//   - 500-class errors no longer leak PDO messages.
// =========================================================

require_once __DIR__ . '/_bootstrap.php';
api_requireApiKey();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_jsonFail('Method not allowed', 405);

$body         = api_jsonBody();
$employeeCode = trim($body['employee_code'] ?? '');
$otpPlain     = trim($body['otp']           ?? '');
$deviceSerial = trim($body['device_serial'] ?? '');
$deviceType   = mb_strtoupper(trim($body['device_type'] ?? ''));
$locationId   = (int)($body['location_id']  ?? 0);
$punchType    = mb_strtoupper(trim($body['punch_type'] ?? ''));

if ($employeeCode === '') api_jsonFail('employee_code is required');
if ($otpPlain     === '') api_jsonFail('otp is required');

// Reject obviously wrong-shape input early — keeps the lockout from being
// burned through by malformed retries.
if (!preg_match('/^\d{4,10}$/', $otpPlain)) api_jsonFail('Invalid OTP format', 401);

try {
    $db = api_getDb();

    // ── Employee lookup ─────────────────────────────────
    $empSt = $db->prepare('
        SELECT otp_device_bypass
        FROM   employees
        WHERE  employee_code = ? AND is_active = 1
    ');
    $empSt->execute([$employeeCode]);
    $emp = $empSt->fetch();

    if (!$emp) {
        logOtpFail($db, $body, $deviceSerial, $deviceType,
                   $locationId > 0 ? $locationId : null, 'employee_not_found');
        api_jsonFail('Employee not found or inactive', 404);
    }

    $bypassDevice = (bool)$emp['otp_device_bypass'];

    // ── Device check ────────────────────────────────────
    if (!$bypassDevice) {
        if ($deviceSerial === '') api_jsonFail('device_serial is required');

        $devSt = $db->prepare(
            'SELECT device_id, location_id FROM devices
              WHERE device_serial = ? AND is_active = 1 LIMIT 1');
        $devSt->execute([$deviceSerial]);
        $device = $devSt->fetch();

        if (!$device) {
            logOtpFail($db, $body, $deviceSerial, $deviceType,
                       $locationId > 0 ? $locationId : null, 'unregistered_device');
            api_jsonFail('Device not registered or inactive', 401);
        }

        $registeredLocationId = (int)$device['location_id'];
    } else {
        $registeredLocationId = $locationId;
    }

    // ── Lockout check ───────────────────────────────────
    $maxAttempts = (int)api_getSetting('OtpMaxVerifyAttempts', '5');
    if ($maxAttempts < 1) $maxAttempts = 5;

    $lockSt = $db->prepare('
        SELECT id, verify_attempts,
               (expires_at < NOW()) AS is_expired,
               otp_hash
        FROM   otp_logs
        WHERE  employee_code = ?
          AND  is_used       = 0
        ORDER  BY sent_at DESC
        LIMIT  1
    ');
    $lockSt->execute([$employeeCode]);
    $current = $lockSt->fetch();

    if (!$current) {
        logOtpFail($db, $body, $deviceSerial, $deviceType,
                   $registeredLocationId, 'otp_invalid');
        api_jsonFail('Invalid or expired OTP', 401);
    }

    if ($current['is_expired']) {
        $db->prepare('UPDATE otp_logs SET is_used = 1, used_at = NOW() WHERE id = ?')
           ->execute([$current['id']]);
        logOtpFail($db, $body, $deviceSerial, $deviceType,
                   $registeredLocationId, 'otp_expired');
        api_jsonFail('OTP has expired', 401);
    }

    if ((int)$current['verify_attempts'] >= $maxAttempts) {
        // Already locked from a prior request — invalidate and force re-send.
        $db->prepare('UPDATE otp_logs SET is_used = 1, used_at = NOW()
                      WHERE employee_code = ? AND is_used = 0')
           ->execute([$employeeCode]);
        logOtpFail($db, $body, $deviceSerial, $deviceType,
                   $registeredLocationId, 'otp_locked');
        api_jsonFail('Too many wrong attempts — request a new OTP', 429,
                     ['locked' => true]);
    }

    // ── Verify (timing-safe) ────────────────────────────
    if (!hash_equals((string)$current['otp_hash'], $otpPlain)) {
        // Wrong code — increment counter atomically; if this miss caused us
        // to hit the cap, invalidate immediately.
        $db->beginTransaction();
        try {
            $db->prepare('UPDATE otp_logs SET verify_attempts = verify_attempts + 1
                          WHERE id = ?')->execute([$current['id']]);

            $newAttempts = (int)$current['verify_attempts'] + 1;
            $reachedCap  = $newAttempts >= $maxAttempts;

            if ($reachedCap) {
                $db->prepare('UPDATE otp_logs SET is_used = 1, used_at = NOW()
                              WHERE employee_code = ? AND is_used = 0')
                   ->execute([$employeeCode]);
            }
            $db->commit();
        } catch (Throwable $te) {
            if ($db->inTransaction()) $db->rollBack();
            throw $te;
        }

        logOtpFail($db, $body, $deviceSerial, $deviceType,
                   $registeredLocationId,
                   $reachedCap ? 'otp_locked' : 'otp_invalid');
        if ($reachedCap) {
            api_jsonFail('Too many wrong attempts — request a new OTP', 429,
                         ['locked' => true]);
        }
        api_jsonFail('Invalid OTP', 401);
    }

    // ── Success: mark used (single-use) ────────────────
    $db->beginTransaction();
    try {
        $db->prepare('UPDATE otp_logs SET is_used = 1, used_at = NOW()
                      WHERE id = ? AND is_used = 0')
           ->execute([$current['id']]);
        $db->commit();
    } catch (Throwable $te) {
        if ($db->inTransaction()) $db->rollBack();
        throw $te;
    }

    api_jsonOk(['punch_method' => 'otp'], 'OTP verified');

} catch (Throwable $e) {
    api_dbFail($e);
}

// ── Helper: log OTP failure to failed_punch_logs ─────────
function logOtpFail(PDO $db, array $body, string $deviceSerial, string $deviceType, ?int $locationId, string $reason): void {
    try {
        $employeeCode = trim($body['employee_code'] ?? '') ?: null;
        $punchType    = mb_strtoupper(trim($body['punch_type'] ?? '')) ?: null;

        $db->prepare("
            INSERT INTO failed_punch_logs
                (employee_code, device_serial, device_type, location_id,
                 punch_type, punch_method, fail_reason, attempted_at)
            VALUES (?, ?, ?, ?, ?, 'otp', ?, NOW())
        ")->execute([$employeeCode, $deviceSerial, $deviceType,
                     $locationId, $punchType, $reason]);
    } catch (Throwable $ignored) {
        // best effort
    }
}
