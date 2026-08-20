<?php
// =========================================================
// API BOOTSTRAP — isolated from modules/ and index.php
//
// Each api/*.php should require this file (and ONLY this file).
// Helpers here are api-owned (api_* prefix) so changes in the modules
// surface cannot affect the device API. The two surfaces share only
// the database, not PHP function definitions.
//
// Credentials (DB_*, API_KEY) are loaded from an out-of-web-root file —
// the same one modules/ uses, but api/ never calls modules' helpers.
// =========================================================

// ── Locate credentials file (constants: DB_HOST, DB_NAME, DB_USER, DB_PASS, API_KEY) ──
(function (): void {
    $candidates = [
        // cPanel-style: /home/USER/public_html/wp/  →  /home/USER/config/
        dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'config_wp' . DIRECTORY_SEPARATOR . 'config.php',
        dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'config_wp' . DIRECTORY_SEPARATOR . 'config.php',
        dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config_wp' . DIRECTORY_SEPARATOR . 'config.php',
        // Local dev fallback (same folder as the legacy config.php loader)
        dirname(__DIR__)    . DIRECTORY_SEPARATOR . 'config.local.php',
    ];
    foreach ($candidates as $path) {
        if (is_file($path)) {
            require_once $path;
            return;
        }
    }
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Server config missing']);
    exit;
})();

// Sanity: required constants must be present.
if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER')
    || !defined('DB_PASS') || !defined('API_KEY')) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Server config incomplete']);
    exit;
}

// =========================================================
// API HELPERS (api_* prefix — never collide with modules)
// =========================================================

// One lazily-created PDO per request. The handle lives on a class property
// rather than a function static so api_dbDisconnect() can drop it: MySQL only
// frees the connection slot once the last reference to the PDO is gone, and a
// request that still has slow outbound work to do (an SMS or SMTP send) should
// not be sitting on a slot while it waits on the network.
final class ApiDbHandle {
    public static ?PDO $pdo = null;
}

function api_getDb(): PDO {
    if (ApiDbHandle::$pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        ApiDbHandle::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // Never persistent: a pooled connection outlives the request and
            // is the classic way a shared-hosting account runs out of slots.
            PDO::ATTR_PERSISTENT         => false,
        ]);
    }
    return ApiDbHandle::$pdo;
}

// Release the MySQL connection early. Callers must drop their own $db and
// PDOStatement references first — those keep the socket open too. Calling
// api_getDb() afterwards simply reconnects, so this is always safe; it is
// only worth doing before something slow that needs no database.
function api_dbDisconnect(): void {
    ApiDbHandle::$pdo = null;
}

function api_requireApiKey(): void {
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!hash_equals(API_KEY, $key)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
}

function api_noCacheHeaders(): void {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
    header('Surrogate-Control: no-store');
}

function api_jsonOk(array $data = [], string $message = 'OK'): void {
    api_noCacheHeaders();
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => true, 'message' => $message], $data));
    exit;
}

function api_jsonFail(string $message, int $code = 400, array $extra = []): void {
    http_response_code($code);
    api_noCacheHeaders();
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => false, 'message' => $message], $extra));
    exit;
}

function api_jsonBody(int $maxBytes = 65536): array {
    // Cap the request body to keep abusive callers from streaming megabytes.
    $declared = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
    if ($declared > $maxBytes) {
        api_jsonFail('Request body too large', 413);
    }
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw !== false && strlen($raw) > $maxBytes) {
        api_jsonFail('Request body too large', 413);
    }
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : [];
}

// system_settings rows already read this request. Values are the stored
// string, or null for a key that has no row. Caching them keeps endpoints
// that read a dozen settings down to one query, and lets a handler warm what
// it needs before dropping the connection.
final class ApiSettingsCache {
    /** @var array<string,?string> */
    public static array $values = [];
}

// Fetch several settings in one round trip and cache them. Keys already
// cached are skipped. On a DB error nothing is cached and api_getSetting()
// falls back to its default, matching the previous behaviour.
function api_warmSettings(array $keys): void {
    $keys = array_values(array_diff(array_unique($keys), array_keys(ApiSettingsCache::$values)));
    if (!$keys) return;
    try {
        $in = implode(',', array_fill(0, count($keys), '?'));
        $st = api_getDb()->prepare(
            "SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($in)"
        );
        $st->execute($keys);
        foreach ($st->fetchAll() as $row) {
            ApiSettingsCache::$values[$row['setting_key']] = (string)$row['setting_value'];
        }
    } catch (Throwable $e) {
        return;
    }
    // Remember the misses too, so a missing key isn't re-queried all request.
    foreach ($keys as $k) {
        if (!array_key_exists($k, ApiSettingsCache::$values)) ApiSettingsCache::$values[$k] = null;
    }
}

function api_getSetting(string $key, string $default = ''): string {
    if (!array_key_exists($key, ApiSettingsCache::$values)) api_warmSettings([$key]);
    return ApiSettingsCache::$values[$key] ?? $default;
}

// ── Error logging — file-based, monthly rotation ──
function api_log(string $context, $exception = null): void {
    try {
        $dir = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $file = $dir . DIRECTORY_SEPARATOR . date('Y-m') . '.log';
        $line = sprintf(
            "[%s] %s %s%s\n",
            date('Y-m-d H:i:s'),
            $context,
            $exception instanceof Throwable
                ? get_class($exception) . ': ' . $exception->getMessage()
                : (is_string($exception) ? $exception : ''),
            $exception instanceof Throwable
                ? "\n" . $exception->getTraceAsString()
                : ''
        );
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    } catch (Throwable $ignored) {
        // logging must never throw
    }
}

// ── Standard 500 path: log + opaque response with request_id ──
function api_dbFail(Throwable $e): void {
    $reqId = bin2hex(random_bytes(6));
    api_log('reqid=' . $reqId, $e);
    api_jsonFail('Internal error', 500, ['request_id' => $reqId]);
}
