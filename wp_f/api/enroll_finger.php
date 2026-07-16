<?php
// =========================================================
// ENROLL FINGER — save template for one device type
// POST /api/enroll_finger.php
// Body (JSON):
//   employee_code  string  required
//   device_type    string  MFS500 | FM220
//   template_base64 string required
//
// Templates are stored as-is in the existing template_*_base64 columns.
// No hashing, no deduplication.
// =========================================================

require_once __DIR__ . '/_bootstrap.php';
api_requireApiKey();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_jsonFail('Method not allowed', 405);

$body = api_jsonBody(131072); // 128 KB cap — templates are typically <8 KB

$employeeCode   = trim($body['employee_code']   ?? '');
$deviceType     = mb_strtoupper(trim($body['device_type'] ?? ''));
$templateBase64 = trim($body['template_base64'] ?? '');

if ($employeeCode   === '') api_jsonFail('employee_code is required');
if ($templateBase64 === '') api_jsonFail('template_base64 is required');

// Hardcoded column mapping — never interpolate user input into SQL.
[$templateCol, $otherCol] = match ($deviceType) {
    'MFS500' => ['template_mfs500_base64', 'template_fm220_base64'],
    'FM220'  => ['template_fm220_base64',  'template_mfs500_base64'],
    default  => [null, null],
};
if ($templateCol === null) api_jsonFail('device_type must be MFS500 or FM220');

if (base64_encode(base64_decode($templateBase64, true)) !== $templateBase64) {
    api_jsonFail('template_base64 is not valid base64');
}

try {
    $db = api_getDb();
    $db->beginTransaction();

    // Lock the employee row while we read state and write it back.
    $st = $db->prepare("
        SELECT id, is_active, {$otherCol} AS other_template
        FROM   employees
        WHERE  employee_code = ?
        FOR UPDATE
    ");
    $st->execute([$employeeCode]);
    $emp = $st->fetch();

    if (!$emp) {
        $db->rollBack();
        api_jsonFail('Employee not found', 404);
    }
    if (!$emp['is_active']) {
        $db->rollBack();
        api_jsonFail('Employee is inactive', 403);
    }

    $newStatus = !empty($emp['other_template']) ? 'active' : 'partial';

    $update = $db->prepare("
        UPDATE employees
        SET {$templateCol}    = ?,
            enrollment_status = ?,
            updated_at        = NOW()
        WHERE employee_code   = ?
    ");
    $update->execute([$templateBase64, $newStatus, $employeeCode]);

    $db->commit();

    api_jsonOk([
        'employee_code'     => $employeeCode,
        'device_type'       => $deviceType,
        'enrollment_status' => $newStatus,
    ], 'Template saved successfully');

} catch (Throwable $e) {
    if (api_getDb()->inTransaction()) api_getDb()->rollBack();
    api_dbFail($e);
}
