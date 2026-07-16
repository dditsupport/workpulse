<?php
// =========================================================
// KIOSK ADMIN LOGIN
// POST /api/admin_login.php
// Body (JSON): { "password": "<plaintext>" }
//
// Compares the supplied password against the AdminPassword
// system_settings row using hash_equals (timing-safe). The
// password is never sent to the client; system_settings.php
// strips it from its response.
//
// Per-IP rate limit: ≤5 attempts / minute. Failures count;
// successes do not (so a legit user isn't locked out by their
// own usage). Tracked via failed_punch_logs entries with
// fail_reason='admin_login_fail' so we don't add another table.
// =========================================================

require_once __DIR__ . '/_bootstrap.php';
api_requireApiKey();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_jsonFail('Method not allowed', 405);

$body     = api_jsonBody(2048);
$password = (string)($body['password'] ?? '');

if ($password === '') api_jsonFail('password is required');

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

try {
    $db = api_getDb();

    // ── Rate limit: 5 failures per IP in the last 60s → 429 ──
    $st = $db->prepare("
        SELECT COUNT(*) AS c
        FROM   failed_punch_logs
        WHERE  fail_reason  = 'admin_login_fail'
          AND  device_serial = ?
          AND  attempted_at > NOW() - INTERVAL 60 SECOND
    ");
    $st->execute([$ip]);
    $row = $st->fetch();
    if ((int)$row['c'] >= 5) {
        api_jsonFail('Too many attempts — try again in a minute', 429);
    }

    $stored = api_getSetting('AdminPassword', '');
    if ($stored === '') {
        // Misconfig — refuse to fail-open.
        api_log('admin_login', 'AdminPassword setting is empty');
        api_jsonFail('Admin login not configured', 500);
    }

    if (hash_equals((string)$stored, $password)) {
        api_jsonOk([], 'OK');
    }

    // Log failed attempt (best-effort).
    try {
        $db->prepare("
            INSERT INTO failed_punch_logs
                (employee_code, device_serial, device_type, location_id,
                 punch_type, punch_method, fail_reason, attempted_at)
            VALUES (NULL, ?, NULL, NULL, NULL, 'otp', 'admin_login_fail', NOW())
        ")->execute([$ip]);
    } catch (Throwable $ignored) {}

    api_jsonFail('Invalid password', 401);

} catch (Throwable $e) {
    api_dbFail($e);
}
