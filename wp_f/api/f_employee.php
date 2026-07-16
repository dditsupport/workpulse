<?php
// =========================================================
// GET ACTIVE EMPLOYEES + TEMPLATE FOR DEVICE TYPE
// GET /api/f_employee.php?device_type=MFS500|FM220[&employee_code=XXX]
//
// device_type: required.
// employee_code: optional.
//   - Without it: returns active employees who have a non-empty
//     template for that device (bulk sync use case).
//   - With it: returns the single active employee with that code
//     regardless of template state — so the enrollment form can
//     find employees who haven't enrolled yet. The C# caller
//     reads template_base64 defensively and treats an empty value
//     as "fresh enrollment".
// =========================================================

require_once __DIR__ . '/_bootstrap.php';
api_requireApiKey();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') api_jsonFail('Method not allowed', 405);

$deviceType = mb_strtoupper(trim($_GET['device_type'] ?? ''));
$empCode    = trim($_GET['employee_code'] ?? '');

// Hardcoded mapping — never interpolate user-controlled values into SQL.
$templateCol = match ($deviceType) {
    'MFS500' => 'template_mfs500_base64',
    'FM220'  => 'template_fm220_base64',
    default  => null,
};
if ($templateCol === null) api_jsonFail('device_type must be MFS500 or FM220');

try {
    $params = [];
    $where  = 'WHERE e.is_active = 1';
    if ($empCode !== '') {
        // Single-employee lookup (enrollment form, punch fallback) — return
        // the row regardless of template state; caller decides what to do.
        $where    .= ' AND e.employee_code = ?';
        $params[]  = $empCode;
    } else {
        // Bulk sync — device only cares about employees who already have a
        // template for it.
        $where    .= " AND e.{$templateCol} IS NOT NULL
                       AND e.{$templateCol} <> ''";
    }

    $sql = "
        SELECT
            e.employee_code,
            e.full_name,
            COALESCE(d.department_name, '') AS department,
            e.phone,
            e.email,
            e.{$templateCol}   AS template_base64,
            e.match_threshold,
            e.otp_channel,
            e.enrollment_status
        FROM employees e
        LEFT JOIN departments d ON e.department_id = d.id
        {$where}
        ORDER BY e.full_name
    ";

    $st = api_getDb()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    $data = array_map(function ($r) {
        return [
            'employee_code'     => $r['employee_code'],
            'full_name'         => $r['full_name'],
            'department'        => $r['department'] ?? '',
            'phone'             => $r['phone']      ?? '',
            'email'             => $r['email']      ?? '',
            'template_base64'   => $r['template_base64'],
            'match_threshold'   => $r['match_threshold'] !== null
                                        ? (int)$r['match_threshold']
                                        : null,
            'otp_channel'       => $r['otp_channel'] ?? 'none',
            'enrollment_status' => $r['enrollment_status'],
        ];
    }, $rows);

    api_jsonOk(['count' => count($data), 'data' => $data]);

} catch (Throwable $e) {
    api_dbFail($e);
}
