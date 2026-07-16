<?php
// =========================================================
// GET SYSTEM SETTINGS
// GET /api/system_settings.php
// Returns key-value pairs (excludes sensitive credentials).
// ShiftCutoffHour, OtpLength, OtpExpiryMinutes etc. are NOW
// stored as system_settings rows — this endpoint just relays
// whatever is in the DB minus the exclusion list.
// =========================================================

require_once __DIR__ . '/_bootstrap.php';
api_requireApiKey();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') api_jsonFail('Method not allowed', 405);

// Allowlist of keys the kiosk actually reads (see MainForm.cs SyncAsync).
// Everything else — admin/SMTP/SMS/MSG91 credentials, web-only flags
// (PriceSlotsActive, EmployeePortalEnabled, notification routing, etc.) —
// is irrelevant to the kiosk and stays server-side. Allowlist > denylist
// so new admin settings don't leak to kiosks by default.
$allowKeys = [
    'MinPunchIntervalMinutes',
    'MatchThreshold_Attendance',
    'MatchThreshold_EnrollDuplicate',
    'ShiftCutoffHour',
];

try {
    $in  = implode(',', array_fill(0, count($allowKeys), '?'));
    $st  = api_getDb()->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($in)");
    $st->execute($allowKeys);
    $rows = $st->fetchAll();

    $data = [];
    foreach ($rows as $row) $data[$row['setting_key']] = $row['setting_value'];

    // Guarantee the kiosk gets a usable shift cutoff even if the row is missing.
    if (!isset($data['ShiftCutoffHour'])) $data['ShiftCutoffHour'] = '4';

    api_jsonOk(['data' => $data]);

} catch (Throwable $e) {
    api_dbFail($e);
}
