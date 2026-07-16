<?php
// =========================================================
// Data helpers, attendance/shift utilities, display helpers
// =========================================================

// Compact "human age" for a datetime — "3m", "2h", "5d", "3w", "2mo".
// Used by the dashboard pending-work widget. Empty / invalid input → ''.
function relAge(?string $datetime): string {
    if (!$datetime) return '';
    $t = strtotime($datetime);
    if ($t === false || $t === -1) return '';
    $d = time() - $t;
    if ($d < 60)      return 'now';
    if ($d < 3600)    return (int)floor($d / 60)     . 'm';
    if ($d < 86400)   return (int)floor($d / 3600)   . 'h';
    if ($d < 604800)  return (int)floor($d / 86400)  . 'd';
    if ($d < 2592000) return (int)floor($d / 604800) . 'w';
    return (int)floor($d / 2592000) . 'mo';
}

// Render a 24-hour HH:MM time picker that doesn't depend on the
// browser's locale. Produces two <select> dropdowns (00-23, 00-59)
// plus a hidden input that holds the combined "HH:MM" value under
// the requested form field name — server side is unchanged.
// The wiring JS lives in nav.php (renderShell).
function time24Input(string $name, string $value = '', bool $required = false, bool $colorHours = false): string {
    $hh = $mm = '';
    if (preg_match('/^(\d{2}):(\d{2})/', $value, $m)) { $hh = $m[1]; $mm = $m[2]; }
    $hopts = '<option value="">--</option>';
    for ($i = 0; $i < 24; $i++) {
        $v   = sprintf('%02d', $i);
        $sel = $v === $hh ? ' selected' : '';
        // Optional cue: hours 01–07 in blue, 00 + 08–23 in yellow/bold.
        $style = $colorHours
            ? (($i >= 1 && $i <= 7) ? ' style="color:#1a8fe3"' : ' style="color:#d4a800;font-weight:700"')
            : '';
        $hopts .= '<option value="' . $v . '"' . $sel . $style . '>' . $v . '</option>';
    }
    $mopts = '<option value="">--</option>';
    for ($i = 0; $i < 60; $i++) {
        $v   = sprintf('%02d', $i);
        $sel = $v === $mm ? ' selected' : '';
        $mopts .= '<option value="' . $v . '"' . $sel . '>' . $v . '</option>';
    }
    $reqAttr = $required ? ' data-required="1"' : '';
    $reqHid  = $required ? ' required' : '';
    $nameAttr = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $valAttr  = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $chAttr = $colorHours ? ' data-color-hours="1"' : '';
    return '<span class="time24" style="display:inline-flex;align-items:center;gap:4px"' . $reqAttr . $chAttr . '>'
         . '<select class="t24-hh form-control" style="width:64px;padding:8px 6px">' . $hopts . '</select>'
         . '<span style="color:var(--muted);font-weight:600">:</span>'
         . '<select class="t24-mm form-control" style="width:64px;padding:8px 6px">' . $mopts . '</select>'
         . '<input type="hidden" name="' . $nameAttr . '" value="' . $valAttr . '"' . $reqHid . '>'
         . '<span class="t24-ampm" style="margin-left:6px;font-size:12px;font-weight:600;color:var(--accent);white-space:nowrap"></span>'
         . '</span>';
}

// ── Data Queries ─────────────────────────────────────────
// $statuses / $deptIds / $activeFlags: empty array = no filter on that
// dimension; non-empty = SQL IN (...). $activeFlags accepts ['0','1'] —
// pass ['1'] for active only, [] for both.
function getEmployees(string $search = '', array $statuses = [], array $deptIds = [], string $location = '', array $activeFlags = []): array {
    try {
        $sql = 'SELECT e.id, e.employee_code, e.full_name, e.department_id, d.department_name,
                       e.phone, e.email, e.portal_password,
                       e.join_date, e.enrollment_status, e.match_threshold, e.otp_channel,
                       e.otp_device_bypass, e.is_active, e.deactivated_at, e.deactivation_reason,
                       e.created_at, e.updated_at, e.location_id, l.location_name,
                       (e.template_mfs500_base64 IS NOT NULL AND e.template_mfs500_base64 != \'\') AS mfs500_enrolled,
                       (e.template_fm220_base64 IS NOT NULL AND e.template_fm220_base64 != \'\') AS fm220_enrolled
                FROM employees e
                LEFT JOIN departments d ON e.department_id = d.id
                LEFT JOIN locations   l ON e.location_id   = l.location_id
                WHERE 1=1'; $p = [];
        if ($search !== '') { $sql .= ' AND (e.employee_code LIKE ? OR e.full_name LIKE ? OR d.department_name LIKE ?)'; $l = "%{$search}%"; $p = [$l, $l, $l]; }
        if (!empty($statuses)) {
            $ph   = implode(',', array_fill(0, count($statuses), '?'));
            $sql .= " AND e.enrollment_status IN ($ph)";
            foreach ($statuses as $s) $p[] = (string)$s;
        }
        $deptIds = array_values(array_filter(array_map('intval', $deptIds)));
        if (!empty($deptIds)) {
            $ph   = implode(',', array_fill(0, count($deptIds), '?'));
            $sql .= " AND e.department_id IN ($ph)";
            foreach ($deptIds as $d) $p[] = $d;
        }
        if ($location !== '') {
            // Special token "none" = employees with no self-claim location
            // (typical of HO / Factory / floating staff). Otherwise treat
            // it as a numeric location_id.
            if ($location === 'none') {
                $sql .= ' AND e.location_id IS NULL';
            } else {
                $sql .= ' AND e.location_id = ?'; $p[] = (int)$location;
            }
        }
        $activeFlags = array_values(array_unique(array_map(fn($x) => (int)(bool)$x, $activeFlags)));
        if (!empty($activeFlags) && count($activeFlags) < 2) {
            $sql .= ' AND e.is_active = ?'; $p[] = $activeFlags[0];
        }
        $st = getDb()->prepare($sql . ' ORDER BY e.full_name'); $st->execute($p); return $st->fetchAll();
    } catch (Exception $e) { return []; }
}
// Lightweight: only code + name for dropdowns/search
function getEmployeesLite(): array {
    try {
        return getDb()->query('SELECT employee_code, full_name, is_active, department_id, location_id FROM employees ORDER BY full_name')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}
function getEmployee(int $id): ?array {
    try { $st = getDb()->prepare('SELECT id, employee_code, full_name, department_id, staff_type, role_id, phone, email, join_date, portal_password, match_threshold, location_id, otp_channel, otp_device_bypass, template_mfs500_base64, template_fm220_base64, enrollment_status, is_active FROM employees WHERE id = ?'); $st->execute([$id]); return $st->fetch() ?: null; }
    catch (Exception $e) { return null; }
}
function getDepartments(): array {
    try { return getDb()->query('SELECT * FROM departments WHERE is_active=1 ORDER BY department_name')->fetchAll(); }
    catch (Exception $e) { return []; }
}
function getLocations(): array {
    try { return getDb()->query('SELECT location_id, location_name, contact_email, contact_phone, is_active FROM locations ORDER BY location_name')->fetchAll(); }
    catch (Exception $e) { return []; }
}
function getActiveLocations(): array {
    try { return getDb()->query('SELECT location_id,location_name FROM locations WHERE is_active=1 ORDER BY location_name')->fetchAll(); }
    catch (Exception $e) { return []; }
}

// Cross-page report defaults. HO and the factory aren't retail outlets,
// so reports that span "every store" should leave them unchecked unless
// the user opts in. Match is case-insensitive on a trimmed name.
const REPORT_LOCATION_DEFAULT_EXCLUDED = ['ahd - ho', 'ahd - pip factory'];

function reportDefaultLocationIds(array $locations): array {
    $out = [];
    foreach ($locations as $l) {
        $name = mb_strtolower(trim((string)($l['location_name'] ?? '')));
        if (in_array($name, REPORT_LOCATION_DEFAULT_EXCLUDED, true)) continue;
        $out[] = (int)$l['location_id'];
    }
    return $out;
}

// Resolve the active location-filter selection from $_GET, falling back
// to reportDefaultLocationIds() when the user hasn't submitted a choice.
// $selected = null  → first load, return defaults (HO+Factory unchecked)
// $selected = []    → user explicitly unchecked everything → empty filter
// $selected = […]   → intersect with the visible location set
function resolveReportLocationFilter(array $locations, ?array $selected): array {
    if ($selected === null) return reportDefaultLocationIds($locations);
    $valid = array_map(fn($l) => (int)$l['location_id'], $locations);
    return array_values(array_intersect(array_map('intval', $selected), $valid));
}

function getLocation(int $id): ?array {
    try { $st = getDb()->prepare('SELECT location_id, location_name, contact_email, contact_phone, location_code, address, latitude, longitude, is_active FROM locations WHERE location_id=?'); $st->execute([$id]); return $st->fetch() ?: null; }
    catch (Exception $e) { return null; }
}
// ── Outlet Directory ─────────────────────────────────────
function getOutlets(string $search = ''): array {
    try {
        $sql = 'SELECT location_id, location_name, location_code, address, contact_email, contact_phone, latitude, longitude FROM locations WHERE is_active=1'; $p = [];
        if ($search !== '') {
            $sql .= ' AND (location_name LIKE ? OR location_code LIKE ? OR address LIKE ? OR contact_email LIKE ? OR contact_phone LIKE ?)';
            $l = "%{$search}%"; $p = [$l, $l, $l, $l, $l];
        }
        $st = getDb()->prepare($sql . ' ORDER BY location_name'); $st->execute($p); return $st->fetchAll();
    } catch (Exception $e) { return []; }
}

// ── Product Shelf Life ──────────────────────────────────
function getShelfLifeProducts(string $search = '', string $group = ''): array {
    try {
        $sql = 'SELECT id, item_group, item_code, item_name, shelf_life_days, basic, tax, mrp, description, image FROM product_shelf_life WHERE 1=1'; $p = [];
        if ($search !== '') {
            $sql .= ' AND (item_code LIKE ? OR item_name LIKE ? OR description LIKE ?)';
            $l = "%{$search}%"; $p = [$l, $l, $l];
        }
        if ($group !== '') { $sql .= ' AND item_group = ?'; $p[] = $group; }
        $st = getDb()->prepare($sql . ' ORDER BY item_group, item_name'); $st->execute($p); return $st->fetchAll();
    } catch (Exception $e) { return []; }
}
function getShelfLifeProduct(int $id): ?array {
    try { $st = getDb()->prepare('SELECT id, item_group, item_code, item_name, shelf_life_days, basic, tax, mrp, description, image FROM product_shelf_life WHERE id = ?'); $st->execute([$id]); return $st->fetch() ?: null; }
    catch (Exception $e) { return null; }
}
function getShelfLifeGroups(): array {
    try { return getDb()->query('SELECT DISTINCT item_group FROM product_shelf_life ORDER BY item_group')->fetchAll(PDO::FETCH_COLUMN); }
    catch (Exception $e) { return []; }
}

function getDevices(): array {
    try {
        return getDb()->query('SELECT d.device_id AS id, d.device_serial, d.device_name, d.device_type, d.location_id, d.is_active, d.registered_at, d.updated_at, d.app_version, d.version_updated_at, l.location_name FROM devices d LEFT JOIN locations l ON d.location_id=l.location_id ORDER BY d.registered_at DESC')->fetchAll();
    } catch (Exception $e) { return []; }
}
function getStats(): array {
    $s = ['total'=>0,'active'=>0,'inactive'=>0,'pending'=>0,'partial'=>0,'enrolled'=>0,'otp'=>0,'locations'=>0,'devices'=>0,'today'=>0];
    try {
        $row = getDb()->query("
            SELECT
                (SELECT COUNT(*) FROM employees) AS total,
                (SELECT COUNT(*) FROM employees WHERE is_active=1) AS active,
                (SELECT COUNT(*) FROM employees WHERE is_active=0) AS inactive,
                (SELECT COUNT(*) FROM employees WHERE is_active=1 AND enrollment_status='pending_enrollment') AS pending,
                (SELECT COUNT(*) FROM employees WHERE is_active=1 AND enrollment_status='partial') AS partial,
                (SELECT COUNT(*) FROM employees WHERE is_active=1 AND enrollment_status='active') AS enrolled,
                (SELECT COUNT(*) FROM employees WHERE otp_channel <> 'none' AND is_active=1) AS otp,
                (SELECT COUNT(*) FROM locations WHERE is_active=1) AS locations,
                (SELECT COUNT(*) FROM devices WHERE is_active=1) AS devices,
                (SELECT COUNT(*) FROM attendance_logs WHERE punch_time >= CURDATE() AND punch_time < CURDATE() + INTERVAL 1 DAY) AS today
        ")->fetch(PDO::FETCH_ASSOC);
        if ($row) $s = array_map('intval', $row);
    } catch (Exception $e) {}
    return $s;
}

// ── Shift / Attendance Helpers ───────────────────────────
// Retail shift: 08:00 - next day 03:59
// Punches before 04:00 AM belong to the previous calendar day
define('SHIFT_CUTOFF_HOUR', 4);

function shiftDay(string $punchTime): string {
    $ts = strtotime($punchTime);
    if ((int)date('G', $ts) < SHIFT_CUTOFF_HOUR) $ts -= 86400;
    return date('Y-m-d', $ts);
}

// Fetch raw rows for selected date range.
// Window is shift-aligned on BOTH sides:
//   start = fromDate @ SHIFT_CUTOFF_HOUR:00:00  (fromDate's shift begins)
//   end   = (toDate + 1 day) @ (SHIFT_CUTOFF_HOUR-1):59:59  (toDate's shift ends)
// Punches before SHIFT_CUTOFF_HOUR on fromDate belong to (fromDate-1)'s shift
// and are excluded so we don't crossover into the previous shift day.
function getAttendance(string $empCode = '', string $fromDate = '', string $toDate = '', int $locationId = 0): array {
    if ($fromDate === '') $fromDate = date('Y-m-01');
    if ($toDate   === '') $toDate   = date('Y-m-d');
    $from = $fromDate . ' ' . sprintf('%02d:00:00', SHIFT_CUTOFF_HOUR);
    $to   = date('Y-m-d', strtotime($toDate . ' +1 day'))
          . ' ' . sprintf('%02d:59:59', SHIFT_CUTOFF_HOUR - 1);
    try {
        $sql = 'SELECT a.*,
                       COALESCE(e.full_name, a.employee_code) AS full_name,
                       d.department_name AS department,
                       l.location_name
                FROM attendance_logs a
                LEFT JOIN employees e ON a.employee_code=e.employee_code
                LEFT JOIN departments d ON e.department_id=d.id
                LEFT JOIN locations l ON a.location_id=l.location_id
                WHERE a.punch_time >= ? AND a.punch_time <= ?';
        $p = [$from, $to];
        if ($empCode !== '')  { $sql .= ' AND a.employee_code=?'; $p[] = $empCode; }
        if ($locationId > 0)  { $sql .= ' AND a.location_id=?';   $p[] = $locationId; }
        $st = getDb()->prepare($sql . ' ORDER BY a.employee_code, a.punch_time ASC');
        $st->execute($p); return $st->fetchAll();
    } catch (Exception $e) { return []; }
}
function getMyPunches(string $empCode, int $month = 0, int $year = 0): array {
    $month = $month ?: (int)date('n'); $year = $year ?: (int)date('Y');
    $from      = sprintf('%04d-%02d-01 00:00:00', $year, $month);
    $nextMonth = $month == 12 ? 1  : $month + 1;
    $nextYear  = $month == 12 ? $year + 1 : $year;
    $to = sprintf('%04d-%02d-01 %02d:59:59', $nextYear, $nextMonth, SHIFT_CUTOFF_HOUR - 1);
    try {
        $st = getDb()->prepare('SELECT a.*,l.location_name FROM attendance_logs a
            LEFT JOIN locations l ON a.location_id=l.location_id
            WHERE a.employee_code=? AND a.punch_time >= ? AND a.punch_time <= ?
            ORDER BY a.punch_time ASC');
        $st->execute([$empCode, $from, $to]); return $st->fetchAll();
    } catch (Exception $e) { return []; }
}

// Build day-summary keyed by [empCode][shiftDate].
// ALL punches kept for display.
// first = earliest, last = latest (for hours calculation).
function buildDaySummary(array $rows): array {
    $out = [];
    foreach ($rows as $r) {
        $code = $r['employee_code'];
        $day  = shiftDay($r['punch_time']);
        if (!isset($out[$code])) {
            $out[$code] = ['name' => $r['full_name'] ?? $code, 'dept' => $r['department'] ?? '', 'days' => []];
        }
        if (!isset($out[$code]['days'][$day])) {
            $out[$code]['days'][$day] = ['punches' => [], 'first' => null, 'last' => null];
        }
        $out[$code]['days'][$day]['punches'][] = $r;
    }
    foreach ($out as &$emp) {
        krsort($emp['days']);
        foreach ($emp['days'] as &$d) {
            $d['first'] = $d['punches'][0];
            $d['last']  = count($d['punches']) > 1 ? end($d['punches']) : null;
        }
        unset($d);
    }
    unset($emp);
    return $out;
}

// Hours between first and last punch
function fmtHours(?string $t1, ?string $t2): string {
    if (!$t1 || !$t2) return '—';
    [$h1,$m1,$s1] = array_map('intval', explode(':', substr($t1, 11, 8)));
    [$h2,$m2,$s2] = array_map('intval', explode(':', substr($t2, 11, 8)));
    $sec1 = $h1*3600 + $m1*60 + $s1;
    $sec2 = $h2*3600 + $m2*60 + $s2;
    // Handle overnight: OUT time numerically before IN time
    $diff = $sec2 >= $sec1 ? $sec2 - $sec1 : (86400 - $sec1) + $sec2;
    return sprintf('%02d:%02d:%02d', intdiv($diff,3600), intdiv($diff%3600,60), $diff%60);
}

// ── PHPMailer includes ──────────────────────────────────
require_once __DIR__ . '/../lib/PHPMailer/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// ── SMTP Email Helper ────────────────────────────────────
function sendSmtpEmail(string $toEmail, string $subject, string $body): bool {
    try {
        $host     = getSetting('SmtpHost');
        $port     = (int)getSetting('SmtpPort', '465');
        $user     = getSetting('SmtpUser');
        $pass     = getSetting('SmtpPass');
        $from     = getSetting('SmtpFromEmail', $user);
        $fromName = getSetting('SmtpFromName', 'Work Pulse');

        if (!$host || !$user || !$pass) return false;

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $user;
        $mail->Password   = $pass;
        $mail->SMTPSecure = ($port == 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $port;
        $mail->Timeout    = 15;
        $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];

        $mail->setFrom($from, $fromName);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body));
        $mail->XMailer = 'WorkPulse/1.0';

        return $mail->send();
    } catch (Exception $e) {
        return false;
    }
}

// Send email in background (non-blocking for user). Enqueues onto an
// in-process queue that drains after the HTTP response has been flushed
// back to the browser (via fastcgi_finish_request / litespeed_finish_request).
// All queued messages share a single SMTP session via SMTPKeepAlive so
// large fan-outs (e.g. price-variation edit → admin list + store) take
// one TCP/TLS/AUTH handshake instead of N.
function sendSmtpEmailQuiet(string $toEmail, string $subject, string $body): void {
    try { SmtpQueue::enqueue($toEmail, $subject, $body); } catch (Throwable $e) { /* silent */ }
}

class SmtpQueue {
    /** @var array<int,array{to:string,subject:string,body:string}> */
    private static array $queue = [];
    private static bool $registered = false;

    public static function enqueue(string $to, string $subject, string $body): void {
        self::$queue[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
        if (self::$registered) return;
        self::$registered = true;
        register_shutdown_function([self::class, 'flush']);
    }

    public static function flush(): void {
        $t0 = microtime(true);
        $count = count(self::$queue);
        if ($count === 0) return;

        // Keep going even if the client disconnects, and don't let the
        // default 30s PHP time limit kill us mid-drain on slow networks.
        @ignore_user_abort(true);
        @set_time_limit(120);

        // Tell the client this response is complete: Content-Length so
        // the browser knows it has all bytes, Connection: close so it
        // doesn't keep the socket open expecting more. Both must be set
        // before output is flushed.
        if (!headers_sent()) {
            $size = function_exists('ob_get_length') ? (int)@ob_get_length() : 0;
            @header('Content-Length: ' . $size);
            @header('Connection: close');
        }

        // Push every buffered byte out to the SAPI.
        if (function_exists('ob_get_level')) {
            while (@ob_get_level() > 0) @ob_end_flush();
        }
        @flush();

        // Explicitly release the worker on SAPIs that support it.
        // PHP-FPM → fastcgi_finish_request. LiteSpeed → litespeed_finish_request.
        // Some shared hosts disable these via php.ini disable_functions; the
        // diagnostic log below tells you whether that's the case.
        $released = 'no-sapi-hook';
        if (function_exists('fastcgi_finish_request')) {
            $ok = @fastcgi_finish_request();
            $released = 'fastcgi_finish_request=' . ($ok ? 'true' : 'false');
        } elseif (function_exists('litespeed_finish_request')) {
            $ok = @litespeed_finish_request();
            $released = 'litespeed_finish_request=' . ($ok ? 'true' : 'false');
        }

        // Release the PHP session lock so concurrent requests from the
        // same user aren't serialised behind our SMTP drain.
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }

        $t1 = microtime(true);
        error_log(sprintf(
            'SmtpQueue: SAPI=%s release=%s queue=%d setupMs=%d',
            PHP_SAPI, $released, $count, (int)round(($t1 - $t0) * 1000)
        ));

        $batch = self::$queue;
        self::$queue = [];
        if (!$batch) return;

        try {
            $host     = getSetting('SmtpHost');
            $port     = (int)getSetting('SmtpPort', '465');
            $user     = getSetting('SmtpUser');
            $pass     = getSetting('SmtpPass');
            $from     = getSetting('SmtpFromEmail', $user);
            $fromName = getSetting('SmtpFromName', 'Work Pulse');
            if (!$host || !$user || !$pass) {
                error_log('SmtpQueue: skipping ' . count($batch) . ' mails — SMTP not configured');
                return;
            }

            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host         = $host;
            $mail->SMTPAuth     = true;
            $mail->Username     = $user;
            $mail->Password     = $pass;
            $mail->SMTPSecure   = ($port == 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port         = $port;
            $mail->Timeout      = 15;
            $mail->SMTPOptions  = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
            $mail->SMTPKeepAlive = true;   // one TCP/TLS/AUTH session for the whole batch
            $mail->setFrom($from, $fromName);
            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            $mail->XMailer = 'WorkPulse/1.0';

            $sendStart = microtime(true);
            foreach ($batch as $msg) {
                $one = microtime(true);
                try {
                    $mail->clearAddresses();
                    $mail->addAddress($msg['to']);
                    $mail->Subject = $msg['subject'];
                    $mail->Body    = $msg['body'];
                    $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $msg['body']));
                    $mail->send();
                    error_log(sprintf('SmtpQueue: → %s OK in %dms', $msg['to'], (int)round((microtime(true) - $one) * 1000)));
                } catch (Throwable $e) {
                    error_log(sprintf('SmtpQueue: → %s FAIL in %dms: %s', $msg['to'], (int)round((microtime(true) - $one) * 1000), $e->getMessage()));
                }
            }
            $mail->smtpClose();
            error_log(sprintf('SmtpQueue: drain done in %dms (%d msgs)',
                (int)round((microtime(true) - $sendStart) * 1000), count($batch)));
        } catch (Throwable $e) {
            error_log('SmtpQueue: batch send failed: ' . $e->getMessage());
        }
    }
}

// Get employee emails for issue notifications
function getEmployeeEmails(array $codes): array {
    if (empty($codes)) return [];
    $codes = array_unique(array_filter($codes));
    if (empty($codes)) return [];
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $st = getDb()->prepare("SELECT employee_code, full_name, email FROM employees WHERE employee_code IN ({$placeholders}) AND email IS NOT NULL AND email != ''");
    $st->execute(array_values($codes));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// Get employee emails for department-based issue notifications
function getEmployeeEmailsByDepts(array $deptIds): array {
    if (empty($deptIds)) return [];
    $deptIds = array_unique(array_filter(array_map('intval', $deptIds)));
    if (empty($deptIds)) return [];
    $placeholders = implode(',', array_fill(0, count($deptIds), '?'));
    $st = getDb()->prepare("SELECT employee_code, full_name, email FROM employees WHERE department_id IN ({$placeholders}) AND is_active = 1 AND email IS NOT NULL AND email != ''");
    $st->execute(array_values($deptIds));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// Get department role mappings for a category
function getCategoryRoles(int $categoryId): array {
    $st = getDb()->prepare("SELECT department_id FROM issue_category_roles WHERE category_id = ?");
    $st->execute([$categoryId]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

// ── Last In Not Out (for outlet directory) ──────────────
function getLastInNotOut(): array {
    $now = time();
    $shiftDate = ((int)date('G', $now) < SHIFT_CUTOFF_HOUR) ? date('Y-m-d', $now - 86400) : date('Y-m-d');
    $windowStart = $shiftDate . ' ' . sprintf('%02d:00:00', SHIFT_CUTOFF_HOUR);
    $windowEnd   = date('Y-m-d', strtotime($shiftDate . ' +1 day')) . ' ' . sprintf('%02d:59:59', SHIFT_CUTOFF_HOUR - 1);

    $sql = "SELECT a.employee_code, a.punch_type, a.punch_time, a.location_id,
                   e.full_name, e.phone, e.location_id AS emp_location_id
            FROM attendance_logs a
            JOIN employees e ON a.employee_code = e.employee_code AND e.is_active = 1
            WHERE a.punch_time >= ? AND a.punch_time <= ?
            ORDER BY a.employee_code, a.punch_time ASC";
    try {
        $st = getDb()->prepare($sql);
        $st->execute([$windowStart, $windowEnd]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }

    // Group by employee — last punch wins due to ASC order
    $byEmp = [];
    foreach ($rows as $r) {
        $byEmp[$r['employee_code']] = $r;
    }

    // Filter: only those whose last punch is IN, keyed by actual punch location_id
    $result = [];
    foreach ($byEmp as $r) {
        if ($r['punch_type'] === 'IN') {
            $locId = (int)$r['location_id'];
            $result[$locId][] = ['name' => $r['full_name'], 'phone' => $r['phone'] ?? ''];
        }
    }
    return $result;
}

// ── Last punch for employee (for my_location) ──────────
function getLastPunchForEmployee(string $empCode): ?array {
    try {
        $st = getDb()->prepare(
            'SELECT a.punch_time, a.punch_type, a.location_id, l.location_name
             FROM attendance_logs a
             LEFT JOIN locations l ON a.location_id = l.location_id
             WHERE a.employee_code = ?
             ORDER BY a.punch_time DESC LIMIT 1'
        );
        $st->execute([$empCode]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) { return null; }
}

// ── Store hours data (first IN / last OUT per day per location) ─
function getStoreHoursData(int $locationId, string $fromDate, string $toDate): array {
    // Inclusive date range using shift-aware boundaries (8AM on fromDate to SHIFT_CUTOFF on day after toDate)
    $from = $fromDate . ' ' . sprintf('%02d:00:00', 8);
    $toTs = strtotime($toDate . ' +1 day');
    $to   = date('Y-m-d', $toTs) . ' ' . sprintf('%02d:59:59', SHIFT_CUTOFF_HOUR - 1);

    $params = [];
    $sql = "SELECT a.punch_time, a.punch_type, a.employee_code, a.location_id,
                   e.full_name, l.location_name
            FROM attendance_logs a
            LEFT JOIN employees e ON a.employee_code = e.employee_code
            LEFT JOIN locations  l ON a.location_id  = l.location_id
            WHERE a.punch_time >= ? AND a.punch_time <= ?";
    $params[] = $from;
    $params[] = $to;
    if ($locationId > 0) {
        $sql .= " AND a.location_id = ?";
        $params[] = $locationId;
    }
    $sql .= " ORDER BY a.location_id, a.punch_time ASC";

    try {
        $st = getDb()->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }

    // Group by location, then by shift day, find first IN (>= 8AM) and last OUT
    $byLoc = [];
    foreach ($rows as $r) {
        $locId = (int)$r['location_id'];
        $day   = shiftDay($r['punch_time']);
        $hour  = (int)date('G', strtotime($r['punch_time']));
        if (!isset($byLoc[$locId])) $byLoc[$locId] = ['location_name' => $r['location_name'] ?? ('Loc #' . $locId), 'days' => []];
        if (!isset($byLoc[$locId]['days'][$day])) {
            $byLoc[$locId]['days'][$day] = ['first_in' => null, 'first_in_emp' => '', 'last_out' => null, 'last_out_emp' => ''];
        }
        if ($r['punch_type'] === 'IN' && ($hour >= 8 || $hour < SHIFT_CUTOFF_HOUR)) {
            if ($byLoc[$locId]['days'][$day]['first_in'] === null) {
                $byLoc[$locId]['days'][$day]['first_in'] = $r['punch_time'];
                $byLoc[$locId]['days'][$day]['first_in_emp'] = $r['full_name'] ?? $r['employee_code'];
            }
        }
        if ($r['punch_type'] === 'OUT') {
            $byLoc[$locId]['days'][$day]['last_out'] = $r['punch_time'];
            $byLoc[$locId]['days'][$day]['last_out_emp'] = $r['full_name'] ?? $r['employee_code'];
        }
    }
    foreach ($byLoc as &$locData) ksort($locData['days']);
    ksort($byLoc);
    return $byLoc;
}

// ── SVG nav icons ───────────────────────────────────────
function navIcon(string $name): string {
    $icons = [
        'dashboard'    => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>',
        'locations'    => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>',
        'departments'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>',
        'devices'      => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12" y2="18"/></svg>',
        'employees'    => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'attendance'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
        'issues'       => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>',
        'categories'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
        'offer'        => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>',
        'generate'     => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
        'checklist'    => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        'tasks'        => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
        'report'       => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
        'audit'        => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
        'punch'        => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>',
        'passwords'    => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
        'outlet'       => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
        'shelf'        => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
        'settings'     => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
        'clock'        => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        'key'          => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>',
        'coupon_used'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/></svg>',
        'alert'        => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        'tag'          => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
        // Admin & access
        'admin'        => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>',
        'account'      => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="10" r="3"/><path d="M7 20.66A7 7 0 0 1 12 18a7 7 0 0 1 5 2.66"/></svg>',
        'roles'        => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m22 11-3-3-3 3"/><path d="M19 8v6"/></svg>',
        'dependency'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="3"/><circle cx="18" cy="6" r="3"/><circle cx="12" cy="18" r="3"/><path d="M6 9v3a3 3 0 0 0 3 3h6a3 3 0 0 0 3-3V9"/><path d="M12 12v3"/></svg>',
        // Issue / task
        'task_check'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M9 2v4"/><path d="M15 2v4"/><path d="m9 14 2 2 4-4"/></svg>',
        // Audits
        'audit_list'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3H8a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2z"/><path d="M9 3v2h6V3"/><path d="m9 13 2 2 4-4"/></svg>',
        'audit_create' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M12 12v6"/><path d="M9 15h6"/></svg>',
        'audit_param'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/><circle cx="9" cy="6" r="2"/><circle cx="15" cy="12" r="2"/><circle cx="11" cy="18" r="2"/></svg>',
        'audit_tpl'    => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="9" x2="9" y2="21"/></svg>',
        // Coupons / discounts
        'coupon'       => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v4a2 2 0 0 1 0 4v4a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-4a2 2 0 0 1 0-4z"/><path d="M9 9v.01"/><path d="M15 15v.01"/><line x1="9" y1="15" x2="15" y2="9"/></svg>',
        'voucher'      => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="7" y1="15" x2="9" y2="15"/></svg>',
        // Retail
        'store_ops'    => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m2 7 1.5-3a2 2 0 0 1 1.8-1h13.4a2 2 0 0 1 1.8 1L22 7"/><path d="M2 7v2a3 3 0 0 0 5 2.2A3 3 0 0 0 12 9.2 3 3 0 0 0 17 11.2 3 3 0 0 0 22 9V7"/><path d="M4 11v9a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-9"/><path d="M9 21v-5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v5"/></svg>',
        'comments'     => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
        'summary'      => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><polyline points="3 6 21 6"/><polyline points="3 18 14 18"/></svg>',
    ];
    return $icons[$name] ?? '';
}

// ── PHP EOL date lookup ─────────────────────────────────
function phpEolDate(string $branch): string {
    $eol = [
        '8.1' => '2025-12-31', '8.2' => '2026-12-31', '8.3' => '2027-12-31',
        '8.4' => '2028-12-31', '8.5' => '2029-12-31',
    ];
    return $eol[$branch] ?? 'Unknown';
}

// ── Display Helpers ──────────────────────────────────────
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function statusBadge(string $status, int $isActive): string {
    if (!$isActive) return '<span class="badge badge-red">Inactive</span>';
    return match($status) {
        'active'             => '<span class="badge badge-green">Active</span>',
        'partial'            => '<span class="badge badge-yellow">Partial</span>',
        'pending_enrollment' => '<span class="badge badge-blue">Pending</span>',
        default              => '<span class="badge badge-grey">'.h($status).'</span>',
    };
}
