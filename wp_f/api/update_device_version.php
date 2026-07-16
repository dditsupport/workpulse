<?php
// =========================================================
// REPORT APP VERSION
// POST /api/update_device_version.php
// Body (JSON):
//   device_serial  string   — must exist in devices table (is_active=1)
//   app_version    string   — semver-ish (1–20 chars, digits/letters/dots/hyphens)
//
// Always validates the device — even if the client reports the
// current baseline version. The previous "skip device check on
// baseline" shortcut was too lax and meant rogue devices could
// avoid being flagged. Now every call confirms the device is
// still active, then writes only when the version actually changed.
// =========================================================

require_once __DIR__ . '/_bootstrap.php';
api_requireApiKey();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_jsonFail('Method not allowed', 405);

$body         = api_jsonBody(2048);
$deviceSerial = trim($body['device_serial'] ?? '');
$appVersion   = trim($body['app_version']   ?? '');

if ($deviceSerial === '') api_jsonFail('device_serial is required');
if ($appVersion   === '') api_jsonFail('app_version is required');

// Strict semver-ish: 1–20 chars, must start with a digit, only digits/letters/dots/hyphens.
if (!preg_match('/^[0-9][0-9A-Za-z.\-]{0,19}$/', $appVersion)) {
    api_jsonFail('app_version format invalid (max 20 chars, must start with a digit)');
}

try {
    $db = api_getDb();

    $st = $db->prepare(
        'SELECT device_id, app_version FROM devices
          WHERE device_serial = ? AND is_active = 1 LIMIT 1');
    $st->execute([$deviceSerial]);
    $device = $st->fetch();

    if (!$device) api_jsonFail('Device not registered or inactive', 401);

    if ($device['app_version'] === $appVersion) {
        api_jsonOk(['updated' => false, 'app_version' => $appVersion], 'Version unchanged');
    }

    $db->prepare('
        UPDATE devices
        SET    app_version        = ?,
               version_updated_at = NOW()
        WHERE  device_id          = ?
    ')->execute([$appVersion, $device['device_id']]);

    api_jsonOk(['updated' => true, 'app_version' => $appVersion], 'App version updated');

} catch (Throwable $e) {
    api_dbFail($e);
}
