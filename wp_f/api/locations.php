<?php
// =========================================================
// GET LOCATIONS
// GET /api/locations.php
// =========================================================

require_once __DIR__ . '/_bootstrap.php';
api_requireApiKey();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') api_jsonFail('Method not allowed', 405);

try {
    $rows = api_getDb()->query('SELECT location_id, location_name FROM locations ORDER BY location_name')->fetchAll();

    $data = array_map(function ($r) {
        return [
            'location_id'   => (int)$r['location_id'],
            'location_name' => $r['location_name'],
        ];
    }, $rows);

    api_jsonOk(['count' => count($data), 'data' => $data]);

} catch (Throwable $e) {
    api_dbFail($e);
}
