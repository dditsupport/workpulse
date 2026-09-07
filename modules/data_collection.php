<?php
// =========================================================
// Data Collection — ad-hoc collection drives
//
// Operations asks every outlet for the same thing at unpredictable
// times: P&P closing stock, a sales sheet, a photo of a device, "how
// many boxes are left in the deep freezer". That drive used to run on
// WhatsApp, where nobody can tell who has not answered yet and the
// files stay in the group forever.
//
// Here it is one task:
//   · a txn_data_collect holder starts it, picks the outlets, and types
//     the question each of them answers,
//   · every outlet uploads its files and writes its answer,
//   · the outlet presses Confirm submission, which LOCKS what it sent —
//     until then it may add files, remove them and rewrite the answer,
//   · Operations downloads the lot as one ZIP, folder per location,
//   · and then DISCARDS the task: files off disk, rows out of the
//     database, task row deleted. Nothing is kept — the download is the
//     record, which is why the discard dialog warns when nothing has
//     been downloaded yet.
//
// Several tasks run at once and none knows about the others: "device
// photos" across 40 outlets and "item qty" across 12 are separate rows,
// separate boards, separate folders on disk.
//
// Permissions:
//   · txn_data_collect — start, edit, close, file on behalf of any
//     targeted outlet, reopen a confirmed submission, download, discard
//   · submitting needs no flag: an employee reaches a task through the
//     outlet on their profile (employees.location_id) or through Manager
//     Mapping naming them Store Manager / Operation Manager of one
//
// Schema: migrations/2026-09-07_data_collection.sql
// =========================================================

define('DC_UPLOAD_DIR', __DIR__ . '/../uploads/data_collection/');
define('DC_MAX_BYTES',  15 * 1024 * 1024);   // 15 MB per file
define('DC_MAX_ANSWER', 2000);               // characters
define('DC_MAX_FILES',  10);                 // per save

// Extension-keyed, the way EVENT_PHOTO_ALLOWED_MIME is: an .xlsx sniffs
// as application/zip and an .xls as application/vnd.ms-excel or plain
// octet-stream depending on who wrote it, so a flat mime list would
// reject the very files this feature exists to collect.
const DC_ALLOWED_MIME = [
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
               'application/zip', 'application/octet-stream'],
    'xls'  => ['application/vnd.ms-excel', 'application/msword',
               'application/octet-stream', 'text/plain'],
    'csv'  => ['text/csv', 'text/plain', 'application/csv', 'application/octet-stream'],
    'pdf'  => ['application/pdf'],
    'doc'  => ['application/msword', 'application/octet-stream'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document',
               'application/zip', 'application/octet-stream'],
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
    'webp' => ['image/webp'],
    'heic' => ['image/heic', 'image/heif', 'application/octet-stream'],
    'heif' => ['image/heif', 'image/heic', 'application/octet-stream'],
];

// ── Schema probe ────────────────────────────────────────
// Every entry point checks this so an un-migrated database shows a
// notice instead of a 500. Probed once per request.
function dcSchemaReady(): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        getDb()->query('SELECT 1 FROM dc_requests LIMIT 0')->fetch();
        getDb()->query('SELECT 1 FROM dc_request_locations LIMIT 0')->fetch();
        getDb()->query('SELECT 1 FROM dc_files LIMIT 0')->fetch();
        getDb()->query('SELECT 1 FROM dc_submissions LIMIT 0')->fetch();
        $ready = true;
    } catch (Exception $e) {
        $ready = false;
    }
    return $ready;
}

function dcSchemaNotice(): string {
    return 'Data Collection is not set up on this database yet — run '
         . 'migrations/2026-09-07_data_collection.sql.';
}

// ── Permissions ─────────────────────────────────────────
function dcCanManage(): bool {
    return isSuperadmin() || hasTxn('data_collect');
}

// [location_id => true] for the outlets this user may submit for: the one
// on their profile, plus any they are mapped to in Manager Mapping — an
// area manager covering five stores files for all five.
function dcMyLocations(): array {
    static $ids = null;
    if ($ids !== null) return $ids;
    $ids = [];
    $mine = myLocationId();
    if ($mine > 0) $ids[$mine] = true;
    $me = myCode();
    if ($me !== '') {
        if (function_exists('getLocationManagerMap')) {
            foreach (getLocationManagerMap() as $lid => $code) {
                if ((string)$code === $me) $ids[(int)$lid] = true;
            }
        }
        if (function_exists('getLocationOperationManagerMap')) {
            foreach (getLocationOperationManagerMap() as $lid => $code) {
                if ((string)$code === $me) $ids[(int)$lid] = true;
            }
        }
    }
    return $ids;
}

function dcCanUsePage(): bool {
    return dcCanManage() || dcMyLocations() !== [];
}

// May this submission still be changed? The one definition, used by the
// save handler, the delete handler and the page. A store user edits its
// own outlet while the submission is a draft; a txn_data_collect holder
// edits any targeted outlet whatever its state — that is what makes
// filing on behalf, and the reopen path, work.
function dcCanEditSubmission(array $req, int $locationId, ?array $sub): bool {
    if (($req['status'] ?? '') !== 'open') return false;
    if (dcCanManage()) return true;
    $mine = dcMyLocations();
    if (!isset($mine[$locationId])) return false;
    return (($sub['status'] ?? 'draft') !== 'submitted');
}

// 'confirmed' | 'draft' | 'nothing' — only 'confirmed' counts as filed.
// A draft with files reads as in progress, so Operations can tell an
// outlet that is working from one that is silent.
function dcLocationState(?array $sub, int $fileCount): string {
    if (($sub['status'] ?? '') === 'submitted') return 'confirmed';
    if ($fileCount > 0 || trim((string)($sub['answer_text'] ?? '')) !== '') return 'draft';
    return 'nothing';
}

function dcStateBadge(string $state): string {
    return match ($state) {
        'confirmed' => '<span class="badge badge-green">Confirmed</span>',
        'draft'     => '<span class="badge badge-yellow">Draft</span>',
        default     => '<span class="badge badge-grey">Nothing yet</span>',
    };
}

function dcStateLabel(string $state): string {
    return match ($state) {
        'confirmed' => 'Confirmed',
        'draft'     => 'Draft',
        default     => 'Nothing yet',
    };
}

// ── Lookups ─────────────────────────────────────────────
function dcRequest(int $id): ?array {
    if ($id <= 0 || !dcSchemaReady()) return null;
    $st = getDb()->prepare('SELECT * FROM dc_requests WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function dcRequestLocationIds(int $id): array {
    $st = getDb()->prepare('SELECT location_id FROM dc_request_locations WHERE request_id = ? ORDER BY location_id');
    $st->execute([$id]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

// [location_id => location_name], every location, so a task targeting an
// outlet since deactivated still prints a name rather than an id.
function dcLocationNames(): array {
    static $names = null;
    if ($names !== null) return $names;
    $names = [];
    foreach (getLocations() as $l) $names[(int)$l['location_id']] = (string)$l['location_name'];
    return $names;
}

// The task list. A manager sees every task; anyone else sees only the
// tasks that target one of their outlets. Open tasks first, then by due
// date — the thing you owe soonest is at the top.
function dcRequests(): array {
    if (!dcSchemaReady()) return [];
    $sql = "SELECT r.*,
                   (SELECT COUNT(*) FROM dc_request_locations rl WHERE rl.request_id = r.id) AS target_count,
                   (SELECT COUNT(*) FROM dc_submissions s
                     WHERE s.request_id = r.id AND s.status = 'submitted')                   AS confirmed_count,
                   (SELECT COUNT(*) FROM dc_files f WHERE f.request_id = r.id)               AS file_count
              FROM dc_requests r";
    $params = [];
    if (!dcCanManage()) {
        $mine = array_keys(dcMyLocations());
        if (!$mine) return [];
        $in  = implode(',', array_fill(0, count($mine), '?'));
        $sql .= " WHERE EXISTS (SELECT 1 FROM dc_request_locations rl2
                                 WHERE rl2.request_id = r.id AND rl2.location_id IN ({$in}))";
        $params = $mine;
    }
    $sql .= " ORDER BY (r.status = 'open') DESC, COALESCE(r.due_date, '9999-12-31') ASC, r.id DESC";
    try {
        $st = getDb()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

// [location_id => [file rows]]
function dcFilesByLocation(int $requestId): array {
    $st = getDb()->prepare(
        'SELECT f.*, e.full_name AS uploader_name
           FROM dc_files f
      LEFT JOIN employees e ON e.employee_code = f.uploaded_by
          WHERE f.request_id = ?
       ORDER BY f.location_id, f.id');
    $st->execute([$requestId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['location_id']][] = $r;
    return $out;
}

// [location_id => submission row]
function dcSubmissions(int $requestId): array {
    $st = getDb()->prepare(
        'SELECT s.*, ec.full_name AS confirmed_name, eu.full_name AS updated_name
           FROM dc_submissions s
      LEFT JOIN employees ec ON ec.employee_code = s.confirmed_by
      LEFT JOIN employees eu ON eu.employee_code = s.updated_by
          WHERE s.request_id = ?');
    $st->execute([$requestId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['location_id']] = $r;
    return $out;
}

function dcFileRow(int $fileId): ?array {
    $st = getDb()->prepare('SELECT * FROM dc_files WHERE id = ?');
    $st->execute([$fileId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function dcFileDir(int $requestId): string {
    return DC_UPLOAD_DIR . $requestId . '/';
}

function dcFilePath(array $row): ?string {
    $p = dcFileDir((int)$row['request_id']) . $row['stored_name'];
    return is_file($p) ? $p : null;
}

// The question as printed above the answer box. A task that only wants a
// free remark leaves it blank and gets the generic label.
function dcQuestionLabel(array $req): string {
    $q = trim((string)($req['question'] ?? ''));
    return $q !== '' ? $q : 'Answer / remark';
}

// Anything a filesystem or a zip entry dislikes, for a folder or file name.
function dcSafeName(string $s): string {
    $s = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $s);
    $s = preg_replace('/[\x00-\x1F\x7F]/', '', $s);
    $s = trim((string)$s, " .\t");
    return $s === '' ? 'unnamed' : mb_substr($s, 0, 120);
}

// A file name that is free within one folder. Two outlets naming their
// sheet the same way is fine — they land in different folders — but two
// files in ONE folder would overwrite each other, so the second becomes
// "stock-2.xlsx". $used carries the names already taken, case-insensitively
// because Windows treats Stock.xlsx and stock.xlsx as one file.
function dcUniqueEntryName(string $original, array &$used): string {
    $name = dcSafeName($original);
    $base = pathinfo($name, PATHINFO_FILENAME);
    $ext  = pathinfo($name, PATHINFO_EXTENSION);
    $try  = $name;
    $n    = 1;
    while (isset($used[mb_strtolower($try)])) {
        $n++;
        $try = $base . '-' . $n . ($ext !== '' ? '.' . $ext : '');
    }
    $used[mb_strtolower($try)] = true;
    return $try;
}

// ── Handler: create / edit a task ───────────────────────
function doDcSaveRequest(): void {
    $back = 'index.php?page=data_collections';
    if (!dcCanManage() || !dcSchemaReady()) {
        flash('error', 'You do not have permission to start a collection task.');
        header("Location: {$back}"); exit;
    }
    $id           = (int)($_POST['id'] ?? 0);
    $title        = trim($_POST['title'] ?? '');
    $instructions = trim($_POST['instructions'] ?? '');
    $question     = trim($_POST['question'] ?? '');
    $dueDate      = trim($_POST['due_date'] ?? '');
    $requiresFile = isset($_POST['requires_file']) ? 1 : 0;
    $locIds       = array_values(array_unique(array_map('intval', (array)($_POST['location_ids'] ?? []))));

    $form = 'index.php?page=data_collection_new' . ($id ? '&id=' . $id : '');
    if ($title === '') {
        flash('error', 'Give the task a title.');
        header("Location: {$form}"); exit;
    }
    if (!$locIds) {
        flash('error', 'Pick at least one location to ask.');
        header("Location: {$form}"); exit;
    }
    if ($dueDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) $dueDate = '';

    $db = getDb();
    try {
        if ($id) {
            $db->prepare('UPDATE dc_requests
                             SET title = ?, instructions = ?, question = ?, requires_file = ?, due_date = ?
                           WHERE id = ?')
               ->execute([mb_substr($title, 0, 150), ($instructions === '' ? null : $instructions),
                          ($question === '' ? null : mb_substr($question, 0, 255)),
                          $requiresFile, ($dueDate === '' ? null : $dueDate), $id]);
        } else {
            $db->prepare('INSERT INTO dc_requests (title, instructions, question, requires_file, due_date, created_by)
                          VALUES (?,?,?,?,?,?)')
               ->execute([mb_substr($title, 0, 150), ($instructions === '' ? null : $instructions),
                          ($question === '' ? null : mb_substr($question, 0, 255)),
                          $requiresFile, ($dueDate === '' ? null : $dueDate), myCode()]);
            $id = (int)$db->lastInsertId();
        }

        // Rewrite the target set, but never drop an outlet that has already
        // sent something — its files would still be on disk with nothing on
        // the board pointing at them. Say so rather than silently keeping it.
        $existing = dcRequestLocationIds($id);
        $kept     = [];
        foreach (array_diff($existing, $locIds) as $gone) {
            $c = $db->prepare('SELECT COUNT(*) FROM dc_files WHERE request_id = ? AND location_id = ?');
            $c->execute([$id, $gone]);
            $hasFiles = (int)$c->fetchColumn() > 0;
            $s = $db->prepare('SELECT COUNT(*) FROM dc_submissions WHERE request_id = ? AND location_id = ?');
            $s->execute([$id, $gone]);
            if ($hasFiles || (int)$s->fetchColumn() > 0) { $kept[] = (int)$gone; }
        }
        $final = array_values(array_unique(array_merge($locIds, $kept)));

        $db->prepare('DELETE FROM dc_request_locations WHERE request_id = ?')->execute([$id]);
        $ins = $db->prepare('INSERT INTO dc_request_locations (request_id, location_id) VALUES (?,?)');
        foreach ($final as $lid) $ins->execute([$id, $lid]);

        $msg = 'Collection task saved — ' . count($final) . ' location(s) asked.';
        if ($kept) {
            $names = dcLocationNames();
            $msg .= ' Kept ' . implode(', ', array_map(fn($l) => $names[$l] ?? ('#' . $l), $kept))
                  . ' because they have already submitted.';
        }
        flash('success', $msg);
    } catch (Exception $e) {
        flash('error', 'Could not save the task: ' . $e->getMessage());
        header("Location: {$form}"); exit;
    }
    header('Location: index.php?page=data_collection&id=' . $id); exit;
}

// ── Handler: close / reopen the whole task ──────────────
function doDcCloseRequest(): void {
    $id   = (int)($_POST['request_id'] ?? 0);
    $back = 'index.php?page=data_collection&id=' . $id;
    if (!dcCanManage()) {
        flash('error', 'You do not have permission to close a collection task.');
        header("Location: {$back}"); exit;
    }
    $req = dcRequest($id);
    if (!$req) { flash('error', 'Task not found.'); header('Location: index.php?page=data_collections'); exit; }

    if ($req['status'] === 'open') {
        getDb()->prepare('UPDATE dc_requests SET status = ?, closed_by = ?, closed_at = NOW() WHERE id = ?')
               ->execute(['closed', myCode(), $id]);
        flash('success', 'Task closed — no more submissions. You can still download what came in.');
    } else {
        getDb()->prepare('UPDATE dc_requests SET status = ?, closed_by = NULL, closed_at = NULL WHERE id = ?')
               ->execute(['open', $id]);
        flash('success', 'Task reopened — locations can submit again.');
    }
    header("Location: {$back}"); exit;
}

// ── Handler: save a location's draft (files + answer) ───
// One handler for one form. The location comes from the POST and is
// re-validated against the caller's rights, so the same code serves a
// store user filing for their own outlet and Operations filing on behalf.
function doDcSaveDraft(): void {
    $id  = (int)($_POST['request_id'] ?? 0);
    $loc = (int)($_POST['location_id'] ?? 0);
    $back = 'index.php?page=data_collection&id=' . $id . '&loc=' . $loc;

    $req = dcRequest($id);
    if (!$req) { flash('error', 'Task not found.'); header('Location: index.php?page=data_collections'); exit; }
    if (!in_array($loc, dcRequestLocationIds($id), true)) {
        flash('error', 'That location is not part of this task.');
        header("Location: index.php?page=data_collection&id={$id}"); exit;
    }
    $subs = dcSubmissions($id);
    $sub  = $subs[$loc] ?? null;
    if (!dcCanEditSubmission($req, $loc, $sub)) {
        flash('error', ($req['status'] !== 'open')
            ? 'This task is closed.'
            : 'This submission is confirmed and can no longer be changed. Ask Operations to reopen it.');
        header("Location: {$back}"); exit;
    }

    $answer = trim($_POST['answer_text'] ?? '');
    if (mb_strlen($answer) > DC_MAX_ANSWER) $answer = mb_substr($answer, 0, DC_MAX_ANSWER);

    $mine     = dcMyLocations();
    $onBehalf = isset($mine[$loc]) ? 0 : 1;

    // ── Files ──
    $saved = 0; $skipped = [];
    if (!empty($_FILES['files']['name']) && is_array($_FILES['files']['name'])) {
        $dir = dcFileDir($id);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (!is_dir($dir) || !is_writable($dir)) {
            flash('error', 'Upload directory is not writable.');
            header("Location: {$back}"); exit;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $ins   = getDb()->prepare(
            'INSERT INTO dc_files
                (request_id, location_id, original_name, stored_name, mime_type, size_bytes, uploaded_by, on_behalf)
             VALUES (?,?,?,?,?,?,?,?)');
        $n = min(count($_FILES['files']['name']), DC_MAX_FILES);
        for ($i = 0; $i < $n; $i++) {
            $err = $_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($err === UPLOAD_ERR_NO_FILE) continue;
            $orig = basename((string)$_FILES['files']['name'][$i]);
            if ($err !== UPLOAD_ERR_OK) { $skipped[] = "{$orig} (upload error {$err})"; continue; }
            if ((int)$_FILES['files']['size'][$i] > DC_MAX_BYTES) {
                $skipped[] = "{$orig} (over " . (DC_MAX_BYTES / 1024 / 1024) . ' MB)'; continue;
            }
            $ext = mb_strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            $ok  = DC_ALLOWED_MIME[$ext] ?? null;
            if (!$ok) { $skipped[] = "{$orig} (.{$ext} not accepted)"; continue; }
            $mime = $finfo->file((string)$_FILES['files']['tmp_name'][$i]) ?: 'application/octet-stream';
            if (!in_array($mime, $ok, true)) { $skipped[] = "{$orig} (not a real .{$ext})"; continue; }

            $stored = uniqid('dc_', true) . '.' . $ext;
            if (!move_uploaded_file((string)$_FILES['files']['tmp_name'][$i], $dir . $stored)) {
                $skipped[] = "{$orig} (could not be saved)"; continue;
            }
            try {
                $ins->execute([$id, $loc, mb_substr($orig, 0, 255), $stored, $mime,
                               (int)$_FILES['files']['size'][$i], myCode(), $onBehalf]);
                $saved++;
            } catch (Exception $e) {
                @unlink($dir . $stored);      // no orphan on disk
                $skipped[] = "{$orig} (" . $e->getMessage() . ')';
            }
        }
    }

    // A save that adds nothing and says nothing is a mistake, not a submission.
    $existingFiles = count(dcFilesByLocation($id)[$loc] ?? []);
    if ($saved === 0 && $answer === '' && $existingFiles === 0) {
        flash('error', 'Attach a file or write an answer before saving.'
            . ($skipped ? ' Skipped: ' . implode('; ', $skipped) : ''));
        header("Location: {$back}"); exit;
    }

    // The answer, and the draft row it lives on. status is deliberately not
    // touched on update: a manager correcting an already-confirmed
    // submission leaves it confirmed — handing it back is what Reopen does.
    try {
        getDb()->prepare(
            'INSERT INTO dc_submissions (request_id, location_id, answer_text, status, updated_by, updated_at, on_behalf)
             VALUES (?,?,?,?,?,NOW(),?)
             ON DUPLICATE KEY UPDATE answer_text = VALUES(answer_text),
                                     updated_by  = VALUES(updated_by),
                                     updated_at  = NOW(),
                                     on_behalf   = IF(VALUES(on_behalf) = 1, 1, on_behalf)')
            ->execute([$id, $loc, ($answer === '' ? null : $answer), 'draft', myCode(), $onBehalf]);
    } catch (Exception $e) {
        flash('error', 'Files saved but the answer could not be: ' . $e->getMessage());
        header("Location: {$back}"); exit;
    }

    $msg = 'Saved as a draft'
         . ($saved ? " — {$saved} file(s) added." : '.')
         . ' Press "Confirm submission" when this location is finished.';
    if ($skipped) $msg .= ' Skipped: ' . implode('; ', $skipped) . '.';
    flash($skipped ? 'error' : 'success', $msg);
    header("Location: {$back}"); exit;
}

// ── Handler: confirm — the lock ─────────────────────────
function doDcConfirm(): void {
    $id  = (int)($_POST['request_id'] ?? 0);
    $loc = (int)($_POST['location_id'] ?? 0);
    $back = 'index.php?page=data_collection&id=' . $id . '&loc=' . $loc;

    $req = dcRequest($id);
    if (!$req) { flash('error', 'Task not found.'); header('Location: index.php?page=data_collections'); exit; }
    if (!in_array($loc, dcRequestLocationIds($id), true)) {
        flash('error', 'That location is not part of this task.');
        header("Location: index.php?page=data_collection&id={$id}"); exit;
    }
    $subs = dcSubmissions($id);
    $sub  = $subs[$loc] ?? null;
    if (!dcCanEditSubmission($req, $loc, $sub)) {
        flash('error', 'This submission can no longer be changed.');
        header("Location: {$back}"); exit;
    }

    $files  = dcFilesByLocation($id)[$loc] ?? [];
    $answer = trim((string)($sub['answer_text'] ?? ''));
    if ((int)$req['requires_file'] === 1 && !$files) {
        flash('error', 'This task asks for a file — attach one before confirming.');
        header("Location: {$back}"); exit;
    }
    if (!$files && $answer === '') {
        flash('error', 'Nothing to confirm — attach a file or write an answer first.');
        header("Location: {$back}"); exit;
    }

    $mine     = dcMyLocations();
    $onBehalf = isset($mine[$loc]) ? 0 : 1;
    try {
        getDb()->prepare(
            'INSERT INTO dc_submissions (request_id, location_id, status, updated_by, updated_at,
                                         confirmed_by, confirmed_at, on_behalf)
             VALUES (?,?,?,?,NOW(),?,NOW(),?)
             ON DUPLICATE KEY UPDATE status       = VALUES(status),
                                     confirmed_by = VALUES(confirmed_by),
                                     confirmed_at = NOW(),
                                     updated_at   = NOW(),
                                     on_behalf    = IF(VALUES(on_behalf) = 1, 1, on_behalf)')
            ->execute([$id, $loc, 'submitted', myCode(), myCode(), $onBehalf]);
        $names = dcLocationNames();
        flash('success', 'Submission confirmed for ' . ($names[$loc] ?? ('#' . $loc))
            . ' — it is locked now. Ask Operations if something needs changing.');
    } catch (Exception $e) {
        flash('error', 'Could not confirm: ' . $e->getMessage());
    }
    header("Location: {$back}"); exit;
}

// ── Handler: reopen one location's submission ───────────
function doDcReopen(): void {
    $id  = (int)($_POST['request_id'] ?? 0);
    $loc = (int)($_POST['location_id'] ?? 0);
    $back = 'index.php?page=data_collection&id=' . $id;
    if (!dcCanManage()) {
        flash('error', 'Only Operations can reopen a confirmed submission.');
        header("Location: {$back}"); exit;
    }
    getDb()->prepare('UPDATE dc_submissions
                         SET status = ?, reopened_by = ?, reopened_at = NOW()
                       WHERE request_id = ? AND location_id = ?')
           ->execute(['draft', myCode(), $id, $loc]);
    $names = dcLocationNames();
    flash('success', 'Reopened for ' . ($names[$loc] ?? ('#' . $loc)) . ' — that location can edit and confirm again.');
    header("Location: {$back}"); exit;
}

// ── Handler: remove one file ────────────────────────────
function doDcDeleteFile(): void {
    $fileId = (int)($_POST['file_id'] ?? 0);
    $row    = dcFileRow($fileId);
    if (!$row) { flash('error', 'File not found.'); header('Location: index.php?page=data_collections'); exit; }
    $id   = (int)$row['request_id'];
    $loc  = (int)$row['location_id'];
    $back = 'index.php?page=data_collection&id=' . $id . '&loc=' . $loc;

    $req  = dcRequest($id);
    $subs = dcSubmissions($id);
    if (!$req || !dcCanEditSubmission($req, $loc, $subs[$loc] ?? null)) {
        flash('error', 'This submission is confirmed or the task is closed — the file cannot be removed.');
        header("Location: {$back}"); exit;
    }
    $path = dcFilePath($row);
    if ($path) @unlink($path);
    getDb()->prepare('DELETE FROM dc_files WHERE id = ?')->execute([$fileId]);
    flash('success', 'Removed ' . $row['original_name'] . '.');
    header("Location: {$back}"); exit;
}

// ── Handler: discard — the whole task, permanently ──────
function doDcDiscard(): void {
    $id   = (int)($_POST['request_id'] ?? 0);
    $back = 'index.php?page=data_collection&id=' . $id;
    if (!dcCanManage()) {
        flash('error', 'You do not have permission to discard a collection task.');
        header("Location: {$back}"); exit;
    }
    $req = dcRequest($id);
    if (!$req) { flash('error', 'Task not found.'); header('Location: index.php?page=data_collections'); exit; }

    if (mb_strtoupper(trim($_POST['confirm_text'] ?? '')) !== 'DISCARD') {
        flash('error', 'Type DISCARD to confirm — nothing was deleted.');
        header("Location: {$back}"); exit;
    }

    $files = 0; $bytes = 0;
    foreach (dcFilesByLocation($id) as $rows) {
        foreach ($rows as $r) {
            $p = dcFilePath($r);
            if ($p) { $bytes += (int)filesize($p); @unlink($p); }
            $files++;
        }
    }
    // Anything left in the folder (a file whose row went missing) goes too,
    // then the folder itself — this task must leave nothing behind.
    $dir = dcFileDir($id);
    if (is_dir($dir)) {
        foreach ((array)scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            if (is_file($dir . $f)) @unlink($dir . $f);
        }
        @rmdir($dir);
    }
    // Children go with it: dc_request_locations, dc_files and
    // dc_submissions are all ON DELETE CASCADE.
    getDb()->prepare('DELETE FROM dc_requests WHERE id = ?')->execute([$id]);

    flash('success', 'Discarded "' . $req['title'] . '" — ' . $files . ' file(s), '
        . dcFormatBytes($bytes) . ' erased from the server. Nothing was kept.');
    header('Location: index.php?page=data_collections'); exit;
}

function dcFormatBytes(int $b): string {
    if ($b >= 1048576) return round($b / 1048576, 1) . ' MB';
    if ($b >= 1024)    return round($b / 1024) . ' KB';
    return $b . ' B';
}

// ── Download: one file ──────────────────────────────────
function dcServeFile(): void {
    $row = dcFileRow((int)($_GET['id'] ?? 0));
    if (!$row) { http_response_code(404); echo 'Not found'; return; }
    $loc = (int)$row['location_id'];
    $mine = dcMyLocations();
    if (!dcCanManage() && !isset($mine[$loc])) { http_response_code(403); echo 'Not allowed'; return; }
    $path = dcFilePath($row);
    if (!$path) { http_response_code(404); echo 'File missing'; return; }

    header('Content-Type: ' . ($row['mime_type'] ?: 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', (string)$row['original_name']) . '"');
    header('Content-Length: ' . (int)filesize($path));
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

// ── The answers sheet, shared by the ZIP and the CSV export ──
// Every targeted outlet gets a row, the silent ones included — "who did
// not answer" is half of what this sheet is read for. The question is the
// answer column's header, so the file reads as a finished sheet.
function dcAnswersCsvString(array $req, array $locIds): string {
    $names  = dcLocationNames();
    $subs   = dcSubmissions((int)$req['id']);
    $byLoc  = dcFilesByLocation((int)$req['id']);
    $out    = fopen('php://temp', 'r+');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Task', $req['title']], escape: '');
    fputcsv($out, ['Downloaded', date('d M Y H:i')], escape: '');
    fputcsv($out, [], escape: '');
    fputcsv($out, ['Location', 'Status', dcQuestionLabel($req), 'Filed by', 'When', 'Files'], escape: '');

    foreach ($locIds as $lid) {
        $sub   = $subs[$lid] ?? null;
        $files = $byLoc[$lid] ?? [];
        $state = dcLocationState($sub, count($files));
        $who   = (string)($sub['confirmed_name'] ?? $sub['confirmed_by'] ?? $sub['updated_name'] ?? $sub['updated_by'] ?? '');
        if ($who !== '' && (int)($sub['on_behalf'] ?? 0) === 1) $who .= ' (on behalf)';
        $when  = (string)($sub['confirmed_at'] ?? $sub['updated_at'] ?? '');
        fputcsv($out, [
            $names[$lid] ?? ('#' . $lid),
            dcStateLabel($state),
            (string)($sub['answer_text'] ?? ''),
            $who,
            $when !== '' ? date('d M Y H:i', strtotime($when)) : '',
            count($files),
        ], escape: '');
    }
    rewind($out);
    $csv = (string)stream_get_contents($out);
    fclose($out);
    return $csv;
}

// Scope check shared by both downloads: managers take everything, a store
// user only its own outlet.
function dcDownloadScope(array $req): ?array {
    $targets = dcRequestLocationIds((int)$req['id']);
    $one     = (int)($_GET['loc'] ?? 0);
    $mine    = dcMyLocations();
    if (dcCanManage()) {
        return $one > 0 ? (in_array($one, $targets, true) ? [$one] : null) : $targets;
    }
    $allowed = array_values(array_intersect($targets, array_keys($mine)));
    if (!$allowed) return null;
    return $one > 0 ? (in_array($one, $allowed, true) ? [$one] : null) : $allowed;
}

function dcZipAvailable(): bool {
    return class_exists('ZipArchive');
}

// ── Download: everything, as one ZIP ────────────────────
// One folder per location, each file under the name the outlet gave it,
// plus answers.csv at the root.
function dcDownloadZip(): void {
    $req = dcRequest((int)($_GET['id'] ?? 0));
    if (!$req) { http_response_code(404); echo 'Not found'; return; }
    $locIds = dcDownloadScope($req);
    if ($locIds === null) { http_response_code(403); echo 'Not allowed'; return; }
    if (!dcZipAvailable()) { http_response_code(501); echo 'ZIP is not available on this server.'; return; }

    $names = dcLocationNames();
    $byLoc = dcFilesByLocation((int)$req['id']);

    $tmp = tempnam(sys_get_temp_dir(), 'dcz_');
    $zip = new ZipArchive();
    if ($tmp === false || $zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        http_response_code(500); echo 'Could not build the ZIP.'; return;
    }
    $zip->addFromString('answers.csv', dcAnswersCsvString($req, $locIds));
    foreach ($locIds as $lid) {
        $folder = dcSafeName($names[$lid] ?? ('location-' . $lid));
        $used   = [];
        foreach ($byLoc[$lid] ?? [] as $f) {
            $path = dcFilePath($f);
            if (!$path) continue;
            $zip->addFile($path, $folder . '/' . dcUniqueEntryName((string)$f['original_name'], $used));
        }
    }
    $zip->close();

    // Only a full download counts as "Operations has it" — that is what the
    // discard dialog checks before letting the data go.
    if (count($locIds) === count(dcRequestLocationIds((int)$req['id'])) && dcCanManage()) {
        try {
            getDb()->prepare('UPDATE dc_requests SET downloaded_by = ?, downloaded_at = NOW() WHERE id = ?')
                   ->execute([myCode(), (int)$req['id']]);
        } catch (Exception $e) { /* the download matters more than the stamp */ }
    }

    $file = dcSafeName((string)$req['title']);
    if (count($locIds) === 1) $file .= ' - ' . dcSafeName($names[$locIds[0]] ?? '');
    header('Content-Type: application/zip');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . $file . '.zip"');
    header('Content-Length: ' . (int)filesize($tmp));
    header('Cache-Control: private, no-store');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

// ── Download: just the answers ──────────────────────────
// Needs no zip extension, so a task that only asked a question always has
// a way out of the app.
function dcExportAnswers(): void {
    $req = dcRequest((int)($_GET['id'] ?? 0));
    if (!$req) { http_response_code(404); echo 'Not found'; return; }
    $locIds = dcDownloadScope($req);
    if ($locIds === null) { http_response_code(403); echo 'Not allowed'; return; }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . dcSafeName((string)$req['title']) . ' - answers.csv"');
    header('Cache-Control: private, no-store');
    echo dcAnswersCsvString($req, $locIds);
    exit;
}

// ── Per-location state for the current user, across every task ──
// One query for every outlet this user covers, so the list can show
// "what do I still owe" without a query per row.
// [request_id => [location_id => 'confirmed'|'draft'|'nothing']]
function dcMyStates(): array {
    $mine = array_keys(dcMyLocations());
    if (!$mine || !dcSchemaReady()) return [];
    $in = implode(',', array_fill(0, count($mine), '?'));
    $st = getDb()->prepare(
        "SELECT rl.request_id, rl.location_id, s.status, s.answer_text,
                (SELECT COUNT(*) FROM dc_files f
                  WHERE f.request_id = rl.request_id AND f.location_id = rl.location_id) AS file_count
           FROM dc_request_locations rl
      LEFT JOIN dc_submissions s
             ON s.request_id = rl.request_id AND s.location_id = rl.location_id
          WHERE rl.location_id IN ({$in})");
    $st->execute($mine);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['request_id']][(int)$r['location_id']] =
            dcLocationState(['status' => $r['status'], 'answer_text' => $r['answer_text']], (int)$r['file_count']);
    }
    return $out;
}

// How many open tasks still want something from this user's outlets. The
// sidebar renders on every page, so this is one lean query with its own
// try/catch rather than the schema probe plus the full list.
function dcOutstandingForMe(): int {
    static $n = null;
    if ($n !== null) return $n;
    $n = 0;
    $mine = array_keys(dcMyLocations());
    if (!$mine) return $n;
    $in = implode(',', array_fill(0, count($mine), '?'));
    try {
        $st = getDb()->prepare(
            "SELECT COUNT(DISTINCT rl.request_id)
               FROM dc_request_locations rl
               JOIN dc_requests r ON r.id = rl.request_id AND r.status = 'open'
          LEFT JOIN dc_submissions s
                 ON s.request_id = rl.request_id AND s.location_id = rl.location_id
              WHERE rl.location_id IN ({$in})
                AND (s.status IS NULL OR s.status <> 'submitted')");
        $st->execute($mine);
        $n = (int)$st->fetchColumn();
    } catch (Exception $e) {
        $n = 0;                      // un-migrated database: nothing to nag about
    }
    return $n;
}

// ── Page: the task list ─────────────────────────────────
function pageDataCollections(): void {
    if (!dcSchemaReady()) {
        echo '<div class="alert alert-error">' . h(dcSchemaNotice()) . '</div>';
        return;
    }
    $manage   = dcCanManage();
    $requests = dcRequests();
    $states   = dcMyStates();
    $names    = dcLocationNames();
?>
<div class="page-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <h2 style="margin:0">Data Collection</h2>
    <?php if ($manage): ?>
    <a href="?page=data_collection_new" class="btn btn-primary btn-sm">+ New Task</a>
    <?php endif; ?>
</div>

<p class="text-muted" style="font-size:12px;margin:-4px 0 14px">
    <?= $manage
        ? 'Start a task, pick the outlets, and watch them come in. Download everything as one ZIP, then discard the task — that erases every file from the server.'
        : 'What your location has been asked for. Add your files and answer, then press Confirm submission — after that it is locked.' ?>
</p>

<?php if (!$requests): ?>
<div class="alert alert-info">
    <?= $manage ? 'No collection tasks yet — start one with + New Task.' : 'Nothing has been asked of your location right now.' ?>
</div>
<?php else: ?>
<div class="table-wrap" data-stack>
<table class="table">
    <thead>
        <tr>
            <th>Task</th>
            <th style="width:110px">Due</th>
            <th style="width:130px"><?= $manage ? 'Submitted' : 'Your status' ?></th>
            <th style="width:90px">Status</th>
            <th style="width:80px"></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($requests as $r):
        $rid   = (int)$r['id'];
        $due   = (string)($r['due_date'] ?? '');
        $late  = $due !== '' && $r['status'] === 'open' && $due < date('Y-m-d');
        $mineStates = $states[$rid] ?? [];
        $mineDone   = 0;
        foreach ($mineStates as $s) if ($s === 'confirmed') $mineDone++;
    ?>
        <tr<?= $r['status'] === 'closed' ? ' style="opacity:.65"' : '' ?>>
            <td>
                <a href="?page=data_collection&id=<?= $rid ?>" style="font-weight:600"><?= h($r['title']) ?></a>
                <?php if (trim((string)($r['question'] ?? '')) !== ''): ?>
                <div class="text-muted" style="font-size:11px;margin-top:2px"><?= h($r['question']) ?></div>
                <?php endif; ?>
                <?php if (!(int)$r['requires_file']): ?>
                <div class="text-muted" style="font-size:11px">Answer only — no file needed</div>
                <?php endif; ?>
            </td>
            <td style="font-size:12px<?= $late ? ';color:var(--red);font-weight:600' : '' ?>">
                <?= $due !== '' ? h(date('d M Y', strtotime($due))) : '<span class="text-muted">—</span>' ?>
            </td>
            <td style="font-size:12px">
                <?php if ($manage): ?>
                    <?php $t = (int)$r['target_count']; $c = (int)$r['confirmed_count']; ?>
                    <span class="badge <?= $c >= $t && $t > 0 ? 'badge-green' : ($c > 0 ? 'badge-yellow' : 'badge-grey') ?>">
                        <?= $c ?>/<?= $t ?>
                    </span>
                    <span class="text-muted" style="margin-left:4px"><?= (int)$r['file_count'] ?> file(s)</span>
                <?php elseif (count($mineStates) === 1): ?>
                    <?= dcStateBadge((string)reset($mineStates)) ?>
                <?php else: ?>
                    <span class="badge <?= $mineDone === count($mineStates) ? 'badge-green' : ($mineDone > 0 ? 'badge-yellow' : 'badge-grey') ?>">
                        <?= $mineDone ?>/<?= count($mineStates) ?> confirmed
                    </span>
                <?php endif; ?>
            </td>
            <td><span class="badge <?= $r['status'] === 'open' ? 'badge-blue' : 'badge-grey' ?>"><?= h(ucfirst((string)$r['status'])) ?></span></td>
            <td class="actions">
                <a href="?page=data_collection&id=<?= $rid ?>" class="btn btn-sm btn-secondary">Open</a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<div class="table-count"><?= count($requests) ?> task(s)</div>
<?php endif;
}

// ── Page: new / edit a task ─────────────────────────────
function pageDataCollectionForm(): void {
    if (!dcSchemaReady()) {
        echo '<div class="alert alert-error">' . h(dcSchemaNotice()) . '</div>';
        return;
    }
    if (!dcCanManage()) {
        echo '<div class="alert alert-error">You do not have permission to start a collection task.</div>';
        return;
    }
    $id  = (int)($_GET['id'] ?? 0);
    $req = $id ? dcRequest($id) : null;
    if ($id && !$req) {
        echo '<div class="alert alert-error">Task not found.</div>';
        return;
    }
    $locations = getActiveLocations();
    // Editing shows the task's own target set; a new task starts with every
    // retail outlet ticked and HO / the factory left out, the same default
    // every cross-location report in the app uses.
    $checked = $req ? dcRequestLocationIds($id) : reportDefaultLocationIds($locations);
    $checked = array_flip($checked);
?>
<div class="page-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px">
    <h2 style="margin:0"><?= $req ? 'Edit Task' : 'New Collection Task' ?></h2>
    <a href="?page=data_collections" class="btn btn-sm btn-ghost">← All tasks</a>
</div>

<form method="POST" class="form-card" style="max-width:none">
    <input type="hidden" name="action" value="dc_save_request">
    <?php if ($req): ?><input type="hidden" name="id" value="<?= (int)$req['id'] ?>"><?php endif; ?>

    <div class="form-grid" style="grid-template-columns:2fr 1fr">
        <div class="form-group">
            <label>Task title <span class="required">*</span></label>
            <input type="text" name="title" class="form-control" maxlength="150" required
                   placeholder="e.g. P&amp;P closing stock 31-08-2026"
                   value="<?= h($req['title'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>Due date</label>
            <input type="date" name="due_date" class="form-control" value="<?= h($req['due_date'] ?? '') ?>">
        </div>
    </div>

    <div class="form-group">
        <label>Question for the location</label>
        <input type="text" name="question" class="form-control" maxlength="255"
               placeholder="e.g. How many boxes are left in the deep freezer?"
               value="<?= h($req['question'] ?? '') ?>">
        <small class="text-muted">Printed above the answer box each location fills in. Leave blank to just ask for a remark.</small>
    </div>

    <div class="form-group">
        <label>Instructions</label>
        <textarea name="instructions" class="form-control" rows="3"
                  placeholder="Anything the outlet needs to know — which report to export, what the photo must show…"><?= h($req['instructions'] ?? '') ?></textarea>
    </div>

    <div class="form-group">
        <label class="checkbox-label" style="display:flex;align-items:center;gap:8px">
            <input type="checkbox" name="requires_file" <?= ($req === null || (int)$req['requires_file'] === 1) ? 'checked' : '' ?>>
            A file must be attached before a location can confirm
        </label>
        <small class="text-muted">Untick it to run a question on its own — the written answer is then the whole submission.</small>
    </div>

    <div class="form-section-title" style="margin-top:12px">Ask these locations <span class="required">*</span></div>
    <div style="margin-bottom:6px">
        <button type="button" class="btn btn-ghost btn-sm" onclick="dcTickAll(true)">Select all</button>
        <button type="button" class="btn btn-ghost btn-sm" onclick="dcTickAll(false)">Clear</button>
    </div>
    <div style="columns:4 200px;column-gap:16px">
        <?php foreach ($locations as $l): $lid = (int)$l['location_id']; ?>
        <label class="checkbox-label" style="font-size:13px;display:flex;padding-top:0;margin-bottom:6px;break-inside:avoid">
            <input type="checkbox" class="dc-loc" name="location_ids[]" value="<?= $lid ?>" <?= isset($checked[$lid]) ? 'checked' : '' ?>>
            <?= h($l['location_name']) ?>
        </label>
        <?php endforeach; ?>
    </div>
    <?php if ($req): ?>
    <small class="text-muted">A location that has already submitted stays on the task even if you untick it — remove its files first.</small>
    <?php endif; ?>

    <div class="form-actions" style="margin-top:14px">
        <button class="btn btn-primary"><?= $req ? 'Save changes' : 'Start task' ?></button>
        <a href="?page=<?= $req ? 'data_collection&id=' . (int)$req['id'] : 'data_collections' ?>" class="btn btn-ghost">Cancel</a>
    </div>
</form>
<script>
function dcTickAll(on){document.querySelectorAll('.dc-loc').forEach(function(c){c.checked=on;});}
</script>
<?php
}

// ── Page: one task ──────────────────────────────────────
function pageDataCollection(): void {
    if (!dcSchemaReady()) {
        echo '<div class="alert alert-error">' . h(dcSchemaNotice()) . '</div>';
        return;
    }
    $id  = (int)($_GET['id'] ?? 0);
    $req = dcRequest($id);
    if (!$req) {
        echo '<div class="alert alert-error">Task not found — it may have been discarded.</div>';
        echo '<a href="?page=data_collections" class="btn btn-sm btn-secondary">← All tasks</a>';
        return;
    }
    $manage  = dcCanManage();
    $targets = dcRequestLocationIds($id);
    $mine    = dcMyLocations();
    $myHere  = array_values(array_intersect($targets, array_keys($mine)));
    if (!$manage && !$myHere) {
        echo '<div class="alert alert-error">This task was not sent to your location.</div>';
        return;
    }

    $names = dcLocationNames();
    $subs  = dcSubmissions($id);
    $byLoc = dcFilesByLocation($id);
    $isOpen = $req['status'] === 'open';

    // Which locations may this user file for, and which one is on screen.
    $submitLocs = $manage ? $targets : $myHere;
    $selected   = (int)($_GET['loc'] ?? 0);
    if (!in_array($selected, $submitLocs, true)) $selected = $submitLocs[0] ?? 0;

    $confirmed = 0;
    foreach ($targets as $lid) {
        if (dcLocationState($subs[$lid] ?? null, count($byLoc[$lid] ?? [])) === 'confirmed') $confirmed++;
    }
    $outstanding = count($targets) - $confirmed;
?>
<div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap">
    <div>
        <h2 style="margin:0 0 4px"><?= h($req['title']) ?></h2>
        <span class="badge <?= $isOpen ? 'badge-blue' : 'badge-grey' ?>"><?= h(ucfirst((string)$req['status'])) ?></span>
        <span class="badge <?= $confirmed >= count($targets) ? 'badge-green' : ($confirmed > 0 ? 'badge-yellow' : 'badge-grey') ?>">
            <?= $confirmed ?>/<?= count($targets) ?> confirmed
        </span>
        <?php if (!empty($req['due_date'])): ?>
        <span class="text-muted" style="font-size:12px;margin-left:6px">Due <?= h(date('d M Y', strtotime((string)$req['due_date']))) ?></span>
        <?php endif; ?>
    </div>
    <a href="?page=data_collections" class="btn btn-sm btn-ghost">← All tasks</a>
</div>

<?php if (trim((string)($req['instructions'] ?? '')) !== ''): ?>
<div class="alert alert-info" style="white-space:pre-wrap"><?= h($req['instructions']) ?></div>
<?php endif; ?>

<?php if ($manage): ?>
<div class="table-wrap" style="padding:12px;margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <?php if (dcZipAvailable()): ?>
    <a href="?page=dc_download_zip&id=<?= $id ?>" class="btn btn-primary btn-sm">
        Download all (ZIP)<?= $outstanding > 0 ? ' — ' . $outstanding . ' still to come' : '' ?>
    </a>
    <?php else: ?>
    <span class="text-muted" style="font-size:12px">
        ZIP is not available on this server — download each file from the board below.
    </span>
    <?php endif; ?>
    <a href="?page=dc_export_answers&id=<?= $id ?>" class="btn btn-secondary btn-sm">Download answers (CSV)</a>
    <a href="?page=data_collection_new&id=<?= $id ?>" class="btn btn-ghost btn-sm">Edit task</a>
    <form method="POST" class="inline-form">
        <input type="hidden" name="action" value="dc_close_request">
        <input type="hidden" name="request_id" value="<?= $id ?>">
        <button class="btn btn-ghost btn-sm"><?= $isOpen ? 'Close task' : 'Reopen task' ?></button>
    </form>
    <button type="button" class="btn btn-danger btn-sm" style="margin-left:auto" onclick="dcOpenDiscard()">Discard task</button>
</div>

<!-- Discard: the one step that cannot be undone -->
<div id="dcDiscardModal" class="dc-modal" onclick="dcCloseDiscard(event)">
    <div class="dc-modal-content">
        <span class="dc-modal-close" onclick="dcCloseDiscard()">&times;</span>
        <h4 style="margin:0 0 10px">Discard "<?= h($req['title']) ?>"?</h4>
        <?php if (empty($req['downloaded_at'])): ?>
        <div class="alert alert-error" style="margin-bottom:10px">
            Nobody has downloaded this task yet. Discarding now loses every file and answer for good.
        </div>
        <?php else: ?>
        <p class="text-muted" style="font-size:12px;margin:0 0 10px">
            Last downloaded <?= h(date('d M Y H:i', strtotime((string)$req['downloaded_at']))) ?>.
        </p>
        <?php endif; ?>
        <p style="font-size:13px;margin:0 0 10px">
            This erases every uploaded file from the server and deletes the task, its answers and its
            submission history. Nothing is kept and it cannot be undone.
        </p>
        <form method="POST">
            <input type="hidden" name="action" value="dc_discard">
            <input type="hidden" name="request_id" value="<?= $id ?>">
            <div class="form-group">
                <label>Type <strong>DISCARD</strong> to confirm</label>
                <input type="text" name="confirm_text" class="form-control" autocomplete="off" placeholder="DISCARD">
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px">
                <button type="button" class="btn btn-secondary" onclick="dcCloseDiscard()">Cancel</button>
                <button type="submit" class="btn btn-danger">Discard permanently</button>
            </div>
        </form>
    </div>
</div>
<style>
.dc-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:9999;align-items:center;justify-content:center;padding:16px}
.dc-modal.active{display:flex}
.dc-modal-content{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:16px;max-width:min(460px,92vw);max-height:90vh;overflow:auto;position:relative}
.dc-modal-close{position:absolute;top:6px;right:12px;font-size:24px;color:var(--muted);cursor:pointer}
</style>
<script>
function dcOpenDiscard(){document.getElementById('dcDiscardModal').classList.add('active');}
function dcCloseDiscard(e){
    var m=document.getElementById('dcDiscardModal');
    if(!e||e.target===m||e.target.classList.contains('dc-modal-close')) m.classList.remove('active');
}
document.addEventListener('keydown',function(e){
    if(e.key==='Escape'){var m=document.getElementById('dcDiscardModal'); if(m) m.classList.remove('active');}
});
</script>
<?php endif; ?>

<?php
// ── The submit card ──
if ($selected > 0):
    $sub      = $subs[$selected] ?? null;
    $myFiles  = $byLoc[$selected] ?? [];
    $state    = dcLocationState($sub, count($myFiles));
    $canEdit  = dcCanEditSubmission($req, $selected, $sub);
    $onBehalf = !isset($mine[$selected]);
?>
<div class="table-wrap" style="padding:16px;margin-bottom:14px">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px">
        <strong><?= $onBehalf ? 'File on behalf of a location' : 'Your submission' ?></strong>
        <?= dcStateBadge($state) ?>
    </div>

    <?php if (count($submitLocs) > 1): ?>
    <form method="GET" style="margin-bottom:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="page" value="data_collection">
        <input type="hidden" name="id" value="<?= $id ?>">
        <label style="font-size:12px;font-weight:600;color:var(--muted)">Location</label>
        <select name="loc" class="form-control" style="max-width:280px" onchange="this.form.submit()">
            <?php foreach ($submitLocs as $lid):
                $s = dcLocationState($subs[$lid] ?? null, count($byLoc[$lid] ?? [])); ?>
            <option value="<?= $lid ?>" <?= $lid === $selected ? 'selected' : '' ?>>
                <?= h($names[$lid] ?? ('#' . $lid)) ?> — <?= h(dcStateLabel($s)) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <noscript><button class="btn btn-sm btn-secondary">Go</button></noscript>
    </form>
    <?php else: ?>
    <div class="text-muted" style="font-size:12px;margin-bottom:10px"><?= h($names[$selected] ?? ('#' . $selected)) ?></div>
    <?php endif; ?>

    <?php if ($state === 'confirmed'): ?>
    <div class="alert alert-success" style="margin-bottom:10px">
        Confirmed<?= !empty($sub['confirmed_name']) || !empty($sub['confirmed_by'])
            ? ' by ' . h((string)($sub['confirmed_name'] ?: $sub['confirmed_by'])) : '' ?><?=
            !empty($sub['confirmed_at']) ? ' on ' . h(date('d M Y H:i', strtotime((string)$sub['confirmed_at']))) : '' ?>.
        This submission is locked<?= $manage ? ' — reopen it from the board below to change it.' : ' — ask Operations to reopen it if something needs changing.' ?>
    </div>
    <?php elseif (!$isOpen): ?>
    <div class="alert alert-info" style="margin-bottom:10px">This task is closed — no more submissions.</div>
    <?php endif; ?>

    <?php // Files already in
    if ($myFiles): ?>
    <div style="margin-bottom:12px">
        <div style="font-size:12px;font-weight:600;color:var(--muted);margin-bottom:4px">Files sent (<?= count($myFiles) ?>)</div>
        <?php foreach ($myFiles as $f): ?>
        <div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--border)">
            <a href="?page=dc_file&id=<?= (int)$f['id'] ?>" style="flex:1 1 auto;word-break:break-all"><?= h($f['original_name']) ?></a>
            <span class="text-muted" style="font-size:11px;white-space:nowrap"><?= h(dcFormatBytes((int)$f['size_bytes'])) ?></span>
            <?php if ($canEdit): ?>
            <form method="POST" class="inline-form" onsubmit="return confirm('Remove this file?')">
                <input type="hidden" name="action" value="dc_delete_file">
                <input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>">
                <button class="btn btn-ghost btn-sm">Remove</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($canEdit): ?>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="dc_save_draft">
        <input type="hidden" name="request_id" value="<?= $id ?>">
        <input type="hidden" name="location_id" value="<?= $selected ?>">
        <div class="form-group">
            <label>Files<?= (int)$req['requires_file'] ? ' <span class="required">*</span>' : '' ?></label>
            <input type="file" name="files[]" class="form-control" multiple style="width:100%">
            <small class="text-muted">
                Excel, CSV, PDF, Word or photos · up to <?= (int)(DC_MAX_BYTES / 1024 / 1024) ?> MB each ·
                pick several at once<?= (int)$req['requires_file'] ? '' : ' · optional for this task' ?>
            </small>
        </div>
        <div class="form-group">
            <label><?= h(dcQuestionLabel($req)) ?></label>
            <textarea name="answer_text" class="form-control" rows="3" maxlength="<?= DC_MAX_ANSWER ?>"
                      placeholder="Type your answer here"><?= h((string)($sub['answer_text'] ?? '')) ?></textarea>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button type="submit" class="btn btn-secondary">Save draft</button>
        </div>
    </form>
    <form method="POST" style="margin-top:8px"
          onsubmit="return confirm('Confirm this submission? Once confirmed you cannot add, remove or change anything.')">
        <input type="hidden" name="action" value="dc_confirm">
        <input type="hidden" name="request_id" value="<?= $id ?>">
        <input type="hidden" name="location_id" value="<?= $selected ?>">
        <button type="submit" class="btn btn-primary">Confirm submission</button>
        <span class="text-muted" style="font-size:11px;margin-left:6px">Save first — confirming locks what is saved.</span>
    </form>
    <?php else: ?>
    <div class="form-group">
        <label><?= h(dcQuestionLabel($req)) ?></label>
        <div style="white-space:pre-wrap;font-size:13px;padding:8px;border:1px solid var(--border);border-radius:6px">
            <?= trim((string)($sub['answer_text'] ?? '')) !== '' ? h((string)$sub['answer_text']) : '<span class="text-muted">No answer written.</span>' ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; // submit card ?>

<?php if ($manage): ?>
<div class="table-wrap" data-stack>
<table class="table">
    <thead>
        <tr>
            <th style="width:190px">Location</th>
            <th style="width:110px">Status</th>
            <th><?= h(dcQuestionLabel($req)) ?></th>
            <th style="width:240px">Files</th>
            <th style="width:170px">Filed by</th>
            <th style="width:150px"></th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$targets): ?>
        <tr><td colspan="6" class="empty-row">No locations on this task yet — add some with Edit task.</td></tr>
    <?php else: foreach ($targets as $lid):
        $sub   = $subs[$lid] ?? null;
        $files = $byLoc[$lid] ?? [];
        $state = dcLocationState($sub, count($files));
        $who   = (string)($sub['confirmed_name'] ?? '') ?: (string)($sub['confirmed_by'] ?? '');
        if ($who === '') $who = (string)($sub['updated_name'] ?? '') ?: (string)($sub['updated_by'] ?? '');
        $when  = (string)($sub['confirmed_at'] ?? '') ?: (string)($sub['updated_at'] ?? '');
    ?>
        <tr>
            <td><?= h($names[$lid] ?? ('#' . $lid)) ?></td>
            <td><?= dcStateBadge($state) ?></td>
            <td style="white-space:pre-wrap;font-size:12px">
                <?= trim((string)($sub['answer_text'] ?? '')) !== '' ? h((string)$sub['answer_text']) : '<span class="text-muted">—</span>' ?>
            </td>
            <td style="font-size:12px">
                <?php if (!$files): ?>
                <span class="text-muted">—</span>
                <?php else: foreach ($files as $f): ?>
                <div style="display:flex;align-items:center;gap:6px;margin-bottom:2px">
                    <a href="?page=dc_file&id=<?= (int)$f['id'] ?>" style="word-break:break-all"><?= h($f['original_name']) ?></a>
                    <?php if (dcCanEditSubmission($req, $lid, $sub)): ?>
                    <form method="POST" class="inline-form" onsubmit="return confirm('Remove this file?')">
                        <input type="hidden" name="action" value="dc_delete_file">
                        <input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>">
                        <button class="btn btn-ghost btn-sm" style="padding:0 4px">&times;</button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endforeach; endif; ?>
            </td>
            <td style="font-size:11px" class="text-muted">
                <?php if ($who !== ''): ?>
                    <?= h($who) ?><?= (int)($sub['on_behalf'] ?? 0) === 1 ? ' <span class="badge badge-grey">on behalf</span>' : '' ?>
                    <?php if ($when !== ''): ?><br><?= h(date('d M Y H:i', strtotime($when))) ?><?php endif; ?>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td class="actions">
                <?php if ($files && dcZipAvailable()): ?>
                <a href="?page=dc_download_zip&id=<?= $id ?>&loc=<?= $lid ?>" class="btn btn-sm btn-ghost">ZIP</a>
                <?php endif; ?>
                <?php if ($state === 'confirmed' && $isOpen): ?>
                <form method="POST" class="inline-form" onsubmit="return confirm('Reopen this submission so the location can change it?')">
                    <input type="hidden" name="action" value="dc_reopen">
                    <input type="hidden" name="request_id" value="<?= $id ?>">
                    <input type="hidden" name="location_id" value="<?= $lid ?>">
                    <button class="btn btn-sm btn-secondary">Reopen</button>
                </form>
                <?php elseif ($isOpen): ?>
                <a href="?page=data_collection&id=<?= $id ?>&loc=<?= $lid ?>" class="btn btn-sm btn-ghost">File for this</a>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
<div class="table-count"><?= $confirmed ?> of <?= count($targets) ?> location(s) confirmed</div>
<?php endif;
}
