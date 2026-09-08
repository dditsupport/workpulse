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
//   · every outlet uploads its files, writes its answer and presses
//     Submit — one button, and it may keep correcting what it sent,
//   · Operations confirms an outlet's submission, and THAT is the lock:
//     from then on the outlet cannot add, remove or change anything,
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
//     targeted outlet, confirm a submission (locking that outlet out of
//     it) and reopen one, download, discard
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

// What a browser will render inline. A sample photo is worth far more on
// the page than in the downloads folder, so these are shown; a .xlsx is
// still a download.
const DC_RENDERABLE = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

function dcIsImage(?string $mime): bool {
    return in_array((string)$mime, DC_RENDERABLE, true);
}

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
// submit handler, the delete handler and the page. An outlet keeps
// editing its own submission until Operations confirms it — sending the
// file is its whole job, and correcting a wrong file should not need
// anyone's permission. A txn_data_collect holder edits any targeted
// outlet whatever its state, which is what makes filing on behalf, and
// fixing a confirmed submission, work.
function dcCanEditSubmission(array $req, int $locationId, ?array $sub): bool {
    if (($req['status'] ?? '') !== 'open') return false;
    if (dcCanManage()) return true;
    $mine = dcMyLocations();
    if (!isset($mine[$locationId])) return false;
    return (($sub['status'] ?? 'submitted') !== 'confirmed');
}

// 'confirmed' | 'submitted' | 'nothing'.
//   nothing   — this outlet has sent neither a file nor an answer
//   submitted — it has sent something and may still change it
//   confirmed — Operations accepted it; it is locked
function dcLocationState(?array $sub, int $fileCount, bool $hasSubAnswers = false): string {
    if (($sub['status'] ?? '') === 'confirmed') return 'confirmed';
    if ($fileCount > 0 || $hasSubAnswers || trim((string)($sub['answer_text'] ?? '')) !== '') return 'submitted';
    return 'nothing';
}

function dcStateBadge(string $state): string {
    return match ($state) {
        'confirmed' => '<span class="badge badge-green">Confirmed</span>',
        'submitted' => '<span class="badge badge-yellow">Submitted</span>',
        default     => '<span class="badge badge-grey">Not submitted</span>',
    };
}

function dcStateLabel(string $state): string {
    return match ($state) {
        'confirmed' => 'Confirmed',
        'submitted' => 'Submitted',
        default     => 'Not submitted',
    };
}

// "This outlet has sent something", as SQL. Kept in one place because the
// list badge, the sidebar count and the dashboard's pending list must all
// agree with dcLocationState() above. $s is the alias of dc_submissions
// and $rl the alias carrying request_id / location_id.
function dcSubmittedSql(string $s = 's', string $rl = 'rl'): string {
    // A task can ask only sub-questions, so a filled box there is a
    // submission on its own. Left out entirely when those tables are not
    // there yet, so the fragment stays valid SQL on an older database.
    $subAnswers = dcQuestionsReady()
        ? " OR EXISTS (SELECT 1 FROM dc_answers a
                         JOIN dc_questions q ON q.id = a.question_id
                        WHERE q.request_id  = {$rl}.request_id
                          AND a.location_id = {$rl}.location_id
                          AND TRIM(COALESCE(a.answer_text, '')) <> '')"
        : '';
    return "({$s}.status = 'confirmed'
             OR TRIM(COALESCE({$s}.answer_text, '')) <> ''
             OR EXISTS (SELECT 1 FROM dc_files f
                         WHERE f.request_id = {$rl}.request_id
                           AND f.location_id = {$rl}.location_id)
             {$subAnswers})";
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
    // submitted_count is the chase number — who has sent anything at all —
    // and confirmed_count is what Operations has accepted. They are
    // different questions and the list shows both.
    $sent = dcSubmittedSql('s', 's');
    $sql  = "SELECT r.*,
                   (SELECT COUNT(*) FROM dc_request_locations rl WHERE rl.request_id = r.id) AS target_count,
                   (SELECT COUNT(*) FROM dc_submissions s
                     WHERE s.request_id = r.id AND {$sent})                                  AS submitted_count,
                   (SELECT COUNT(*) FROM dc_submissions s
                     WHERE s.request_id = r.id AND s.status = 'confirmed')                   AS confirmed_count,
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

// The task's questions as one numbered series — the main question first,
// then the sub-questions under it. They are all questions to the outlet
// answering them, so they are numbered together rather than the main one
// standing outside the count.
//
// Each entry is ['id' => 0 for the main question else the dc_questions id,
//                'text' => wording, 'no' => 1-based number or 0 when the
//                task asks only one thing and a number would be noise].
function dcQuestionSeries(array $req, array $subQs): array {
    $main = trim((string)($req['question'] ?? ''));

    // No main question, but sub-questions: the real questions carry the
    // numbering and the general box goes last, unnumbered — "1. Answer /
    // remark" ahead of the actual questions would read as nonsense.
    if ($main === '' && $subQs) {
        $out = [];
        foreach ($subQs as $i => $q) {
            $out[] = ['id' => (int)$q['id'], 'text' => (string)$q['question_text'], 'no' => $i + 1];
        }
        $out[] = ['id' => 0, 'text' => 'Any other remark', 'no' => 0];
        return $out;
    }

    $out = [['id' => 0, 'text' => dcQuestionLabel($req), 'no' => 0]];
    foreach ($subQs as $q) {
        $out[] = ['id' => (int)$q['id'], 'text' => (string)$q['question_text'], 'no' => 0];
    }
    // A lone box does not need to be called "1".
    if (count($out) > 1) {
        foreach ($out as $i => $_) $out[$i]['no'] = $i + 1;
    }
    return $out;
}

// The number badge in front of a question, empty for a lone question.
function dcQuestionNo(int $no): string {
    return $no > 0 ? '<span class="dc-q-n">' . $no . '</span>' : '';
}

// Anything a filesystem or a zip entry dislikes, for a folder or file name.
function dcSafeName(string $s): string {
    $s = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $s);
    $s = preg_replace('/[\x00-\x1F\x7F]/', '', $s);
    $s = trim((string)$s, " .\t");
    return $s === '' ? 'unnamed' : mb_substr($s, 0, 120);
}

// Sub-questions arrived after the first release too, so the pair of
// tables is probed on its own: a database with only the earlier
// migrations keeps working with the single answer box.
function dcQuestionsReady(): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        getDb()->query('SELECT 1 FROM dc_questions LIMIT 0')->fetch();
        getDb()->query('SELECT 1 FROM dc_answers LIMIT 0')->fetch();
        $ready = true;
    } catch (Exception $e) {
        $ready = false;
    }
    return $ready;
}

// The task's sub-questions, in the order they were written.
function dcQuestions(int $requestId): array {
    if (!dcQuestionsReady()) return [];
    try {
        $st = getDb()->prepare('SELECT * FROM dc_questions WHERE request_id = ? ORDER BY sort_order, id');
        $st->execute([$requestId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

// [location_id => [question_id => answer_text]] for one task.
function dcAnswers(int $requestId): array {
    if (!dcQuestionsReady()) return [];
    try {
        $st = getDb()->prepare(
            'SELECT a.question_id, a.location_id, a.answer_text
               FROM dc_answers a
               JOIN dc_questions q ON q.id = a.question_id
              WHERE q.request_id = ?');
        $st->execute([$requestId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['location_id']][(int)$r['question_id']] = (string)$r['answer_text'];
        }
        return $out;
    } catch (Exception $e) {
        return [];
    }
}

// Has this outlet written anything into the sub-question boxes? Counts as
// content, so a task that asks only questions can still be submitted.
function dcHasSubAnswers(array $answersForLocation): bool {
    foreach ($answersForLocation as $txt) {
        if (trim((string)$txt) !== '') return true;
    }
    return false;
}

// Sample files arrived after the first release, so the table is probed
// rather than assumed: a database that took only the first migration
// keeps working, minus the sample box. Same idiom as
// txnHasValidationCols() in modules/transactions.php.
function dcSamplesReady(): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        getDb()->query('SELECT 1 FROM dc_samples LIMIT 0')->fetch();
        $ready = true;
    } catch (Exception $e) {
        $ready = false;
    }
    return $ready;
}

// The blank formats this task hands out, oldest first.
function dcSamples(int $requestId): array {
    if (!dcSamplesReady()) return [];
    try {
        $st = getDb()->prepare('SELECT * FROM dc_samples WHERE request_id = ? ORDER BY id');
        $st->execute([$requestId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function dcSampleRow(int $id): ?array {
    if (!dcSamplesReady()) return null;
    $st = getDb()->prepare('SELECT * FROM dc_samples WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// The task's folder on disk, created on demand. Returns null when it
// cannot be written to, which the callers turn into a flash rather than a
// half-saved submission.
function dcEnsureDir(int $requestId): ?string {
    $dir = dcFileDir($requestId);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return (is_dir($dir) && is_writable($dir)) ? $dir : null;
}

// Validate one entry of a $_FILES array-of-files and move it into $dir.
// Returns ['stored','original','mime','size'] or null, appending a plain
// reason to $skipped so the user is told which file was dropped and why.
// Shared by the outlet's submission and the sample files a task ships
// with: same size cap, same allow-list, same "is it really an .xlsx"
// sniff. $prefix keeps the two apart on disk (dc_ / dcs_).
function dcStoreUpload(array $files, int $i, string $dir, string $prefix, array &$skipped): ?array {
    $err = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) return null;
    $orig = basename((string)$files['name'][$i]);
    if ($err !== UPLOAD_ERR_OK) { $skipped[] = "{$orig} (upload error {$err})"; return null; }
    if ((int)$files['size'][$i] > DC_MAX_BYTES) {
        $skipped[] = "{$orig} (over " . (DC_MAX_BYTES / 1024 / 1024) . ' MB)';
        return null;
    }
    $ext = mb_strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $ok  = DC_ALLOWED_MIME[$ext] ?? null;
    if (!$ok) { $skipped[] = "{$orig} (.{$ext} not accepted)"; return null; }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$files['tmp_name'][$i]) ?: 'application/octet-stream';
    if (!in_array($mime, $ok, true)) { $skipped[] = "{$orig} (not a real .{$ext})"; return null; }

    $stored = uniqid($prefix, true) . '.' . $ext;
    if (!move_uploaded_file((string)$files['tmp_name'][$i], $dir . $stored)) {
        $skipped[] = "{$orig} (could not be saved)";
        return null;
    }
    return ['stored' => $stored, 'original' => mb_substr($orig, 0, 255),
            'mime' => $mime, 'size' => (int)$files['size'][$i]];
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

        dcSaveQuestions($id);

        // Sample files ride along with the task form, so a new task can be
        // created and given its format in one go.
        $sampleMsg = dcSaveSamples($id);

        $msg = 'Collection task saved — ' . count($final) . ' location(s) asked.' . $sampleMsg;
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

// Rewrite the task's sub-questions from the form. Rows arrive as parallel
// arrays: sq_id[] carries 0 for a new row and the existing id for one
// being edited, sq_text[] the wording. A row cleared to blank is deleted,
// and its answers go with it — the only way to lose an answer, and it
// takes deliberately emptying the question to do it.
function dcSaveQuestions(int $requestId): void {
    if (!dcQuestionsReady()) return;
    $ids   = array_map('intval', (array)($_POST['sq_id'] ?? []));
    $texts = (array)($_POST['sq_text'] ?? []);
    if (!$ids && !$texts) return;

    $db   = getDb();
    $keep = [];
    $ins  = $db->prepare('INSERT INTO dc_questions (request_id, question_text, sort_order) VALUES (?,?,?)');
    $upd  = $db->prepare('UPDATE dc_questions SET question_text = ?, sort_order = ? WHERE id = ? AND request_id = ?');
    $order = 0;
    foreach ($texts as $i => $raw) {
        $text = trim((string)$raw);
        $qid  = (int)($ids[$i] ?? 0);
        if ($text === '') continue;                    // blank row: drop it
        $order++;
        try {
            if ($qid > 0) {
                $upd->execute([mb_substr($text, 0, 255), $order, $qid, $requestId]);
                $keep[] = $qid;
            } else {
                $ins->execute([$requestId, mb_substr($text, 0, 255), $order]);
                $keep[] = (int)$db->lastInsertId();
            }
        } catch (Exception $e) { /* one bad row must not lose the rest */ }
    }
    // Anything not in the form any more is gone on purpose.
    try {
        $existing = $db->prepare('SELECT id FROM dc_questions WHERE request_id = ?');
        $existing->execute([$requestId]);
        foreach ($existing->fetchAll(PDO::FETCH_COLUMN) as $old) {
            if (!in_array((int)$old, $keep, true)) {
                $db->prepare('DELETE FROM dc_questions WHERE id = ?')->execute([(int)$old]);
            }
        }
    } catch (Exception $e) { }
}

// Store whatever came up in the task form's sample picker. Returns a
// fragment for the caller's flash rather than flashing itself, so saving
// a task stays one message.
function dcSaveSamples(int $requestId): string {
    if (empty($_FILES['samples']['name']) || !is_array($_FILES['samples']['name'])) return '';
    if (!dcSamplesReady()) {
        return ' Sample files were ignored — run migrations/2026-09-09_data_collection_samples.sql.';
    }
    $dir = dcEnsureDir($requestId);
    if ($dir === null) return ' Sample files could not be saved — upload directory not writable.';

    $skipped = []; $saved = 0;
    $ins = getDb()->prepare(
        'INSERT INTO dc_samples (request_id, original_name, stored_name, mime_type, size_bytes, uploaded_by)
         VALUES (?,?,?,?,?,?)');
    $n = min(count($_FILES['samples']['name']), DC_MAX_FILES);
    for ($i = 0; $i < $n; $i++) {
        $f = dcStoreUpload($_FILES['samples'], $i, $dir, 'dcs_', $skipped);
        if ($f === null) continue;
        try {
            $ins->execute([$requestId, $f['original'], $f['stored'], $f['mime'], $f['size'], myCode()]);
            $saved++;
        } catch (Exception $e) {
            @unlink($dir . $f['stored']);
            $skipped[] = $f['original'] . ' (' . $e->getMessage() . ')';
        }
    }
    $msg = $saved ? " {$saved} sample file(s) attached." : '';
    if ($skipped) $msg .= ' Sample skipped: ' . implode('; ', $skipped) . '.';
    return $msg;
}

// ── Handler: remove one sample file ─────────────────────
function doDcDeleteSample(): void {
    $row  = dcSampleRow((int)($_POST['sample_id'] ?? 0));
    if (!$row) { flash('error', 'Sample file not found.'); header('Location: index.php?page=data_collections'); exit; }
    $id   = (int)$row['request_id'];
    $back = 'index.php?page=data_collection_new&id=' . $id;
    if (!dcCanManage()) {
        flash('error', 'You do not have permission to change this task.');
        header("Location: index.php?page=data_collection&id={$id}"); exit;
    }
    $path = dcFileDir($id) . $row['stored_name'];
    if (is_file($path)) @unlink($path);
    getDb()->prepare('DELETE FROM dc_samples WHERE id = ?')->execute([(int)$row['id']]);
    flash('success', 'Removed the sample file ' . $row['original_name'] . '.');
    header("Location: {$back}"); exit;
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

// ── Handler: an outlet submits (files + answer) ─────────
// One handler for one button. The location comes from the POST and is
// re-validated against the caller's rights, so the same code serves a
// store user filing for their own outlet and Operations filing on behalf.
// Submitting again adds files and overwrites the answer — an outlet fixes
// its own mistake without asking anyone, right up until Operations
// confirms it.
function doDcSubmit(): void {
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
            : 'Operations has confirmed this submission — it can no longer be changed. Ask them to reopen it.');
        header("Location: {$back}"); exit;
    }

    $answer = trim($_POST['answer_text'] ?? '');
    if (mb_strlen($answer) > DC_MAX_ANSWER) $answer = mb_substr($answer, 0, DC_MAX_ANSWER);

    $mine     = dcMyLocations();
    $onBehalf = isset($mine[$loc]) ? 0 : 1;

    // ── Files ──
    $saved = 0; $skipped = [];
    if (!empty($_FILES['files']['name']) && is_array($_FILES['files']['name'])) {
        $dir = dcEnsureDir($id);
        if ($dir === null) {
            flash('error', 'Upload directory is not writable.');
            header("Location: {$back}"); exit;
        }
        $ins = getDb()->prepare(
            'INSERT INTO dc_files
                (request_id, location_id, original_name, stored_name, mime_type, size_bytes, uploaded_by, on_behalf)
             VALUES (?,?,?,?,?,?,?,?)');
        $n = min(count($_FILES['files']['name']), DC_MAX_FILES);
        for ($i = 0; $i < $n; $i++) {
            $f = dcStoreUpload($_FILES['files'], $i, $dir, 'dc_', $skipped);
            if ($f === null) continue;
            try {
                $ins->execute([$id, $loc, $f['original'], $f['stored'], $f['mime'],
                               $f['size'], myCode(), $onBehalf]);
                $saved++;
            } catch (Exception $e) {
                @unlink($dir . $f['stored']);      // no orphan on disk
                $skipped[] = $f['original'] . ' (' . $e->getMessage() . ')';
            }
        }
    }

    // ── Sub-question answers ──
    // Each box posts under its own question id. A cleared box clears the
    // stored answer rather than leaving yesterday's text behind.
    $subFilled = false;
    if (dcQuestionsReady() && isset($_POST['sub_answers']) && is_array($_POST['sub_answers'])) {
        $valid = [];
        foreach (dcQuestions($id) as $q) $valid[(int)$q['id']] = true;
        $up = getDb()->prepare(
            'INSERT INTO dc_answers (question_id, location_id, answer_text, updated_by, updated_at)
             VALUES (?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE answer_text = VALUES(answer_text),
                                     updated_by  = VALUES(updated_by),
                                     updated_at  = NOW()');
        foreach ($_POST['sub_answers'] as $qid => $txt) {
            $qid = (int)$qid;
            if (!isset($valid[$qid])) continue;            // not this task's question
            $txt = trim((string)$txt);
            if (mb_strlen($txt) > DC_MAX_ANSWER) $txt = mb_substr($txt, 0, DC_MAX_ANSWER);
            if ($txt !== '') $subFilled = true;
            try {
                $up->execute([$qid, $loc, ($txt === '' ? null : $txt), myCode()]);
            } catch (Exception $e) { /* one bad box must not lose the rest */ }
        }
    }

    // A save that adds nothing and says nothing is a mistake, not a submission.
    $existingFiles = count(dcFilesByLocation($id)[$loc] ?? []);
    $existingSub   = dcHasSubAnswers(dcAnswers($id)[$loc] ?? []);
    if ($saved === 0 && $answer === '' && $existingFiles === 0 && !$subFilled && !$existingSub) {
        flash('error', 'Attach a file or write an answer before submitting.'
            . ($skipped ? ' Skipped: ' . implode('; ', $skipped) : ''));
        header("Location: {$back}"); exit;
    }

    // The answer, and the row it lives on. status is deliberately not
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
            ->execute([$id, $loc, ($answer === '' ? null : $answer), 'submitted', myCode(), $onBehalf]);
    } catch (Exception $e) {
        flash('error', 'Files saved but the answer could not be: ' . $e->getMessage());
        header("Location: {$back}"); exit;
    }

    $msg = 'Submitted'
         . ($saved ? " — {$saved} file(s) added." : '.')
         . ' You can keep changing this until Operations confirms it.';
    if ($skipped) $msg .= ' Skipped: ' . implode('; ', $skipped) . '.';
    flash($skipped ? 'error' : 'success', $msg);
    header("Location: {$back}"); exit;
}

// ── Handler: Operations confirms — the lock ─────────────
// Confirming accepts what an outlet sent and locks that outlet out of it.
// It is a txn_data_collect action, never the outlet's own: a location's
// job is to send the file, and it may correct what it sent until someone
// with the permission says the submission is good.
function doDcConfirm(): void {
    $id  = (int)($_POST['request_id'] ?? 0);
    $loc = (int)($_POST['location_id'] ?? 0);
    $back = 'index.php?page=data_collection&id=' . $id;
    if (!dcCanManage()) {
        flash('error', 'Only Operations can confirm a submission.');
        header("Location: {$back}"); exit;
    }
    $req = dcRequest($id);
    if (!$req) { flash('error', 'Task not found.'); header('Location: index.php?page=data_collections'); exit; }
    if ($req['status'] !== 'open') {
        flash('error', 'This task is closed — reopen it to confirm a submission.');
        header("Location: {$back}"); exit;
    }
    if (!in_array($loc, dcRequestLocationIds($id), true)) {
        flash('error', 'That location is not part of this task.');
        header("Location: {$back}"); exit;
    }

    $subs   = dcSubmissions($id);
    $files    = dcFilesByLocation($id)[$loc] ?? [];
    $answer   = trim((string)($subs[$loc]['answer_text'] ?? ''));
    // A filled sub-question box is an answer like any other.
    $answered = $answer !== '' || dcHasSubAnswers(dcAnswers($id)[$loc] ?? []);
    if ((int)$req['requires_file'] === 1 && !$files) {
        flash('error', 'This task asks for a file and this location has not sent one — nothing to confirm.');
        header("Location: {$back}"); exit;
    }
    if (!$files && !$answered) {
        flash('error', 'This location has sent nothing yet — nothing to confirm.');
        header("Location: {$back}"); exit;
    }

    $names = dcLocationNames();
    if (dcConfirmOne($id, $loc)) {
        flash('success', 'Confirmed ' . ($names[$loc] ?? ('#' . $loc)) . ' — that location can no longer change it.');
    } else {
        flash('error', 'Could not confirm ' . ($names[$loc] ?? ('#' . $loc)) . '.');
    }
    header("Location: {$back}"); exit;
}

// ── Handler: confirm every outlet that has sent something ──
// A drive spans forty-odd outlets; locking them one at a time once the
// ZIP is down is forty clicks. Outlets that sent nothing are untouched,
// so this never marks a silent store as done.
function doDcConfirmAll(): void {
    $id   = (int)($_POST['request_id'] ?? 0);
    $back = 'index.php?page=data_collection&id=' . $id;
    if (!dcCanManage()) {
        flash('error', 'Only Operations can confirm submissions.');
        header("Location: {$back}"); exit;
    }
    $req = dcRequest($id);
    if (!$req) { flash('error', 'Task not found.'); header('Location: index.php?page=data_collections'); exit; }
    if ($req['status'] !== 'open') {
        flash('error', 'This task is closed — reopen it to confirm submissions.');
        header("Location: {$back}"); exit;
    }

    $subs    = dcSubmissions($id);
    $byLoc   = dcFilesByLocation($id);
    $answers = dcAnswers($id);
    $done    = 0;
    foreach (dcRequestLocationIds($id) as $lid) {
        $files = $byLoc[$lid] ?? [];
        if (dcLocationState($subs[$lid] ?? null, count($files),
                            dcHasSubAnswers($answers[$lid] ?? [])) !== 'submitted') continue;
        if ((int)$req['requires_file'] === 1 && !$files) continue;
        if (dcConfirmOne($id, $lid)) $done++;
    }
    flash($done > 0 ? 'success' : 'error', $done > 0
        ? $done . ' submission(s) confirmed and locked.'
        : 'Nothing to confirm — no location has an unconfirmed submission.');
    header("Location: {$back}"); exit;
}

// The write both confirm paths share. on_behalf is left alone here: it
// records who FILED the submission, not who accepted it.
function dcConfirmOne(int $requestId, int $locationId): bool {
    try {
        getDb()->prepare(
            'INSERT INTO dc_submissions (request_id, location_id, status, updated_by, updated_at,
                                         confirmed_by, confirmed_at)
             VALUES (?,?,?,?,NOW(),?,NOW())
             ON DUPLICATE KEY UPDATE status       = VALUES(status),
                                     confirmed_by = VALUES(confirmed_by),
                                     confirmed_at = NOW()')
            ->execute([$requestId, $locationId, 'confirmed', myCode(), myCode()]);
        return true;
    } catch (Exception $e) {
        return false;
    }
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
           ->execute(['submitted', myCode(), $id, $loc]);
    $names = dcLocationNames();
    flash('success', 'Reopened for ' . ($names[$loc] ?? ('#' . $loc)) . ' — that location can change what it sent again.');
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
        flash('error', 'Operations has confirmed this submission, or the task is closed — the file cannot be removed.');
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
    // The task's own sample files go the same way.
    foreach (dcSamples($id) as $r) {
        $p = dcFileDir($id) . $r['stored_name'];
        if (is_file($p)) { $bytes += (int)filesize($p); @unlink($p); }
        $files++;
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

    // Same rule as the sample files: ?inline=1 on a real image is the
    // preview modal asking for it, anything else is a download.
    $inline = !empty($_GET['inline']) && dcIsImage($row['mime_type']);
    header('Content-Type: ' . ($row['mime_type'] ?: 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
         . '; filename="' . str_replace('"', '', (string)$row['original_name']) . '"');
    header('Content-Length: ' . (int)filesize($path));
    header('Cache-Control: private, max-age=300');
    readfile($path);
    exit;
}

// ── Download: the sample file a task hands out ──────────
// Readable by anyone the task was sent to, not just Operations — the
// whole point is that the outlet takes the format and fills it in.
function dcServeSample(): void {
    $row = dcSampleRow((int)($_GET['id'] ?? 0));
    if (!$row) { http_response_code(404); echo 'Not found'; return; }
    $id  = (int)$row['request_id'];
    if (!dcCanManage()) {
        $mine = dcMyLocations();
        $seen = array_intersect(dcRequestLocationIds($id), array_keys($mine));
        if (!$seen) { http_response_code(403); echo 'Not allowed'; return; }
    }
    $path = dcFileDir($id) . $row['stored_name'];
    if (!is_file($path)) { http_response_code(404); echo 'File missing'; return; }

    // ?inline=1 on an image is the page previewing it; everything else is
    // a download. nosniff means a mislabelled file cannot be talked into
    // running as script either way.
    $inline = !empty($_GET['inline']) && dcIsImage($row['mime_type']);
    header('Content-Type: ' . ($row['mime_type'] ?: 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
         . '; filename="' . str_replace('"', '', (string)$row['original_name']) . '"');
    header('Content-Length: ' . (int)filesize($path));
    header('Cache-Control: private, max-age=300');
    readfile($path);
    exit;
}

// ── The answers sheet, shared by the ZIP and the CSV export ──
// Every targeted outlet gets a row, the silent ones included — "who did
// not answer" is half of what this sheet is read for. The question is the
// answer column's header, so the file reads as a finished sheet.
function dcAnswersCsvString(array $req, array $locIds): string {
    $names   = dcLocationNames();
    $subs    = dcSubmissions((int)$req['id']);
    $byLoc   = dcFilesByLocation((int)$req['id']);
    $subQs   = dcQuestions((int)$req['id']);
    $answers = dcAnswers((int)$req['id']);
    $out     = fopen('php://temp', 'r+');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Task', $req['title']], escape: '');
    fputcsv($out, ['Downloaded', date('d M Y H:i')], escape: '');
    fputcsv($out, [], escape: '');
    // Each question is its own column, headed by the question itself and
    // numbered as it is on screen, so the sheet can be read and sorted
    // without going back to the app.
    $series = dcQuestionSeries($req, $subQs);
    $head   = ['Location', 'Status'];
    foreach ($series as $q) {
        $head[] = ((int)$q['no'] > 0 ? $q['no'] . '. ' : '') . $q['text'];
    }
    $head[] = 'Filed by'; $head[] = 'When'; $head[] = 'Files';
    fputcsv($out, $head, escape: '');

    foreach ($locIds as $lid) {
        $sub   = $subs[$lid] ?? null;
        $files = $byLoc[$lid] ?? [];
        $state = dcLocationState($sub, count($files), dcHasSubAnswers($answers[$lid] ?? []));
        $who   = (string)($sub['confirmed_name'] ?? $sub['confirmed_by'] ?? $sub['updated_name'] ?? $sub['updated_by'] ?? '');
        if ($who !== '' && (int)($sub['on_behalf'] ?? 0) === 1) $who .= ' (on behalf)';
        $when  = (string)($sub['confirmed_at'] ?? $sub['updated_at'] ?? '');
        $row = [$names[$lid] ?? ('#' . $lid), dcStateLabel($state)];
        foreach ($series as $q) {
            $row[] = (int)$q['id'] === 0
                ? (string)($sub['answer_text'] ?? '')
                : (string)($answers[$lid][(int)$q['id']] ?? '');
        }
        $row[] = $who;
        $row[] = $when !== '' ? date('d M Y H:i', strtotime($when)) : '';
        $row[] = count($files);
        fputcsv($out, $row, escape: '');
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
// [request_id => [location_id => 'confirmed'|'submitted'|'nothing']]
function dcMyStates(): array {
    $mine = array_keys(dcMyLocations());
    if (!$mine || !dcSchemaReady()) return [];
    $in = implode(',', array_fill(0, count($mine), '?'));
    // Sub-question answers count as a submission, but only ask for them on a
    // database that has the tables.
    $hasSub = dcQuestionsReady()
        ? "EXISTS (SELECT 1 FROM dc_answers a
                     JOIN dc_questions q ON q.id = a.question_id
                    WHERE q.request_id  = rl.request_id
                      AND a.location_id = rl.location_id
                      AND TRIM(COALESCE(a.answer_text, '')) <> '')"
        : '0';
    $st = getDb()->prepare(
        "SELECT rl.request_id, rl.location_id, s.status, s.answer_text,
                (SELECT COUNT(*) FROM dc_files f
                  WHERE f.request_id = rl.request_id AND f.location_id = rl.location_id) AS file_count,
                {$hasSub} AS has_sub
           FROM dc_request_locations rl
      LEFT JOIN dc_submissions s
             ON s.request_id = rl.request_id AND s.location_id = rl.location_id
          WHERE rl.location_id IN ({$in})");
    $st->execute($mine);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['request_id']][(int)$r['location_id']] = dcLocationState(
            ['status' => $r['status'], 'answer_text' => $r['answer_text']],
            (int)$r['file_count'],
            (bool)(int)$r['has_sub']);
    }
    return $out;
}

// How many open tasks this user's outlets have not sent anything for. A
// submission waiting on Operations to confirm is not outstanding — the
// outlet has done its part. The sidebar renders on every page, so this is
// one lean query with its own try/catch rather than the schema probe plus
// the full list.
function dcOutstandingForMe(): int {
    static $n = null;
    if ($n !== null) return $n;
    $n = 0;
    $mine = array_keys(dcMyLocations());
    if (!$mine) return $n;
    $in   = implode(',', array_fill(0, count($mine), '?'));
    $sent = dcSubmittedSql('s', 'rl');
    try {
        $st = getDb()->prepare(
            "SELECT COUNT(DISTINCT rl.request_id)
               FROM dc_request_locations rl
               JOIN dc_requests r ON r.id = rl.request_id AND r.status = 'open'
              WHERE rl.location_id IN ({$in})
                AND NOT EXISTS (SELECT 1 FROM dc_submissions s
                                 WHERE s.request_id  = rl.request_id
                                   AND s.location_id = rl.location_id
                                   AND {$sent})");
        $st->execute($mine);
        $n = (int)$st->fetchColumn();
    } catch (Exception $e) {
        $n = 0;                      // un-migrated database: nothing to nag about
    }
    return $n;
}

// ── The image preview modal ─────────────────────────────
// Rendered by both the task page and the task form. Any element carrying
// data-dc-img opens here: the sample format, a photo an outlet sent, the
// thumbnails on either. Same shape as the Review Punch Request modal in
// modules/punch_requests.php, so the two feel like one app.
function dcRenderImageModal(): void {
?>
<style>
/* Image preview — same shape as the Review Punch Request modal in
   modules/punch_requests.php, so a photo opens where you are looking
   instead of in another tab.
   The dc-pv- prefix is deliberate: the Discard dialog further down this
   file already owns .dc-modal (as its OVERLAY, with display:none), and
   its rules come later in the page, so sharing the name left this box
   hidden behind a darkened screen for anyone who could see both. */
.dc-pv-overlay{position:fixed;inset:0;background:rgba(0,0,0,.78);display:none;z-index:9100;align-items:flex-start;justify-content:center;padding:14px;overflow:auto}
.dc-pv-overlay.open{display:flex}
.dc-pv-box{background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:10px;width:100%;max-width:min(1280px,96vw);max-height:calc(100vh - 28px);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 16px 48px rgba(0,0,0,.6)}
.dc-pv-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 18px;border-bottom:1px solid var(--border)}
.dc-pv-head h3{margin:0;font-size:15px;font-weight:600;word-break:break-all}
.dc-pv-close{background:transparent;border:none;color:var(--muted);font-size:24px;cursor:pointer;line-height:1;padding:0 4px}
.dc-pv-close:hover{color:var(--text)}
.dc-pv-body{padding:14px 18px;overflow:auto;flex:1}
.dc-pv-img{background:#000;border:1px solid var(--border);border-radius:6px;display:flex;align-items:center;justify-content:center;min-height:480px;max-height:78vh;overflow:auto}
.dc-pv-img img{max-width:100%;max-height:78vh;display:block;cursor:zoom-in}
.dc-pv-img img.dc-pv-zoomed{max-height:none;max-width:none;cursor:zoom-out}
.dc-pv-foot{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 18px;border-top:1px solid var(--border);background:rgba(0,0,0,.15);flex-wrap:wrap}
@media(max-width:900px){.dc-pv-img{min-height:320px}}
@media(max-width:560px){.dc-pv-img{min-height:240px}}
</style>
<!-- ── Image preview, shared by every photo on the page ── -->
<div class="dc-pv-overlay" id="dcOverlay" role="dialog" aria-modal="true" aria-labelledby="dcImgTitle">
    <div class="dc-pv-box">
        <div class="dc-pv-head">
            <h3 id="dcImgTitle"></h3>
            <button type="button" class="dc-pv-close" aria-label="Close" onclick="dcImgClose()">&times;</button>
        </div>
        <div class="dc-pv-body"><div class="dc-pv-img" id="dcImgWrap"></div></div>
        <div class="dc-pv-foot">
            <div class="text-muted" style="font-size:12px" id="dcImgMeta"></div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <button type="button" class="btn btn-ghost" onclick="dcImgClose()">Close</button>
                <a id="dcImgFull" class="btn btn-secondary" href="#" target="_blank" rel="noopener" style="padding:4px 12px">Open Original</a>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var overlay = document.getElementById('dcOverlay');
    if (!overlay) return;
    var wrap = document.getElementById('dcImgWrap');

    // Anything carrying data-dc-img opens here — the sample format, a
    // photo an outlet sent, the thumbnails on both.
    document.addEventListener('click', function (e) {
        var el = e.target.closest ? e.target.closest('[data-dc-img]') : null;
        if (!el) return;
        e.preventDefault();
        document.getElementById('dcImgTitle').textContent = el.getAttribute('data-dc-name') || 'Photo';
        document.getElementById('dcImgMeta').textContent  = el.getAttribute('data-dc-meta') || '';
        document.getElementById('dcImgFull').setAttribute('href', el.getAttribute('data-dc-full') || '#');
        wrap.innerHTML = '';
        var img = document.createElement('img');
        img.src = el.getAttribute('data-dc-img');
        img.alt = el.getAttribute('data-dc-name') || '';
        img.title = 'Click to zoom';
        img.addEventListener('click', function () { img.classList.toggle('dc-pv-zoomed'); });
        wrap.appendChild(img);
        overlay.classList.add('open');
    });

    window.dcImgClose = function () {
        overlay.classList.remove('open');
        wrap.innerHTML = '';                     // stop the browser holding the bytes
    };
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('open')) dcImgClose();
    });
    overlay.addEventListener('click', function (e) { if (e.target === overlay) dcImgClose(); });
})();
</script>

<?php
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
        : 'What your location has been asked for. Add your files, write your answer and press Submit. You can change it until Operations confirms it.' ?>
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
        // For an outlet, "done" is having sent it — the confirm is not theirs.
        foreach ($mineStates as $s) if ($s !== 'nothing') $mineDone++;
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
                    <?php $t = (int)$r['target_count'];
                          $sent = (int)$r['submitted_count'];
                          $c = (int)$r['confirmed_count']; ?>
                    <span class="badge <?= $sent >= $t && $t > 0 ? 'badge-green' : ($sent > 0 ? 'badge-yellow' : 'badge-grey') ?>">
                        <?= $sent ?>/<?= $t ?> submitted
                    </span>
                    <div class="text-muted" style="font-size:11px;margin-top:2px">
                        <?= $c ?> confirmed · <?= (int)$r['file_count'] ?> file(s)
                    </div>
                <?php elseif (count($mineStates) === 1): ?>
                    <?= dcStateBadge((string)reset($mineStates)) ?>
                <?php else: ?>
                    <span class="badge <?= $mineDone === count($mineStates) ? 'badge-green' : ($mineDone > 0 ? 'badge-yellow' : 'badge-grey') ?>">
                        <?= $mineDone ?>/<?= count($mineStates) ?> submitted
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

<form method="POST" class="form-card" style="max-width:none" enctype="multipart/form-data">
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

    <?php if (dcQuestionsReady()): $subQs = $req ? dcQuestions((int)$req['id']) : []; ?>
    <div class="form-group">
        <label>Sub-questions</label>
        <small class="text-muted" style="display:block;margin-bottom:6px">
            Break a long ask into separate questions — each one gets its own answer box for the
            location to fill in, and its own column in the answers sheet. Clear a line to delete it.
        </small>
        <div id="dcSubQs">
            <?php foreach ($subQs as $n => $q): ?>
            <div class="dc-sq-row" style="display:flex;gap:6px;align-items:center;margin-bottom:6px">
                <span class="text-muted" style="font-size:12px;width:18px;text-align:right"><?= $n + 1 ?>.</span>
                <input type="hidden" name="sq_id[]" value="<?= (int)$q['id'] ?>">
                <input type="text" name="sq_text[]" class="form-control" maxlength="255" style="flex:1 1 auto"
                       value="<?= h($q['question_text']) ?>">
                <button type="button" class="btn btn-ghost btn-sm" onclick="dcDropSq(this)">&times;</button>
            </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-ghost btn-sm" onclick="dcAddSq()">+ Add a question</button>
    </div>
    <?php endif; ?>

    <div class="form-group">
        <label>Instructions</label>
        <textarea name="instructions" class="form-control" rows="3"
                  placeholder="Anything the outlet needs to know — which report to export, what the photo must show…"><?= h($req['instructions'] ?? '') ?></textarea>
    </div>

    <div class="form-group">
        <label>Sample / format file</label>
        <?php if (dcSamplesReady()): ?>
        <?php $samples = $req ? dcSamples((int)$req['id']) : []; ?>
        <?php if ($samples): ?>
        <div style="margin-bottom:6px">
            <?php foreach ($samples as $sf): ?>
            <div style="display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid var(--border)">
                <?php if (dcIsImage($sf['mime_type'])): ?>
                <a href="?page=dc_sample&id=<?= (int)$sf['id'] ?>"
                   data-dc-img="?page=dc_sample&id=<?= (int)$sf['id'] ?>&inline=1"
                   data-dc-full="?page=dc_sample&id=<?= (int)$sf['id'] ?>&inline=1"
                   data-dc-name="<?= h($sf['original_name']) ?>" data-dc-meta="Sample / format file">
                    <img src="?page=dc_sample&id=<?= (int)$sf['id'] ?>&inline=1" alt="" title="Click to view"
                         style="height:38px;width:38px;object-fit:cover;border-radius:4px;border:1px solid var(--border);cursor:zoom-in;display:block">
                </a>
                <?php endif; ?>
                <a href="?page=dc_sample&id=<?= (int)$sf['id'] ?>" style="flex:1 1 auto;word-break:break-all"><?= h($sf['original_name']) ?></a>
                <span class="text-muted" style="font-size:11px;white-space:nowrap"><?= h(dcFormatBytes((int)$sf['size_bytes'])) ?></span>
                <button type="button" class="btn btn-ghost btn-sm"
                        value="<?= (int)$sf['id'] ?>" onclick="dcDelSample(this)">Remove</button>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <input type="file" name="samples[]" class="form-control" multiple style="width:100%">
        <small class="text-muted">
            Optional. The blank sheet, example photo or instruction PDF each location downloads,
            fills in and sends back — so 41 outlets return the same shape instead of 41 layouts.
        </small>
        <?php else: ?>
        <small class="text-muted">
            Sample files need migrations/2026-09-09_data_collection_samples.sql — everything else works without it.
        </small>
        <?php endif; ?>
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
<?php dcRenderImageModal(); ?>
<script>
function dcTickAll(on){document.querySelectorAll('.dc-loc').forEach(function(c){c.checked=on;});}
// Sub-question rows. A new row carries id 0; the server tells new from
// edited by that, and treats a row left blank as deleted.
function dcAddSq(){
    var box=document.getElementById('dcSubQs');
    var row=document.createElement('div');
    row.className='dc-sq-row';
    row.style.cssText='display:flex;gap:6px;align-items:center;margin-bottom:6px';
    row.innerHTML='<span class="text-muted" style="font-size:12px;width:18px;text-align:right"></span>'
      +'<input type="hidden" name="sq_id[]" value="0">'
      +'<input type="text" name="sq_text[]" class="form-control" maxlength="255" style="flex:1 1 auto" '
      +'placeholder="e.g. How many ACs are in the outlet?">'
      +'<button type="button" class="btn btn-ghost btn-sm" onclick="dcDropSq(this)">&times;</button>';
    box.appendChild(row);
    dcNumberSq();
    row.querySelector('input[type=text]').focus();
}
function dcDropSq(btn){ btn.closest('.dc-sq-row').remove(); dcNumberSq(); }
function dcNumberSq(){
    document.querySelectorAll('#dcSubQs .dc-sq-row').forEach(function(r,i){
        r.querySelector('span').textContent=(i+1)+'.';
    });
}
// Removing a sample must not carry the whole task form with it (and must
// not nest a form inside one), so it posts its own.
function dcDelSample(btn){
    if(!confirm('Remove this sample file?')) return;
    var f=document.createElement('form');
    f.method='POST'; f.action='index.php';
    f.innerHTML='<input type="hidden" name="action" value="dc_delete_sample">'
              + '<input type="hidden" name="sample_id" value="'+parseInt(btn.value,10)+'">';
    document.body.appendChild(f); f.submit();
}
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

    $names   = dcLocationNames();
    $subs    = dcSubmissions($id);
    $byLoc   = dcFilesByLocation($id);
    $subQs   = dcQuestions($id);
    $answers = dcAnswers($id);
    $isOpen  = $req['status'] === 'open';

    // Which locations may this user file for, and which one is on screen.
    $submitLocs = $manage ? $targets : $myHere;
    $selected   = (int)($_GET['loc'] ?? 0);
    if (!in_array($selected, $submitLocs, true)) $selected = $submitLocs[0] ?? 0;
    // Operations filing for an outlet that is not theirs gets the form in a
    // window rather than inline; an outlet's own submission stays on the page.
    $inWindow = $manage && $selected > 0 && !isset($mine[$selected]);

    // Two different numbers: who has sent anything (the chase), and what
    // Operations has accepted (the lock).
    $confirmed = 0; $sentHere = 0;
    foreach ($targets as $lid) {
        $st = dcLocationState($subs[$lid] ?? null, count($byLoc[$lid] ?? []),
                              dcHasSubAnswers($answers[$lid] ?? []));
        if ($st === 'confirmed') $confirmed++;
        if ($st !== 'nothing')   $sentHere++;
    }
    $outstanding = count($targets) - $sentHere;   // still to send
?>
<style>
/* A question has to read as a question, not as a caption under the file
   hint — that is exactly how the first version lost it. */
.dc-q{font-size:13px;font-weight:600;color:var(--text);line-height:1.45;margin-bottom:6px;
      padding:7px 10px;background:rgba(26,143,227,.10);border-left:3px solid var(--accent);border-radius:0 5px 5px 0}
.dc-q-n{display:inline-block;min-width:18px;color:var(--accent);font-weight:700}
.dc-a{white-space:pre-wrap;font-size:13px;padding:8px 10px;border:1px solid var(--border);border-radius:6px}
.dc-sample-thumb{max-height:150px;max-width:100%;border:1px solid var(--border);border-radius:6px;display:block;cursor:zoom-in}
/* The stacked-table rule in styles.php sets tr{display:block} on narrow
   screens, which outranks the browser's own [hidden] — so a filtered-out
   row would still show on a phone without this. */
tr.dc-row[hidden]{display:none!important}
</style>
<div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap">
    <div>
        <h2 style="margin:0 0 4px"><?= h($req['title']) ?></h2>
        <span class="badge <?= $isOpen ? 'badge-blue' : 'badge-grey' ?>"><?= h(ucfirst((string)$req['status'])) ?></span>
        <span class="badge <?= $sentHere >= count($targets) ? 'badge-green' : ($sentHere > 0 ? 'badge-yellow' : 'badge-grey') ?>">
            <?= $sentHere ?>/<?= count($targets) ?> submitted
        </span>
        <?php if ($confirmed > 0): ?>
        <span class="text-muted" style="font-size:12px"><?= $confirmed ?> confirmed</span>
        <?php endif; ?>
        <?php if (!empty($req['due_date'])): ?>
        <span class="text-muted" style="font-size:12px;margin-left:6px">Due <?= h(date('d M Y', strtotime((string)$req['due_date']))) ?></span>
        <?php endif; ?>
    </div>
    <a href="?page=data_collections" class="btn btn-sm btn-ghost">← All tasks</a>
</div>

<?php if (trim((string)($req['instructions'] ?? '')) !== ''): ?>
<div class="alert alert-info">
    <div style="font-weight:600;margin-bottom:4px">Instructions</div>
    <div style="white-space:pre-wrap"><?= h($req['instructions']) ?></div>
</div>
<?php endif; ?>

<?php // The blank format the task hands out. Shown to everyone the task
      // reached, above their own upload box, because taking this file and
      // filling it in is the first step of answering.
$samples = dcSamples($id);
if ($samples): ?>
<div class="table-wrap" style="padding:14px;margin-bottom:14px;border-left:3px solid var(--accent)">
    <div style="font-size:13px;font-weight:600;margin-bottom:4px">
        <?= count($samples) > 1 ? 'Sample / format files' : 'Sample / format file' ?>
    </div>
    <div class="text-muted" style="font-size:12px;margin-bottom:8px">
        Download this, fill in your figures and upload it back below.
    </div>
    <?php foreach ($samples as $sf): ?>
    <div style="display:flex;align-items:center;gap:8px;padding:5px 0">
        <a href="?page=dc_sample&id=<?= (int)$sf['id'] ?>" class="btn btn-sm btn-secondary">Download</a>
        <span style="flex:1 1 auto;word-break:break-all;font-size:13px"><?= h($sf['original_name']) ?></span>
        <span class="text-muted" style="font-size:11px;white-space:nowrap"><?= h(dcFormatBytes((int)$sf['size_bytes'])) ?></span>
    </div>
    <?php if (dcIsImage($sf['mime_type'])): ?>
    <?php // An example photo says in one look what the words take a paragraph
          // to say, so it is shown here rather than left as a download. ?>
    <a href="?page=dc_sample&id=<?= (int)$sf['id'] ?>" style="display:inline-block;margin:2px 0 8px"
       data-dc-img="?page=dc_sample&id=<?= (int)$sf['id'] ?>&inline=1"
       data-dc-full="?page=dc_sample&id=<?= (int)$sf['id'] ?>&inline=1"
       data-dc-name="<?= h($sf['original_name']) ?>"
       data-dc-meta="Sample / format file · <?= h(dcFormatBytes((int)$sf['size_bytes'])) ?>">
        <img src="?page=dc_sample&id=<?= (int)$sf['id'] ?>&inline=1" class="dc-sample-thumb"
             alt="<?= h($sf['original_name']) ?>" title="Click to view" loading="lazy">
    </a>
    <?php endif; ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php dcRenderImageModal(); ?>

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
    $state     = dcLocationState($sub, count($myFiles), dcHasSubAnswers($answers[$selected] ?? []));
    $canEdit   = dcCanEditSubmission($req, $selected, $sub);
    $myAnswers = $answers[$selected] ?? [];
    ob_start();
?>
<div class="<?= $inWindow ? '' : 'table-wrap' ?>" style="<?= $inWindow ? '' : 'padding:16px;margin-bottom:14px' ?>">
    <?php if (!$inWindow): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px">
        <strong>Your submission</strong>
        <?= dcStateBadge($state) ?>
    </div>
    <?php endif; ?>

    <?php if (count($submitLocs) > 1): ?>
    <form method="GET" style="margin-bottom:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="page" value="data_collection">
        <input type="hidden" name="id" value="<?= $id ?>">
        <label style="font-size:12px;font-weight:600;color:var(--muted)">Location</label>
        <select name="loc" class="form-control" style="max-width:280px" onchange="this.form.submit()">
            <?php foreach ($submitLocs as $lid):
                $s = dcLocationState($subs[$lid] ?? null, count($byLoc[$lid] ?? []),
                                     dcHasSubAnswers($answers[$lid] ?? [])); ?>
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
        Confirmed by Operations<?= !empty($sub['confirmed_name']) || !empty($sub['confirmed_by'])
            ? ' (' . h((string)($sub['confirmed_name'] ?: $sub['confirmed_by'])) . ')' : '' ?><?=
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
            <?php if (dcIsImage($f['mime_type'])): ?>
            <a href="?page=dc_file&id=<?= (int)$f['id'] ?>"
               data-dc-img="?page=dc_file&id=<?= (int)$f['id'] ?>&inline=1"
               data-dc-full="?page=dc_file&id=<?= (int)$f['id'] ?>&inline=1"
               data-dc-name="<?= h($f['original_name']) ?>"
               data-dc-meta="<?= h($names[$selected] ?? '') ?> · <?= h(dcFormatBytes((int)$f['size_bytes'])) ?>">
                <img src="?page=dc_file&id=<?= (int)$f['id'] ?>&inline=1" alt="" loading="lazy" title="Click to view"
                     style="height:40px;width:40px;object-fit:cover;border-radius:4px;border:1px solid var(--border);cursor:zoom-in;display:block">
            </a>
            <?php endif; ?>
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
        <input type="hidden" name="action" value="dc_submit">
        <input type="hidden" name="request_id" value="<?= $id ?>">
        <input type="hidden" name="location_id" value="<?= $selected ?>">

        <?php // The questions come before the file picker: they are what the
              // files are meant to show, and a question printed under the
              // upload hint reads as part of that hint. ?>
        <?php foreach (dcQuestionSeries($req, $subQs) as $q): ?>
        <div class="form-group">
            <div class="dc-q"><?= dcQuestionNo((int)$q['no']) ?><?= h($q['text']) ?></div>
            <?php if ((int)$q['id'] === 0): ?>
            <textarea name="answer_text" class="form-control" rows="3" maxlength="<?= DC_MAX_ANSWER ?>"
                      placeholder="Type your answer here"><?= h((string)($sub['answer_text'] ?? '')) ?></textarea>
            <?php else: ?>
            <textarea name="sub_answers[<?= (int)$q['id'] ?>]" class="form-control" rows="3" maxlength="<?= DC_MAX_ANSWER ?>"
                      placeholder="Type your answer here"><?= h((string)($myAnswers[(int)$q['id']] ?? '')) ?></textarea>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <div class="form-group">
            <label>Files<?= (int)$req['requires_file'] ? ' <span class="required">*</span>' : '' ?></label>
            <input type="file" name="files[]" class="form-control" multiple style="width:100%">
            <small class="text-muted">
                Excel, CSV, PDF, Word or photos · up to <?= (int)(DC_MAX_BYTES / 1024 / 1024) ?> MB each ·
                pick several at once<?= (int)$req['requires_file'] ? '' : ' · optional for this task' ?>
            </small>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <button type="submit" class="btn btn-primary">Submit</button>
            <span class="text-muted" style="font-size:11px">
                <?= $state === 'nothing'
                    ? 'You can come back and change this until Operations confirms it.'
                    : 'Already submitted — sending again adds files and replaces your answer.' ?>
            </span>
        </div>
    </form>
    <?php if ($manage): ?>
    <?php // Confirming is the manager's action, so it sits on their card too —
          // handy right after filing on behalf of an outlet. Already
          // confirmed? Then the useful action here is handing it back. ?>
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--border)">
        <?php if ($state === 'confirmed'): ?>
        <form method="POST" class="inline-form"
              onsubmit="return confirm('Reopen this submission so the location can change it?')">
            <input type="hidden" name="action" value="dc_reopen">
            <input type="hidden" name="request_id" value="<?= $id ?>">
            <input type="hidden" name="location_id" value="<?= $selected ?>">
            <button type="submit" class="btn btn-secondary">Reopen this submission</button>
            <span class="text-muted" style="font-size:11px;margin-left:6px">Hands it back to the location.</span>
        </form>
        <?php else: ?>
        <form method="POST" class="inline-form"
              onsubmit="return confirm('Confirm this submission? The location will not be able to change it afterwards.')">
            <input type="hidden" name="action" value="dc_confirm">
            <input type="hidden" name="request_id" value="<?= $id ?>">
            <input type="hidden" name="location_id" value="<?= $selected ?>">
            <button type="submit" class="btn btn-secondary" <?= $state === 'nothing' ? 'disabled' : '' ?>>Confirm this submission</button>
            <span class="text-muted" style="font-size:11px;margin-left:6px">Locks this location out of it.</span>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <?php foreach (dcQuestionSeries($req, $subQs) as $q):
        $txt = (int)$q['id'] === 0
            ? trim((string)($sub['answer_text'] ?? ''))
            : trim((string)($myAnswers[(int)$q['id']] ?? '')); ?>
    <div class="form-group">
        <div class="dc-q"><?= dcQuestionNo((int)$q['no']) ?><?= h($q['text']) ?></div>
        <div class="dc-a">
            <?= $txt !== '' ? h($txt) : '<span class="text-muted">No answer written.</span>' ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php
$cardHtml = ob_get_clean();
if (!$inWindow) {
    echo $cardHtml;
} else {
    // "On behalf" on a board row is a link that reloads with the outlet in
    // the URL, and so is the picker inside the window. Opening whenever the
    // URL names a location is what makes both survive that reload.
    $autoOpen = isset($_GET['loc']);
?>
<div class="dc-pv-overlay<?= $autoOpen ? ' open' : '' ?>" id="dcFileOverlay" role="dialog" aria-modal="true"
     aria-labelledby="dcFileTitle">
    <div class="dc-pv-box" style="max-width:min(880px,96vw)">
        <div class="dc-pv-head">
            <h3 id="dcFileTitle">File on behalf of <?= h($names[$selected] ?? ('#' . $selected)) ?></h3>
            <div style="display:flex;align-items:center;gap:10px">
                <?= dcStateBadge($state) ?>
                <button type="button" class="dc-pv-close" aria-label="Close" onclick="dcFileClose()">&times;</button>
            </div>
        </div>
        <div class="dc-pv-body"><?= $cardHtml ?></div>
    </div>
</div>
<script>
(function () {
    var ov = document.getElementById('dcFileOverlay');
    if (!ov) return;
    window.dcFileClose = function () { ov.classList.remove('open'); };
    ov.addEventListener('click', function (e) { if (e.target === ov) dcFileClose(); });
    document.addEventListener('keydown', function (e) {
        // The photo preview sits on top of this one; let it close first.
        if (e.key !== 'Escape' || !ov.classList.contains('open')) return;
        var pv = document.getElementById('dcOverlay');
        if (pv && pv.classList.contains('open')) return;
        dcFileClose();
    });
})();
</script>
<?php
}
endif; // submit card
?>

<?php if ($manage):
    $awaiting = $sentHere - $confirmed;         // sent, not yet accepted
?>
<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px">
    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
        <?php // Filtering happens in the browser: every row is already on the
              // page, so there is nothing to fetch and no scroll position to
              // lose between one status and the next.
        $counts = ['all' => count($targets), 'confirmed' => $confirmed,
                   'submitted' => $sentHere - $confirmed, 'nothing' => count($targets) - $sentHere];
        foreach (['all' => 'All', 'nothing' => 'Not submitted',
                  'submitted' => 'Waiting on you', 'confirmed' => 'Confirmed'] as $key => $label): ?>
        <button type="button" class="btn btn-sm dc-fbtn<?= $key === 'all' ? ' btn-primary active' : ' btn-ghost' ?>"
                data-dc-filter="<?= $key ?>"><?= h($label) ?> (<?= (int)$counts[$key] ?>)</button>
        <?php endforeach; ?>
        <span class="text-muted" style="font-size:12px;margin-left:4px" id="dcFilterNote"></span>
    </div>
    <?php if ($awaiting > 0 && $isOpen): ?>
    <form method="POST" onsubmit="return confirm('Confirm all <?= $awaiting ?> submitted location(s)? They will not be able to change anything afterwards.')">
        <input type="hidden" name="action" value="dc_confirm_all">
        <input type="hidden" name="request_id" value="<?= $id ?>">
        <button class="btn btn-secondary btn-sm">Confirm all submitted (<?= $awaiting ?>)</button>
    </form>
    <?php endif; ?>
</div>
<div class="table-wrap" data-stack>
<table class="table">
    <thead>
        <tr>
            <th style="width:190px">Location</th>
            <th style="width:110px">Status</th>
            <th>Answers<?= $subQs ? ' <span class="text-muted" style="font-weight:400">(' . (count($subQs) + 1) . ' questions)</span>' : '' ?></th>
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
        $state = dcLocationState($sub, count($files), dcHasSubAnswers($answers[$lid] ?? []));
        $who   = (string)($sub['confirmed_name'] ?? '') ?: (string)($sub['confirmed_by'] ?? '');
        if ($who === '') $who = (string)($sub['updated_name'] ?? '') ?: (string)($sub['updated_by'] ?? '');
        $when  = (string)($sub['confirmed_at'] ?? '') ?: (string)($sub['updated_at'] ?? '');
    ?>
        <tr class="dc-row" data-dc-state="<?= h($state) ?>">
            <td><?= h($names[$lid] ?? ('#' . $lid)) ?></td>
            <td><?= dcStateBadge($state) ?></td>
            <td style="font-size:12px">
                <?php foreach (dcQuestionSeries($req, $subQs) as $qi => $q):
                    $a = (int)$q['id'] === 0
                        ? trim((string)($sub['answer_text'] ?? ''))
                        : trim((string)($answers[$lid][(int)$q['id']] ?? '')); ?>
                <div<?= $qi ? ' style="margin-top:6px"' : '' ?>>
                    <?php if ((int)$q['no'] > 0): ?>
                    <div class="text-muted" style="font-size:11px"><?= (int)$q['no'] ?>. <?= h($q['text']) ?></div>
                    <?php endif; ?>
                    <div style="white-space:pre-wrap"><?= $a !== '' ? h($a) : '<span class="text-muted">—</span>' ?></div>
                </div>
                <?php endforeach; ?>
            </td>
            <td style="font-size:12px">
                <?php if (!$files): ?>
                <span class="text-muted">—</span>
                <?php else: foreach ($files as $f): ?>
                <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px">
                    <?php if (dcIsImage($f['mime_type'])): ?>
                    <a href="?page=dc_file&id=<?= (int)$f['id'] ?>"
                       data-dc-img="?page=dc_file&id=<?= (int)$f['id'] ?>&inline=1"
                       data-dc-full="?page=dc_file&id=<?= (int)$f['id'] ?>&inline=1"
                       data-dc-name="<?= h($f['original_name']) ?>"
                       data-dc-meta="<?= h($names[$lid] ?? '') ?> · <?= h(dcFormatBytes((int)$f['size_bytes'])) ?>">
                        <img src="?page=dc_file&id=<?= (int)$f['id'] ?>&inline=1" alt="" loading="lazy" title="Click to view"
                             style="height:34px;width:34px;object-fit:cover;border-radius:4px;border:1px solid var(--border);cursor:zoom-in;display:block">
                    </a>
                    <?php endif; ?>
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
                <?php elseif ($state === 'submitted' && $isOpen): ?>
                <form method="POST" class="inline-form" onsubmit="return confirm('Confirm this submission? The location will not be able to change it afterwards.')">
                    <input type="hidden" name="action" value="dc_confirm">
                    <input type="hidden" name="request_id" value="<?= $id ?>">
                    <input type="hidden" name="location_id" value="<?= $lid ?>">
                    <button class="btn btn-sm btn-primary">Confirm</button>
                </form>
                <?php endif; ?>
                <?php if ($isOpen): ?>
                <?php // On every row, not only the empty ones: an outlet that
                      // sent the wrong photo needs Operations to fix it just as
                      // much as one that sent nothing. Reloads with the outlet
                      // in the URL, which opens the window on it. ?>
                <a href="?page=data_collection&id=<?= $id ?>&loc=<?= $lid ?>" class="btn btn-sm btn-ghost"
                   title="File on behalf of <?= h($names[$lid] ?? '') ?>">On behalf</a>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
<div class="table-count"><?= $sentHere ?> of <?= count($targets) ?> location(s) submitted · <?= $confirmed ?> confirmed</div>
<script>
(function () {
    var btns = document.querySelectorAll('.dc-fbtn');
    var rows = document.querySelectorAll('tr.dc-row');
    var note = document.getElementById('dcFilterNote');
    if (!btns.length || !rows.length) return;
    btns.forEach(function (b) {
        b.addEventListener('click', function () {
            var want = b.getAttribute('data-dc-filter');
            btns.forEach(function (o) {
                o.classList.remove('active', 'btn-primary');
                o.classList.add('btn-ghost');
            });
            b.classList.add('active', 'btn-primary');
            b.classList.remove('btn-ghost');
            var shown = 0;
            rows.forEach(function (r) {
                // "Waiting on you" is the submitted-but-not-confirmed set.
                var hit = want === 'all' || r.getAttribute('data-dc-state') === want;
                r.hidden = !hit;
                if (hit) shown++;
            });
            note.textContent = want === 'all' ? '' : 'showing ' + shown + ' of ' + rows.length;
        });
    });
})();
</script>
<?php endif;
}
