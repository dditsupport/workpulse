<?php
// =========================================================
// Negative Feedback — customer complaints, from intake to closure
//
// A bad Google review, a low Reelo bill rating, a Swiggy or Zomato
// complaint used to be raised as an ordinary ticket. Here it has its own
// lifecycle:
//
//   · Intake — someone with txn_feedback_entry types it in: source,
//     outlet, customer, rating, what they said, when, and the platform's
//     own reference. Every source is manual for now; Swiggy and Zomato
//     offer restaurant partners no review API, so those are copied from
//     the partner dashboards.
//   · Open — the outlet's Store Manager, its Operation Manager and the
//     operations team (txn_feedback_view) are emailed and can see it.
//   · Resolution submitted — any of them writes a remark (mandatory) and
//     may attach proof: call recordings and/or receipts. The Operation
//     Manager's part ends here: they resolve, they do not close.
//   · Verification — a CLOSER approves it (Closed) or sends it back with
//     a mandatory reason (Open again, both sides emailed).
//   · A closer can also close an open complaint without a resolution:
//     duplicate, spam, not actionable.
//
// Closers are named by employee ID and nothing else: the employees listed
// in the FeedbackCloserCodes setting, for every outlet. Only the superadmin
// edits that list, from the Negative Feedback page itself — it is not on
// the Settings page. Being an outlet's Operation Manager, holding a flag,
// or being the superadmin (who has no employee ID) does not make anyone a
// closer. Nobody verifies their own resolution — another closer must.
//
// A complaint left open longer than FeedbackEscalateHours is escalated:
// the closers are emailed once per wait. That runs lazily from the list
// page and from cron/run_feedback_escalation.php.
//
// Schema: migrations/2026-09-24_negative_feedback.sql
// =========================================================

define('FB_UPLOAD_DIR', __DIR__ . '/../uploads/feedback/');
define('FB_MAX_BYTES',  20 * 1024 * 1024);   // 20 MB per file — call recordings run long
define('FB_MAX_FILES',  10);                 // per resolution
define('FB_MAX_TEXT',   4000);               // characters, feedback text and remarks

const FB_SOURCES = [
    'google' => 'Google',
    'reelo'  => 'Reelo',
    'swiggy' => 'Swiggy',
    'zomato' => 'Zomato',
    'other'  => 'Other',
];

// What the platform reference means for each source, for the intake form.
const FB_REF_HINT = [
    'google' => 'Review link or reviewer name',
    'reelo'  => 'Bill number',
    'swiggy' => 'Order or complaint ID',
    'zomato' => 'Order or complaint ID',
    'other'  => 'Any reference',
];

const FB_CLOSE_REASONS = [
    'duplicate'      => 'Duplicate',
    'spam'           => 'Spam / fake review',
    'not_actionable' => 'Not actionable',
];

// Extension-keyed, the way DC_ALLOWED_MIME is: phone recorders write .m4a,
// .amr and .aac that finfo reports in several ways, and a flat mime list
// would turn away the very recordings this exists to collect.
const FB_ALLOWED = [
    'recording' => [
        'mp3'  => ['audio/mpeg', 'audio/mp3'],
        'm4a'  => ['audio/mp4', 'audio/x-m4a', 'video/mp4', 'application/octet-stream'],
        'aac'  => ['audio/aac', 'audio/x-hx-aac-adts', 'application/octet-stream'],
        'wav'  => ['audio/wav', 'audio/x-wav', 'audio/wave'],
        'ogg'  => ['audio/ogg', 'application/ogg'],
        'opus' => ['audio/ogg', 'audio/opus', 'application/ogg', 'application/octet-stream'],
        'amr'  => ['audio/amr', 'application/octet-stream'],
        '3gp'  => ['video/3gpp', 'audio/3gpp'],
        'webm' => ['audio/webm', 'video/webm'],
    ],
    'receipt' => [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'webp' => ['image/webp'],
        'heic' => ['image/heic', 'image/heif', 'application/octet-stream'],
        'heif' => ['image/heif', 'image/heic', 'application/octet-stream'],
        'pdf'  => ['application/pdf'],
    ],
];

// What a browser plays or shows inline. Anything else is a download.
const FB_INLINE = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf',
                   'audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/x-m4a', 'audio/aac',
                   'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/ogg', 'audio/webm'];

// ── Schema probe ────────────────────────────────────────
function fbSchemaReady(): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        getDb()->query('SELECT 1 FROM fb_feedback LIMIT 0')->fetch();
        getDb()->query('SELECT 1 FROM fb_resolutions LIMIT 0')->fetch();
        getDb()->query('SELECT 1 FROM fb_files LIMIT 0')->fetch();
        $ready = true;
    } catch (Exception $e) {
        $ready = false;
    }
    return $ready;
}

function fbSchemaNotice(): string {
    return 'Negative Feedback is not set up on this database yet — run '
         . 'migrations/2026-09-24_negative_feedback.sql.';
}

function fbRef(int $id): string { return 'NF-' . $id; }

// ── Permissions ─────────────────────────────────────────
function fbCanEnter(): bool {
    return isSuperadmin() || hasTxn('feedback_entry');
}

// Employee IDs in FeedbackCloserCodes, trimmed, as a [code => true] set.
function fbCloserCodes(): array {
    static $codes = null;
    if ($codes !== null) return $codes;
    $codes = [];
    $raw = function_exists('getSetting') ? (string)getSetting('FeedbackCloserCodes', '') : '';
    foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $c) {
        $c = trim($c);
        if ($c !== '') $codes[$c] = true;
    }
    return $codes;
}

// Named in FeedbackCloserCodes — the only way to approve, send back or
// close. Deliberately not superadmin: closing records an employee ID.
function fbIsCloser(): bool {
    $me = myCode();
    return $me !== '' && isset(fbCloserCodes()[$me]);
}

function fbCanManageClosers(): bool {
    return isSuperadmin();
}

// Sees every outlet: the operations team, intake staff and global closers.
function fbSeesAll(): bool {
    return isSuperadmin() || hasTxn('feedback_view') || hasTxn('feedback_entry') || fbIsCloser();
}

// [location_id => 'sm'|'om'|'both'] — the outlets Manager Mapping puts this
// user in charge of. Deliberately not the outlet on their profile: a
// complaint about a store is for the people who run it, not every cashier
// who works there.
function fbMyLocations(): array {
    static $ids = null;
    if ($ids !== null) return $ids;
    $ids = [];
    $me = myCode();
    if ($me === '') return $ids;
    if (function_exists('getLocationManagerMap')) {
        foreach (getLocationManagerMap() as $lid => $code) {
            if ((string)$code === $me) $ids[(int)$lid] = 'sm';
        }
    }
    if (function_exists('getLocationOperationManagerMap')) {
        foreach (getLocationOperationManagerMap() as $lid => $code) {
            if ((string)$code === $me) $ids[(int)$lid] = isset($ids[(int)$lid]) ? 'both' : 'om';
        }
    }
    return $ids;
}

function fbCanUsePage(): bool {
    return fbSeesAll() || fbMyLocations() !== [];
}

function fbCanSee(array $fb): bool {
    return fbSeesAll() || isset(fbMyLocations()[(int)$fb['location_id']]);
}

// The one definition of "may approve, send back or close this complaint":
// an employee listed in FeedbackCloserCodes. The outlet's Operation
// Manager is not one by virtue of the mapping — they submit remarks.
function fbCanClose(array $fb): bool {
    return fbIsCloser();
}

// Submitting a resolution: the outlet's Store Manager or Operation
// Manager, or anyone on the operations team — and only while it is open.
function fbCanResolve(array $fb): bool {
    if (($fb['status'] ?? '') !== 'open') return false;
    return isSuperadmin() || hasTxn('feedback_view') || isset(fbMyLocations()[(int)$fb['location_id']]);
}

// ── Lookups ─────────────────────────────────────────────
function fbGet(int $id): ?array {
    if ($id <= 0) return null;
    $st = getDb()->prepare(
        'SELECT f.*, l.location_name, l.contact_email AS location_email,
                cb.full_name AS created_by_name, xb.full_name AS closed_by_name
           FROM fb_feedback f
           LEFT JOIN locations l  ON l.location_id = f.location_id
           LEFT JOIN employees cb ON cb.employee_code = f.created_by
           LEFT JOIN employees xb ON xb.employee_code = f.closed_by
          WHERE f.id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Every resolution on a complaint, oldest first, with who submitted and
// who decided it.
function fbResolutions(int $feedbackId): array {
    $st = getDb()->prepare(
        'SELECT r.*, sb.full_name AS submitted_by_name, db.full_name AS decided_by_name
           FROM fb_resolutions r
           LEFT JOIN employees sb ON sb.employee_code = r.submitted_by
           LEFT JOIN employees db ON db.employee_code = r.decided_by
          WHERE r.feedback_id = ?
          ORDER BY r.submitted_at, r.id');
    $st->execute([$feedbackId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// [resolution_id => [file rows]]
function fbFilesByResolution(int $feedbackId): array {
    $st = getDb()->prepare('SELECT * FROM fb_files WHERE feedback_id = ? ORDER BY kind, id');
    $st->execute([$feedbackId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) $out[(int)$f['resolution_id']][] = $f;
    return $out;
}

function fbPendingResolution(int $feedbackId): ?array {
    $st = getDb()->prepare("SELECT * FROM fb_resolutions WHERE feedback_id = ? AND decision = 'pending'
                             ORDER BY id DESC LIMIT 1");
    $st->execute([$feedbackId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fbEmployeeName(string $code): string {
    if ($code === '') return '';
    $st = getDb()->prepare('SELECT full_name FROM employees WHERE employee_code = ?');
    $st->execute([$code]);
    return (string)($st->fetchColumn() ?: '');
}

// "Name (CODE)" — who did something, with the employee ID the closure
// rules are written in.
function fbWho(?string $name, ?string $code): string {
    $name = trim((string)$name);
    $code = trim((string)$code);
    if ($code === '') return $name !== '' ? $name : '—';
    return ($name !== '' ? $name . ' ' : '') . '(' . $code . ')';
}

// ── Presentation ────────────────────────────────────────
function fbStatusBadge(array $fb): string {
    switch ($fb['status']) {
        case 'submitted': return '<span class="badge badge-yellow">Resolution submitted</span>';
        case 'closed':    return '<span class="badge badge-green">Closed</span>';
        default:          return '<span class="badge badge-red">Open</span>';
    }
}

function fbSourceBadge(string $source): string {
    $cls = ['google' => 'badge-blue', 'reelo' => 'badge-purple', 'swiggy' => 'badge-amber',
            'zomato' => 'badge-red', 'other' => 'badge-grey'][$source] ?? 'badge-grey';
    return '<span class="badge ' . $cls . '">' . h(FB_SOURCES[$source] ?? ucfirst($source)) . '</span>';
}

function fbStars(?int $rating): string {
    if (!$rating) return '<span class="text-muted">—</span>';
    return '<span style="color:#f59e0b;letter-spacing:1px" title="' . $rating . ' of 5">'
         . str_repeat('★', $rating) . '<span style="opacity:.3">' . str_repeat('★', 5 - $rating) . '</span></span>';
}

// "2d 4h", "3h 20m", "12m" — how long something took or has waited.
function fbDuration(int $seconds): string {
    if ($seconds < 60) return '< 1m';
    $d = intdiv($seconds, 86400);
    $h = intdiv($seconds % 86400, 3600);
    $m = intdiv($seconds % 3600, 60);
    if ($d > 0) return $d . 'd ' . $h . 'h';
    if ($h > 0) return $h . 'h ' . $m . 'm';
    return $m . 'm';
}

function fbFmt(?string $dt): string {
    return $dt ? date('d M Y, H:i', strtotime($dt)) : '—';
}

// ── Notifications ───────────────────────────────────────
// Email only for now. WhatsApp and push need a provider the app does not
// have yet; when one arrives, it hangs off this same function.
function fbSettingEmails(string $key): array {
    $raw = function_exists('getSetting') ? (string)getSetting($key, '') : '';
    $out = [];
    foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $e) {
        $e = trim($e);
        if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $out[] = $e;
    }
    return $out;
}

// Who hears about each event:
//   opened / sent_back — Store Manager, Operation Manager, the outlet's own
//                        address, the operations team (and on a send-back
//                        whoever submitted the rejected resolution)
//   submitted          — the closers (FeedbackCloserCodes)
//   escalated          — the closers, and the Operation Manager so they
//                        can chase the store for a remark
//   closed             — Store Manager, the resolver, the operations team
function fbNotify(int $feedbackId, string $event, string $note = '', string $extraCode = ''): void {
    if (!function_exists('sendSmtpEmailQuiet')) return;
    try {
        $fb = fbGet($feedbackId);
        if (!$fb) return;
        $loc   = (int)$fb['location_id'];
        $smMap = function_exists('getLocationManagerMap') ? getLocationManagerMap() : [];
        $omMap = function_exists('getLocationOperationManagerMap') ? getLocationOperationManagerMap() : [];
        $sm    = (string)($smMap[$loc] ?? '');
        $om    = (string)($omMap[$loc] ?? '');

        $codes  = [];
        $emails = [];
        switch ($event) {
            case 'opened':
                $codes  = [$sm, $om];
                $emails = array_merge(fbSettingEmails('FeedbackNotifyEmails'), [(string)$fb['location_email']]);
                $heading = 'New negative feedback';
                break;
            case 'sent_back':
                $codes  = [$sm, $om, $extraCode];
                $emails = array_merge(fbSettingEmails('FeedbackNotifyEmails'), [(string)$fb['location_email']]);
                $heading = 'Resolution sent back';
                break;
            case 'submitted':
                $codes   = array_keys(fbCloserCodes());
                $heading = 'Resolution waiting for verification';
                break;
            case 'escalated':
                $codes   = array_merge([$om], array_keys(fbCloserCodes()));
                $heading = 'Unresolved past the time limit';
                break;
            case 'closed':
                $codes  = [$sm, $extraCode];
                $emails = fbSettingEmails('FeedbackNotifyEmails');
                $heading = 'Negative feedback closed';
                break;
            default:
                return;
        }
        foreach (getEmployeeEmails(array_values(array_filter($codes))) as $r) $emails[] = (string)$r['email'];
        $emails = array_values(array_unique(array_filter(array_map('trim', $emails))));
        if (!$emails) return;

        $base = rtrim((string)(function_exists('getSetting') ? getSetting('AppBaseUrl', '') : ''), '/');
        if ($base === '') $base = 'https://wp.aromen.biz';
        $link = $base . '/index.php?page=feedback_view&id=' . $feedbackId;

        $ref     = fbRef($feedbackId);
        $source  = FB_SOURCES[$fb['source']] ?? $fb['source'];
        $subject = "DD - {$ref} {$heading}: {$fb['location_name']} ({$source})";
        $rating  = $fb['rating'] ? str_repeat('★', (int)$fb['rating']) . ' (' . (int)$fb['rating'] . '/5)' : '—';
        $row = fn(string $k, string $v) => "<tr><td style='padding:6px 8px;font-weight:bold;width:130px;vertical-align:top'>{$k}</td>"
                                          . "<td style='padding:6px 8px'>{$v}</td></tr>";
        $body = "
        <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto'>
            <div style='background:#1a1d2e;color:#fff;padding:16px 20px;border-radius:8px 8px 0 0'>
                <h2 style='margin:0;font-size:16px'>" . h($heading) . " — {$ref}</h2>
            </div>
            <div style='background:#f8f9fa;padding:20px;border:1px solid #dee2e6;border-top:0;border-radius:0 0 8px 8px'>
                <table style='width:100%;font-size:14px;border-collapse:collapse'>"
                . $row('Outlet', h($fb['location_name']))
                . $row('Source', h($source) . ($fb['source_ref'] ? ' · ' . h($fb['source_ref']) : ''))
                . $row('Customer', h(trim($fb['customer_name'] . ' ' . $fb['customer_phone'])) ?: '—')
                . $row('Rating', $rating)
                . $row('Received', h(fbFmt($fb['received_at'])))
                . $row('Feedback', nl2br(h(mb_substr((string)$fb['feedback_text'], 0, 600))))
                . "</table>"
                . ($note !== '' ? "<div style='margin-top:12px;padding:10px;background:#fff;border:1px solid #dee2e6;border-radius:4px;font-size:13px'>" . nl2br(h($note)) . "</div>" : '')
                . "<div style='margin-top:16px;text-align:center'>
                    <a href='" . h($link) . "' style='display:inline-block;background:#4f46e5;color:#fff;padding:10px 24px;border-radius:6px;text-decoration:none;font-size:14px'>Open {$ref}</a>
                </div>
            </div>
            <div style='text-align:center;padding:12px;font-size:11px;color:#999'>Work Pulse — Negative Feedback</div>
        </div>";
        foreach ($emails as $e) sendSmtpEmailQuiet($e, $subject, $body);
    } catch (Throwable $e) {
        error_log('[feedback] notify ' . $event . ' for ' . $feedbackId . ' failed: ' . $e->getMessage());
    }
}

// ── Escalation ──────────────────────────────────────────
// Every complaint still waiting for a resolution longer than
// FeedbackEscalateHours is escalated once per wait: escalated_at is
// claimed with a conditional UPDATE, so the lazy run on the list page and
// the cron run can never both mail the same one. A send-back clears it.
// Returns how many were escalated.
function fbRunEscalation(): int {
    if (!fbSchemaReady()) return 0;
    $hours = (int)(function_exists('getSetting') ? getSetting('FeedbackEscalateHours', '24') : 24);
    if ($hours <= 0) return 0;
    $db = getDb();
    try {
        $st = $db->prepare("SELECT id FROM fb_feedback
                             WHERE status = 'open' AND escalated_at IS NULL
                               AND open_since <= ?
                             ORDER BY id LIMIT 50");
        $st->execute([date('Y-m-d H:i:s', time() - $hours * 3600)]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {
        return 0;
    }
    $n = 0;
    $claim = $db->prepare("UPDATE fb_feedback SET escalated_at = ?
                            WHERE id = ? AND status = 'open' AND escalated_at IS NULL");
    foreach ($ids as $id) {
        $claim->execute([date('Y-m-d H:i:s'), $id]);
        if ($claim->rowCount() !== 1) continue;
        fbNotify($id, 'escalated', "No resolution has been submitted in {$hours} hours.");
        $n++;
    }
    return $n;
}

// ── Sidebar count ───────────────────────────────────────
// What is waiting on this user: resolutions to verify if they are a
// closer, plus open complaints at the outlets they run (Store Manager or
// Operation Manager — both owe a remark).
function fbNavLabel(): string {
    $n = 0;
    if (fbSchemaReady()) {
        try {
            $mine = array_keys(fbMyLocations());
            $db   = getDb();
            if (fbIsCloser()) {
                $n = (int)$db->query("SELECT COUNT(*) FROM fb_feedback WHERE status = 'submitted'")->fetchColumn();
            }
            if ($mine) {
                $ph = implode(',', array_fill(0, count($mine), '?'));
                $st = $db->prepare("SELECT COUNT(*) FROM fb_feedback WHERE status = 'open' AND location_id IN ({$ph})");
                $st->execute($mine);
                $n += (int)$st->fetchColumn();
            }
        } catch (Exception $e) {
            $n = 0;
        }
    }
    return 'Negative Feedback' . ($n > 0 ? ' <span class="badge badge-yellow">' . $n . '</span>' : '');
}

// ── Files ───────────────────────────────────────────────
function fbFileDir(int $feedbackId): string {
    return FB_UPLOAD_DIR . $feedbackId . '/';
}

// Check every file in one $_FILES array-of-files before anything is moved,
// so a resolution is either saved with all its proof or not saved at all.
// Returns [['tmp','original','ext','mime','size','kind'], …]; appends plain
// reasons to $errors.
function fbCollectUploads(string $field, string $kind, array &$errors): array {
    $files = $_FILES[$field] ?? null;
    if (!$files || !is_array($files['name'] ?? null)) return [];
    $out = [];
    foreach ($files['name'] as $i => $name) {
        $err = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        $orig = basename((string)$name);
        if ($err !== UPLOAD_ERR_OK) { $errors[] = "{$orig} (upload error {$err})"; continue; }
        if ((int)$files['size'][$i] > FB_MAX_BYTES) {
            $errors[] = "{$orig} (over " . (FB_MAX_BYTES / 1024 / 1024) . ' MB)';
            continue;
        }
        $ext = mb_strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $ok  = FB_ALLOWED[$kind][$ext] ?? null;
        if (!$ok) {
            $errors[] = "{$orig} (.{$ext} is not accepted as a " . ($kind === 'recording' ? 'call recording' : 'receipt') . ')';
            continue;
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$files['tmp_name'][$i]) ?: 'application/octet-stream';
        if (!in_array($mime, $ok, true)) { $errors[] = "{$orig} (not a real .{$ext})"; continue; }
        $out[] = ['tmp' => (string)$files['tmp_name'][$i], 'original' => mb_substr($orig, 0, 255),
                  'ext' => $ext, 'mime' => $mime, 'size' => (int)$files['size'][$i], 'kind' => $kind];
    }
    return $out;
}

// ── Handler: intake ─────────────────────────────────────
function doFbSave(): void {
    $form = 'index.php?page=feedback_new';
    if (!fbSchemaReady() || !fbCanEnter()) {
        flash('error', 'You do not have permission to log negative feedback.');
        header('Location: index.php?page=feedback'); exit;
    }
    $source   = (string)($_POST['source'] ?? '');
    $locId    = (int)($_POST['location_id'] ?? 0);
    $custName = trim((string)($_POST['customer_name'] ?? ''));
    $custPh   = trim((string)($_POST['customer_phone'] ?? ''));
    $rating   = (int)($_POST['rating'] ?? 0);
    $text     = trim((string)($_POST['feedback_text'] ?? ''));
    $received = trim((string)($_POST['received_at'] ?? ''));
    $ref      = trim((string)($_POST['source_ref'] ?? ''));

    // Keep what was typed so a rejected form does not have to be redone.
    $_SESSION['fb_form'] = $_POST;

    $ts = $received !== '' ? strtotime(str_replace('T', ' ', $received)) : false;
    $problem = '';
    if (!isset(FB_SOURCES[$source]))              $problem = 'Pick where the feedback came from.';
    elseif ($locId <= 0)                          $problem = 'Pick the outlet it is about.';
    elseif ($text === '')                         $problem = 'Paste what the customer said.';
    elseif (mb_strlen($text) > FB_MAX_TEXT)       $problem = 'The feedback text is longer than ' . FB_MAX_TEXT . ' characters.';
    elseif ($rating < 0 || $rating > 5)           $problem = 'Rating must be 1 to 5 stars.';
    elseif ($ts === false)                        $problem = 'Enter when the customer posted it.';
    elseif ($ts > time() + 300)                   $problem = 'The received time is in the future.';
    if ($problem !== '') { flash('error', $problem); header("Location: {$form}"); exit; }

    $db = getDb();
    $loc = $db->prepare('SELECT location_id FROM locations WHERE location_id = ? AND is_active = 1');
    $loc->execute([$locId]);
    if (!$loc->fetchColumn()) { flash('error', 'That outlet is not active.'); header("Location: {$form}"); exit; }

    // The same review logged twice would be chased twice. The unique key
    // is the backstop; this lookup is what tells the user which one it was.
    if ($ref !== '') {
        $dup = $db->prepare('SELECT id FROM fb_feedback WHERE source = ? AND source_ref = ?');
        $dup->execute([$source, mb_substr($ref, 0, 150)]);
        if ($dupId = (int)$dup->fetchColumn()) {
            flash('error', 'This ' . FB_SOURCES[$source] . ' reference is already logged as ' . fbRef($dupId) . '.');
            header("Location: {$form}"); exit;
        }
    }

    $now = date('Y-m-d H:i:s');
    try {
        $db->prepare('INSERT INTO fb_feedback
                        (source, location_id, customer_name, customer_phone, rating, feedback_text,
                         received_at, source_ref, created_by, created_at, open_since)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$source, $locId,
                      $custName === '' ? null : mb_substr($custName, 0, 100),
                      $custPh === '' ? null : mb_substr($custPh, 0, 20),
                      $rating ?: null, $text, date('Y-m-d H:i:s', $ts),
                      $ref === '' ? null : mb_substr($ref, 0, 150), myCode(), $now, $now]);
        $id = (int)$db->lastInsertId();
    } catch (Exception $e) {
        flash('error', 'Could not save: ' . $e->getMessage());
        header("Location: {$form}"); exit;
    }
    unset($_SESSION['fb_form']);
    fbNotify($id, 'opened');
    flash('success', fbRef($id) . ' opened. The store manager and operations team have been emailed.');
    header('Location: index.php?page=feedback_view&id=' . $id); exit;
}

// ── Handler: submit a resolution ────────────────────────
function doFbResolve(): void {
    $id   = (int)($_POST['id'] ?? 0);
    $back = 'index.php?page=feedback_view&id=' . $id;
    $fb   = fbSchemaReady() ? fbGet($id) : null;
    if (!$fb || !fbCanSee($fb)) {
        flash('error', 'Feedback not found.');
        header('Location: index.php?page=feedback'); exit;
    }
    if (!fbCanResolve($fb)) {
        flash('error', $fb['status'] === 'open'
            ? 'You cannot submit a resolution for this outlet.'
            : 'This complaint is not open — a resolution is already ' . ($fb['status'] === 'closed' ? 'closed.' : 'waiting for verification.'));
        header("Location: {$back}"); exit;
    }
    $remark = trim((string)($_POST['remark'] ?? ''));
    if ($remark === '') {
        flash('error', 'A remark is required: say what was done for the customer.');
        header("Location: {$back}"); exit;
    }
    if (mb_strlen($remark) > FB_MAX_TEXT) {
        flash('error', 'The remark is longer than ' . FB_MAX_TEXT . ' characters.');
        header("Location: {$back}"); exit;
    }

    $errors = [];
    $uploads = array_merge(fbCollectUploads('recordings', 'recording', $errors),
                           fbCollectUploads('receipts', 'receipt', $errors));
    if ($errors) {
        flash('error', 'Nothing was submitted. Fix these files and try again: ' . implode('; ', $errors));
        header("Location: {$back}"); exit;
    }
    if (count($uploads) > FB_MAX_FILES) {
        flash('error', 'Attach at most ' . FB_MAX_FILES . ' files to one resolution.');
        header("Location: {$back}"); exit;
    }
    $dir = fbFileDir($id);
    if ($uploads) {
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (!is_dir($dir) || !is_writable($dir)) {
            flash('error', 'The server cannot store attachments right now — nothing was submitted.');
            header("Location: {$back}"); exit;
        }
    }

    $db = getDb();
    $moved = [];
    try {
        $db->beginTransaction();
        // Claim the open → submitted move first: two people submitting at
        // once must not both land a pending resolution.
        $claim = $db->prepare("UPDATE fb_feedback SET status = 'submitted' WHERE id = ? AND status = 'open'");
        $claim->execute([$id]);
        if ($claim->rowCount() !== 1) {
            $db->rollBack();
            flash('error', 'Someone else submitted a resolution a moment ago.');
            header("Location: {$back}"); exit;
        }
        $db->prepare('INSERT INTO fb_resolutions (feedback_id, remark, submitted_by, submitted_at) VALUES (?,?,?,?)')
           ->execute([$id, $remark, myCode(), date('Y-m-d H:i:s')]);
        $resId = (int)$db->lastInsertId();
        $ins = $db->prepare('INSERT INTO fb_files
                               (resolution_id, feedback_id, kind, original_name, stored_name, mime_type, size_bytes, uploaded_by)
                             VALUES (?,?,?,?,?,?,?,?)');
        foreach ($uploads as $u) {
            $stored = uniqid('fb_', true) . '.' . $u['ext'];
            if (!move_uploaded_file($u['tmp'], $dir . $stored)) {
                throw new RuntimeException($u['original'] . ' could not be saved');
            }
            $moved[] = $dir . $stored;
            $ins->execute([$resId, $id, $u['kind'], $u['original'], $stored, $u['mime'], $u['size'], myCode()]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        foreach ($moved as $p) @unlink($p);
        flash('error', 'Nothing was submitted: ' . $e->getMessage());
        header("Location: {$back}"); exit;
    }
    fbNotify($id, 'submitted', 'Resolution by ' . fbWho(myName(), myCode()) . ":\n" . $remark
        . ($uploads ? "\n\n" . count($uploads) . ' attachment(s).' : ''));
    flash('success', 'Resolution submitted for verification.');
    header("Location: {$back}"); exit;
}

// ── Handler: verify (approve / send back) ───────────────
function doFbVerify(): void {
    $id       = (int)($_POST['id'] ?? 0);
    $decision = (string)($_POST['decision'] ?? '');
    $note     = trim((string)($_POST['note'] ?? ''));
    $back     = 'index.php?page=feedback_view&id=' . $id;
    $fb       = fbSchemaReady() ? fbGet($id) : null;
    if (!$fb || !fbCanSee($fb)) {
        flash('error', 'Feedback not found.');
        header('Location: index.php?page=feedback'); exit;
    }
    if (!fbCanClose($fb)) {
        flash('error', 'Only an employee listed as a feedback closer can verify.');
        header("Location: {$back}"); exit;
    }
    $res = fbPendingResolution($id);
    if ($fb['status'] !== 'submitted' || !$res) {
        flash('error', 'There is no resolution waiting for verification.');
        header("Location: {$back}"); exit;
    }
    // Nobody signs off their own work — another closer has to.
    if ((string)$res['submitted_by'] === myCode()) {
        flash('error', 'You submitted this resolution, so someone else has to verify it.');
        header("Location: {$back}"); exit;
    }
    if (!in_array($decision, ['approve', 'send_back'], true)) {
        flash('error', 'Choose Approve or Send back.');
        header("Location: {$back}"); exit;
    }
    if ($decision === 'send_back' && $note === '') {
        flash('error', 'A reason is required to send a resolution back.');
        header("Location: {$back}"); exit;
    }
    $note = mb_substr($note, 0, FB_MAX_TEXT);

    // Every timestamp this module writes comes from PHP, not MySQL NOW():
    // the waits and resolution times are worked out in PHP, and the two
    // clocks need not share a timezone.
    $now = date('Y-m-d H:i:s');
    $db  = getDb();
    try {
        $db->beginTransaction();
        $st = $db->prepare("UPDATE fb_resolutions
                               SET decision = ?, decided_by = ?, decided_at = ?, decision_note = ?
                             WHERE id = ? AND decision = 'pending'");
        $st->execute([$decision === 'approve' ? 'approved' : 'sent_back', myCode(), $now,
                      $note === '' ? null : $note, (int)$res['id']]);
        if ($st->rowCount() !== 1) throw new RuntimeException('it was decided by someone else a moment ago');
        if ($decision === 'approve') {
            $db->prepare("UPDATE fb_feedback
                             SET status = 'closed', closed_by = ?, closed_at = ?,
                                 close_reason = 'approved', close_note = ?
                           WHERE id = ?")
               ->execute([myCode(), $now, $note === '' ? null : mb_substr($note, 0, 500), $id]);
        } else {
            // Back to Open, and a fresh wait: the escalation clock restarts.
            $db->prepare("UPDATE fb_feedback
                             SET status = 'open', open_since = ?, escalated_at = NULL
                           WHERE id = ?")
               ->execute([$now, $id]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error', 'Not saved: ' . $e->getMessage());
        header("Location: {$back}"); exit;
    }
    if ($decision === 'approve') {
        fbNotify($id, 'closed', 'Resolution approved by ' . fbWho(myName(), myCode()) . ($note !== '' ? ":\n" . $note : '.'),
                 (string)$res['submitted_by']);
        flash('success', fbRef($id) . ' closed.');
    } else {
        fbNotify($id, 'sent_back', 'Sent back by ' . fbWho(myName(), myCode()) . ":\n" . $note, (string)$res['submitted_by']);
        flash('success', fbRef($id) . ' sent back — it is open again.');
    }
    header("Location: {$back}"); exit;
}

// ── Handler: close without a resolution ─────────────────
function doFbCloseDirect(): void {
    $id     = (int)($_POST['id'] ?? 0);
    $reason = (string)($_POST['reason'] ?? '');
    $note   = trim((string)($_POST['note'] ?? ''));
    $back   = 'index.php?page=feedback_view&id=' . $id;
    $fb     = fbSchemaReady() ? fbGet($id) : null;
    if (!$fb || !fbCanSee($fb)) {
        flash('error', 'Feedback not found.');
        header('Location: index.php?page=feedback'); exit;
    }
    if (!fbCanClose($fb)) {
        flash('error', 'Only an employee listed as a feedback closer can close it.');
        header("Location: {$back}"); exit;
    }
    if (!isset(FB_CLOSE_REASONS[$reason])) {
        flash('error', 'Pick why it is being closed.');
        header("Location: {$back}"); exit;
    }
    // Only an OPEN complaint closes this way. One with a resolution waiting
    // is closed by deciding that resolution, so it never sits pending forever.
    $st = getDb()->prepare("UPDATE fb_feedback
                               SET status = 'closed', closed_by = ?, closed_at = ?,
                                   close_reason = ?, close_note = ?
                             WHERE id = ? AND status = 'open'");
    $st->execute([myCode(), date('Y-m-d H:i:s'), $reason, $note === '' ? null : mb_substr($note, 0, 500), $id]);
    if ($st->rowCount() !== 1) {
        flash('error', 'Only an open complaint can be closed without a resolution.');
        header("Location: {$back}"); exit;
    }
    fbNotify($id, 'closed', 'Closed without a resolution by ' . fbWho(myName(), myCode()) . ' — '
        . FB_CLOSE_REASONS[$reason] . ($note !== '' ? ":\n" . $note : '.'));
    flash('success', fbRef($id) . ' closed as ' . strtolower(FB_CLOSE_REASONS[$reason]) . '.');
    header("Location: {$back}"); exit;
}

// ── Download / play a proof file ────────────────────────
function fbServeFile(): void {
    if (!fbSchemaReady()) { http_response_code(404); echo 'Not found'; return; }
    $st = getDb()->prepare('SELECT * FROM fb_files WHERE id = ?');
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo 'Not found'; return; }
    $fb = fbGet((int)$row['feedback_id']);
    if (!$fb || !fbCanSee($fb)) { http_response_code(403); echo 'Not allowed'; return; }
    $path = fbFileDir((int)$row['feedback_id']) . basename((string)$row['stored_name']);
    if (!is_file($path)) { http_response_code(404); echo 'File missing'; return; }

    // ?inline=1 on something a browser can play or show is the page's own
    // player / preview; anything else downloads. nosniff keeps a mislabelled
    // file from running as script either way.
    $mime   = (string)($row['mime_type'] ?: 'application/octet-stream');
    $inline = !empty($_GET['inline']) && in_array($mime, FB_INLINE, true);
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
         . '; filename="' . str_replace('"', '', (string)$row['original_name']) . '"');
    header('Content-Length: ' . (int)filesize($path));
    header('Cache-Control: private, max-age=300');
    readfile($path);
    exit;
}

// ── Closers: who may approve, send back and close ───────
// Kept in system_settings (FeedbackCloserCodes) but edited only here, and
// only by the superadmin — the Settings page does not list it, and
// doSaveSettings() refuses it from anyone else. Every code must be an
// active employee, so a typo cannot silently leave nobody able to close.

// [code => ['name' => …, 'active' => bool]] for the listed codes, in the
// order they were listed. A code with no employees row comes back with an
// empty name so the panel can flag it.
function fbCloserDetails(): array {
    $codes = array_keys(fbCloserCodes());
    if (!$codes) return [];
    $ph = implode(',', array_fill(0, count($codes), '?'));
    $st = getDb()->prepare("SELECT employee_code, full_name, is_active FROM employees WHERE employee_code IN ({$ph})");
    $st->execute($codes);
    $found = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $found[mb_strtoupper((string)$r['employee_code'])] = $r;
    }
    $out = [];
    foreach ($codes as $c) {
        $r = $found[mb_strtoupper($c)] ?? null;
        $out[$c] = ['name' => (string)($r['full_name'] ?? ''), 'active' => $r && (int)$r['is_active'] === 1];
    }
    return $out;
}

function doFbSaveClosers(): void {
    $back = 'index.php?page=feedback';
    if (!fbCanManageClosers() || !fbSchemaReady()) {
        flash('error', 'Only the superadmin can change who closes negative feedback.');
        header("Location: {$back}"); exit;
    }
    $raw   = (string)($_POST['closer_codes'] ?? '');
    $codes = [];
    foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $c) {
        $c = trim($c);
        if ($c !== '') $codes[mb_strtoupper($c)] = $c;
    }
    $db = getDb();
    $canonical = [];
    if ($codes) {
        $ph = implode(',', array_fill(0, count($codes), '?'));
        $st = $db->prepare("SELECT employee_code FROM employees WHERE is_active = 1 AND employee_code IN ({$ph})");
        $st->execute(array_values($codes));
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $code) $canonical[mb_strtoupper((string)$code)] = (string)$code;
        $unknown = array_diff_key($codes, $canonical);
        if ($unknown) {
            flash('error', 'Not saved — no active employee with ID: ' . implode(', ', $unknown) . '.');
            header("Location: {$back}"); exit;
        }
    }
    // Keep the order they were typed in, with the ID as the employee
    // master spells it.
    $value = implode(',', array_map(fn($k) => $canonical[$k], array_keys($codes)));
    try {
        $has = $db->prepare('SELECT COUNT(*) FROM system_settings WHERE setting_key = ?');
        $has->execute(['FeedbackCloserCodes']);
        if ((int)$has->fetchColumn() > 0) {
            $db->prepare('UPDATE system_settings SET setting_value = ? WHERE setting_key = ?')
               ->execute([$value, 'FeedbackCloserCodes']);
        } else {
            $db->prepare('INSERT IGNORE INTO system_settings (setting_key, setting_value, description) VALUES (?,?,?)')
               ->execute(['FeedbackCloserCodes', $value, 'Employee IDs who may approve, send back or close negative feedback. Edited on the Negative Feedback page by the superadmin.']);
        }
    } catch (Exception $e) {
        flash('error', 'Could not save: ' . $e->getMessage());
        header("Location: {$back}"); exit;
    }
    flash('success', $value === ''
        ? 'Closer list cleared — nobody can close negative feedback until an employee ID is added.'
        : 'Feedback closers saved: ' . str_replace(',', ', ', $value) . '.');
    header("Location: {$back}"); exit;
}

// Everyone sees who the closers are; only the superadmin gets the form.
function fbRenderClosersPanel(): void {
    $closers = fbCloserDetails();
    $manage  = fbCanManageClosers();
    if (!$closers && !$manage) {
        echo '<div class="alert alert-error" style="font-size:13px">No feedback closer is set yet, so nothing can be closed. Ask the superadmin to add one.</div>';
        return;
    }
?>
<div class="form-card" style="max-width:none;padding:12px 16px;margin-bottom:14px">
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:13px">
        <strong>Closers:</strong>
        <?php if (!$closers): ?>
            <span class="badge badge-red">None set — nothing can be closed</span>
        <?php endif; ?>
        <?php foreach ($closers as $code => $c): ?>
            <span class="badge <?= $c['active'] ? 'badge-blue' : 'badge-red' ?>" title="<?= $c['active'] ? '' : 'Not an active employee' ?>">
                <?= h(fbWho($c['name'], $code)) ?><?= $c['active'] ? '' : ' · inactive' ?>
            </span>
        <?php endforeach; ?>
        <?php if ($manage): ?>
        <button type="button" class="btn btn-sm btn-secondary" style="margin-left:auto"
                onclick="var f=document.getElementById('fbClosersForm');f.style.display=f.style.display==='none'?'':'none'">Edit</button>
        <?php endif; ?>
    </div>
    <?php if ($manage): ?>
    <form method="POST" id="fbClosersForm" style="display:none;margin-top:10px">
        <input type="hidden" name="action" value="fb_save_closers">
        <div class="form-group" style="margin-bottom:8px">
            <label>Employee IDs<span class="hint"> — comma-separated. Only these employees can approve, send back or close negative feedback, for every outlet.</span></label>
            <input type="text" name="closer_codes" class="form-control" autocomplete="off"
                   value="<?= h(implode(', ', array_keys($closers))) ?>" placeholder="e.g. EMP101, EMP204">
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Save Closers</button>
    </form>
    <?php endif; ?>
</div>
<?php
}

// ── Page: list ──────────────────────────────────────────
function pageFeedbackList(): void {
    if (!fbSchemaReady()) {
        echo '<div class="alert alert-error">' . h(fbSchemaNotice()) . '</div>';
        return;
    }
    fbRunEscalation();

    $all     = fbSeesAll();
    $mine    = fbMyLocations();
    $source  = (string)($_GET['source'] ?? '');
    if (!isset(FB_SOURCES[$source])) $source = '';
    $status  = (string)($_GET['status'] ?? 'active');
    if (!in_array($status, ['active', 'open', 'submitted', 'closed', 'all'], true)) $status = 'active';
    $locF    = (int)($_GET['location_id'] ?? 0);
    $q       = trim((string)($_GET['q'] ?? ''));

    // Scope first: what this user may see at all.
    $where = [];
    $args  = [];
    if (!$all) {
        if (!$mine) { echo '<div class="alert alert-error">You are not mapped to any outlet.</div>'; return; }
        $where[] = 'f.location_id IN (' . implode(',', array_fill(0, count($mine), '?')) . ')';
        $args    = array_merge($args, array_keys($mine));
    }
    if ($locF > 0)  { $where[] = 'f.location_id = ?'; $args[] = $locF; }
    if ($status === 'active')      $where[] = "f.status <> 'closed'";
    elseif ($status !== 'all')   { $where[] = 'f.status = ?'; $args[] = $status; }
    if ($q !== '') {
        $where[] = '(f.feedback_text LIKE ? OR f.customer_name LIKE ? OR f.customer_phone LIKE ? OR f.source_ref LIKE ? OR f.id = ?)';
        $like = '%' . $q . '%';
        array_push($args, $like, $like, $like, $like, (int)preg_replace('/\D/', '', $q));
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $db = getDb();
    // Tab counts use every filter except the source itself.
    $cnt = $db->prepare("SELECT f.source, COUNT(*) FROM fb_feedback f {$whereSql} GROUP BY f.source");
    $cnt->execute($args);
    $counts = array_map('intval', $cnt->fetchAll(PDO::FETCH_KEY_PAIR));

    $listWhere = $where;
    $listArgs  = $args;
    if ($source !== '') { $listWhere[] = 'f.source = ?'; $listArgs[] = $source; }
    $listSql = $listWhere ? 'WHERE ' . implode(' AND ', $listWhere) : '';
    $st = $db->prepare(
        "SELECT f.*, l.location_name,
                (SELECT COUNT(*) FROM fb_resolutions r WHERE r.feedback_id = f.id AND r.decision = 'sent_back') AS sent_back_count
           FROM fb_feedback f
           LEFT JOIN locations l ON l.location_id = f.location_id
           {$listSql}
          ORDER BY FIELD(f.status, 'submitted', 'open', 'closed'), f.received_at DESC
          LIMIT 500");
    $st->execute($listArgs);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $locations = getActiveLocations();
    if (!$all) $locations = array_values(array_filter($locations, fn($l) => isset($mine[(int)$l['location_id']])));
    $qs = function (array $over) use ($source, $status, $locF, $q): string {
        $p = array_merge(['page' => 'feedback', 'source' => $source, 'status' => $status,
                          'location_id' => $locF ?: '', 'q' => $q], $over);
        return '?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== null));
    };
?>
<div class="page-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <h2 style="margin:0">Negative Feedback</h2>
    <?php if (fbCanEnter()): ?>
    <a href="?page=feedback_new" class="btn btn-primary btn-sm">+ Log Feedback</a>
    <?php endif; ?>
</div>

<p class="text-muted" style="font-size:12px;margin:-4px 0 14px">
    Open → Resolution submitted → Closed. The outlet's Store Manager, its Operation Manager or the operations team
    submits a resolution; only the employees listed as closers approve it, send it back or close it.
</p>

<?php fbRenderClosersPanel(); ?>

<div class="filter-bar" style="gap:6px">
    <?php $total = array_sum($counts); ?>
    <a href="<?= h($qs(['source' => ''])) ?>" class="btn btn-sm <?= $source === '' ? 'btn-primary' : 'btn-secondary' ?>">All (<?= $total ?>)</a>
    <?php foreach (FB_SOURCES as $k => $label): ?>
    <a href="<?= h($qs(['source' => $k])) ?>" class="btn btn-sm <?= $source === $k ? 'btn-primary' : 'btn-secondary' ?>"><?= h($label) ?> (<?= (int)($counts[$k] ?? 0) ?>)</a>
    <?php endforeach; ?>
</div>

<form method="GET" class="filter-bar">
    <input type="hidden" name="page" value="feedback">
    <?php if ($source !== ''): ?><input type="hidden" name="source" value="<?= h($source) ?>"><?php endif; ?>
    <select name="status" class="form-control" style="width:auto" onchange="this.form.submit()">
        <?php foreach (['active' => 'Not closed', 'open' => 'Open', 'submitted' => 'Resolution submitted', 'closed' => 'Closed', 'all' => 'All statuses'] as $k => $label): ?>
        <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if (count($locations) > 1): ?>
    <select name="location_id" class="form-control" style="width:auto" onchange="this.form.submit()">
        <option value="">All outlets</option>
        <?php foreach ($locations as $l): ?>
        <option value="<?= (int)$l['location_id'] ?>" <?= $locF === (int)$l['location_id'] ? 'selected' : '' ?>><?= h($l['location_name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <input type="text" name="q" class="form-control" style="width:220px" value="<?= h($q) ?>" placeholder="Search text, customer, reference, NF-…">
    <button type="submit" class="btn btn-sm btn-secondary">Search</button>
</form>

<?php if (!$rows): ?>
<div class="alert alert-info">Nothing matches these filters.</div>
<?php else: ?>
<div class="table-wrap" data-stack>
<table class="table">
    <thead>
        <tr>
            <th style="width:70px">ID</th>
            <th style="width:90px">Source</th>
            <th>Outlet</th>
            <th>Feedback</th>
            <th style="width:90px">Rating</th>
            <th style="width:120px">Received</th>
            <th style="width:150px">Status</th>
            <th style="width:70px"></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
        $rid = (int)$r['id'];
        $age = $r['status'] === 'closed'
            ? 'Closed in ' . fbDuration(max(0, strtotime((string)$r['closed_at']) - strtotime((string)$r['created_at'])))
            : 'Waiting ' . fbDuration(max(0, time() - strtotime((string)$r['open_since'])));
    ?>
        <tr>
            <td><a href="?page=feedback_view&id=<?= $rid ?>" style="font-weight:600"><?= fbRef($rid) ?></a></td>
            <td><?= fbSourceBadge((string)$r['source']) ?></td>
            <td style="font-size:12px"><?= h($r['location_name']) ?></td>
            <td style="font-size:12px">
                <?= h(mb_strimwidth((string)$r['feedback_text'], 0, 140, '…')) ?>
                <?php if ($r['customer_name'] || $r['customer_phone']): ?>
                <div class="text-muted" style="font-size:11px"><?= h(trim($r['customer_name'] . ' · ' . $r['customer_phone'], ' ·')) ?></div>
                <?php endif; ?>
            </td>
            <td><?= fbStars($r['rating'] !== null ? (int)$r['rating'] : null) ?></td>
            <td style="font-size:12px"><?= h(fbFmt($r['received_at'])) ?></td>
            <td>
                <?= fbStatusBadge($r) ?>
                <?php if ($r['status'] === 'open' && (int)$r['sent_back_count'] > 0): ?>
                <span class="badge badge-amber">Sent back</span>
                <?php endif; ?>
                <?php if ($r['status'] === 'open' && $r['escalated_at']): ?>
                <span class="badge badge-red">Escalated</span>
                <?php endif; ?>
                <div class="text-muted" style="font-size:11px;margin-top:2px"><?= h($age) ?></div>
            </td>
            <td class="actions"><a href="?page=feedback_view&id=<?= $rid ?>" class="btn btn-sm btn-secondary">Open</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<div class="table-count"><?= count($rows) ?> item(s)<?= count($rows) >= 500 ? ' — showing the first 500; narrow the filters' : '' ?></div>
<?php endif;
}

// ── Page: intake form ───────────────────────────────────
function pageFeedbackForm(): void {
    if (!fbSchemaReady()) {
        echo '<div class="alert alert-error">' . h(fbSchemaNotice()) . '</div>';
        return;
    }
    if (!fbCanEnter()) {
        echo '<div class="alert alert-error">You do not have permission to log negative feedback.</div>';
        return;
    }
    $old = $_SESSION['fb_form'] ?? [];
    unset($_SESSION['fb_form']);
    $src = (string)($old['source'] ?? ($_GET['source'] ?? ''));
    $locations = getActiveLocations();
?>
<div class="page-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px">
    <h2 style="margin:0">Log Negative Feedback</h2>
    <a href="?page=feedback" class="btn btn-sm btn-ghost">← All feedback</a>
</div>

<form method="POST" class="form-card">
    <input type="hidden" name="action" value="fb_save">

    <div class="form-group">
        <label>Source *</label>
        <div style="display:flex;gap:14px;flex-wrap:wrap">
            <?php foreach (FB_SOURCES as $k => $label): ?>
            <label style="display:flex;align-items:center;gap:6px;font-weight:400;cursor:pointer">
                <input type="radio" name="source" value="<?= $k ?>" data-hint="<?= h(FB_REF_HINT[$k]) ?>" <?= $src === $k ? 'checked' : '' ?> required>
                <?= h($label) ?>
            </label>
            <?php endforeach; ?>
        </div>
        <?php if (in_array($src, ['', 'swiggy', 'zomato'], true)): ?>
        <div class="text-muted" style="font-size:11px;margin-top:4px">Swiggy and Zomato have no review feed for restaurants — copy the complaint from the partner dashboard.</div>
        <?php endif; ?>
    </div>

    <div class="form-row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
        <div class="form-group">
            <label>Outlet *</label>
            <select name="location_id" class="form-control" required>
                <option value="">— Select outlet —</option>
                <?php foreach ($locations as $l): ?>
                <option value="<?= (int)$l['location_id'] ?>" <?= (int)($old['location_id'] ?? 0) === (int)$l['location_id'] ? 'selected' : '' ?>><?= h($l['location_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Received *<span class="hint"> — when the customer posted it</span></label>
            <input type="datetime-local" name="received_at" class="form-control" required
                   max="<?= date('Y-m-d\TH:i') ?>" value="<?= h((string)($old['received_at'] ?? date('Y-m-d\TH:i'))) ?>">
        </div>
        <div class="form-group">
            <label>Customer name</label>
            <input type="text" name="customer_name" class="form-control" maxlength="100" value="<?= h((string)($old['customer_name'] ?? '')) ?>">
        </div>
        <div class="form-group">
            <label>Customer phone</label>
            <input type="tel" name="customer_phone" class="form-control" maxlength="20" value="<?= h((string)($old['customer_phone'] ?? '')) ?>">
        </div>
        <div class="form-group">
            <label>Rating</label>
            <select name="rating" class="form-control">
                <option value="0">No rating</option>
                <?php for ($i = 1; $i <= 5; $i++): ?>
                <option value="<?= $i ?>" <?= (int)($old['rating'] ?? 0) === $i ? 'selected' : '' ?>><?= str_repeat('★', $i) ?> (<?= $i ?>)</option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Source reference<span class="hint" id="fbRefHint"> — <?= h(FB_REF_HINT[$src] ?? 'order ID, bill number, review link') ?></span></label>
            <input type="text" name="source_ref" class="form-control" maxlength="150" value="<?= h((string)($old['source_ref'] ?? '')) ?>">
        </div>
    </div>

    <div class="form-group">
        <label>Feedback *<span class="hint"> — exactly what the customer wrote</span></label>
        <textarea name="feedback_text" class="form-control" rows="5" maxlength="<?= FB_MAX_TEXT ?>" required><?= h((string)($old['feedback_text'] ?? '')) ?></textarea>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Open Issue</button>
        <a href="?page=feedback" class="btn btn-ghost">Cancel</a>
    </div>
</form>
<script>
(function () {
    var hint = document.getElementById('fbRefHint');
    document.querySelectorAll('input[name=source]').forEach(function (r) {
        r.addEventListener('change', function () { if (hint) hint.textContent = ' — ' + r.dataset.hint; });
    });
})();
</script>
<?php
}

// ── Page: detail ────────────────────────────────────────
function pageFeedbackView(): void {
    if (!fbSchemaReady()) {
        echo '<div class="alert alert-error">' . h(fbSchemaNotice()) . '</div>';
        return;
    }
    $id = (int)($_GET['id'] ?? 0);
    $fb = fbGet($id);
    if (!$fb || !fbCanSee($fb)) {
        echo '<div class="alert alert-error">Feedback not found.</div>';
        return;
    }
    $resolutions = fbResolutions($id);
    $files       = fbFilesByResolution($id);
    $pending     = null;
    foreach ($resolutions as $r) if ($r['decision'] === 'pending') $pending = $r;
    $lastSentBack = null;
    foreach ($resolutions as $r) if ($r['decision'] === 'sent_back') $lastSentBack = $r;

    $canResolve = fbCanResolve($fb);
    $canClose   = fbCanClose($fb);
    $ownPending = $pending && (string)$pending['submitted_by'] === myCode();

    $smMap = function_exists('getLocationManagerMap') ? getLocationManagerMap() : [];
    $omMap = function_exists('getLocationOperationManagerMap') ? getLocationOperationManagerMap() : [];
    $smCode = (string)($smMap[(int)$fb['location_id']] ?? '');
    $omCode = (string)($omMap[(int)$fb['location_id']] ?? '');

    $kv = function (string $k, string $vHtml): void {
        echo '<div><div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px">' . h($k)
           . '</div><div style="font-size:13px;margin-top:2px">' . $vHtml . '</div></div>';
    };
?>
<div class="page-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <h2 style="margin:0"><?= fbRef($id) ?> · <?= h($fb['location_name']) ?></h2>
    <a href="?page=feedback" class="btn btn-sm btn-ghost">← All feedback</a>
</div>

<div class="form-card" style="max-width:900px;margin-bottom:16px">
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:14px">
        <?= fbSourceBadge((string)$fb['source']) ?>
        <?= fbStatusBadge($fb) ?>
        <?php if ($fb['status'] === 'open' && $fb['escalated_at']): ?>
        <span class="badge badge-red">Escalated <?= h(fbFmt($fb['escalated_at'])) ?></span>
        <?php endif; ?>
        <span style="margin-left:auto"><?= fbStars($fb['rating'] !== null ? (int)$fb['rating'] : null) ?></span>
    </div>
    <blockquote style="margin:0 0 16px;padding:10px 14px;border-left:3px solid var(--red);background:rgba(220,64,64,.06);white-space:pre-wrap;font-size:14px"><?= h($fb['feedback_text']) ?></blockquote>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px">
        <?php
        $kv('Customer', h(trim((string)$fb['customer_name'])) ?: '<span class="text-muted">—</span>');
        $kv('Phone', $fb['customer_phone'] ? '<a href="tel:' . h($fb['customer_phone']) . '">' . h($fb['customer_phone']) . '</a>' : '<span class="text-muted">—</span>');
        $kv('Source reference', h((string)$fb['source_ref']) ?: '<span class="text-muted">—</span>');
        $kv('Received', h(fbFmt($fb['received_at'])));
        $kv('Logged', h(fbFmt($fb['created_at'])) . '<div class="text-muted" style="font-size:11px">by ' . h(fbWho($fb['created_by_name'], $fb['created_by'])) . '</div>');
        $kv('Store Manager', $smCode !== '' ? h(fbWho(fbEmployeeName($smCode), $smCode)) : '<span class="text-muted">Not mapped</span>');
        $kv('Operation Manager', $omCode !== '' ? h(fbWho(fbEmployeeName($omCode), $omCode)) : '<span class="text-muted">Not mapped</span>');
        if ($fb['status'] === 'closed') {
            $kv('Closed by', h(fbWho($fb['closed_by_name'], $fb['closed_by'])));
            $kv('Closed', h(fbFmt($fb['closed_at'])));
            $kv('Total resolution time', '<strong>' . h(fbDuration(max(0, strtotime((string)$fb['closed_at']) - strtotime((string)$fb['created_at'])))) . '</strong>'
                . '<div class="text-muted" style="font-size:11px">from logged to closed</div>');
            $kv('Closed as', $fb['close_reason'] === 'approved' ? 'Resolution approved' : h(FB_CLOSE_REASONS[$fb['close_reason']] ?? (string)$fb['close_reason']));
        } else {
            $kv('Waiting', h(fbDuration(max(0, time() - strtotime((string)$fb['open_since'])))));
        }
        ?>
    </div>
    <?php if ($fb['status'] === 'closed' && $fb['close_note']): ?>
    <div style="margin-top:12px;font-size:13px"><span class="text-muted">Closing note:</span> <?= nl2br(h($fb['close_note'])) ?></div>
    <?php endif; ?>
</div>

<?php if ($resolutions): ?>
<div class="form-card" style="max-width:900px;margin-bottom:16px">
    <div class="form-section-title" style="margin-top:0">Resolutions</div>
    <?php foreach ($resolutions as $i => $r):
        $rFiles = $files[(int)$r['id']] ?? [];
        $badge  = ['pending' => '<span class="badge badge-yellow">Waiting for verification</span>',
                   'approved' => '<span class="badge badge-green">Approved</span>',
                   'sent_back' => '<span class="badge badge-amber">Sent back</span>'][$r['decision']] ?? '';
    ?>
    <div style="padding:12px 0;<?= $i ? 'border-top:1px solid var(--border)' : '' ?>">
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:12px">
            <strong>#<?= $i + 1 ?></strong>
            <span><?= h(fbWho($r['submitted_by_name'], $r['submitted_by'])) ?></span>
            <span class="text-muted"><?= h(fbFmt($r['submitted_at'])) ?></span>
            <?= $badge ?>
        </div>
        <div style="margin-top:6px;white-space:pre-wrap;font-size:13px"><?= h($r['remark']) ?></div>
        <?php if ($rFiles): ?>
        <div style="display:flex;flex-direction:column;gap:8px;margin-top:10px">
            <?php foreach ($rFiles as $f):
                $url  = '?page=feedback_file&id=' . (int)$f['id'];
                $mime = (string)$f['mime_type'];
            ?>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;font-size:12px">
                <span class="badge <?= $f['kind'] === 'recording' ? 'badge-blue' : 'badge-purple' ?>"><?= $f['kind'] === 'recording' ? 'Call recording' : 'Receipt' ?></span>
                <?php if (str_starts_with($mime, 'audio/') && in_array($mime, FB_INLINE, true)): ?>
                <audio controls preload="none" src="<?= h($url) ?>&inline=1" style="height:32px;max-width:100%"></audio>
                <?php elseif (in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)): ?>
                <a href="<?= h($url) ?>&inline=1" target="_blank" rel="noopener"><img src="<?= h($url) ?>&inline=1" alt="" style="height:60px;border-radius:4px;border:1px solid var(--border)"></a>
                <?php endif; ?>
                <a href="<?= h($url) ?>"><?= h($f['original_name']) ?></a>
                <span class="text-muted"><?= number_format(max(1, (int)ceil((int)$f['size_bytes'] / 1024))) ?> KB</span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($r['decision'] !== 'pending'): ?>
        <div style="margin-top:8px;font-size:12px;padding:8px 10px;border-radius:6px;background:<?= $r['decision'] === 'sent_back' ? 'rgba(245,158,11,.10)' : 'rgba(39,174,96,.08)' ?>">
            <strong><?= $r['decision'] === 'sent_back' ? 'Sent back' : 'Approved' ?></strong>
            by <?= h(fbWho($r['decided_by_name'], $r['decided_by'])) ?> · <?= h(fbFmt($r['decided_at'])) ?>
            <?php if ($r['decision_note']): ?><div style="white-space:pre-wrap;margin-top:4px"><?= h($r['decision_note']) ?></div><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($canResolve): ?>
<form method="POST" enctype="multipart/form-data" class="form-card" style="max-width:900px;margin-bottom:16px">
    <input type="hidden" name="action" value="fb_resolve">
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="form-section-title" style="margin-top:0">Submit Resolution</div>
    <?php if ($lastSentBack): ?>
    <div class="alert alert-error" style="font-size:13px">
        <strong>Sent back by <?= h(fbWho($lastSentBack['decided_by_name'], $lastSentBack['decided_by'])) ?>:</strong>
        <?= nl2br(h((string)$lastSentBack['decision_note'])) ?>
    </div>
    <?php endif; ?>
    <div class="form-group">
        <label>Issue ID</label>
        <input type="text" class="form-control" value="<?= fbRef($id) ?>" readonly style="max-width:160px">
    </div>
    <div class="form-group">
        <label>Remark *<span class="hint"> — what was done for the customer</span></label>
        <textarea name="remark" class="form-control" rows="4" maxlength="<?= FB_MAX_TEXT ?>" required></textarea>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px">
        <div class="form-group">
            <label>Call recording<span class="hint"> — optional; mp3, m4a, aac, wav, ogg, amr</span></label>
            <input type="file" name="recordings[]" class="form-control" multiple
                   accept="audio/*,.<?= implode(',.', array_keys(FB_ALLOWED['recording'])) ?>">
        </div>
        <div class="form-group">
            <label>Receipt<span class="hint"> — optional; photo or PDF</span></label>
            <input type="file" name="receipts[]" class="form-control" multiple
                   accept="image/*,application/pdf,.<?= implode(',.', array_keys(FB_ALLOWED['receipt'])) ?>">
        </div>
    </div>
    <div class="text-muted" style="font-size:11px;margin-bottom:10px">Up to <?= FB_MAX_FILES ?> files, <?= FB_MAX_BYTES / 1024 / 1024 ?> MB each.</div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Submit for Verification</button>
    </div>
</form>
<?php elseif ($fb['status'] === 'open'): ?>
<div class="alert alert-info" style="max-width:900px">Waiting for the store manager or operations team to submit a resolution.</div>
<?php endif; ?>

<?php if ($fb['status'] === 'submitted' && $pending): ?>
    <?php if ($canClose && !$ownPending): ?>
<form method="POST" class="form-card" style="max-width:900px;margin-bottom:16px">
    <input type="hidden" name="action" value="fb_verify">
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="form-section-title" style="margin-top:0">Verification</div>
    <div class="form-group">
        <label>Note<span class="hint"> — optional on approve, required to send back</span></label>
        <textarea name="note" class="form-control" rows="3" maxlength="<?= FB_MAX_TEXT ?>" id="fbVerifyNote"></textarea>
    </div>
    <div class="form-actions" style="display:flex;gap:8px;flex-wrap:wrap">
        <button type="submit" name="decision" value="approve" class="btn btn-success">✓ Approve &amp; Close</button>
        <button type="submit" name="decision" value="send_back" class="btn btn-danger"
                onclick="var n=document.getElementById('fbVerifyNote');if(!n.value.trim()){alert('Write the reason for sending it back.');n.focus();return false;}">↩ Send Back</button>
    </div>
</form>
    <?php elseif ($canClose && $ownPending): ?>
<div class="alert alert-info" style="max-width:900px">You submitted this resolution, so another closer has to verify it.</div>
    <?php else: ?>
<div class="alert alert-info" style="max-width:900px">Waiting for verification by a feedback closer.</div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($fb['status'] === 'open' && $canClose): ?>
<form method="POST" class="form-card" style="max-width:900px;margin-bottom:16px"
      onsubmit="return confirm('Close <?= fbRef($id) ?> without a resolution?');">
    <input type="hidden" name="action" value="fb_close_direct">
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="form-section-title" style="margin-top:0">Close Without Resolution</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">
        <div class="form-group">
            <label>Reason *</label>
            <select name="reason" class="form-control" required>
                <option value="">— Select —</option>
                <?php foreach (FB_CLOSE_REASONS as $k => $label): ?>
                <option value="<?= $k ?>"><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Note<span class="hint"> — e.g. the ID it duplicates</span></label>
            <input type="text" name="note" class="form-control" maxlength="500">
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-danger">Close</button>
    </div>
</form>
<?php endif;
}
