<?php
// =========================================================
// GET LAST PUNCH PER EMPLOYEE
// GET /api/last_punch.php?device_serial=<serial>
//
// Used by the kiosk on startup to restore punch state.
// Returns each employee's last punch ACROSS ALL LOCATIONS.
// This MUST be global: an employee can punch IN at one location
// and OUT at another, and the server's sequence check in
// attendance.php is global — so the kiosk's IN/OUT decision has
// to see the same global last punch. Scoping this to the device's
// location made a punch from another location look stale here and
// triggered a false "Sequence error — last punch was IN, expected OUT".
//
// shift_day uses the configured ShiftCutoffHour (default 4).
// =========================================================

require_once __DIR__ . '/_bootstrap.php';
api_requireApiKey();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') api_jsonFail('Method not allowed', 405);

$deviceSerial = trim($_GET['device_serial'] ?? '');
if ($deviceSerial === '') api_jsonFail('device_serial is required');

try {
    $db = api_getDb();

    // Device auth only — confirm the serial is a registered, active device.
    // The result set is global (see header), not location-scoped.
    $devSt = $db->prepare(
        'SELECT 1 FROM devices
          WHERE device_serial = ? AND is_active = 1 LIMIT 1');
    $devSt->execute([$deviceSerial]);
    if (!$devSt->fetch()) api_jsonFail('Device not registered or inactive', 401);

    $cutoff = (int)api_getSetting('ShiftCutoffHour', '4');
    if ($cutoff < 0 || $cutoff > 23) $cutoff = 4;

    $sql = "
        SELECT a.employee_code,
               a.punch_type,
               a.punch_time,
               DATE(a.punch_time - INTERVAL {$cutoff} HOUR)             AS shift_day,
               IF(a.punch_type = 'IN', 'OUT', 'IN')                    AS next_punch_type
        FROM   attendance_logs a
        INNER JOIN (
            SELECT employee_code, MAX(punch_time) AS max_time
            FROM   attendance_logs
            GROUP  BY employee_code
        ) latest
            ON  a.employee_code = latest.employee_code
            AND a.punch_time    = latest.max_time
        ORDER BY a.employee_code
    ";

    $st = $db->prepare($sql);
    $st->execute();
    $rows = $st->fetchAll();

    $data = array_map(function ($r) {
        return [
            'employee_code'   => $r['employee_code'],
            'punch_type'      => $r['punch_type'],
            'punch_time'      => $r['punch_time'],
            'shift_day'       => $r['shift_day'],
            'next_punch_type' => $r['next_punch_type'],
        ];
    }, $rows);

    api_jsonOk(['count' => count($data), 'data' => $data]);

} catch (Throwable $e) {
    api_dbFail($e);
}
