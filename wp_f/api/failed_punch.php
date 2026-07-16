<?php
// =========================================================
// LOG FAILED PUNCH
// POST /api/failed_punch.php
// Body (JSON):
//   employee_code  string  (nullable)
//   device_serial  string  required — must be registered + active
//   device_type    MFS500 | FM220
//   location_id    int     informational; the SERVER overrides
//                          this with the device's registered location
//   punch_type     IN | OUT  (nullable)
//   punch_method   fingerprint | otp
//   match_score    int   (normalized 0–100, nullable)
//   threshold_used int   (normalized 0–100, nullable)
//   fail_reason    string
//   app_version    string  (nullable) — kiosk build string,
//                          e.g. "2.2.2", surfaced on the
//                          Failed Punches admin page.
// =========================================================

require_once __DIR__ . '/_bootstrap.php';
api_requireApiKey();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_jsonFail('Method not allowed', 405);

$body = api_jsonBody();

$employeeCode = trim($body['employee_code'] ?? '') ?: null;
$deviceSerial = trim($body['device_serial'] ?? '');
$deviceType   = mb_strtoupper(trim($body['device_type'] ?? ''));
$punchType    = mb_strtoupper(trim($body['punch_type'] ?? '')) ?: null;
$punchMethod  = mb_strtolower(trim($body['punch_method'] ?? 'fingerprint'));
$matchScore   = isset($body['match_score'])   ? (int)$body['match_score']   : null;
$thresholdUsed= isset($body['threshold_used'])? (int)$body['threshold_used']: null;
$failReason   = trim($body['fail_reason']  ?? '') ?: null;
$appVersion   = trim($body['app_version']  ?? '') ?: null;
if ($appVersion !== null && mb_strlen($appVersion) > 50) $appVersion = mb_substr($appVersion, 0, 50);

if ($deviceSerial === '')                                         api_jsonFail('device_serial is required');
if (!in_array($deviceType, ['MFS500','FM220'], true))             api_jsonFail('Invalid device_type');
if ($punchType  !== null && !in_array($punchType, ['IN','OUT']))  api_jsonFail('Invalid punch_type');
if (!in_array($punchMethod, ['fingerprint','otp'], true))         api_jsonFail('Invalid punch_method');

try {
    $db = api_getDb();

    // Resolve location from the device's registration — never trust the client.
    $devSt = $db->prepare(
        'SELECT location_id FROM devices
          WHERE device_serial = ? AND is_active = 1 LIMIT 1');
    $devSt->execute([$deviceSerial]);
    $device = $devSt->fetch();
    if (!$device) api_jsonFail('Device not registered or inactive', 401);
    $locationId = (int)$device['location_id'];

    // app_version is appended to the column list when the migration has
    // been applied; for installs that haven't run it yet we fall back to
    // the legacy 9-column insert so the kiosk doesn't error out.
    $hasAppVersion = false;
    try {
        $db->query('SELECT app_version FROM failed_punch_logs LIMIT 0')->fetch();
        $hasAppVersion = true;
    } catch (Exception $e) { /* pre-migration */ }

    if ($hasAppVersion) {
        $st = $db->prepare('
            INSERT INTO failed_punch_logs
                (employee_code, device_serial, device_type, location_id,
                 punch_type, punch_method, match_score, threshold_used, fail_reason,
                 app_version, attempted_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        $st->execute([
            $employeeCode, $deviceSerial, $deviceType, $locationId,
            $punchType, $punchMethod, $matchScore, $thresholdUsed, $failReason,
            $appVersion,
        ]);
    } else {
        $st = $db->prepare('
            INSERT INTO failed_punch_logs
                (employee_code, device_serial, device_type, location_id,
                 punch_type, punch_method, match_score, threshold_used, fail_reason,
                 attempted_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        $st->execute([
            $employeeCode, $deviceSerial, $deviceType, $locationId,
            $punchType, $punchMethod, $matchScore, $thresholdUsed, $failReason,
        ]);
    }

    api_jsonOk([], 'Failed punch logged');

} catch (Throwable $e) {
    api_dbFail($e);
}
