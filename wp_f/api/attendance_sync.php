<?php
// =========================================================
// ATTENDANCE SYNC FEED (read-only, paginated by id)
// GET /api/attendance_sync.php?since_id=<int>&limit=<int>
//
// Returns attendance_logs rows with id > since_id, in ascending id
// order, capped at `limit` (default 5000, max 10000). Caller pages
// by passing back the highest id it received as the next since_id
// until the response returns 0 rows.
//
// Only the columns needed for the downstream HRMS sync are exposed:
//   id, employee_code, punch_time, location_id, punch_method
// (punch_method lets the HRMS MERGE exclude system 'auto_close' rows.
//  punch_type / device_serial / match_score are intentionally omitted.)
//
// Consumer is the HRMS sync job (server-to-server), not a kiosk —
// the API key is the trust boundary.
// =========================================================

require_once __DIR__ . '/_bootstrap.php';
api_requireApiKey();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') api_jsonFail('Method not allowed', 405);

$sinceId = (int)($_GET['since_id'] ?? 0);
if ($sinceId < 0) $sinceId = 0;

$limit = (int)($_GET['limit'] ?? 5000);
if ($limit < 1)     $limit = 5000;
if ($limit > 10000) $limit = 10000;

try {
    $st = api_getDb()->prepare(
        'SELECT id, employee_code, punch_time, location_id, punch_method
         FROM   attendance_logs
         WHERE  id > ?
         ORDER  BY id ASC
         LIMIT  ' . (int)$limit
    );
    $st->execute([$sinceId]);
    $rows = $st->fetchAll();

    $data = array_map(function ($r) {
        return [
            'id'            => (int)$r['id'],
            'employee_code' => $r['employee_code'],
            'punch_time'    => $r['punch_time'],
            'location_id'   => (int)$r['location_id'],
            'punch_method'  => $r['punch_method'] ?? '',
        ];
    }, $rows);

    $maxId = !empty($data) ? $data[count($data) - 1]['id'] : $sinceId;

    api_jsonOk([
        'count'    => count($data),
        'since_id' => $sinceId,
        'max_id'   => $maxId,
        'limit'    => $limit,
        'data'     => $data,
    ]);

} catch (Throwable $e) {
    api_dbFail($e);
}
