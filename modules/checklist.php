<?php
// =========================================================
// Checklist Module — daily / weekly / monthly checklists + manage tasks
//
// A checklist repeats on one of three cycles (chk_checklists.frequency):
//   daily   → one cycle per calendar day, closing at midnight (or at
//             rollover_min past it, e.g. Store = 02:00)
//   weekly  → Sunday → Saturday, closing Saturday midnight
//   monthly → 1st → last day, closing on the last day at midnight
//
// Time-window rules (non-superadmin):
//   - Only one period is fillable: the "effective checklist date", which
//     is the *anchor* (first day) of the current cycle — the day itself
//     for daily, that week's Sunday for weekly, the 1st for monthly.
//   - On a DAILY checklist, sections further split the day into editable
//     windows, e.g.:
//       1.Morning   → 08:00 → 14:00 same day
//       2.Afternoon → 14:00 → 19:00 same day
//       3.Evening   → 19:00 → 02:00 next calendar day
//     Outside the window the cells render read-only ("Opens at HH:MM"
//     before the start, "Closed at HH:MM" after the deadline).
//   - On a WEEKLY / MONTHLY checklist the sections are grouping headers
//     only: the whole period is fillable until it closes.
//   - Past periods render read-only; future periods are blocked.
//   - Superadmin bypasses all restrictions.
// =========================================================

// ── Checklist + section data access ───────────────────────
// One registry row per checklist (the merged Store checklist + the factory
// department work-lists). Cached per request.
function chkGetChecklist(int $id): ?array {
    static $cache = [];
    if ($id <= 0) return null;
    if (array_key_exists($id, $cache)) return $cache[$id];
    $st = getDb()->prepare("SELECT * FROM chk_checklists WHERE id = ?");
    $st->execute([$id]);
    return $cache[$id] = ($st->fetch(PDO::FETCH_ASSOC) ?: null);
}

function chkActiveChecklists(): array {
    try {
        return getDb()->query(
            "SELECT * FROM chk_checklists WHERE is_active = 1 ORDER BY sort_order, id"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

// Sections of a checklist, ordered, keyed by id.
function chkGetSections(int $checklistId): array {
    $st = getDb()->prepare(
        "SELECT * FROM chk_sections WHERE checklist_id = ? ORDER BY sort_order, id");
    $st->execute([$checklistId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = $r;
    return $out;
}

function chkIsAssignee(int $checklistId, string $code): bool {
    if ($code === '') return false;
    $st = getDb()->prepare("SELECT 1 FROM chk_assignees WHERE checklist_id = ? AND employee_code = ? LIMIT 1");
    $st->execute([$checklistId, $code]);
    return (bool)$st->fetchColumn();
}

function chkIsValidator(int $checklistId, string $code): bool {
    if ($code === '') return false;
    $st = getDb()->prepare("SELECT 1 FROM chk_validators WHERE checklist_id = ? AND employee_code = ? LIMIT 1");
    $st->execute([$checklistId, $code]);
    return (bool)$st->fetchColumn();
}

// Admin over all checklists (manage every checklist + view-all reports).
// Management is split by assign_type: txn_manage_tasks governs location-assigned
// (Store) checklists, txn_manage_dept_tasks the employee-assigned (department)
// ones. Superadmin governs both. Pass null to ask "may manage at least one
// kind?" — the coarse gate for the Manage Checklists page and its routes.
function chkCanManageChecklist(?array $cl): bool {
    if (isSuperadmin()) return true;
    if ($cl === null) return hasTxn('manage_tasks') || hasTxn('manage_dept_tasks');
    return chkScopeIsLocation($cl) ? hasTxn('manage_tasks') : hasTxn('manage_dept_tasks');
}
function chkCanManage(): bool { return chkCanManageChecklist(null); }

// Guard for every manage handler: resolve the target checklist and bail unless
// the caller holds the role for that kind. Returns the checklist row.
function chkRequireManage(int $checklistId): array {
    $cl = chkGetChecklist($checklistId);
    if (!$cl || !chkCanManageChecklist($cl)) {
        flash('error', 'You cannot manage this checklist.');
        header('Location: ' . chkManageBack(0)); exit;
    }
    return $cl;
}

// The checklist an item actually belongs to. Task handlers resolve ownership
// from the item rather than trusting the POSTed checklist_id, so a manager of
// one kind can't reach another kind's task by forging it.
function chkChecklistOfItem(int $itemId): int {
    $st = getDb()->prepare("SELECT checklist_id FROM chk_items WHERE id = ?");
    $st->execute([$itemId]);
    return (int)($st->fetchColumn() ?: 0);
}

// May the current user validate this checklist? Only a designated validator
// (chk_validators) — the txn_checklist_validate role merely reveals the page,
// it does NOT grant validation rights. Superadmin is the global override.
function chkCanValidateChecklist(int $checklistId, string $code): bool {
    return isSuperadmin() || chkIsValidator($checklistId, $code);
}

// Is the user a designated filler / validator of *any* checklist? Used by the
// sidebar to surface the hub / validate pages to designated factory staff who
// don't hold the location-based checklist txns. Cached per request.
function chkUserHasAssignment(string $code): bool {
    static $c = [];
    if ($code === '') return false;
    if (isset($c[$code])) return $c[$code];
    try { $st = getDb()->prepare("SELECT 1 FROM chk_assignees WHERE employee_code = ? LIMIT 1"); $st->execute([$code]); return $c[$code] = (bool)$st->fetchColumn(); }
    catch (Exception $e) { return $c[$code] = false; }
}
function chkUserHasValidation(string $code): bool {
    static $c = [];
    if ($code === '') return false;
    if (isset($c[$code])) return $c[$code];
    try { $st = getDb()->prepare("SELECT 1 FROM chk_validators WHERE employee_code = ? LIMIT 1"); $st->execute([$code]); return $c[$code] = (bool)$st->fetchColumn(); }
    catch (Exception $e) { return $c[$code] = false; }
}

// Scope (location_id) a checklist's responses live under: 0 for the single
// shared factory copy of an employee-assigned checklist; the outlet id for
// a location-assigned one.
function chkScopeIsLocation(array $cl): bool { return ($cl['assign_type'] ?? 'location') === 'location'; }

// May the current user fill this checklist? Employee-mode → designated
// assignee (or admin); location-mode → has the checklist txn or a claimed
// location (or admin).
function chkCanFill(array $cl, string $code): bool {
    if (chkCanManageChecklist($cl)) return true;
    if (!chkScopeIsLocation($cl)) return chkIsAssignee((int)$cl['id'], $code);
    return hasTxn('checklist') || myLocationId() > 0;
}

// ── Frequency / period engine ─────────────────────────────
// Responses, attachments and validations are all keyed by a single date
// (chk_daily_responses.log_date), so a repeating cycle is represented by
// its *anchor* — the first day of the period. Everything downstream keeps
// working unchanged because it still sees one date per cycle.
define('CHK_FREQS', ['daily', 'weekly', 'monthly']);

// Is chk_checklists.frequency there? Weekly / monthly cycles are hidden
// until the column is added, so the module keeps working on a database
// that has not run the migration (every checklist reads as daily).
function chkHasFrequency(): bool {
    static $has = null;
    if ($has !== null) return $has;
    try {
        $r = getDb()->query('SELECT frequency FROM chk_checklists LIMIT 0');
        return $has = ($r !== false);
    } catch (Exception $e) {
        return $has = false;
    }
}

function chkFrequency(array $cl): string {
    $f = (string)($cl['frequency'] ?? 'daily');
    return in_array($f, CHK_FREQS, true) ? $f : 'daily';
}

// Is chk_sections.frequency there? The cycle lives on the section, so one
// checklist can carry Daily, Weekly and Monthly work at once. Without the
// column every section reads as the checklist's own cycle, which is how the
// module behaved before.
function chkHasSectionFreq(): bool {
    static $has = null;
    if ($has !== null) return $has;
    try {
        $r = getDb()->query('SELECT frequency FROM chk_sections LIMIT 0');
        return $has = ($r !== false);
    } catch (Exception $e) {
        return $has = false;
    }
}

// Is chk_daily_responses.worked_on there? It records the calendar day minutes
// were actually entered, as opposed to log_date which is the anchor of the
// cycle the answer belongs to. Without it the timesheet falls back to dating
// entries by the anchor, which is the old (wrong for weekly/monthly) behaviour.
function chkHasWorkedOn(): bool {
    static $has = null;
    if ($has !== null) return $has;
    try {
        $r = getDb()->query('SELECT worked_on FROM chk_daily_responses LIMIT 0');
        return $has = ($r !== false);
    } catch (Exception $e) {
        return $has = false;
    }
}

// The cycle one task runs on: its section's, falling back to the checklist's
// for a task with no section. $section is a chk_sections row or null.
function chkItemFreq(?array $section, array $cl): string {
    if ($section !== null && chkHasSectionFreq()) {
        $f = (string)($section['frequency'] ?? '');
        if (in_array($f, CHK_FREQS, true)) return $f;
    }
    return chkFrequency($cl);
}

// The log_date one task's answer is stored under, for a given calendar day:
// the day itself for daily work, that week's Sunday, or the 1st of the month.
function chkItemLogDate(?array $section, array $cl, string $day): string {
    return chkPeriodStart(chkItemFreq($section, $cl), $day);
}

// Does this checklist ask how long each task took? Location-assigned lists
// (the outlet checklists) do not — a store manager ticks the round off, they
// do not run a timesheet against it.
function chkTracksTime(array $cl): bool {
    return !chkScopeIsLocation($cl);
}

// First day of the cycle a date falls in: the date itself (daily), that
// week's Sunday (weekly — the week closes the following Saturday at
// midnight), or the 1st of the month (monthly).
function chkPeriodStart(string $freq, string $date): string {
    $ts = strtotime($date);
    if ($ts === false) $ts = time();
    switch ($freq) {
        case 'weekly':  return date('Y-m-d', strtotime('-' . (int)date('w', $ts) . ' day', $ts));
        case 'monthly': return date('Y-m-01', $ts);
        default:        return date('Y-m-d', $ts);
    }
}

// Last day of the cycle that starts on $periodStart — Saturday for a week,
// the month's last day for a month. The cycle closes at that day's midnight.
function chkPeriodEnd(string $freq, string $periodStart): string {
    $ts = strtotime($periodStart);
    if ($ts === false) $ts = time();
    switch ($freq) {
        case 'weekly':  return date('Y-m-d', strtotime('+6 day', $ts));
        case 'monthly': return date('Y-m-t', $ts);
        default:        return date('Y-m-d', $ts);
    }
}

// "Daily" / "Weekly" / "Monthly", and the noun the copy uses for one cycle.
function chkFreqLabel(string $freq): string {
    return ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'][$freq] ?? 'Daily';
}
function chkFreqNoun(string $freq): string {
    return ['daily' => 'day', 'weekly' => 'week', 'monthly' => 'month'][$freq] ?? 'day';
}
// "today" / "this week" / "this month" — used on the hub cards.
function chkFreqThis(string $freq): string {
    return ['daily' => 'Today', 'weekly' => 'This week', 'monthly' => 'This month'][$freq] ?? 'Today';
}

// Human label for one cycle: "07 Aug 2026", "02–08 Aug 2026", "August 2026".
function chkPeriodLabel(string $freq, string $periodStart): string {
    $s = strtotime($periodStart);
    if ($s === false) return $periodStart;
    if ($freq === 'monthly') return date('F Y', $s);
    if ($freq === 'weekly') {
        $e = strtotime(chkPeriodEnd('weekly', $periodStart));
        return date('M Y', $s) === date('M Y', $e)
            ? date('d', $s) . '–' . date('d M Y', $e)
            : date('d M', $s) . ' – ' . date('d M Y', $e);
    }
    return date('d M Y', $s);
}

// When the open cycle stops accepting answers, in words.
function chkPeriodCloseLabel(array $cl, string $periodStart): string {
    $freq = chkFrequency($cl);
    if ($freq === 'daily') {
        $roll = (int)($cl['rollover_min'] ?? 0);
        return $roll > 0
            ? ('at ' . date('h:i A', strtotime('00:00') + $roll * 60) . ' tomorrow')
            : 'at midnight';
    }
    return 'at midnight on ' . date('D, d M Y', strtotime(chkPeriodEnd($freq, $periodStart)));
}

// ── Time-window engine (per-checklist, DB-driven) ─────────
// The one calendar day a checklist is fillable on. rollover_min shifts the
// boundary: while now's minute-of-day is below rollover_min we still report
// yesterday (Store rollover_min=120 → before 02:00 counts as the prior day).
//
// This is a *day*, not a cycle anchor: a checklist can hold daily, weekly and
// monthly sections at once, so each task derives its own anchor from this day
// via chkItemLogDate(). Filling the current day is what keeps every cycle's
// open period writable.
function checklistEffectiveDate(array $cl): string {
    $now  = time();
    $minOfDay = (int)date('G', $now) * 60 + (int)date('i', $now);
    if ($minOfDay < (int)($cl['rollover_min'] ?? 0)) {
        return date('Y-m-d', strtotime('-1 day', $now));
    }
    return date('Y-m-d', $now);
}

// Does this section's band cover the whole day, i.e. gate nothing?
function chkSectionAllDay(array $section): bool {
    return (int)($section['start_min'] ?? 0) <= 0 && (int)($section['end_min'] ?? 1440) >= 1440;
}

// Per-section start / deadline (Unix ts) on the given checklist date, from
// the section's minutes-from-midnight window (end_min may exceed 1440 for
// cross-midnight bands, e.g. Store Evening → 02:00 next day).
function checklistSectionStartTs(array $section, string $logDate): int {
    $base = strtotime($logDate);
    return $base === false ? 0 : $base + (int)$section['start_min'] * 60;
}
function checklistSectionDeadlineTs(array $section, string $logDate): int {
    $base = strtotime($logDate);
    return $base === false ? 0 : $base + (int)$section['end_min'] * 60;
}

// "not_yet_open" | "open" | "closed" for a section on a given calendar day.
// $section may be null (an item with no section → open all day). Superadmin
// bypasses.
//
// The start_min/end_min bands are minutes-from-midnight of a single day, so
// they only mean something for a daily section: a weekly or monthly section
// stays open for its whole cycle, which the day gate below already expresses,
// since every day inside the open week or month is the effective day on the
// day it is filled.
function checklistSectionState(?array $section, string $day, array $cl): string {
    if (isSuperadmin()) return 'open';
    if ($day !== checklistEffectiveDate($cl)) return 'closed';
    if ($section === null || empty($cl['time_gated'])) return 'open';
    if (chkItemFreq($section, $cl) !== 'daily') return 'open';
    $now = time();
    if ($now < checklistSectionStartTs($section, $day))    return 'not_yet_open';
    if ($now > checklistSectionDeadlineTs($section, $day)) return 'closed';
    return 'open';
}

// True iff the current user may still write answers for the given section
// on the given calendar day.
function checklistSectionEditable(?array $section, string $day, array $cl): bool {
    return checklistSectionState($section, $day, $cl) === 'open';
}

// ── Attachment storage (per response) ─────────────────────
define('CHECKLIST_UPLOAD_DIR', __DIR__ . '/../uploads/checklist/');
define('DEPT_CHECKLIST_UPLOAD_DIR', __DIR__ . '/../uploads/department_checklist/');
define('CHECKLIST_MAX_FILE_SIZE', 10 * 1024 * 1024);
define('CHECKLIST_ALLOWED_EXT', ['jpg','jpeg','png','gif','webp','pdf','heic','heif','doc','docx','xls','xlsx']);
define('CHECKLIST_ALLOWED_MIME', [
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
    'gif'  => ['image/gif'],
    'webp' => ['image/webp'],
    'heic' => ['image/heic','image/heif','application/octet-stream'],
    'heif' => ['image/heif','image/heic','application/octet-stream'],
    'pdf'  => ['application/pdf'],
    'doc'  => ['application/msword','application/octet-stream'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/octet-stream','application/zip'],
    'xls'  => ['application/vnd.ms-excel','application/octet-stream'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/octet-stream','application/zip'],
]);

// Month bucket ("YYYY-MM") a log date's uploads roll up under.
function checklistAttachmentMonth(string $logDate): string {
    return preg_match('/^(\d{4}-\d{2})/', $logDate, $m) ? $m[1] : date('Y-m');
}

// Build the storage directory for one attachment's scope.
// Layout: uploads/checklist/{YYYY-MM}/{location_id}/ — month bucket first
// (rolls a single month's uploads under one parent for easy archival /
// trimming), location next. The log date itself isn't in the path because
// every attachment already carries its date via chk_daily_responses.
//
// Employee-assigned (department) checklists have no outlet — they scope to
// location_id = 0, which would otherwise pile every department into a bogus
// "0" location folder. They get their own root, and inside the month bucket
// a folder per checklist so the twenty-odd lists don't share one directory:
//   uploads/department_checklist/{YYYY-MM}/{checklist_id}/
// The checklist id rather than its name, matching how the location tree
// keys on location_id: ids survive a rename, names do not.
//
// location_id = 0 means department here by construction: doSaveChecklist()
// forces a real outlet id on every location-mode save.
function checklistAttachmentDir(int $locationId, string $logDate, int $checklistId = 0): string {
    $monthBucket = checklistAttachmentMonth($logDate);
    if ($locationId !== 0) return CHECKLIST_UPLOAD_DIR . $monthBucket . '/' . $locationId . '/';
    return DEPT_CHECKLIST_UPLOAD_DIR . $monthBucket . '/'
         . ($checklistId > 0 ? $checklistId . '/' : '');
}

// Legacy layout used briefly during the first rollout — kept as a
// read-only fallback so any file written under the old path still
// resolves on download / delete. New writes never use this.
function checklistAttachmentDirLegacy(int $locationId, string $logDate): string {
    return CHECKLIST_UPLOAD_DIR . $locationId . '/' . $logDate . '/';
}

// Resolve the on-disk path for a stored file, newest layout first and older
// ones after. Nothing on disk is ever moved, so every layout this module has
// written stays readable. Returns null when the file is under none of them.
function checklistAttachmentPath(int $locationId, string $logDate, string $storedName, int $checklistId = 0): ?string {
    $primary = checklistAttachmentDir($locationId, $logDate, $checklistId) . $storedName;
    if (file_exists($primary)) return $primary;
    // Department files written before the per-checklist folder existed sit
    // directly in the month bucket. Read-only fallback; never written.
    if ($locationId === 0 && $checklistId > 0) {
        $flat = DEPT_CHECKLIST_UPLOAD_DIR . checklistAttachmentMonth($logDate) . '/' . $storedName;
        if (file_exists($flat)) return $flat;
    }
    $legacy = checklistAttachmentDirLegacy($locationId, $logDate) . $storedName;
    if (file_exists($legacy)) return $legacy;
    // Department files written before department_checklist/ existed landed in
    // the location tree under a "0" folder. Read-only fallback; never written.
    if ($locationId === 0) {
        $pre = CHECKLIST_UPLOAD_DIR . checklistAttachmentMonth($logDate) . '/0/' . $storedName;
        if (file_exists($pre)) return $pre;
    }
    return null;
}

// Resolve the chk_daily_responses.id for this uploader's own answer on a
// (checklist, location, item, date) tuple — attachments hang off the row the
// current user just upserted. Returns null if no such row exists yet.
function checklistResponseId(int $checklistId, int $locationId, int $itemId, string $logDate, string $empCode): ?int {
    $st = getDb()->prepare(
        'SELECT id FROM chk_daily_responses
         WHERE checklist_id = ? AND location_id = ? AND item_id = ? AND log_date = ? AND employee_code = ?
         ORDER BY id DESC LIMIT 1');
    $st->execute([$checklistId, $locationId, $itemId, $logDate, $empCode]);
    $id = $st->fetchColumn();
    return $id !== false ? (int)$id : null;
}

// Persist files uploaded for one item under name="attachments[ITEM_ID][]".
// Skips silently on size/extension/mime mismatches so a single bad file
// doesn't derail the whole submit.
function checklistSaveAttachments(int $responseId, int $locationId, string $logDate, int $itemId, string $uploaderCode, int $checklistId = 0): int {
    if (empty($_FILES['attachments']['name'][$itemId]) || !is_array($_FILES['attachments']['name'][$itemId])) {
        return 0;
    }
    $files = [
        'name'     => $_FILES['attachments']['name'][$itemId],
        'tmp_name' => $_FILES['attachments']['tmp_name'][$itemId],
        'error'    => $_FILES['attachments']['error'][$itemId],
        'size'     => $_FILES['attachments']['size'][$itemId],
        'type'     => $_FILES['attachments']['type'][$itemId] ?? [],
    ];
    $dir = checklistAttachmentDir($locationId, $logDate, $checklistId);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $db = getDb();
    $st = $db->prepare(
        'INSERT INTO chk_response_attachments
            (response_id, filename, stored_name, mime_type, file_size, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?)');
    $saved = 0;
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $n = count($files['name']);
    for ($i = 0; $i < $n; $i++) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        if ($files['size'][$i] > CHECKLIST_MAX_FILE_SIZE) continue;
        $origName = basename((string)$files['name'][$i]);
        $ext = mb_strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, CHECKLIST_ALLOWED_EXT, true)) continue;
        $mime = $finfo->file($files['tmp_name'][$i]) ?: 'application/octet-stream';
        $ok = CHECKLIST_ALLOWED_MIME[$ext] ?? [];
        if (!in_array($mime, $ok, true)) continue;
        $storedName = uniqid('chk_', true) . '.' . $ext;
        if (move_uploaded_file($files['tmp_name'][$i], $dir . $storedName)) {
            $st->execute([$responseId, $origName, $storedName, $mime, (int)$files['size'][$i], $uploaderCode]);
            $saved++;
        }
    }
    return $saved;
}

// Fetch all attachments for a given (checklist, location, date) grouped by item_id.
// Returns: [item_id => [['id','filename','mime_type','file_size','uploaded_by','uploader_name','uploaded_at','is_image'], ...]]
function checklistAttachmentsByItem(int $checklistId, int $locationId, string $logDate): array {
    $st = getDb()->prepare(
        'SELECT a.id, a.response_id, a.filename, a.mime_type, a.file_size,
                a.uploaded_by, a.uploaded_at, r.item_id,
                e.full_name AS uploader_name
         FROM chk_response_attachments a
         JOIN chk_daily_responses r ON r.id = a.response_id
         LEFT JOIN employees e ON e.employee_code = a.uploaded_by
         WHERE r.checklist_id = ? AND r.location_id = ? AND r.log_date = ?
         ORDER BY a.uploaded_at ASC, a.id ASC');
    try {
        $st->execute([$checklistId, $locationId, $logDate]);
    } catch (Exception $e) {
        // Table not yet created — fail open with no attachments.
        return [];
    }
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['is_image'] = stripos((string)$r['mime_type'], 'image/') === 0;
        $out[(int)$r['item_id']][] = $r;
    }
    return $out;
}

// How many files a task carries across *every* date, not just the one on
// screen. The fill view shows one day at a time, so a task done on a cycle
// ("Clean AC filter every 10 days") looks file-less on the other nine —
// this is what the per-task 📎 badge counts.
// $locationId = null lifts the scope filter (report viewers looking at all
// outlets). Returns [item_id => ['n' => int, 'last_date' => 'YYYY-MM-DD']].
function checklistItemFileCounts(int $checklistId, ?int $locationId): array {
    $sql = 'SELECT r.item_id, COUNT(*) AS n, MAX(r.log_date) AS last_date
            FROM chk_response_attachments a
            JOIN chk_daily_responses r ON r.id = a.response_id
            WHERE r.checklist_id = ?';
    $args = [$checklistId];
    if ($locationId !== null) { $sql .= ' AND r.location_id = ?'; $args[] = $locationId; }
    $sql .= ' GROUP BY r.item_id';
    try {
        $st = getDb()->prepare($sql);
        $st->execute($args);
    } catch (Exception $e) {
        // Table not yet created — fail open with no counts.
        return [];
    }
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['item_id']] = ['n' => (int)$r['n'], 'last_date' => (string)$r['last_date']];
    }
    return $out;
}

// Every file ever attached to one task, newest day first, carrying the day
// and the answer it was filed against. Same optional scope rule as above.
// $onDate narrows to a single checklist day — what a report cell links to
// when the viewer clicked the marker on one specific date.
function checklistItemFiles(int $checklistId, ?int $locationId, int $itemId, int $limit = 200, ?string $onDate = null): array {
    $sql = 'SELECT a.id, a.filename, a.mime_type, a.file_size, a.uploaded_by, a.uploaded_at,
                   r.log_date, r.location_id, r.response_value, r.employee_code,
                   e.full_name AS uploader_name
            FROM chk_response_attachments a
            JOIN chk_daily_responses r ON r.id = a.response_id
            LEFT JOIN employees e ON e.employee_code = a.uploaded_by
            WHERE r.checklist_id = ? AND r.item_id = ?';
    $args = [$checklistId, $itemId];
    if ($locationId !== null) { $sql .= ' AND r.location_id = ?'; $args[] = $locationId; }
    if ($onDate !== null)     { $sql .= ' AND r.log_date = ?';    $args[] = $onDate; }
    $sql .= ' ORDER BY r.log_date DESC, a.uploaded_at DESC, a.id DESC LIMIT ' . max(1, $limit);
    try {
        $st = getDb()->prepare($sql);
        $st->execute($args);
    } catch (Exception $e) {
        return [];
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['is_image'] = stripos((string)$r['mime_type'], 'image/') === 0;
    return $rows;
}

// ── Attachment markers for the report views ───────────────
// Reports ask a narrower question than the fill view: "did the store
// manager attach anything against THIS task on THIS day?" Both helpers
// count files and images separately, so a report can say "photo" rather
// than the vaguer "file".

// One day, every location: [location_id][item_id] => ['n' => int, 'img' => int].
// Used by the audit report (a day across outlets) and the validate page
// (pass $locationId to narrow it to one).
function checklistAttachmentCountsForDate(int $checklistId, string $logDate, ?int $locationId = null): array {
    $sql = "SELECT r.location_id, r.item_id, COUNT(*) AS n,
                   SUM(CASE WHEN a.mime_type LIKE 'image/%' THEN 1 ELSE 0 END) AS img
            FROM chk_response_attachments a
            JOIN chk_daily_responses r ON r.id = a.response_id
            WHERE r.checklist_id = ? AND r.log_date = ?";
    $args = [$checklistId, $logDate];
    if ($locationId !== null) { $sql .= ' AND r.location_id = ?'; $args[] = $locationId; }
    $sql .= ' GROUP BY r.location_id, r.item_id';
    try {
        $st = getDb()->prepare($sql);
        $st->execute($args);
    } catch (Exception $e) {
        return [];
    }
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['location_id']][(int)$r['item_id']] = ['n' => (int)$r['n'], 'img' => (int)$r['img']];
    }
    return $out;
}

// One month, one location: [item_id][day-of-month] => ['n' => int, 'img' => int].
// Feeds the marker in each cell of the monthly report grid.
function checklistAttachmentCountsByItemDay(int $checklistId, int $locationId, string $from, string $to): array {
    $sql = "SELECT r.item_id, r.log_date, COUNT(*) AS n,
                   SUM(CASE WHEN a.mime_type LIKE 'image/%' THEN 1 ELSE 0 END) AS img
            FROM chk_response_attachments a
            JOIN chk_daily_responses r ON r.id = a.response_id
            WHERE r.checklist_id = ? AND r.location_id = ? AND r.log_date BETWEEN ? AND ?
            GROUP BY r.item_id, r.log_date";
    try {
        $st = getDb()->prepare($sql);
        $st->execute([$checklistId, $locationId, $from, $to]);
    } catch (Exception $e) {
        return [];
    }
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $day = (int)date('j', strtotime((string)$r['log_date']));
        $out[(int)$r['item_id']][$day] = ['n' => (int)$r['n'], 'img' => (int)$r['img']];
    }
    return $out;
}

// The shared badge the reports (and the fill view) put next to a task.
// $n = 0 renders a muted "none" — a report must answer "or not", so the
// badge is always drawn and always clickable.
// $onDate opens the files page filtered to that single day; $locationId is
// passed through for viewers looking at an outlet other than their own.
function chkFileBadge(int $checklistId, int $itemId, int $n, int $img = 0,
                      ?string $onDate = null, ?int $locationId = null, string $backDate = ''): string {
    $url = '?page=checklist_files&amp;id=' . $checklistId . '&amp;item_id=' . $itemId;
    if ($backDate !== '')      $url .= '&amp;date=' . h($backDate);
    if ($onDate !== null)      $url .= '&amp;on=' . h($onDate);
    if ($locationId !== null)  $url .= '&amp;location_id=' . (int)$locationId;
    if ($n <= 0) {
        $title = 'No file attached' . ($onDate !== null ? ' on this day' : '');
        return '<a class="chk-file-badge" href="' . $url . '" title="' . h($title) . '">'
             . chkClipIcon(11) . 'none</a>';
    }
    $title = $img > 0
        ? ($img . ' image(s)' . ($n > $img ? ' + ' . ($n - $img) . ' other file(s)' : '') . ' attached')
        : ($n . ' file(s) attached');
    $icon = $img > 0 ? chkImageIcon(11) : chkClipIcon(11);
    return '<a class="chk-file-badge has-files" href="' . $url . '" title="' . h($title) . '">'
         . $icon . $n . '</a>';
}

// Human file size for the attachment cards.
function chkFmtBytes(int $b): string {
    if ($b < 1024) return $b . ' B';
    if ($b < 1024 * 1024) return round($b / 1024, 1) . ' KB';
    return round($b / 1024 / 1024, 2) . ' MB';
}

// ── Save checklist responses ──────────────────────────────
function doSaveChecklist(): void {
    $checklistId = (int)($_POST['checklist_id'] ?? 0);
    $cl          = chkGetChecklist($checklistId);
    $empCode     = myCode();
    if (!$cl) {
        flash('error', 'Unknown checklist.');
        header("Location: index.php?page=checklist"); exit;
    }
    $isLocMode = chkScopeIsLocation($cl);
    $answers   = $_POST['ans'] ?? [];
    // The form posts one calendar day. Each task then resolves its own
    // log_date from it — the day itself for daily work, that week's Sunday,
    // the 1st of the month — so a single submit can write three cycles.
    $day = (string)($_POST['log_date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) $day = checklistEffectiveDate($cl);

    // Resolve scope (location_id). Employee-mode checklists share one factory
    // copy under location_id = 0; location-mode keeps the per-outlet scope.
    if ($isLocMode) {
        // Everyone fills against their own employees.location_id — a POSTed
        // location_id is never trusted, so no one can write another outlet.
        $myLocId = myLocationId();
        if ($myLocId <= 0) {
            flash('error', 'You can only fill checklist for your claimed location.');
            header("Location: index.php?page=checklist&id={$checklistId}"); exit;
        }
        $locationId = $myLocId;
    } else {
        $locationId = 0;
        if (!chkCanManageChecklist($cl) && !chkIsAssignee($checklistId, $empCode)) {
            flash('error', 'You are not assigned to this checklist.');
            header("Location: index.php?page=checklist"); exit;
        }
    }

    $back = "index.php?page=checklist&id={$checklistId}"
          . ($isLocMode ? "&location_id={$locationId}" : '')
          . "&date={$day}";

    // Only the current day is writable for non-superadmin. Filling today is
    // what keeps every cycle's open period writable, since today falls inside
    // the open week and the open month by definition.
    if (!isSuperadmin() && $day !== checklistEffectiveDate($cl)) {
        flash('error', 'You can only fill the current checklist day ('
            . date('d M Y', strtotime(checklistEffectiveDate($cl))) . ').');
        header("Location: {$back}"); exit;
    }

    // Attach-only submits (no fresh answers, only files) are legitimate
    // once the response row already exists — let those through. So is a
    // time-only submit: the day's tasks may already be filled and the
    // employee is just recording how long each took.
    $hasUploads = !empty($_FILES['attachments']['name']) && is_array($_FILES['attachments']['name']);
    $tracksTime = chkTracksTime($cl) && chkHasTaskTime();
    $times      = $tracksTime ? chkNormalizeTaskTimes($_POST['task_time'] ?? null) : [];  // itemId => minutes
    $remarks    = chkHasRemarks() ? chkNormalizeRemarks($_POST['remark'] ?? null) : [];   // itemId => text
    // Every editable cell posts an ans[] key, blank or not — "did they
    // actually answer something" is a different question from "was ans[] sent".
    $hasRealAnswers = (bool)array_filter($answers, fn($v) => trim((string)$v) !== '');
    if (($isLocMode && $locationId <= 0) || (empty($answers) && !$hasUploads && empty($times) && empty($remarks))) {
        flash('error', 'No data submitted.');
        header("Location: {$back}"); exit;
    }

    $db = getDb();
    $allSections = chkGetSections($checklistId);
    $secByItem = [];   // itemId => section row (or null)
    $ownItems  = [];   // itemIds that belong to this checklist
    $touched   = array_values(array_unique(array_map('intval',
        array_merge(array_keys($answers), array_keys($times), array_keys($remarks)))));
    chkLoadItemMeta($checklistId, $touched, $allSections, $ownItems, $secByItem);

    // Build one write per task before touching the database: which columns this
    // submit actually carries for it, and which log_date its cycle puts it
    // under. A column the submit did not carry is left alone; a column it
    // carried but left blank becomes NULL, which is how each is cleared.
    $onTime  = chkHasWorkedOn() ? date('Y-m-d') : null;
    $plan = [];  // itemId => ['log' => date, 'set' => [col => value|null]]
    $skippedClosed = 0;
    foreach ($touched as $itemId) {
        if ($itemId <= 0 || empty($ownItems[$itemId])) continue;
        $sec = $secByItem[$itemId] ?? null;
        $set = [];
        if ($tracksTime && array_key_exists($itemId, $times)) {
            // On a checklist that tracks time the minutes ARE the answer: any
            // time logged means the task was done, none means it was not. The
            // answer is still written so every done-count and report that
            // reads response_value keeps working untouched.
            $mins = (int)$times[$itemId];
            $set['response_value'] = $mins > 0 ? 'Yes' : null;
            $set['time_minutes']   = $mins > 0 ? $mins : null;
        } else {
            if (array_key_exists($itemId, $answers) && trim((string)$answers[$itemId]) !== '') {
                $set['response_value'] = (string)$answers[$itemId];
            }
            if ($tracksTime && array_key_exists($itemId, $times)) {
                $set['time_minutes'] = $times[$itemId] > 0 ? (int)$times[$itemId] : null;
            }
        }
        if (chkHasRemarks() && array_key_exists($itemId, $remarks)) {
            $set['remarks'] = $remarks[$itemId] !== '' ? $remarks[$itemId] : null;
        }
        if (!$set) continue;
        if (!checklistSectionEditable($sec, $day, $cl)) { $skippedClosed++; continue; }
        $plan[$itemId] = ['log' => chkItemLogDate($sec, $cl, $day), 'set' => $set];
    }

    // Minutes move to whichever day they were entered on, so any day they move
    // *off* needs its timesheet entry recomputed too. Collect those before the
    // writes overwrite them.
    $syncDays = [];
    if ($onTime !== null) {
        $syncDays[$onTime] = true;
        foreach ($plan as $itemId => $p) {
            if (!array_key_exists('time_minutes', $p['set'])) continue;
            try {
                $q = $db->prepare('SELECT worked_on FROM chk_daily_responses
                                   WHERE checklist_id = ? AND location_id = ? AND item_id = ?
                                     AND log_date = ? AND employee_code = ?');
                $q->execute([$checklistId, $locationId, $itemId, $p['log'], $empCode]);
                $prev = $q->fetchColumn();
                if ($prev) $syncDays[(string)$prev] = true;
            } catch (Exception $e) { /* column absent — nothing to resync */ }
        }
    }

    // One prepared statement per column subset, built on first use.
    $stCache = [];
    $writeFor = function (array $cols, bool $clear) use (&$stCache, $db) {
        $key = ($clear ? 'c:' : 'w:') . implode(',', $cols);
        if (!isset($stCache[$key])) {
            $stCache[$key] = $clear
                ? $db->prepare('UPDATE chk_daily_responses SET '
                    . implode(', ', array_map(fn($c) => "{$c} = NULL", $cols))
                    . ' WHERE checklist_id = ? AND location_id = ? AND item_id = ? AND log_date = ? AND employee_code = ?')
                : $db->prepare('INSERT INTO chk_daily_responses ('
                    . implode(', ', array_merge(
                        ['checklist_id', 'location_id', 'item_id', 'employee_code', 'log_date'], $cols))
                    . ', submitted_at) VALUES ('
                    . implode(', ', array_fill(0, 5 + count($cols), '?')) . ', NOW()) ON DUPLICATE KEY UPDATE '
                    . implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})",
                        array_merge(['checklist_id', 'employee_code'], $cols)))
                    . ', submitted_at = NOW()');
        }
        return $stCache[$key];
    };

    $saved = 0; $remarksSaved = 0;
    try {
        $db->beginTransaction();
        foreach ($plan as $itemId => $p) {
            $set = $p['set'];
            // Stamp the day the work was recorded whenever minutes are written,
            // so the timesheet dates it by when it happened rather than by the
            // anchor of the cycle it belongs to.
            if ($onTime !== null && array_key_exists('time_minutes', $set) && ($set['time_minutes'] ?? null) !== null) {
                $set['worked_on'] = $onTime;
            }
            if (array_filter($set, fn($v) => $v !== null)) {
                $writeFor(array_keys($set), false)->execute(array_merge(
                    [$checklistId, $locationId, $itemId, $empCode, $p['log']], array_values($set)));
                if (($set['response_value'] ?? null) !== null) $saved++;
                if (($remarks[$itemId] ?? '') !== '') $remarksSaved++;
            } else {
                // Everything this submit carried for the task was blank. Clear
                // those columns with an UPDATE rather than an INSERT: emptying
                // the boxes on a task never answered must not conjure a row
                // whose only content is NULLs.
                $writeFor(array_keys($set), true)
                    ->execute([$checklistId, $locationId, $itemId, $p['log'], $empCode]);
            }
        }
        $db->commit();
        if ($skippedClosed > 0) {
            flash('success', "Saved {$saved} task(s). {$skippedClosed} task(s) skipped — section is outside its allowed time window.");
        } elseif ($saved > 0) {
            flash('success', 'Checklist submitted successfully.');
        } elseif ($hasRealAnswers) {
            // Had answers in the POST but none applied → outside window.
            flash('error', 'Nothing saved — all submitted tasks are outside their allowed time window.');
        }
        // Attach-only submits (empty $answers) skip the flash here; the
        // file-attachment block below sets its own success/error message.
    } catch (Exception $e) {
        $db->rollBack();
        flash('error', 'Error saving checklist.');
    }

    // ── File attachments ─────────────────────────────────
    // Run *after* the answer commit so checklistResponseId() can find the
    // upserted row. Each item's files come through as attachments[ITEM_ID][]
    // — process only items belonging to this checklist with an existing
    // response row in an editable section.
    if (!empty($_FILES['attachments']['name']) && is_array($_FILES['attachments']['name'])) {
        $attachItemIds = [];
        foreach ($_FILES['attachments']['name'] as $iid => $names) {
            if (is_array($names) && count($names) > 0) $attachItemIds[] = (int)$iid;
        }
        $attachItemIds = array_values(array_filter($attachItemIds, fn($i) => $i > 0));
        chkLoadItemMeta($checklistId, $attachItemIds, $allSections, $ownItems, $secByItem);
        $attSaved = 0;
        foreach ($attachItemIds as $itemId) {
            if (empty($ownItems[$itemId])) continue;
            $sec = $secByItem[$itemId] ?? null;
            if (!checklistSectionEditable($sec, $day, $cl)) continue;
            // The file hangs off the row for this task's own cycle, not the day.
            $itemLog = chkItemLogDate($sec, $cl, $day);
            $respId  = checklistResponseId($checklistId, $locationId, $itemId, $itemLog, $empCode);
            if ($respId === null) continue; // no answer yet → ignore file
            $attSaved += checklistSaveAttachments($respId, $locationId, $itemLog, $itemId, $empCode, $checklistId);
        }
        if ($attSaved > 0) {
            $prev = $_SESSION['flash'] ?? null;
            $prevMsg = ($prev && ($prev['type'] ?? '') === 'success') ? rtrim((string)$prev['msg']) . ' ' : '';
            flash('success', $prevMsg . "{$attSaved} file(s) attached.");
        }
    }

    // Mirror this user's time into page=my_time. One entry per day worked, so
    // minutes land on the day they were entered whatever cycle the task runs
    // on; any day the minutes moved off is recomputed too.
    $logged = 0;
    if ($syncDays) {
        foreach (array_keys($syncDays) as $d) {
            $n = chkSyncTimeEntry($checklistId, $empCode, $d);
            if ($d === $onTime) $logged = $n;
        }
    } else {
        $logged = chkSyncTimeEntry($checklistId, $empCode, $day);
    }
    if (($times || $remarksSaved > 0) && empty($_SESSION['flash'])) {
        // Time- or remark-only submit — say so, otherwise the redirect looks
        // like a no-op.
        flash('success', $logged > 0
            ? ('Time logged: ' . (function_exists('fmtMinutes') ? fmtMinutes($logged) : $logged . 'm') . '.')
            : 'Time entry cleared.');
    }
    // Remarks posted but all of them blank — the submit cleared one. Say
    // something, otherwise the redirect looks like the click did nothing.
    if ($remarks && empty($_SESSION['flash'])) {
        flash('success', 'Checklist updated.');
    }
    // A remark only reaches My Time through the cycle's time entry, and that
    // entry only exists once there are minutes to log. Say so rather than
    // letting the remark quietly go nowhere.
    if ($remarksSaved > 0 && $logged <= 0) {
        $prev = $_SESSION['flash'] ?? null;
        $prevMsg = ($prev && ($prev['type'] ?? '') === 'success') ? rtrim((string)$prev['msg']) . ' ' : '';
        flash('success', $prevMsg . 'Remark saved. Add the minutes you spent on that task for it to show in My Time.');
    }

    header("Location: {$back}"); exit;
}

// ── Map checklist time into the timesheet ─────────────────
// Time is recorded per task, in chk_daily_responses.time_minutes, next to
// the answer it belongs to. The day's rows are then summed into the single
// checklist-linked time_entries row that page=my_time shows. When no task
// carries a typed duration the est_minutes of the completed items stand in,
// which is how this worked before per-task entry existed; the notes column
// says which of the two produced the row.
define('CHK_TIME_NOTE_ENTERED', 'Checklist time (per task)');
define('CHK_TIME_NOTE_AUTO',    'Checklist auto-logged');

// Clock glyph for the per-task time boxes. Same drawing as navIcon('clock'),
// sized down for 11–12px labels — an inline SVG rather than the ⏱ emoji so it
// inherits the surrounding text colour instead of rendering in whatever the
// device's emoji font paints.
function chkClockIcon(int $px = 13): string {
    return '<svg width="' . $px . '" height="' . $px . '" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
         . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"'
         . ' style="vertical-align:-2px;flex:none"><circle cx="12" cy="12" r="10"/>'
         . '<polyline points="12 6 12 12 16 14"/></svg>';
}

// Attachment glyphs, same reasoning as the clock above: inline SVG so they
// take the surrounding text colour instead of whatever the device's emoji
// font paints (📎 / 🖼 / 📄 render as flat grey boxes on most Windows
// browsers). Paperclip = "files", picture = an image attachment, sheet =
// any other document.
function chkSvgIcon(string $body, int $px, string $extraStyle = ''): string {
    return '<svg width="' . $px . '" height="' . $px . '" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
         . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"'
         . ' style="vertical-align:-2px;flex:none' . ($extraStyle ? ';' . $extraStyle : '') . '">'
         . $body . '</svg>';
}
function chkClipIcon(int $px = 12): string {
    return chkSvgIcon('<path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66'
                    . 'l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>', $px);
}
function chkImageIcon(int $px = 12): string {
    return chkSvgIcon('<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>'
                    . '<circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>', $px);
}
function chkDocIcon(int $px = 12): string {
    return chkSvgIcon('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>'
                    . '<polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/>'
                    . '<line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>', $px);
}

// Is chk_daily_responses.time_minutes there? Per-task time entry is hidden
// until the column is added, so the module keeps working on a database
// that has not run the migration.
function chkHasTaskTime(): bool {
    static $has = null;
    if ($has !== null) return $has;
    try {
        $r = getDb()->query('SELECT time_minutes FROM chk_daily_responses LIMIT 0');
        return $has = ($r !== false);
    } catch (Exception $e) {
        return $has = false;
    }
}

// Same gate for chk_daily_responses.remarks — the per-task note the filler
// writes beside the answer. Absent column → no remarks box, no remarks in
// the reports, and My Time notes keep their old fixed labels.
function chkHasRemarks(): bool {
    static $has = null;
    if ($has !== null) return $has;
    try {
        $r = getDb()->query('SELECT remarks FROM chk_daily_responses LIMIT 0');
        return $has = ($r !== false);
    } catch (Exception $e) {
        return $has = false;
    }
}

// The longest a task description may run inside a My Time note before it is
// trimmed. Descriptions go up to 500 characters on the Store checklist, which
// would bury the remark it is meant to label.
define('CHK_REMARK_TASK_MAX', 40);

// The single checklist-linked time entry for one employee+checklist+day,
// or null. Fails open (returns null) when time_entries.checklist_id is not
// there yet, so that column stays optional too.
function chkTimeEntryRow(int $checklistId, string $empCode, string $logDate): ?array {
    if ($checklistId <= 0 || $empCode === '') return null;
    try {
        $st = getDb()->prepare(
            "SELECT id, minutes, notes FROM time_entries
             WHERE employee_code = ? AND checklist_id = ? AND entry_date = ?
             ORDER BY id DESC LIMIT 1");
        $st->execute([$empCode, $checklistId, $logDate]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        return null;
    }
}

// Normalise the posted task_time[ITEM_ID] map to itemId => minutes. Blank
// boxes are dropped; a 0 is kept (it clears that task's time); anything
// else is clamped to a sane 0–8h per task.
function chkNormalizeTaskTimes($raw): array {
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $itemId => $val) {
        $itemId = (int)$itemId;
        if ($itemId <= 0) continue;
        $val = trim((string)$val);
        // The empty slot is a real choice, not a missing one: on a checklist
        // where the duration is the answer, "--" is how a task is marked not
        // done, so it has to reach the save as an explicit 0 rather than being
        // dropped the way a malformed value is.
        if ($val === '')      { $out[$itemId] = 0; continue; }
        if (!is_numeric($val)) continue;
        $out[$itemId] = max(0, min(8 * 60, (int)$val));
    }
    return $out;
}

// Same for the posted remark[ITEM_ID] map. An empty string is kept rather
// than dropped — that is how a remark gets cleared, exactly as a 0 clears a
// task's minutes above. Capped to the column's 500 characters.
function chkNormalizeRemarks($raw): array {
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $itemId => $val) {
        $itemId = (int)$itemId;
        if ($itemId <= 0 || !is_scalar($val)) continue;
        $out[$itemId] = mb_substr(trim((string)$val), 0, 500);
    }
    return $out;
}

// Resolve section + ownership metadata for item ids not looked up yet.
// $ownItems / $secByItem are filled in place, so a caller can hand it a
// second batch (files, times) without re-reading what it already has.
function chkLoadItemMeta(int $checklistId, array $itemIds, array $allSections, array &$ownItems, array &$secByItem): void {
    $missing = array_values(array_unique(array_filter(
        array_map('intval', $itemIds), fn($i) => $i > 0 && !isset($ownItems[$i]))));
    if (!$missing) return;
    $ph  = implode(',', array_fill(0, count($missing), '?'));
    $sst = getDb()->prepare("SELECT id, section_id FROM chk_items WHERE checklist_id = ? AND id IN ($ph)");
    $sst->execute(array_merge([$checklistId], $missing));
    foreach ($sst->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $iid = (int)$r['id'];
        $ownItems[$iid]  = true;
        $secByItem[$iid] = $r['section_id'] ? ($allSections[(int)$r['section_id']] ?? null) : null;
    }
}

// The column that says which day a response's minutes belong to. worked_on is
// the day they were actually entered; log_date is the anchor of the cycle the
// answer sits under, which is only the same thing for daily work. Falls back
// to log_date on a database that has not run the migration.
function chkWorkDayCol(): string { return chkHasWorkedOn() ? 'worked_on' : 'log_date'; }

// Minutes this employee logged on one checklist on one day worked.
function chkTaskTimeTotal(int $checklistId, string $empCode, string $day): int {
    if (!chkHasTaskTime()) return 0;
    $col = chkWorkDayCol();
    try {
        $st = getDb()->prepare(
            "SELECT COALESCE(SUM(time_minutes),0) FROM chk_daily_responses
             WHERE checklist_id = ? AND employee_code = ? AND {$col} = ?");
        $st->execute([$checklistId, $empCode, $day]);
        return (int)$st->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

// The cycle's remarks, as the notes line for its My Time entry: each remark
// labelled with the task it belongs to, joined by the same " · " My Time
// already uses when it renders notes inline. Task descriptions run to 500
// characters on the Store checklist, so each is trimmed — a note is meant to
// be read at a glance in a timesheet row. Returns '' when nothing was noted,
// which leaves the caller's fixed label in place.
function chkRemarksNote(int $checklistId, string $empCode, string $day): string {
    if (!chkHasRemarks() || $empCode === '') return '';
    $col = chkWorkDayCol();
    try {
        $st = getDb()->prepare(
            "SELECT i.task_description, r.remarks
             FROM chk_daily_responses r
             JOIN chk_items i ON i.id = r.item_id
             WHERE r.checklist_id = ? AND r.employee_code = ? AND r.{$col} = ?
               AND r.remarks IS NOT NULL AND r.remarks <> ''
             ORDER BY i.id");
        $st->execute([$checklistId, $empCode, $day]);
    } catch (Exception $e) {
        return '';
    }
    $parts = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $task = trim(preg_replace('/\s+/', ' ', (string)$r['task_description']));
        if (mb_strlen($task) > CHK_REMARK_TASK_MAX) {
            $task = rtrim(mb_substr($task, 0, CHK_REMARK_TASK_MAX - 1)) . '…';
        }
        $note = trim(preg_replace('/\s+/', ' ', (string)$r['remarks']));
        if ($note === '') continue;
        $parts[] = $task === '' ? $note : ($task . ': ' . $note);
    }
    return implode(' · ', $parts);
}

// Recomputes the one time_entries row for an employee's day on a checklist
// (delete-then-insert, keyed by employee+checklist+day) and returns the minutes
// logged. Fails open — returns 0 — so a missing time_entries.checklist_id
// column never blocks a checklist save.
//
// The day here is the day the work was recorded, not the anchor of the cycle
// the tasks belong to: a monthly task filled on the 11th logs its time on the
// 11th, where the person actually spent it, instead of on the 1st. Since each
// response carries exactly one such day, summing per day cannot double count.
function chkSyncTimeEntry(int $checklistId, string $empCode, string $day): int {
    if ($empCode === '') return 0;
    $db  = getDb();
    $col = chkWorkDayCol();
    try {
        $cl = chkGetChecklist($checklistId);
        // A checklist that does not ask how long a task took does not log time.
        if ($cl && !chkTracksTime($cl)) {
            $db->prepare("DELETE FROM time_entries WHERE employee_code = ? AND checklist_id = ? AND entry_date = ?")
               ->execute([$empCode, $checklistId, $day]);
            return 0;
        }
        $mins  = chkTaskTimeTotal($checklistId, $empCode, $day);
        $notes = CHK_TIME_NOTE_ENTERED;
        if ($mins <= 0) {
            // Nothing typed for any task — fall back to the estimates.
            $sumSt = $db->prepare(
                "SELECT COALESCE(SUM(i.est_minutes),0)
                 FROM chk_daily_responses r
                 JOIN chk_items i ON i.id = r.item_id
                 WHERE r.checklist_id = ? AND r.employee_code = ? AND r.{$col} = ?
                   AND r.response_value IS NOT NULL AND r.response_value <> '' AND i.est_minutes > 0");
            $sumSt->execute([$checklistId, $empCode, $day]);
            $mins  = (int)$sumSt->fetchColumn();
            $notes = CHK_TIME_NOTE_AUTO;
        }

        // What the person actually wrote beats the fixed label. Only when no
        // task carries a remark does the entry fall back to saying where the
        // minutes came from.
        $remarkNote = chkRemarksNote($checklistId, $empCode, $day);
        if ($remarkNote !== '') $notes = $remarkNote;

        $db->prepare("DELETE FROM time_entries WHERE employee_code = ? AND checklist_id = ? AND entry_date = ?")
           ->execute([$empCode, $checklistId, $day]);
        if ($mins > 0) {
            $db->prepare(
                "INSERT INTO time_entries (employee_code, checklist_id, entry_date, minutes, notes)
                 VALUES (?, ?, ?, ?, ?)")
               ->execute([$empCode, $checklistId, $day, $mins, $notes]);
        }
        return $mins;
    } catch (Exception $e) {
        // time_entries.checklist_id not present yet — ignore.
        return 0;
    }
}

// ── View permission for a single attachment ──────────────
// Returns the joined attachment+response row if the current user is
// allowed to view it, or null otherwise. Mirrors the page visibility
// rule: superadmin / checklist-report txn see everything; everyone else
// only sees their own location.
function checklistAttachmentForView(int $attId): ?array {
    if ($attId < 1) return null;
    $st = getDb()->prepare(
        'SELECT a.id, a.filename, a.stored_name, a.mime_type, a.file_size,
                a.uploaded_by, a.uploaded_at,
                r.checklist_id, r.location_id, r.log_date, r.item_id
         FROM chk_response_attachments a
         JOIN chk_daily_responses r ON r.id = a.response_id
         WHERE a.id = ?');
    try { $st->execute([$attId]); } catch (Exception $e) { return null; }
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    if (isSuperadmin() || hasTxn('checklist_report')) return $row;
    $cid = (int)$row['checklist_id'];
    $cl  = chkGetChecklist($cid);
    // A manager sees attachments only for the kind of checklist they govern.
    if ($cl && chkCanManageChecklist($cl)) return $row;
    // Location-assigned checklist → own outlet; employee-assigned → assignee
    // or validator of that checklist.
    if ($cl && chkScopeIsLocation($cl)) {
        $myLoc = myLocationId();
        if ($myLoc > 0 && (int)$row['location_id'] === $myLoc) return $row;
    }
    $me = myCode();
    if ($cid > 0 && ($me === (string)$row['uploaded_by'] || chkIsAssignee($cid, $me) || chkIsValidator($cid, $me))) {
        return $row;
    }
    return null;
}

// ── Download endpoint ────────────────────────────────────
function downloadChecklistAttachment(): void {
    $attId = (int)($_GET['att_id'] ?? 0);
    $row   = checklistAttachmentForView($attId);
    if (!$row) { http_response_code(403); echo 'Access denied'; return; }
    $path  = checklistAttachmentPath((int)$row['location_id'], (string)$row['log_date'], (string)$row['stored_name'], (int)$row['checklist_id']);
    if (!$path) { http_response_code(404); echo 'File missing'; return; }
    header('Content-Type: ' . $row['mime_type']);
    header('Content-Disposition: inline; filename="' . str_replace('"', '', $row['filename']) . '"');
    header('Content-Length: ' . (int)$row['file_size']);
    readfile($path);
    exit;
}

// ── Delete attachment (uploader-only while section is open) ─
function doDeleteChecklistAttachment(): void {
    $attId = (int)($_POST['att_id'] ?? 0);
    $row   = checklistAttachmentForView($attId);

    $checklistId = (int)($row['checklist_id'] ?? ($_POST['checklist_id'] ?? 0));
    $cl          = chkGetChecklist($checklistId);
    $locationId  = (int)($row['location_id'] ?? ($_POST['location_id'] ?? 0));
    $logDate     = (string)($row['log_date'] ?? ($_POST['log_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $logDate)) $logDate = $cl ? checklistEffectiveDate($cl) : date('Y-m-d');
    $back = "Location: index.php?page=checklist&id={$checklistId}"
          . (($cl && chkScopeIsLocation($cl)) ? "&location_id={$locationId}" : '')
          . "&date={$logDate}";

    if (!$row) { flash('error', 'Attachment not found or access denied.'); header($back); exit; }

    // Only the original uploader (or superadmin) can delete, and only
    // while their section is still editable for the original day.
    $isOwner = ((string)$row['uploaded_by'] === myCode());
    if (!isSuperadmin() && !$isOwner) {
        flash('error', 'You can only delete files you uploaded.');
        header($back); exit;
    }
    if (!isSuperadmin() && $cl) {
        $sst = getDb()->prepare('SELECT section_id FROM chk_items WHERE id = ?');
        $sst->execute([(int)$row['item_id']]);
        $secId = (int)($sst->fetchColumn() ?: 0);
        $secRow = $secId ? (chkGetSections($checklistId)[$secId] ?? null) : null;
        if (!checklistSectionEditable($secRow, (string)$row['log_date'], $cl)) {
            flash('error', 'Section is closed — file can no longer be removed.');
            header($back); exit;
        }
    }

    $path = checklistAttachmentPath((int)$row['location_id'], (string)$row['log_date'], (string)$row['stored_name'], (int)$row['checklist_id']);
    try {
        getDb()->prepare('DELETE FROM chk_response_attachments WHERE id = ?')->execute([$attId]);
        if ($path) @unlink($path);
        flash('success', 'Attachment deleted.');
    } catch (Exception $e) {
        flash('error', 'Could not delete attachment.');
    }
    header($back); exit;
}

// Redirect target for Manage Tasks, keeping the selected checklist.
function chkManageBack(int $checklistId): string {
    return 'index.php?page=manage_tasks' . ($checklistId > 0 ? '&id=' . $checklistId : '');
}
// Parse "HH:MM" → minutes from midnight, or null.
function chkHhmmToMin(string $s): ?int {
    if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($s), $m)) {
        $h = (int)$m[1]; $mm = (int)$m[2];
        if ($h <= 24 && $mm < 60) return $h * 60 + $mm;
    }
    return null;
}

// ── Manage: save the checklist registry row ───────────────
function doSaveChecklistMeta(): void {
    $id       = (int)($_POST['checklist_id'] ?? 0);
    $name     = trim($_POST['name'] ?? '');
    $assign   = ($_POST['assign_type'] ?? 'location') === 'employee' ? 'employee' : 'location';
    $gated    = isset($_POST['time_gated']) ? 1 : 0;
    $rollover = max(0, (int)($_POST['rollover_min'] ?? 0));
    $sort     = (int)($_POST['sort_order'] ?? 0);
    $freq     = (string)($_POST['frequency'] ?? 'daily');
    if (!in_array($freq, CHK_FREQS, true)) $freq = 'daily';
    $hasFreq  = chkHasFrequency();
    if ($name === '') {
        flash('error', 'Checklist name required.');
        header('Location: ' . chkManageBack($id)); exit;
    }
    // Creating a checklist, and changing assign_type on an existing one, are
    // superadmin-only: assign_type decides which manage role owns the
    // checklist, so letting a manager set it would let them grant themselves
    // control of the other kind.
    if ($id <= 0 && !isSuperadmin()) {
        flash('error', 'Only a superadmin can create a checklist.');
        header('Location: ' . chkManageBack(0)); exit;
    }
    $db = getDb();
    // frequency is written only when the column exists, so the module still
    // saves on a database that has not run the migration.
    $freqCol = $hasFreq ? ', frequency=?' : '';
    $freqArg = $hasFreq ? [$freq] : [];
    if ($id > 0) {
        $cl = chkRequireManage($id);
        if (!isSuperadmin()) $assign = (string)$cl['assign_type'];
        $db->prepare("UPDATE chk_checklists SET name=?, assign_type=?, time_gated=?, rollover_min=?, sort_order=?{$freqCol} WHERE id=?")
           ->execute(array_merge([$name, $assign, $gated, $rollover, $sort], $freqArg, [$id]));
    } else {
        $db->prepare('INSERT INTO chk_checklists (name, assign_type, time_gated, rollover_min, sort_order, is_active'
                   . ($hasFreq ? ', frequency' : '') . ') VALUES (?,?,?,?,?,1' . ($hasFreq ? ',?' : '') . ')')
           ->execute(array_merge([$name, $assign, $gated, $rollover, $sort], $freqArg));
        $id = (int)$db->lastInsertId();
    }
    flash('success', 'Checklist saved.');
    header('Location: ' . chkManageBack($id)); exit;
}

// ── Manage: toggle a checklist active ─────────────────────
function doToggleChecklist(): void {
    $id = (int)($_POST['checklist_id'] ?? 0);
    chkRequireManage($id);
    getDb()->prepare("UPDATE chk_checklists SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
    flash('success', 'Checklist status toggled.');
    header('Location: ' . chkManageBack($id)); exit;
}

// ── Manage: save a section (time band) ────────────────────
function doSaveSection(): void {
    $checklistId = (int)($_POST['checklist_id'] ?? 0);
    $sectionId   = (int)($_POST['section_id'] ?? 0);
    $name        = trim($_POST['name'] ?? '');
    $startMin    = chkHhmmToMin($_POST['start_time'] ?? '') ?? 0;
    $endMin      = chkHhmmToMin($_POST['end_time'] ?? '') ?? 1440;
    if (!empty($_POST['end_next_day'])) $endMin += 1440;
    $sort        = (int)($_POST['sort_order'] ?? 0);
    $secFreq     = (string)($_POST['frequency'] ?? 'daily');
    if (!in_array($secFreq, CHK_FREQS, true)) $secFreq = 'daily';
    $hasSecFreq  = chkHasSectionFreq();
    $back        = chkManageBack($checklistId);
    if ($checklistId <= 0 || $name === '') {
        flash('error', 'Section name required.');
        header("Location: {$back}"); exit;
    }
    chkRequireManage($checklistId);
    $db = getDb();
    $fCol = $hasSecFreq ? ', frequency=?' : '';
    $fArg = $hasSecFreq ? [$secFreq] : [];
    if ($sectionId > 0) {
        $db->prepare("UPDATE chk_sections SET name=?, start_min=?, end_min=?, sort_order=?{$fCol} WHERE id=? AND checklist_id=?")
           ->execute(array_merge([$name, $startMin, $endMin, $sort], $fArg, [$sectionId, $checklistId]));
        // Keep the denormalized copy on chk_items in sync so reports that group
        // by section_name (audit, overview filters, exports) stay aligned with
        // the section's new name instead of drifting to the old label.
        $db->prepare("UPDATE chk_items SET section_name=? WHERE section_id=? AND checklist_id=?")
           ->execute([$name, $sectionId, $checklistId]);
    } else {
        $db->prepare('INSERT INTO chk_sections (checklist_id, name, start_min, end_min, sort_order'
                   . ($hasSecFreq ? ', frequency' : '') . ') VALUES (?,?,?,?,?' . ($hasSecFreq ? ',?' : '') . ')')
           ->execute(array_merge([$checklistId, $name, $startMin, $endMin, $sort], $fArg));
    }
    flash('success', 'Section saved.');
    header("Location: {$back}"); exit;
}

// ── Manage: delete a section (orphans its items to "no section") ─
function doDelSection(): void {
    $checklistId = (int)($_POST['checklist_id'] ?? 0);
    $sectionId   = (int)($_POST['section_id'] ?? 0);
    chkRequireManage($checklistId);
    $db = getDb();
    $db->prepare("UPDATE chk_items SET section_id = NULL WHERE section_id = ? AND checklist_id = ?")
       ->execute([$sectionId, $checklistId]);
    $db->prepare("DELETE FROM chk_sections WHERE id = ? AND checklist_id = ?")->execute([$sectionId, $checklistId]);
    flash('success', 'Section deleted.');
    header('Location: ' . chkManageBack($checklistId)); exit;
}

// ── Manage: add / remove an assignee or validator ─────────
function doSaveChkPerson(string $table, string $label): void {
    $checklistId = (int)($_POST['checklist_id'] ?? 0);
    $code        = trim($_POST['employee_code'] ?? '');
    $back        = chkManageBack($checklistId);
    if ($checklistId <= 0 || $code === '') {
        flash('error', "Pick an employee to add as {$label}.");
        header("Location: {$back}"); exit;
    }
    chkRequireManage($checklistId);
    try {
        getDb()->prepare("INSERT IGNORE INTO {$table} (checklist_id, employee_code) VALUES (?,?)")
               ->execute([$checklistId, $code]);
        flash('success', ucfirst($label) . ' added.');
    } catch (Exception $e) {
        flash('error', "Could not add {$label}.");
    }
    header("Location: {$back}"); exit;
}
function doDelChkPerson(string $table, string $label): void {
    $checklistId = (int)($_POST['checklist_id'] ?? 0);
    $rowId       = (int)($_POST['row_id'] ?? 0);
    chkRequireManage($checklistId);
    getDb()->prepare("DELETE FROM {$table} WHERE id = ? AND checklist_id = ?")->execute([$rowId, $checklistId]);
    flash('success', ucfirst($label) . ' removed.');
    header('Location: ' . chkManageBack($checklistId)); exit;
}
function doSaveAssignee():  void { doSaveChkPerson('chk_assignees',  'assignee');  }
function doDelAssignee():   void { doDelChkPerson('chk_assignees',   'assignee');  }
function doSaveValidator(): void { doSaveChkPerson('chk_validators', 'validator'); }
function doDelValidator():  void { doDelChkPerson('chk_validators',  'validator'); }

// ── Manage tasks: save ────────────────────────────────────
function doSaveTask(): void {
    $id          = (int)($_POST['task_id'] ?? 0);
    $checklistId = (int)($_POST['checklist_id'] ?? 0);
    $sectionId   = (int)($_POST['section_id'] ?? 0);
    $desc        = trim($_POST['description'] ?? '');
    $type        = $_POST['input_type'] ?? 'yes_no';
    $est         = max(0, (int)($_POST['est_minutes'] ?? 0));
    if (!in_array($type, ['yes_no', 'time', 'text', 'number'], true)) $type = 'yes_no';
    $back = chkManageBack($checklistId);

    if (!$desc || $checklistId <= 0) {
        flash('error', 'Description and checklist required.');
        header("Location: {$back}"); exit;
    }
    chkRequireManage($checklistId);
    // Editing an existing task: its CURRENT checklist must be manageable too,
    // otherwise a manager could move another kind's task into their own.
    if ($id > 0) chkRequireManage(chkChecklistOfItem($id));

    $db = getDb();
    // Resolve the section name (kept on chk_items for legacy display) and
    // confirm the section belongs to this checklist.
    $secName = null;
    if ($sectionId > 0) {
        $sx = $db->prepare("SELECT name FROM chk_sections WHERE id = ? AND checklist_id = ?");
        $sx->execute([$sectionId, $checklistId]);
        $secName = $sx->fetchColumn();
        if ($secName === false) { $sectionId = 0; $secName = null; }
    }
    if ($id > 0) {
        $st = $db->prepare("UPDATE chk_items SET checklist_id=?, section_id=?, section_name=?, task_description=?, input_type=?, est_minutes=? WHERE id=?");
        $st->execute([$checklistId, ($sectionId ?: null), $secName, $desc, $type, $est, $id]);
    } else {
        $st = $db->prepare("INSERT INTO chk_items (checklist_id, section_id, section_name, task_description, input_type, est_minutes, is_active) VALUES (?,?,?,?,?,?,1)");
        $st->execute([$checklistId, ($sectionId ?: null), $secName, $desc, $type, $est]);
    }
    flash('success', $id ? 'Task updated.' : 'Task added.');
    header("Location: {$back}"); exit;
}

// ── Manage tasks: toggle active ───────────────────────────
function doToggleTask(): void {
    $id = (int)($_POST['task_id'] ?? 0);
    $checklistId = (int)($_POST['checklist_id'] ?? 0);
    chkRequireManage(chkChecklistOfItem($id));
    getDb()->prepare("UPDATE chk_items SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
    flash('success', 'Task status toggled.');
    header('Location: ' . chkManageBack($checklistId)); exit;
}

// ── Manage tasks: delete ──────────────────────────────────
function doDelTask(): void {
    $id = (int)($_POST['task_id'] ?? 0);
    $checklistId = (int)($_POST['checklist_id'] ?? 0);
    chkRequireManage(chkChecklistOfItem($id));
    $db = getDb();
    $chk = $db->prepare("SELECT COUNT(*) FROM chk_daily_responses WHERE item_id = ?");
    $chk->execute([$id]);
    if ($chk->fetchColumn() > 0) {
        flash('error', 'Cannot delete: task has historical data. Deactivate instead.');
    } else {
        $db->prepare("DELETE FROM chk_items WHERE id = ?")->execute([$id]);
        flash('success', 'Task deleted.');
    }
    header('Location: ' . chkManageBack($checklistId)); exit;
}

// ── Checklist landing: hub (no id) or fill view (with id) ─
function pageChecklist(): void {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) { pageChecklistFill($id); return; }
    pageChecklistHub();
}

// Count of active items in a checklist (its "out of N").
function chkItemTotal(int $checklistId): int {
    $st = getDb()->prepare("SELECT COUNT(*) FROM chk_items WHERE checklist_id = ? AND is_active = 1");
    $st->execute([$checklistId]);
    return (int)$st->fetchColumn();
}

// SQL fragment resolving a task's cycle from its section, falling back to the
// checklist's own. Used wherever a count has to stay inside one cycle.
function chkItemFreqSql(string $itemAlias = 'i', string $secAlias = 'sec', string $clAlias = 'c'): string {
    return chkHasSectionFreq()
        ? "COALESCE({$secAlias}.frequency, {$clAlias}.frequency, 'daily')"
        : "COALESCE({$clAlias}.frequency, 'daily')";
}

// Active items per cycle: ['daily' => n, 'weekly' => n, 'monthly' => n],
// omitting cycles the checklist has no tasks for.
function chkItemTotalByFreq(int $checklistId): array {
    if (!chkHasFrequency()) return ['daily' => chkItemTotal($checklistId)];
    $f = chkItemFreqSql();
    try {
        $st = getDb()->prepare(
            "SELECT {$f} AS freq, COUNT(*) AS n
             FROM chk_items i
             JOIN chk_checklists c ON c.id = i.checklist_id
             LEFT JOIN chk_sections sec ON sec.id = i.section_id
             WHERE i.checklist_id = ? AND i.is_active = 1
             GROUP BY freq");
        $st->execute([$checklistId]);
    } catch (Exception $e) {
        return ['daily' => chkItemTotal($checklistId)];
    }
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[(string)$r['freq']] = (int)$r['n'];
    // Always shortest cycle first, whatever order the group by came back in.
    $out = [];
    foreach (CHK_FREQS as $f) if (isset($rows[$f])) $out[$f] = $rows[$f];
    return $out ?: ['daily' => 0];
}

// Distinct answered items for one (checklist, scope, cycle) on the cycle's
// anchor. The frequency filter is not decoration: on the 1st of a month the
// daily and monthly anchors are the same date, and on a Sunday so are the
// daily and weekly ones, so counting by log_date alone would mix cycles.
function chkDoneCount(int $checklistId, int $locationId, string $logDate, ?string $freq = null): int {
    if ($freq === null || !chkHasFrequency()) {
        $st = getDb()->prepare(
            "SELECT COUNT(DISTINCT item_id) FROM chk_daily_responses
             WHERE checklist_id = ? AND location_id = ? AND log_date = ?
               AND response_value IS NOT NULL AND response_value <> ''");
        $st->execute([$checklistId, $locationId, $logDate]);
        return (int)$st->fetchColumn();
    }
    $f = chkItemFreqSql();
    try {
        $st = getDb()->prepare(
            "SELECT COUNT(DISTINCT r.item_id)
             FROM chk_daily_responses r
             JOIN chk_items i ON i.id = r.item_id
             JOIN chk_checklists c ON c.id = i.checklist_id
             LEFT JOIN chk_sections sec ON sec.id = i.section_id
             WHERE r.checklist_id = ? AND r.location_id = ? AND r.log_date = ?
               AND r.response_value IS NOT NULL AND r.response_value <> ''
               AND {$f} = ?");
        $st->execute([$checklistId, $locationId, $logDate, $freq]);
        return (int)$st->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

// ── Hub: list every checklist assigned to the user ────────
// One card per checklist, carrying a line for each cycle it actually holds.
// A checklist can mix Daily, Weekly and Monthly sections, so grouping the
// cards by cycle would put the same checklist in three places; the cycles
// belong on the card instead.
function pageChecklistHub(): void {
    $me    = myCode();
    $cards = [];
    foreach (chkActiveChecklists() as $cl) {
        if (!chkCanFill($cl, $me)) continue;
        $cid   = (int)$cl['id'];
        $isLoc = chkScopeIsLocation($cl);
        // Progress is scoped to the user's own location; null = none claimed,
        // which the card reports instead of a misleading 0/N.
        $scopeLoc = $isLoc ? myLocationId() : 0;
        $day      = checklistEffectiveDate($cl);
        $noScope  = ($isLoc && $scopeLoc <= 0);
        $lines    = [];
        $doneAll  = 0; $totalAll = 0;
        foreach (chkItemTotalByFreq($cid) as $freq => $total) {
            if ($total <= 0) continue;
            $anchor = chkPeriodStart($freq, $day);
            $done   = $noScope ? null : chkDoneCount($cid, $scopeLoc, $anchor, $freq);
            $lines[] = [
                'freq'   => $freq,
                'label'  => chkFreqLabel($freq),
                'total'  => $total,
                'done'   => $done,
                'period' => $freq === 'daily' ? 'today' : chkPeriodLabel($freq, $anchor),
            ];
            $totalAll += $total;
            if ($done !== null) $doneAll += $done;
        }
        if (!$lines) continue;
        $cards[] = [
            'cl' => $cl, 'isLoc' => $isLoc, 'lines' => $lines, 'noScope' => $noScope,
            'pct' => ($totalAll > 0 && !$noScope) ? (int)round($doneAll * 100 / $totalAll) : 0,
            'link' => "?page=checklist&id={$cid}",
        ];
    }
?>
<div class="page-header"><h2>✅ Checklists</h2></div>
<?php if (!$cards): ?>
<div class="alert alert-error">No checklists are assigned to you yet.
    <?php if (!isSuperadmin() && myLocationId() <= 0): ?>
    If you fill a store checklist, claim your location under <a href="?page=my_location" style="color:var(--accent)">My Location</a> first.
    <?php endif; ?>
</div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px">
    <?php foreach ($cards as $c): $cl = $c['cl'];
        $bg = $c['noScope'] ? 'var(--border)'
            : ($c['pct'] >= 100 ? 'var(--green)' : ($c['pct'] > 0 ? 'var(--yellow)' : 'var(--red)'));
    ?>
    <a href="<?= h($c['link']) ?>" class="table-wrap" style="display:block;padding:16px;text-decoration:none;color:var(--text)">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px">
            <strong style="font-size:15px"><?= h($cl['name']) ?></strong>
            <span class="badge <?= $c['isLoc'] ? 'badge-blue' : 'badge-grey' ?>" style="font-weight:600"><?= $c['isLoc'] ? 'By location' : 'Department' ?></span>
        </div>
        <div style="height:8px;border-radius:999px;background:var(--bg);overflow:hidden;margin-bottom:8px">
            <span style="display:block;height:100%;width:<?= (int)$c['pct'] ?>%;background:<?= $bg ?>"></span>
        </div>
        <?php if ($c['noScope']): ?>
        <div class="text-muted" style="font-size:12px">No location claimed</div>
        <?php else: ?>
        <?php
        // A column gap rather than line-height: the cycle badges carry their own
        // padding, so leading alone leaves their boxes all but touching.
        ?>
        <div style="display:flex;flex-direction:column;gap:7px">
            <?php foreach ($c['lines'] as $ln): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;font-size:12px">
                <span style="display:inline-flex;align-items:center;gap:7px;min-width:0">
                    <span class="badge <?= $ln['freq'] === 'daily' ? 'badge-grey' : 'badge-blue' ?>" style="font-weight:600"><?= h($ln['label']) ?></span>
                    <span class="text-muted" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($ln['period']) ?></span>
                </span>
                <strong style="white-space:nowrap<?= $ln['done'] >= $ln['total'] ? ';color:var(--green)' : '' ?>"><?= (int)$ln['done'] ?>/<?= (int)$ln['total'] ?></strong>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>
<?php endif;
}

// ── Fill view for one checklist ───────────────────────────
function pageChecklistFill(int $checklistId): void {
    $cl = chkGetChecklist($checklistId);
    $me = myCode();
    if (!$cl || (int)($cl['is_active'] ?? 0) !== 1) {
        flash('error', 'Checklist not found.');
        header('Location: index.php?page=checklist'); exit;
    }
    if (!chkCanFill($cl, $me)) {
        flash('error', 'You are not assigned to this checklist.');
        header('Location: index.php?page=checklist'); exit;
    }
    $isLoc         = chkScopeIsLocation($cl);
    $freq          = chkFrequency($cl);
    $timeTracked   = chkTracksTime($cl);
    $effectiveDate = checklistEffectiveDate($cl);
    // ?date= names a calendar day. Each section then resolves its own period
    // from it, so picking a day shows that day's daily work alongside the week
    // and the month containing it.
    $displayDate   = $_GET['date'] ?? $effectiveDate;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $displayDate)) $displayDate = $effectiveDate;
    $isPast        = ($displayDate < $effectiveDate);
    $isFutureDate  = ($displayDate > $effectiveDate);
    $periodLabel   = date('d M Y', strtotime($displayDate));

    // Resolve scope (location_id) for location-mode. Everyone — admins
    // included — fills against their own employees.location_id; there is no
    // picker. location_id in the query string is ignored.
    $myLocId       = myLocationId();
    $noLocation    = false;
    $locationName  = '';
    if (!$isLoc) {
        $locationId = 0;
    } elseif ($myLocId > 0) {
        $locationId = $myLocId;
        $loc = getLocation($myLocId);
        $locationName = (string)($loc['location_name'] ?? '');
    } else {
        $locationId = 0;
        $noLocation = true;
    }
    $haveScope = !$isLoc || $locationId > 0;

    // When the open cycle stops accepting answers, in words.
    $closeLabel = chkPeriodCloseLabel($cl, $effectiveDate);

    // ── Day picker ───────────────────────────────────────
    // One tile per day of the month. Picking a day drives every section: the
    // daily ones show that day, the weekly and monthly ones the week and month
    // containing it. The tile figures count daily work only — on the 1st of a
    // month the daily and monthly anchors are the same date, so counting by
    // log_date alone would fold monthly tasks into that day's tile.
    $dTs      = strtotime($displayDate);
    $navFirst = date('Y-m-01', $dTs);
    $navLast  = date('Y-m-t',  $dTs);
    $navPrev  = date('Y-m-d', strtotime('-1 month', strtotime($navFirst)));
    $navNext  = date('Y-m-d', strtotime('+1 month', strtotime($navFirst)));
    $navLabel = date('F Y', $dTs);

    $tiles = [];   // ['start','label']
    $daysInMonth = (int)date('t', $dTs);
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $start = date('Y-m-', $dTs) . str_pad($d, 2, '0', STR_PAD_LEFT);
        $tiles[] = ['start' => $start, 'label' => (string)$d];
    }
    $tileMin  = $navFirst;
    $tileMax  = $navLast;
    $tileWide = 72;
    $locQS    = $isLoc && $locationId > 0 ? "&location_id={$locationId}" : '';

    $db = getDb();
    $sections = chkGetSections($checklistId);
    $tasks = [];
    $existingCounts = [];
    $totalQ = max(1, chkItemTotal($checklistId));

    // The anchor each cycle resolves to for the selected day.
    $anchors = [];
    foreach (CHK_FREQS as $f) $anchors[$f] = chkPeriodStart($f, $displayDate);

    if ($haveScope) {
        // Tile counts — daily work only, see the note on the picker above.
        $fSql = chkItemFreqSql();
        try {
            $st = $db->prepare(
                "SELECT r.log_date, COUNT(DISTINCT r.item_id) AS done
                 FROM chk_daily_responses r
                 JOIN chk_items i ON i.id = r.item_id
                 JOIN chk_checklists c ON c.id = i.checklist_id
                 LEFT JOIN chk_sections sec ON sec.id = i.section_id
                 WHERE r.checklist_id = ? AND r.location_id = ? AND r.log_date BETWEEN ? AND ?
                   AND r.response_value IS NOT NULL AND r.response_value <> ''
                   AND {$fSql} = 'daily'
                 GROUP BY r.log_date");
            $st->execute([$checklistId, $locationId, $tileMin, $tileMax]);
            $existingCounts = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Exception $e) {
            $existingCounts = [];
        }

        // Tasks, each carrying the cycle it runs on so the view can group and
        // label by it and resolve its answer to the right anchor.
        $freqSel = chkHasSectionFreq()
            ? "COALESCE(sec.frequency, c.frequency, 'daily')"
            : "COALESCE(c.frequency, 'daily')";
        $st = $db->prepare(
            "SELECT q.id, q.task_description, q.input_type, q.section_id, q.section_name, q.est_minutes,
                    COALESCE(sec.name, q.section_name) AS sec_name,
                    COALESCE(sec.sort_order, 9999) AS sec_sort,
                    {$freqSel} AS item_freq
             FROM chk_items q
             LEFT JOIN chk_checklists c ON c.id = q.checklist_id
             LEFT JOIN chk_sections sec ON sec.id = q.section_id
             WHERE q.checklist_id = ? AND q.is_active = 1
             ORDER BY CASE {$freqSel} WHEN 'daily' THEN 1 WHEN 'weekly' THEN 2 ELSE 3 END,
                      sec_sort, q.id ASC");
        $st->execute([$checklistId]);
        $tasks = $st->fetchAll(PDO::FETCH_ASSOC);

        // Every response sitting on any of the three anchors, matched to its
        // task in PHP. One query rather than a correlated per-row anchor join,
        // and the matching stays somewhere it can be read and tested.
        // `latest` is whoever answered most recently — that is what the cell
        // shows; `mine` is this user's own row, which is where the minutes and
        // the remark come from, so a colleague's entry never appears as theirs.
        $sel = 'r.item_id, r.log_date, r.id, r.response_value, r.employee_code'
             . (chkHasTaskTime() ? ', r.time_minutes' : '')
             . (chkHasRemarks()  ? ', r.remarks' : '');
        $rs = $db->prepare(
            "SELECT {$sel}, e.full_name
             FROM chk_daily_responses r
             LEFT JOIN employees e ON e.employee_code = r.employee_code
             WHERE r.checklist_id = ? AND r.location_id = ? AND r.log_date IN (?, ?, ?)
             ORDER BY r.id ASC");
        $rs->execute(array_merge([$checklistId, $locationId], array_values($anchors)));
        $latest = []; $mine = [];
        foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = (int)$r['item_id'] . '|' . $r['log_date'];
            $latest[$k] = $r;
            if ((string)$r['employee_code'] === $me) $mine[$k] = $r;
        }
        foreach ($tasks as &$t) {
            $k = (int)$t['id'] . '|' . ($anchors[$t['item_freq']] ?? $displayDate);
            $t['log_date']       = $anchors[$t['item_freq']] ?? $displayDate;
            $t['response_value'] = $latest[$k]['response_value'] ?? null;
            $t['submitted_by']   = $latest[$k]['full_name'] ?? null;
            $t['my_minutes']     = $mine[$k]['time_minutes'] ?? null;
            $t['my_remarks']     = $mine[$k]['remarks'] ?? null;
        }
        unset($t);
    }
    // Attachments hang off each task's own anchor, so all three are collected.
    $itemAttachments = [];
    if ($haveScope) {
        foreach (array_unique(array_values($anchors)) as $a) {
            foreach (checklistAttachmentsByItem($checklistId, $locationId, $a) as $iid => $rows) {
                $itemAttachments[$iid] = array_merge($itemAttachments[$iid] ?? [], $rows);
            }
        }
    }
    // All-time file counts per task, so a row shows "has a file" even when
    // the file was uploaded on a different day than the one being viewed.
    $itemFileCounts = $haveScope
        ? checklistItemFileCounts($checklistId, $locationId)
        : [];
?>
<div class="page-header" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <a href="?page=checklist" class="btn btn-ghost btn-sm">&lsaquo; All checklists</a>
    <h2 style="margin:0">✅ <?= h($cl['name']) ?></h2>
    <span class="badge badge-blue" style="font-weight:600"><?= h(chkFreqLabel($freq)) ?></span>
</div>

<!-- Scope -->
<div class="filter-bar" style="margin-bottom:14px">
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <?php if (!$isLoc): ?>
        <span class="badge badge-grey" style="font-weight:600">Department checklist</span>
    <?php elseif ($myLocId > 0): ?>
        <strong style="font-size:15px"><?= h($locationName) ?></strong>
    <?php endif; ?>
        <span class="text-muted">Viewing: <strong><?= h($periodLabel) ?></strong></span>
        <?php if (!$isPast && !$isFutureDate): ?>
        <span class="text-muted" style="font-size:12px">· Closes <?= h($closeLabel) ?></span>
        <?php endif; ?>
    </div>
</div>

<?php if ($noLocation): ?>
<div class="alert alert-error">You have not claimed a location yet. Please go to <a href="?page=my_location" style="color:var(--accent)">My Location</a> to claim your location first.</div>
<?php elseif ($haveScope): ?>
<!-- Period tiles: days of a month / weeks of a month / months of a year -->
<div class="table-wrap" style="padding:16px;margin-bottom:14px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <a href="?page=checklist&id=<?= $checklistId ?><?= $locQS ?>&date=<?= $navPrev ?>" class="btn btn-ghost btn-sm">&lsaquo; Prev</a>
        <strong><?= h($navLabel) ?></strong>
        <a href="?page=checklist&id=<?= $checklistId ?><?= $locQS ?>&date=<?= $navNext ?>" class="btn btn-ghost btn-sm">Next &rsaquo;</a>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(<?= (int)$tileWide ?>px,1fr));gap:6px">
        <?php foreach ($tiles as $tile):
            $tileDate = $tile['start'];
            // Anything past the effective period is "future" for fill
            // purposes (you can't open next week's checklist). Past periods
            // stay clickable but render the form read-only.
            $tileFuture = ($tileDate > $effectiveDate);
            $tilePast   = ($tileDate < $effectiveDate);
            $done = $existingCounts[$tileDate] ?? 0;
            if ($tileFuture) { $bg = '#4b5563'; }
            elseif ($done >= $totalQ)  { $bg = 'var(--green)'; }
            elseif ($done > 0)     { $bg = 'var(--yellow)'; }
            else                   { $bg = $tilePast ? '#6b7280' : 'var(--red)'; }
            $active = ($displayDate === $tileDate && !$tileFuture) ? 'outline:3px solid var(--accent);outline-offset:2px;' : '';
            $tileOpacity = $tilePast ? '0.7' : '1';
        ?>
        <?php if ($tileFuture): ?>
        <span style="background:<?= $bg ?>;color:#9ca3af;border-radius:6px;padding:8px 4px;text-align:center;
                  font-size:11px;font-weight:700;display:block;opacity:0.5;cursor:not-allowed;">
            <?= h($tile['label']) ?><br><span style="font-weight:400">—</span>
        </span>
        <?php else: ?>
        <a href="?page=checklist&id=<?= $checklistId ?><?= $locQS ?>&date=<?= $tileDate ?>"
           title="<?= h(chkPeriodLabel($freq, $tileDate)) ?>"
           style="background:<?= $bg ?>;color:#fff;border-radius:6px;padding:8px 4px;text-align:center;
                  font-size:11px;font-weight:700;text-decoration:none;display:block;opacity:<?= $tileOpacity ?>;<?= $active ?>">
            <?= h($tile['label']) ?><br><span style="font-weight:400"><?= $done ?>/<?= $totalQ ?></span>
        </a>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <div style="display:flex;gap:14px;margin-top:10px;font-size:11px;color:var(--muted)">
        <span><span style="color:var(--green)">&#9632;</span> Complete</span>
        <span><span style="color:var(--yellow)">&#9632;</span> Partial</span>
        <span><span style="color:var(--red)">&#9632;</span> Pending</span>
    </div>
</div>

<!-- Checklist Form -->
<?php if ($isFutureDate): ?>
<div class="alert alert-error">Future days cannot be filled. The current checklist day is <strong><?= h(date('d M Y', strtotime($effectiveDate))) ?></strong>.</div>
<?php elseif (!empty($tasks)): ?>
<?php
// Per-section editability for this displayed period — used to gate inputs.
// Keyed by section_id (0 = item with no section). Driven by the checklist's
// editable chk_sections windows via the time-window engine.
// $sectionStatus[<section_id>] = ['state','open','name','startLabel','deadlineLabel']
// The HH:MM labels only mean something on a daily checklist: a weekly or
// monthly cycle has no per-day bands, so its sections stay label-less and
// the badge reads a plain Open / Closed.
$sectionStatus = [];
foreach ($tasks as $t) {
    $sid = (int)($t['section_id'] ?? 0);
    if (!isset($sectionStatus[$sid])) {
        $secRow  = $sid ? ($sections[$sid] ?? null) : null;
        $state   = checklistSectionState($secRow, $displayDate, $cl);
        $sFreq   = (string)($t['item_freq'] ?? 'daily');
        // The HH:MM bands describe one day, so they only mean anything on a
        // daily section; a weekly or monthly one is labelled with its period.
        $bands   = ($sFreq === 'daily');
        $sectionStatus[$sid] = [
            'state'         => $state,
            'open'          => $state === 'open',
            'freq'          => $sFreq,
            'period'        => chkPeriodLabel($sFreq, $anchors[$sFreq] ?? $displayDate),
            'name'          => $secRow['name'] ?? ($t['section_name'] ?: 'General'),
            // A band running the whole day is not a window anyone needs told
            // about — only a genuine slice of the day earns the label.
            'startLabel'    => ($secRow && $bands && !chkSectionAllDay($secRow)) ? date('h:i A', checklistSectionStartTs($secRow, $displayDate))    : '',
            'deadlineLabel' => ($secRow && $bands && !chkSectionAllDay($secRow)) ? date('h:i A', checklistSectionDeadlineTs($secRow, $displayDate)) : '',
        ];
    }
}

// Form is read-only when viewing a past period OR when no section is
// currently open (either everything's closed already OR everything's
// still scheduled for later in the day — we show different copy below).
$anyOpenSection = false;
$anyUpcoming    = false;
foreach ($sectionStatus as $info) {
    if ($info['open'])                       $anyOpenSection = true;
    if ($info['state'] === 'not_yet_open')   $anyUpcoming    = true;
}
$readOnly = $isPast || !$anyOpenSection;

// Per-task time entry needs the chk_daily_responses.time_minutes column and
// fmtMinutes() from the time tracking module; without either, the time boxes
// simply don't render and the est_minutes fallback carries on as before.
$timeUi      = $timeTracked && chkHasTaskTime() && function_exists('fmtMinutes');
$remarkUi    = chkHasRemarks();
$timeRow     = $timeUi ? chkTimeEntryRow($checklistId, $me, $displayDate) : null;
$loggedMins  = (int)($timeRow['minutes'] ?? 0);
// Whether the minutes were typed rather than estimated. Read from the
// responses, not from the notes text — notes now carry the cycle's remarks
// when there are any, so the old string compare would always miss.
$timeEntered = $timeRow && chkTaskTimeTotal($checklistId, $me, $displayDate) > 0;
$myTimeUrl   = '?page=my_time&week=' . urlencode(function_exists('weekStartSunday') ? weekStartSunday($displayDate) : $displayDate);
?>
<?php if ($timeUi && $loggedMins > 0): ?>
<div class="alert" style="margin-bottom:10px;background:rgba(99,102,241,.10);color:var(--text);border:1px solid rgba(99,102,241,.30)">
    <?= chkClockIcon(14) ?> <strong><?= h(fmtMinutes($loggedMins)) ?></strong> logged for this day in
    <a href="<?= h($myTimeUrl) ?>" style="color:var(--accent)">My Time</a><?= $timeEntered ? '' : ' (estimated from the tasks you completed)' ?>.
</div>
<?php elseif ($timeUi && !$readOnly): ?>
<div class="text-muted" style="font-size:12px;margin-bottom:10px">
    <?= chkClockIcon(13) ?> Pick how long each task took — the minutes add up into one
    <a href="<?= h($myTimeUrl) ?>" style="color:var(--accent)">My Time</a> entry for the day you enter them,
    whichever cycle the task belongs to.
</div>
<?php endif; ?>
<?php if ($isPast): ?>
<div class="alert" style="margin-bottom:10px;background:rgba(107,114,128,.12);color:#9ca3af;border:1px solid rgba(107,114,128,.3)">Viewing a past day — read-only.</div>
<?php elseif (!$anyOpenSection && $anyUpcoming): ?>
<div class="alert" style="margin-bottom:10px;background:rgba(201,168,0,.10);color:var(--yellow);border:1px solid rgba(201,168,0,.30)">No section is open right now. The next section opens later today — check back at the time shown on each section header.</div>
<?php elseif (!$anyOpenSection): ?>
<div class="alert" style="margin-bottom:10px;background:rgba(107,114,128,.12);color:#9ca3af;border:1px solid rgba(107,114,128,.3)">
    All sections for today are closed. The day rolls over <?= h($closeLabel) ?>.
</div>
<?php endif; ?>
<form method="POST" enctype="multipart/form-data" id="chkForm"<?= $readOnly ? ' onsubmit="return false"' : '' ?>>
    <input type="hidden" name="action" value="save_checklist">
    <input type="hidden" name="checklist_id" value="<?= $checklistId ?>">
    <?php if ($isLoc): ?><input type="hidden" name="location_id" value="<?= $locationId ?>"><?php endif; ?>
    <input type="hidden" name="log_date" value="<?= h($displayDate) ?>">
    <div class="table-wrap">
        <table class="table chk-table">
            <thead>
                <tr><th style="width:48px;text-align:center">#</th><th>Particular</th><th style="width:<?= $timeUi ? 320 : 260 ?>px">Status / Answer</th></tr>
            </thead>
            <tbody>
            <?php
            $currentSection = null; $sr = 1;
            foreach ($tasks as $t):
                $sid = (int)($t['section_id'] ?? 0);
                $secInfo = $sectionStatus[$sid] ?? ['state' => 'closed', 'open' => false, 'name' => 'General',
                                                    'startLabel' => '', 'deadlineLabel' => '', 'freq' => 'daily', 'period' => ''];
                $cellEditable = !$readOnly && $secInfo['open'];
                if ($sid !== $currentSection):
                    $currentSection = $sid;
            ?>
                <tr><td colspan="3" class="chk-section" style="background:var(--border);font-weight:700;font-size:12px;padding:8px 13px">
                    <?= h($secInfo['name'] ?: 'General') ?>
                    <span class="badge badge-blue" style="margin-left:8px;font-weight:600"><?= h(chkFreqLabel($secInfo['freq'])) ?></span>
                    <?php if ($secInfo['period'] !== ''): ?>
                        <span class="text-muted" style="margin-left:6px;font-weight:400"><?= h($secInfo['period']) ?></span>
                    <?php endif; ?>
                    <?php if ($isPast): ?>
                        <span class="badge badge-grey" style="margin-left:8px;font-weight:600">Read-only (past)</span>
                    <?php elseif ($secInfo['state'] === 'not_yet_open'): ?>
                        <span class="badge badge-yellow" style="margin-left:8px;font-weight:600">Opens at <?= h($secInfo['startLabel']) ?></span>
                    <?php elseif ($secInfo['open']): ?>
                        <span class="badge badge-green" style="margin-left:8px;font-weight:600"><?= ($secInfo['startLabel'] && $secInfo['deadlineLabel']) ? 'Open ' . h($secInfo['startLabel']) . ' – ' . h($secInfo['deadlineLabel']) : 'Open' ?></span>
                    <?php else: ?>
                        <span class="badge badge-red" style="margin-left:8px;font-weight:600"><?= $secInfo['deadlineLabel'] ? 'Closed at ' . h($secInfo['deadlineLabel']) : 'Closed' ?></span>
                    <?php endif; ?>
                </td></tr>
            <?php endif; ?>
                <tr>
                    <td class="chk-num" style="text-align:center;color:var(--muted);font-size:12px"><?= $sr++ ?></td>
                    <td class="chk-particular">
                        <?= h($t['task_description']) ?>
                        <?php
                        // Paperclip badge — all-time file count for this task
                        // in the current scope. Always a link, so "no files"
                        // is an answer you can click through to and confirm.
                        $fc      = $itemFileCounts[(int)$t['id']] ?? null;
                        $fcN     = (int)($fc['n'] ?? 0);
                        $fcLast  = (string)($fc['last_date'] ?? '');
                        $fcTitle = $fcN > 0
                            ? ($fcN . ' file(s) — last on ' . date('d M Y', strtotime($fcLast)))
                            : 'No files attached to this task yet';
                        ?>
                        <a class="chk-file-badge<?= $fcN > 0 ? ' has-files' : '' ?>"
                           href="?page=checklist_files&amp;id=<?= $checklistId ?>&amp;item_id=<?= (int)$t['id'] ?>&amp;date=<?= h($displayDate) ?>"
                           title="<?= h($fcTitle) ?>"><?= chkClipIcon(11) ?><?= $fcN > 0 ? (int)$fcN : 'none' ?></a>
                    </td>
                    <td class="chk-answer">
                        <?php
                        // Two lines: the duration (or, on a location checklist,
                        // the answer control) with the attachments beside it,
                        // then the remark on its own line underneath.
                        $myMin = (int)($t['my_minutes'] ?? 0);
                        ?>
                        <?php
                            $cellMsg = $isPast
                                ? 'Not filled'
                                : ($secInfo['state'] === 'not_yet_open'
                                    ? ('Opens at ' . ($secInfo['startLabel'] ?: '—'))
                                    : ($secInfo['freq'] === 'daily' ? 'Section closed' : 'Closed'));
                        ?>
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                        <?php if ($timeUi): ?>
                        <?php
                        // On a checklist that tracks time the duration IS the
                        // answer — how long it took says it was done, and
                        // nothing says it was not. So there is no separate
                        // Yes/No to keep in step with the minutes.
                        ?>
                            <?php if ($cellEditable): ?>
                            <span style="display:inline-flex;align-items:center;gap:5px">
                                <span class="text-muted" style="display:inline-flex" title="How long this task took"><?= chkClockIcon(13) ?></span>
                                <?= durationSelect('task_time[' . (int)$t['id'] . ']', $myMin, false, 'chk-task-time', 'width:110px;padding:3px 6px;font-size:12px') ?>
                            </span>
                            <?php if (!empty($t['response_value'])): ?>
                                <span class="text-muted" style="font-size:11px">By: <?= h($t['submitted_by'] ?? 'Unknown') ?></span>
                            <?php endif; ?>
                            <?php elseif ($myMin > 0 || !empty($t['response_value'])): ?>
                            <span class="badge badge-green">&#10003; <?= $myMin > 0 ? h(fmtMinutes($myMin)) : h($t['response_value']) ?></span>
                            <span class="text-muted" style="font-size:11px">By: <?= h($t['submitted_by'] ?? 'Unknown') ?></span>
                            <?php else: ?>
                            <span class="text-muted" style="font-size:12px">— <?= h($cellMsg) ?> —</span>
                            <?php endif; ?>
                        <?php else: ?>
                        <div>
                        <?php if (!empty($t['response_value'])): ?>
                            <span class="badge badge-green">&#10003; <?= h($t['response_value']) ?></span>
                            <div class="text-muted" style="margin-top:2px">By: <?= h($t['submitted_by'] ?? 'Unknown') ?></div>
                        <?php elseif (!$cellEditable): ?>
                            <span class="text-muted" style="font-size:12px">— <?= h($cellMsg) ?> —</span>
                        <?php else: ?>
                            <?php if ($t['input_type'] === 'time'): ?>
                                <?= time24Input('ans[' . $t['id'] . ']') ?>
                            <?php elseif ($t['input_type'] === 'yes_no'): ?>
                                <select name="ans[<?= $t['id'] ?>]" class="form-control" style="width:120px">
                                    <option value="">— Select —</option>
                                    <option value="Yes">Yes</option>
                                    <option value="No">No</option>
                                </select>
                            <?php elseif ($t['input_type'] === 'number'): ?>
                                <input type="number" name="ans[<?= $t['id'] ?>]" class="form-control" style="width:140px" placeholder="Enter number">
                            <?php else: ?>
                                <input type="text" name="ans[<?= $t['id'] ?>]" class="form-control" placeholder="Enter details">
                            <?php endif; ?>
                        <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php
                        // Attachments sit beside the duration rather than under it:
                        // choosing a file belongs with saying how long the task took,
                        // and stacking them made every row three lines tall. Chips for
                        // files already on the task flow along the same line and wrap
                        // when there are several.
                        $attList   = $itemAttachments[(int)$t['id']] ?? [];
                        // Allow file input alongside an unanswered editable
                        // input too — the save handler upserts the answer
                        // first then attaches, so a single submit covers both.
                        $canAttach = $cellEditable;
                        if ($attList || $canAttach):
                        ?>
                            <span class="chk-att-wrap" style="display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap">
                                <?php foreach ($attList as $att): ?>
                                    <span style="display:inline-flex;align-items:center;gap:4px">
                                        <a class="chk-att-chip" target="_blank"
                                           href="?page=download_checklist_attachment&att_id=<?= (int)$att['id'] ?>"
                                           title="<?= h($att['uploader_name'] ?? $att['uploaded_by']) . ' · ' . h($att['uploaded_at']) ?>"
                                           style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border:1px solid var(--border);border-radius:999px;font-size:11px;color:var(--text);text-decoration:none;background:rgba(255,255,255,.04)">
                                            <?= $att['is_image'] ? chkImageIcon(12) : chkClipIcon(12) ?>
                                            <span style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($att['filename']) ?></span>
                                        </a>
                                        <?php if ($cellEditable && (string)$att['uploaded_by'] === myCode()): ?>
                                            <button type="button" class="btn-ghost-x"
                                                onclick="if(confirm('Delete this file?')){document.getElementById('chkAttDelId').value='<?= (int)$att['id'] ?>';document.getElementById('chkAttDelForm').submit();}"
                                                style="border:none;background:transparent;color:var(--muted);cursor:pointer;font-size:14px;line-height:1;padding:0 2px"
                                                title="Delete">×</button>
                                        <?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                                <?php if ($canAttach): ?>
                                    <input type="file" class="form-control chk-files"
                                           name="attachments[<?= (int)$t['id'] ?>][]"
                                           accept="image/*,application/pdf,.doc,.docx,.xls,.xlsx"
                                           multiple capture="environment"
                                           style="font-size:11px;max-width:190px">
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                        </div>
                        <?php
                        // Per-task remark, the cell's second line.
                        // Editable on the same terms as the minutes box, so a
                        // note can be added to a task ticked off earlier. The
                        // cycle's remarks become the notes of its My Time entry.
                        $myRemark = trim((string)($t['my_remarks'] ?? ''));
                        if ($remarkUi && $cellEditable): ?>
                            <div style="margin-top:4px">
                                <input type="text" name="remark[<?= (int)$t['id'] ?>]" class="form-control chk-task-remark"
                                       maxlength="500" value="<?= h($myRemark) ?>"
                                       placeholder="Remark (optional)"
                                       aria-label="Remark for this task"
                                       style="width:100%;padding:3px 6px;font-size:12px">
                            </div>
                        <?php elseif ($remarkUi && $myRemark !== ''): ?>
                            <div class="text-muted" style="margin-top:4px;font-size:11px;white-space:normal;word-break:break-word">
                                &ldquo;<?= h($myRemark) ?>&rdquo;
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    // Show submit button while any open section is available — covers both
    // unanswered tasks AND attach-only saves on already-answered ones.
    $hasFillable = false;
    $hasAttachable = false;
    if (!$readOnly) {
        foreach ($tasks as $tk) {
            $sectionOpen = ($sectionStatus[(int)($tk['section_id'] ?? 0)]['open'] ?? false);
            if (!$sectionOpen) continue;
            if (empty($tk['response_value']))  $hasFillable   = true;
            else                               $hasAttachable = true;
        }
    }
    if ($hasFillable || $hasAttachable): ?>
    <div class="form-actions" style="position:sticky;bottom:0;margin-top:10px;padding:10px 0;background:var(--bg);border-top:1px solid var(--border);z-index:20">
        <button type="submit" class="btn btn-success">
            <?= $hasFillable ? 'Submit Progress' : 'Save Attachments' ?>
        </button>
        <?php if ($timeUi): ?>
        <span class="text-muted" style="font-size:12px;margin-left:10px">
            Time this submit: <strong id="chkTimeSum">—</strong>
        </span>
        <?php endif; ?>
    </div>
    <?php elseif (!$readOnly): ?>
    <div class="alert alert-success" style="margin-top:10px">All open-section tasks have been completed.</div>
    <?php endif; ?>
</form>
<script>
// Live total of the per-task minute boxes, so the day's My Time entry is
// no surprise after submitting.
(function () {
    var out = document.getElementById('chkTimeSum');
    if (!out) return;
    var boxes = document.querySelectorAll('.chk-task-time');
    function fmt(m) {
        if (!m) return '—';
        var h = Math.floor(m / 60), mm = m % 60;
        return h && mm ? h + 'h ' + mm + 'm' : (h ? h + 'h' : mm + 'm');
    }
    function sum() {
        var t = 0;
        Array.prototype.forEach.call(boxes, function (b) {
            var v = parseInt(b.value, 10);
            if (!isNaN(v) && v > 0) t += v;
        });
        out.textContent = fmt(t);
    }
    Array.prototype.forEach.call(boxes, function (b) { b.addEventListener('input', sum); });
    sum();
})();
<?php if ($timeUi): ?>
// A remark only reaches My Time through the day's time entry, and that needs
// minutes. Flag it inline as soon as a remark is typed against a task with no
// duration picked, rather than only after the round trip. Skipped entirely on
// a checklist that does not ask for time — there is nothing to add there.
(function () {
    var remarks = document.querySelectorAll('.chk-task-remark');
    if (!remarks.length || !document.querySelector('.chk-task-time')) return;
    function cellOf(el) { return el.closest ? el.closest('td') : null; }
    function hintFor(box) {
        var cell = cellOf(box);
        if (!cell) return null;
        var node = cell.querySelector('.chk-remark-hint');
        if (!node) {
            node = document.createElement('div');
            node.className = 'chk-remark-hint text-muted';
            node.style.cssText = 'font-size:10px;margin-top:2px;color:var(--yellow)';
            box.parentNode.appendChild(node);
        }
        return node;
    }
    function check(box) {
        var cell = cellOf(box);
        if (!cell) return;
        var time = cell.querySelector('.chk-task-time');
        var mins = time ? parseInt(time.value, 10) : NaN;
        var node = hintFor(box);
        if (!node) return;
        node.textContent = (box.value.trim() !== '' && !(mins > 0))
            ? 'Add minutes for this remark to reach My Time.' : '';
    }
    Array.prototype.forEach.call(remarks, function (box) {
        box.addEventListener('input', function () { check(box); });
        var cell = cellOf(box), time = cell && cell.querySelector('.chk-task-time');
        if (time) time.addEventListener('input', function () { check(box); });
        check(box);
    });
})();
<?php endif; ?>
</script>
<!-- Hidden form for attachment delete (one form, item-id-less by design — uses att_id only) -->
<form id="chkAttDelForm" method="POST" style="display:none">
    <input type="hidden" name="action" value="delete_checklist_attachment">
    <input type="hidden" name="att_id" id="chkAttDelId" value="">
    <input type="hidden" name="checklist_id" value="<?= $checklistId ?>">
    <input type="hidden" name="location_id" value="<?= $locationId ?>">
    <input type="hidden" name="log_date" value="<?= h($displayDate) ?>">
</form>
<script>
// ── Client-side image compression for checklist uploads ──
// Same rules as the audit edit page: image > 600 KB → downscale long
// edge to 1600 px and re-encode JPEG q=0.75. Other files pass through.
// Gates submit until every in-flight compression resolves.
(function () {
    var form = document.getElementById('chkForm');
    if (!form) return;
    var MAX_EDGE = 1600, SKIP_BELOW = 600 * 1024, JPEG_QUALITY = 0.75;
    var IMAGE_RE = /^image\/(jpeg|png|gif|webp|heic|heif)$/i;
    var inflight = 0;
    var submitBtns = form.querySelectorAll('button[type="submit"]');
    function fmtSize(b) {
        if (b < 1024) return b + ' B';
        if (b < 1024 * 1024) return (b / 1024).toFixed(1) + ' KB';
        return (b / 1024 / 1024).toFixed(2) + ' MB';
    }
    function setSubmitDisabled(d) { submitBtns.forEach(function (b) { b.disabled = d; }); }
    function statusNodeFor(input) {
        var node = input.nextElementSibling;
        if (!node || !node.classList || !node.classList.contains('chk-att-status')) {
            node = document.createElement('div');
            node.className = 'chk-att-status';
            node.style.cssText = 'font-size:10px;margin-top:2px;color:var(--muted);min-height:12px;flex-basis:100%';
            input.parentNode.insertBefore(node, input.nextSibling);
        }
        return node;
    }
    function setFiles(input, arr) {
        try { var dt = new DataTransfer(); arr.forEach(function (f) { dt.items.add(f); }); input.files = dt.files; return true; }
        catch (e) { return false; }
    }
    function decode(file) {
        if (typeof createImageBitmap === 'function') {
            try { return createImageBitmap(file, { imageOrientation: 'from-image' }); }
            catch (e) { return createImageBitmap(file); }
        }
        return new Promise(function (res, rej) {
            var url = URL.createObjectURL(file); var img = new Image();
            img.onload = function () { URL.revokeObjectURL(url); res(img); };
            img.onerror = function () { URL.revokeObjectURL(url); rej(new Error('decode failed')); };
            img.src = url;
        });
    }
    function compressOne(file) {
        if (!IMAGE_RE.test(file.type)) return Promise.resolve(file);
        if (file.size <= SKIP_BELOW)   return Promise.resolve(file);
        return decode(file).then(function (bmp) {
            var w = bmp.width || bmp.naturalWidth, h = bmp.height || bmp.naturalHeight;
            if (!w || !h) return file;
            var scale = Math.min(1, MAX_EDGE / Math.max(w, h));
            var tw = Math.round(w * scale), th = Math.round(h * scale);
            var canvas = document.createElement('canvas');
            canvas.width = tw; canvas.height = th;
            canvas.getContext('2d').drawImage(bmp, 0, 0, tw, th);
            return new Promise(function (res) {
                canvas.toBlob(function (blob) {
                    if (!blob || blob.size >= file.size) { res(file); return; }
                    var base = (file.name || 'photo').replace(/\.(png|jpe?g|gif|webp|heic|heif)$/i, '');
                    res(new File([blob], base + '.jpg', { type: 'image/jpeg', lastModified: Date.now() }));
                }, 'image/jpeg', JPEG_QUALITY);
            });
        }).catch(function () { return file; });
    }
    document.addEventListener('change', function (e) {
        if (!e.target.matches || !e.target.matches('.chk-files')) return;
        var input = e.target, status = statusNodeFor(input);
        var files = Array.from(input.files || []);
        if (!files.length) { status.textContent = ''; return; }
        var origTotal = files.reduce(function (n, f) { return n + f.size; }, 0);
        var any = files.some(function (f) { return IMAGE_RE.test(f.type) && f.size > SKIP_BELOW; });
        if (!any) { status.style.color = 'var(--muted)'; status.textContent = files.length + ' file(s) — ' + fmtSize(origTotal); return; }
        inflight++; setSubmitDisabled(true);
        status.style.color = 'var(--muted)'; status.textContent = 'Compressing photo(s)…';
        Promise.all(files.map(compressOne)).then(function (out) {
            var newTotal = out.reduce(function (n, f) { return n + f.size; }, 0);
            if (!setFiles(input, out)) {
                status.style.color = 'var(--yellow)';
                status.textContent = 'Could not replace files — uploading originals (' + fmtSize(origTotal) + ').';
            } else if (newTotal < origTotal) {
                status.style.color = 'var(--green)';
                status.textContent = fmtSize(origTotal) + ' → ' + fmtSize(newTotal) + ' (' + Math.round((1 - newTotal / origTotal) * 100) + '% smaller).';
            } else {
                status.style.color = 'var(--muted)';
                status.textContent = out.length + ' file(s) — ' + fmtSize(newTotal);
            }
        }).catch(function () {
            status.style.color = 'var(--yellow)';
            status.textContent = 'Compression failed — uploading originals (' + fmtSize(origTotal) + ').';
        }).then(function () {
            inflight = Math.max(0, inflight - 1);
            if (inflight === 0) setSubmitDisabled(false);
        });
    }, true);
    form.addEventListener('submit', function (e) {
        if (inflight > 0) { e.preventDefault(); alert('Still compressing photo(s) — please wait a moment and try again.'); }
    }, true);
})();
</script>
<?php else: ?>
<div class="alert alert-error">No active checklist tasks found. Add tasks via Manage Tasks.</div>
<?php endif; ?>

<?php endif; // locationId ?>
<?php }

// ── Files of one task, across every date ──────────────────
// ?page=checklist_files&id=<checklist>&item_id=<task>[&date=<back link>]
// Answers "does this question have an attachment, and when?" for tasks whose
// files live on some other day than the one open in the fill view.
function pageChecklistItemFiles(): void {
    $checklistId = (int)($_GET['id'] ?? 0);
    $itemId      = (int)($_GET['item_id'] ?? 0);
    $me          = myCode();
    $cl          = chkGetChecklist($checklistId);
    if (!$cl) {
        flash('error', 'Checklist not found.');
        header('Location: index.php?page=checklist'); exit;
    }
    // Same visibility rule as a single attachment: report / manage roles and
    // this checklist's designated validators see every outlet (the validate
    // page lets them pick any), everyone else must be able to fill it — and
    // then only within their own scope, below.
    $canSeeAll = isSuperadmin() || hasTxn('checklist_report')
              || chkCanManageChecklist($cl) || chkIsValidator($checklistId, $me);
    if (!$canSeeAll && !chkCanFill($cl, $me)) {
        flash('error', 'You are not assigned to this checklist.');
        header('Location: index.php?page=checklist'); exit;
    }

    $st = getDb()->prepare(
        'SELECT id, task_description, section_name, input_type, is_active
         FROM chk_items WHERE id = ? AND checklist_id = ?');
    $st->execute([$itemId, $checklistId]);
    $item = $st->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        flash('error', 'Task not found on this checklist.');
        header('Location: index.php?page=checklist&id=' . $checklistId); exit;
    }

    // Scope. Department checklists share one copy (location 0). Location
    // checklists are pinned to the viewer's own outlet; only report/manage
    // roles may widen to another outlet or to all of them.
    $isLoc     = chkScopeIsLocation($cl);
    $freq      = chkFrequency($cl);
    $myLoc     = myLocationId();
    $scopeAll  = false;
    if (!$isLoc) {
        $scopeLoc = 0;
    } elseif ($canSeeAll) {
        $req = trim((string)($_GET['location_id'] ?? ''));
        if ($req === 'all')     { $scopeLoc = null; $scopeAll = true; }
        elseif ((int)$req > 0)  { $scopeLoc = (int)$req; }
        elseif ($myLoc > 0)     { $scopeLoc = $myLoc; }
        else                    { $scopeLoc = null; $scopeAll = true; }
    } else {
        $scopeLoc = $myLoc;   // 0 when no location claimed → nothing matches
    }
    $noLocation = $isLoc && !$canSeeAll && $myLoc <= 0;

    // ?on=YYYY-MM-DD narrows to one checklist cycle — where a report marker
    // for a specific date lands. Any day inside the cycle resolves to its
    // anchor, which is what log_date holds. Without it the whole history is
    // listed.
    $onDate = trim((string)($_GET['on'] ?? ''));
    $onDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $onDate) ? chkPeriodStart($freq, $onDate) : null;

    $LIMIT = 200;
    $files = $noLocation ? [] : checklistItemFiles($checklistId, $scopeLoc, $itemId, $LIMIT, $onDate);
    // A day marker that leads to an empty page is worse than useless — when
    // the scope hides that day's files, fall back to the full history.
    if ($onDate !== null && !$files) {
        $files  = $noLocation ? [] : checklistItemFiles($checklistId, $scopeLoc, $itemId, $LIMIT);
        $onDate = null;
    }

    // Group by the checklist cycle the file was filed against.
    $byDate = [];
    foreach ($files as $f) $byDate[(string)$f['log_date']][] = $f;

    $backDate = (string)($_GET['date'] ?? '');
    $backDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $backDate)
        ? chkPeriodStart($freq, $backDate)
        : checklistEffectiveDate($cl);
    $backUrl   = '?page=checklist&id=' . $checklistId . '&date=' . h($backDate);
    $backLabel = 'Back to checklist';
    // Arriving from a report? Send them back to the report they were reading
    // rather than to the fill view. Same-origin index.php query strings only.
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($ref !== '') {
        $p = parse_url($ref);
        $sameHost = empty($p['host']) || strcasecmp((string)$p['host'], (string)($_SERVER['HTTP_HOST'] ?? '')) === 0;
        parse_str((string)($p['query'] ?? ''), $rq);
        $refPage = (string)($rq['page'] ?? '');
        if ($sameHost && in_array($refPage, ['checklist_report', 'checklist_audit', 'checklist_validate'], true)) {
            $backUrl   = '?' . http_build_query($rq);
            $backLabel = 'Back to report';
        }
    }
    $scopeUrl = function (string $loc) use ($checklistId, $itemId, $backDate, $onDate): string {
        return '?page=checklist_files&id=' . $checklistId . '&item_id=' . $itemId
             . '&date=' . h($backDate) . ($onDate !== null ? '&on=' . h($onDate) : '')
             . '&location_id=' . h($loc);
    };
    // Same page without the single-day filter.
    $allDatesUrl = '?page=checklist_files&id=' . $checklistId . '&item_id=' . $itemId
                 . '&date=' . h($backDate)
                 . ($isLoc && $canSeeAll ? '&location_id=' . ($scopeAll ? 'all' : (int)$scopeLoc) : '');
    // Outlet names, resolved once each — the all-outlets view labels every card.
    $nameCache = [];
    $locName = function (int $id) use (&$nameCache): string {
        if ($id <= 0) return 'Department';
        if (!isset($nameCache[$id])) {
            $l = getLocation($id);
            $nameCache[$id] = (string)($l['location_name'] ?? ('Location #' . $id));
        }
        return $nameCache[$id];
    };
?>
<div class="page-header" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <a href="<?= h($backUrl) ?>" class="btn btn-ghost btn-sm">&lsaquo; <?= h($backLabel) ?></a>
    <h2 style="margin:0;display:inline-flex;align-items:center;gap:8px"><?= chkClipIcon(18) ?> Task files</h2>
</div>

<div class="table-wrap" style="padding:14px;margin-bottom:14px">
    <div style="font-size:15px;font-weight:600;margin-bottom:6px"><?= h($item['task_description']) ?></div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:12px" class="text-muted">
        <span class="badge badge-grey" style="font-weight:600"><?= h($cl['name']) ?></span>
        <?php if (!empty($item['section_name'])): ?>
            <span class="badge badge-blue" style="font-weight:600"><?= h($item['section_name']) ?></span>
        <?php endif; ?>
        <?php if ((int)$item['is_active'] !== 1): ?>
            <span class="badge badge-grey" style="font-weight:600">Inactive task</span>
        <?php endif; ?>
        <span><?= $isLoc ? ($scopeAll ? 'All outlets' : h($locName((int)$scopeLoc))) : 'Department checklist' ?></span>
        <span>·</span>
        <?php if ($onDate !== null): ?>
        <span><strong><?= count($files) ?></strong> file(s) on <strong><?= h(chkPeriodLabel($freq, $onDate)) ?></strong></span>
        <span>·</span>
        <a href="<?= $allDatesUrl ?>" style="color:var(--accent)">Show all dates</a>
        <?php else: ?>
        <span><strong><?= count($files) ?></strong> file(s) across <strong><?= count($byDate) ?></strong> <?= h(chkFreqNoun($freq)) ?>(s)</span>
        <?php endif; ?>
    </div>
    <?php if ($isLoc && $canSeeAll): ?>
    <div style="display:flex;gap:6px;margin-top:10px;flex-wrap:wrap">
        <?php if ($myLoc > 0): ?>
        <a class="btn btn-ghost btn-sm" href="<?= $scopeUrl((string)$myLoc) ?>"<?= $scopeAll ? '' : ' style="border-color:var(--accent)"' ?>>My outlet</a>
        <?php endif; ?>
        <a class="btn btn-ghost btn-sm" href="<?= $scopeUrl('all') ?>"<?= $scopeAll ? ' style="border-color:var(--accent)"' : '' ?>>All outlets</a>
    </div>
    <?php endif; ?>
</div>

<?php if ($noLocation): ?>
<div class="alert alert-error">You have not claimed a location yet. Please go to <a href="?page=my_location" style="color:var(--accent)">My Location</a> to claim your location first.</div>
<?php elseif (empty($files)): ?>
<div class="alert" style="background:rgba(107,114,128,.12);color:#9ca3af;border:1px solid rgba(107,114,128,.3)">
    No files have been attached to this task<?= $isLoc && !$scopeAll ? ' for this outlet' : '' ?> yet.
</div>
<?php else: ?>
<?php if ($onDate !== null): ?>
<div class="alert" style="margin-bottom:12px;background:rgba(26,143,227,.10);color:var(--text);border:1px solid rgba(26,143,227,.30)">
    Showing <strong><?= h(chkPeriodLabel($freq, $onDate)) ?></strong> only —
    <a href="<?= $allDatesUrl ?>" style="color:var(--accent)">see every <?= h(chkFreqNoun($freq)) ?> this task has files</a>.
</div>
<?php endif; ?>
<?php if (count($files) >= $LIMIT): ?>
<div class="alert" style="background:rgba(201,168,0,.10);color:var(--yellow);border:1px solid rgba(201,168,0,.30)">
    Showing the most recent <?= $LIMIT ?> files only.
</div>
<?php endif; ?>
<?php foreach ($byDate as $date => $rows): ?>
<div class="table-wrap" style="padding:14px;margin-bottom:12px">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px">
        <strong style="font-size:14px"><?= h($freq === 'daily' ? date('d M Y (D)', strtotime($date)) : chkPeriodLabel($freq, $date)) ?></strong>
        <span class="text-muted" style="font-size:12px">
            <?php
            // A shared location checklist can carry answers from more than
            // one person on the same day — show each distinct one.
            $answers = [];
            foreach ($rows as $r) {
                $a = trim((string)($r['response_value'] ?? ''));
                if ($a !== '') $answers[$a] = true;
            }
            foreach (array_keys($answers) as $a):
                $neg = strcasecmp($a, 'No') === 0; ?>
                <span class="badge <?= $neg ? 'badge-red' : 'badge-green' ?>"><?= $neg ? '' : '&#10003; ' ?><?= h($a) ?></span>
            <?php endforeach; ?>
            <?= count($rows) ?> file(s)
            <a href="?page=checklist&id=<?= $checklistId ?>&date=<?= h($date) ?>" style="color:var(--accent);margin-left:8px">Open day</a>
        </span>
    </div>
    <div class="chk-file-grid">
        <?php foreach ($rows as $f): ?>
        <a class="chk-file-card" target="_blank"
           href="?page=download_checklist_attachment&amp;att_id=<?= (int)$f['id'] ?>"
           title="<?= h($f['filename']) ?>">
            <span class="thumb">
                <?php if (!empty($f['is_image'])): ?>
                    <img src="?page=download_checklist_attachment&amp;att_id=<?= (int)$f['id'] ?>"
                         alt="<?= h($f['filename']) ?>" loading="lazy">
                <?php else: ?>
                    <span class="doc-glyph"><?= chkDocIcon(34) ?></span>
                <?php endif; ?>
            </span>
            <span class="meta">
                <span class="name"><?= h($f['filename']) ?></span>
                <span class="text-muted"><?= h(chkFmtBytes((int)$f['file_size'])) ?>
                    <?php if ($scopeAll): ?>· <?= h($locName((int)$f['location_id'])) ?><?php endif; ?>
                </span><br>
                <span class="text-muted"><?= h($f['uploader_name'] ?? $f['uploaded_by']) ?> · <?= h(date('d M, h:i A', strtotime((string)$f['uploaded_at']))) ?></span>
            </span>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
<?php
}

// ── Manage Tasks page (per-checklist admin) ───────────────
function pageManageTasks(): void {
    $db = getDb();
    $checklists = $db->query("SELECT * FROM chk_checklists ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    // Only the kinds this manager governs (superadmin sees both).
    $checklists = array_values(array_filter($checklists, 'chkCanManageChecklist'));

    // Selected checklist (?id), defaulting to the first one.
    $selId = (int)($_GET['id'] ?? 0);
    $cl = null;
    foreach ($checklists as $c) { if ((int)$c['id'] === $selId) { $cl = $c; break; } }
    if (!$cl && $checklists) { $cl = $checklists[0]; $selId = (int)$cl['id']; }
    if (!$cl) $selId = 0;

    $sections = $selId ? chkGetSections($selId) : [];
    $tasks = [];
    if ($selId) {
        $st = $db->prepare(
            "SELECT q.id, q.task_description, q.section_id, q.section_name, q.input_type, q.est_minutes, q.is_active,
                    COALESCE(sec.sort_order, 9999) AS sec_sort
             FROM chk_items q LEFT JOIN chk_sections sec ON sec.id = q.section_id
             WHERE q.checklist_id = ? ORDER BY sec_sort, q.id ASC");
        $st->execute([$selId]);
        $tasks = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $isEmp = $cl && ($cl['assign_type'] ?? '') === 'employee';

    // Assignees / validators for this checklist (with names).
    $people = function (string $table) use ($db, $selId): array {
        if (!$selId) return [];
        $st = $db->prepare("SELECT p.id, p.employee_code, e.full_name FROM {$table} p
                            LEFT JOIN employees e ON e.employee_code = p.employee_code
                            WHERE p.checklist_id = ? ORDER BY e.full_name");
        $st->execute([$selId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };
    $assignees  = $isEmp ? $people('chk_assignees') : [];
    $validators = $selId ? $people('chk_validators') : [];
    $employees  = getEmployeesLite();
    $totalEst   = 0; foreach ($tasks as $t) { if ($t['is_active']) $totalEst += (int)$t['est_minutes']; }
?>
<div class="page-header"><h2>📝 Manage Checklists</h2></div>

<!-- Checklist selector -->
<div class="filter-bar" style="margin-bottom:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <form method="GET" style="display:flex;gap:8px;align-items:center">
        <input type="hidden" name="page" value="manage_tasks">
        <label class="text-muted" style="font-size:13px">Checklist</label>
        <select name="id" class="form-control" style="width:240px" onchange="this.form.submit()">
            <?php foreach ($checklists as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $selId ? 'selected' : '' ?>>
                <?= h($c['name']) ?> — <?= h(chkFreqLabel(chkFrequency($c))) ?><?= (int)$c['is_active'] ? '' : ' (inactive)' ?>
            </option>
            <?php endforeach; ?>
        </select>
    </form>
    <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('clEdit').style.display='';clMeta(<?= $cl ? (int)$cl['id'] : 0 ?>,<?= h(json_encode($cl['name'] ?? '')) ?>,'<?= h($cl['assign_type'] ?? 'location') ?>',<?= (int)($cl['time_gated'] ?? 1) ?>,<?= (int)($cl['rollover_min'] ?? 0) ?>,<?= (int)($cl['sort_order'] ?? 0) ?>,'<?= h($cl ? chkFrequency($cl) : 'daily') ?>')">Edit checklist</button>
    <?php if (isSuperadmin()): ?>
    <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('clEdit').style.display='';clMetaNew()">+ New checklist</button>
    <?php endif; ?>
</div>

<!-- Checklist registry add/edit -->
<div class="form-card" id="clEdit" style="margin-bottom:16px;display:none">
    <h3 id="clTitle" style="font-size:14px;margin-bottom:12px">Edit Checklist</h3>
    <form method="POST">
        <input type="hidden" name="action" value="save_checklist_meta">
        <input type="hidden" name="checklist_id" id="clId" value="0">
        <div class="form-grid">
            <div class="form-group" style="grid-column:1/-1"><label>Name <span class="required">*</span></label>
                <input type="text" name="name" id="clName" class="form-control" required></div>
            <div class="form-group"><label>Assigned by</label>
                <select name="assign_type" id="clAssign" class="form-control">
                    <option value="location">Location (all outlets)</option>
                    <option value="employee">Designated employees</option>
                </select></div>
            <div class="form-group"><label>Frequency</label>
                <select name="frequency" id="clFreq" class="form-control"<?= chkHasFrequency() ? '' : ' disabled' ?>>
                    <option value="daily">Daily</option>
                    <option value="weekly">Weekly (Sun–Sat, closes Saturday midnight)</option>
                    <option value="monthly">Monthly (1st–last day, closes last day midnight)</option>
                </select>
                <?php if (!chkHasFrequency()): ?>
                <span class="text-muted" style="font-size:11px">Add the <code>frequency</code> column to enable weekly / monthly cycles.</span>
                <?php endif; ?></div>
            <div class="form-group"><label>Day rollover (min after midnight)</label>
                <input type="number" name="rollover_min" id="clRollover" class="form-control" min="0" max="1440" value="0">
                <span class="text-muted" style="font-size:11px">Daily only — e.g. 120 = day rolls at 02:00</span></div>
            <div class="form-group"><label>Sort order</label>
                <input type="number" name="sort_order" id="clSort" class="form-control" value="0"></div>
            <div class="form-group"><label>&nbsp;</label>
                <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="time_gated" id="clGated" value="1" checked> Enforce section time windows</label></div>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn-primary">Save Checklist</button>
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('clEdit').style.display='none'">Cancel</button></div>
    </form>
</div>

<?php if (!$cl): ?>
<div class="alert alert-error">No checklists yet. Click <strong>+ New checklist</strong> to create one.</div>
<?php else: ?>

<!-- Sections -->
<div class="form-card" style="margin-bottom:16px">
    <h3 style="font-size:14px;margin-bottom:10px">Sections &amp; time windows — <?= h($cl['name']) ?></h3>
    <?php if (chkFrequency($cl) !== 'daily'): ?>
    <div class="text-muted" style="font-size:12px;margin-bottom:10px">
        This is a <?= h(strtolower(chkFreqLabel(chkFrequency($cl)))) ?> checklist — the sections group its tasks, but the
        time windows below are not enforced. The whole <?= h(chkFreqNoun(chkFrequency($cl))) ?> stays fillable until it closes.
    </div>
    <?php endif; ?>
    <div class="table-wrap" data-stack style="margin-bottom:10px">
        <table class="table" style="font-size:13px">
            <thead><tr><th style="width:50px">Sort</th><th>Name</th><th style="width:90px">Cycle</th><th style="width:120px">Window</th><th style="width:160px">Actions</th></tr></thead>
            <tbody>
            <?php if (empty($sections)): ?>
            <tr><td colspan="5" class="empty-row">No sections — tasks will be open all day.</td></tr>
            <?php else: foreach ($sections as $s):
                $sm = (int)$s['start_min']; $em = (int)$s['end_min'];
                $startTxt = sprintf('%02d:%02d', intdiv($sm,60)%24, $sm%60);
                $endTxt   = sprintf('%02d:%02d', intdiv($em,60)%24, $em%60);
                $nextDay  = $em >= 1440;
            ?>
            <tr>
                <td><?= (int)$s['sort_order'] ?></td>
                <td><?= h($s['name']) ?></td>
                <td><span class="badge badge-blue" style="font-weight:600"><?= h(chkFreqLabel(chkItemFreq($s, $cl))) ?></span></td>
                <td><?= chkItemFreq($s, $cl) === 'daily' ? h($startTxt) . '–' . h($endTxt) . ($nextDay ? ' <span class="badge badge-grey">+1d</span>' : '') : '<span class="text-muted">whole cycle</span>' ?></td>
                <td class="actions" style="display:flex;gap:4px;flex-wrap:wrap">
                    <button type="button" class="btn btn-primary btn-sm" onclick="editSec(<?= (int)$s['id'] ?>,<?= h(json_encode($s['name'])) ?>,'<?= h($startTxt) ?>','<?= h(sprintf('%02d:%02d', intdiv($em,60)%24, $em%60)) ?>',<?= $nextDay ? 1 : 0 ?>,<?= (int)$s['sort_order'] ?>,'<?= h(chkItemFreq($s, $cl)) ?>')">Edit</button>
                    <form method="POST" class="inline-form" onsubmit="return confirm('Delete this section? Its tasks become un-sectioned.')">
                        <input type="hidden" name="action" value="del_section">
                        <input type="hidden" name="checklist_id" value="<?= $selId ?>">
                        <input type="hidden" name="section_id" value="<?= (int)$s['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Del</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <form method="POST" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="action" value="save_section">
        <input type="hidden" name="checklist_id" value="<?= $selId ?>">
        <input type="hidden" name="section_id" id="secId" value="0">
        <div class="form-group" style="margin:0"><label>Section name</label><input type="text" name="name" id="secName" class="form-control" style="width:160px" required></div>
        <div class="form-group" style="margin:0"><label>Cycle</label>
            <select name="frequency" id="secFreq" class="form-control" style="width:110px"<?= chkHasSectionFreq() ? '' : ' disabled' ?>>
                <option value="daily">Daily</option>
                <option value="weekly">Weekly</option>
                <option value="monthly">Monthly</option>
            </select></div>
        <div class="form-group" style="margin:0"><label>Start</label><input type="time" name="start_time" id="secStart" class="form-control" value="00:00"></div>
        <div class="form-group" style="margin:0"><label>End</label><input type="time" name="end_time" id="secEnd" class="form-control" value="00:00"></div>
        <div class="form-group" style="margin:0"><label>&nbsp;</label><label style="display:flex;gap:6px;align-items:center;font-size:12px"><input type="checkbox" name="end_next_day" id="secNext" value="1"> End next day</label></div>
        <div class="form-group" style="margin:0"><label>Sort</label><input type="number" name="sort_order" id="secSort" class="form-control" style="width:80px" value="0"></div>
        <button type="submit" class="btn btn-primary" id="secSubmit">Add Section</button>
        <button type="button" class="btn btn-secondary" onclick="secReset()">Clear</button>
    </form>
</div>

<!-- Add / Edit Task -->
<div class="form-card" style="margin-bottom:16px">
    <h3 id="taskFormTitle" style="font-size:14px;margin-bottom:12px">Add New Task — <?= h($cl['name']) ?></h3>
    <form method="POST" id="taskForm">
        <input type="hidden" name="action" value="save_task">
        <input type="hidden" name="checklist_id" value="<?= $selId ?>">
        <input type="hidden" name="task_id" id="taskId" value="0">
        <div class="form-grid">
            <div class="form-group" style="grid-column:1/-1">
                <label>Task Description <span class="required">*</span></label>
                <input type="text" name="description" id="taskDesc" class="form-control" required placeholder="e.g. Check Fridge Temperature">
            </div>
            <div class="form-group">
                <label>Section</label>
                <select name="section_id" id="taskSection" class="form-control">
                    <option value="0">— None —</option>
                    <?php foreach ($sections as $s): ?>
                    <option value="<?= (int)$s['id'] ?>"><?= h($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Input Type</label>
                <select name="input_type" id="taskType" class="form-control">
                    <option value="yes_no">Yes / No</option>
                    <option value="time">Time</option>
                    <option value="text">Text</option>
                    <option value="number">Number</option>
                </select>
            </div>
            <div class="form-group">
                <label>Std time (min)</label>
                <input type="number" name="est_minutes" id="taskEst" class="form-control" min="0" value="0">
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" id="taskSubmitBtn" class="btn btn-primary">Add Task</button>
            <button type="button" id="taskCancelBtn" class="btn btn-secondary" style="display:none" onclick="cancelEdit()">Cancel</button>
        </div>
    </form>
</div>
<script>
function editTask(id, sectionId, desc, type, est) {
    document.getElementById('taskId').value = id;
    document.getElementById('taskSection').value = sectionId || 0;
    document.getElementById('taskDesc').value = desc;
    document.getElementById('taskType').value = type;
    document.getElementById('taskEst').value = est || 0;
    document.getElementById('taskFormTitle').textContent = 'Edit Task #' + id;
    document.getElementById('taskSubmitBtn').textContent = 'Update Task';
    document.getElementById('taskCancelBtn').style.display = 'inline-block';
    document.getElementById('taskForm').scrollIntoView({behavior:'smooth'});
}
function cancelEdit() {
    document.getElementById('taskId').value = 0;
    document.getElementById('taskSection').value = 0;
    document.getElementById('taskDesc').value = '';
    document.getElementById('taskType').value = 'yes_no';
    document.getElementById('taskEst').value = 0;
    document.getElementById('taskFormTitle').textContent = 'Add New Task';
    document.getElementById('taskSubmitBtn').textContent = 'Add Task';
    document.getElementById('taskCancelBtn').style.display = 'none';
}
function editSec(id, name, start, end, nextDay, sort, freq) {
    document.getElementById('secId').value = id;
    document.getElementById('secName').value = name;
    document.getElementById('secStart').value = start;
    document.getElementById('secEnd').value = end;
    document.getElementById('secNext').checked = !!nextDay;
    document.getElementById('secSort').value = sort;
    document.getElementById('secFreq').value = freq || 'daily';
    document.getElementById('secSubmit').textContent = 'Update Section';
}
function secReset() {
    document.getElementById('secId').value = 0;
    document.getElementById('secName').value = '';
    document.getElementById('secStart').value = '00:00';
    document.getElementById('secEnd').value = '00:00';
    document.getElementById('secNext').checked = false;
    document.getElementById('secSort').value = 0;
    document.getElementById('secFreq').value = 'daily';
    document.getElementById('secSubmit').textContent = 'Add Section';
}
function clMeta(id, name, assign, gated, rollover, sort, freq) {
    document.getElementById('clTitle').textContent = id ? 'Edit Checklist #' + id : 'New Checklist';
    document.getElementById('clId').value = id;
    document.getElementById('clName').value = name;
    document.getElementById('clAssign').value = assign;
    document.getElementById('clGated').checked = !!gated;
    document.getElementById('clRollover').value = rollover;
    document.getElementById('clSort').value = sort;
    document.getElementById('clFreq').value = freq || 'daily';
}
function clMetaNew() { clMeta(0, '', 'location', 1, 0, 0, 'daily'); }
</script>

<!-- Tasks Table -->
<div class="table-wrap" data-stack style="margin-bottom:16px">
    <div style="display:flex;justify-content:space-between;align-items:center;padding:4px 2px 8px">
        <strong style="font-size:13px">Tasks</strong>
        <span class="text-muted" style="font-size:12px">Total std time: <?= (int)$totalEst ?> min</span>
    </div>
    <table class="table">
        <thead>
            <tr>
                <th style="width:50px">ID</th>
                <th style="width:140px">Section</th>
                <th>Description</th>
                <th style="width:90px">Type</th>
                <th style="width:70px">Min</th>
                <th style="width:80px">Status</th>
                <th style="width:180px">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($tasks)): ?>
            <tr><td colspan="7" class="empty-row">No tasks defined yet.</td></tr>
            <?php else: foreach ($tasks as $t): ?>
            <tr class="<?= $t['is_active'] ? '' : 'row-inactive' ?>">
                <td><?= $t['id'] ?></td>
                <td><?= h($t['section_name'] ?: 'General') ?></td>
                <td><?= h($t['task_description']) ?></td>
                <td><span class="badge badge-blue"><?= h($t['input_type']) ?></span></td>
                <td><?= (int)$t['est_minutes'] ?></td>
                <td><?= $t['is_active'] ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-grey">Inactive</span>' ?></td>
                <td class="actions" style="display:flex;gap:4px;flex-wrap:wrap">
                    <button type="button" class="btn btn-primary btn-sm" onclick="editTask(<?= $t['id'] ?>, <?= (int)$t['section_id'] ?>, <?= h(json_encode($t['task_description'])) ?>, '<?= h($t['input_type']) ?>', <?= (int)$t['est_minutes'] ?>)">Edit</button>
                    <form method="POST" class="inline-form">
                        <input type="hidden" name="action" value="toggle_task">
                        <input type="hidden" name="checklist_id" value="<?= $selId ?>">
                        <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                        <button type="submit" class="btn btn-ghost btn-sm"><?= $t['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                    </form>
                    <?php if (!$t['is_active']): ?>
                    <form method="POST" class="inline-form" onsubmit="return confirm('Delete this task permanently?')">
                        <input type="hidden" name="action" value="del_task">
                        <input type="hidden" name="checklist_id" value="<?= $selId ?>">
                        <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Del</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<!-- Assignees (employee-mode) + Validators -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px">
    <?php
    $personBox = function (string $title, string $addAction, string $delAction, array $rows, string $empty) use ($selId, $employees) {
        ?>
        <div class="form-card">
            <h3 style="font-size:14px;margin-bottom:10px"><?= h($title) ?></h3>
            <?php if (empty($rows)): ?><div class="text-muted" style="font-size:12px;margin-bottom:8px"><?= h($empty) ?></div>
            <?php else: ?>
            <div class="table-wrap" style="margin-bottom:10px"><table class="table" style="font-size:13px"><tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= h($r['full_name'] ?? $r['employee_code']) ?> <span class="text-muted" style="font-size:11px">(<?= h($r['employee_code']) ?>)</span></td>
                    <td style="width:60px;text-align:right">
                        <form method="POST" class="inline-form">
                            <input type="hidden" name="action" value="<?= h($delAction) ?>">
                            <input type="hidden" name="checklist_id" value="<?= $selId ?>">
                            <input type="hidden" name="row_id" value="<?= (int)$r['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">×</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
            <form method="POST" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
                <input type="hidden" name="action" value="<?= h($addAction) ?>">
                <input type="hidden" name="checklist_id" value="<?= $selId ?>">
                <div class="form-group" style="margin:0;flex:1"><label>Add employee</label>
                    <select name="employee_code" class="form-control" required>
                        <option value="">— Select —</option>
                        <?php foreach ($employees as $e): if ((int)$e['is_active'] !== 1) continue; ?>
                        <option value="<?= h($e['employee_code']) ?>"><?= h($e['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Add</button>
            </form>
        </div>
        <?php
    };
    if ($isEmp) $personBox('Designated fillers (assignees)', 'save_assignee', 'del_assignee', $assignees, 'No assignees — only admins can fill this checklist.');
    $personBox('Validators', 'save_validator', 'del_validator', $validators, 'No validators designated yet.');
    ?>
</div>
<?php endif; // $cl ?>
<?php }
