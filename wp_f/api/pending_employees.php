<?php
// =========================================================
// GET PENDING EMPLOYEES FOR ENROLLMENT
// GET /api/pending_employees.php?device_type=MFS500|FM220
// =========================================================

require_once __DIR__ . '/_bootstrap.php';
api_requireApiKey();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') api_jsonFail('Method not allowed', 405);

$deviceType = mb_strtoupper(trim($_GET['device_type'] ?? ''));

$templateCol = match ($deviceType) {
    'MFS500' => 'template_mfs500_base64',
    'FM220'  => 'template_fm220_base64',
    default  => null,
};
if ($templateCol === null) api_jsonFail('device_type must be MFS500 or FM220');

try {
    $sql = "
        SELECT
            e.employee_code,
            e.full_name,
            COALESCE(d.department_name, '') AS department,
            e.phone,
            e.enrollment_status
        FROM employees e
        LEFT JOIN departments d ON e.department_id = d.id
        WHERE e.is_active = 1
          AND e.enrollment_status IN ('pending_enrollment', 'partial')
          AND (e.{$templateCol} IS NULL OR e.{$templateCol} = '')
        ORDER BY e.full_name
    ";

    $rows = api_getDb()->query($sql)->fetchAll();

    $data = array_map(function ($r) {
        return [
            'employee_code'     => $r['employee_code'],
            'full_name'         => $r['full_name'],
            'department'        => $r['department'] ?? '',
            'phone'             => $r['phone']      ?? '',
            'enrollment_status' => $r['enrollment_status'],
        ];
    }, $rows);

    api_jsonOk(['count' => count($data), 'data' => $data]);

} catch (Throwable $e) {
    api_dbFail($e);
}
