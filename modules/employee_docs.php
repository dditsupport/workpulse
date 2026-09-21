<?php
// =========================================================
// Employee Documents — the HRMS personnel file
//
// The paperwork a hire arrives with — resume, government ID, the
// interview sheet the panel filled in, the signed offer — kept against
// the employee inside the app instead of in a desk folder or a mail
// thread.
//
// The reason it exists is the employee who has LEFT. That is when the
// file is asked for: a background-verification call from their next
// employer, a PF or gratuity query, an audit asking who interviewed
// them, a re-hire enquiry two years on — and that is exactly when the
// desk folder has already been cleared out. So deactivating an employee
// does nothing to their documents: the archive lists a left employee the
// same as a serving one, and "Left staff" is a filter because that is
// the set HR comes looking for.
//
// Files live under uploads/employee_docs/{YYYY-MM}/ and are only ever
// served through ?page=employee_doc_file&id=N — never by direct URL.
//
// Permissions:
//   · txn_employee_docs — see the archive, upload, remove a document.
//     Not implied by txn_employees: a government ID is a narrower thing
//     than the employee master, so it is granted by hand on Roles.
//   · superadmin — additionally sees removed documents and restores them.
//
// Removing is a SOFT delete: the row is hidden and the file stays on
// disk. A retention archive a mis-click can empty is not an archive.
//
// Schema: migrations/2026-09-21_employee_documents.sql
// =========================================================

define('EMPDOC_UPLOAD_DIR', __DIR__ . '/../uploads/employee_docs/');
define('EMPDOC_MAX_BYTES',  15 * 1024 * 1024);  // 15 MB per file
define('EMPDOC_MAX_FILES',  10);                // per submit

// Keyed by what the file IS, never by the name it arrived with — a scan
// app will happily hand over a JPEG called ".pdf". The value is the
// extension it gets stored under, so that is never the uploader's choice
// either. PDF is what these documents mostly are; a phone photo of an
// Aadhaar or a signed interview sheet is just as much the record, so
// JPEG and PNG are taken too.
const EMPDOC_MIME_EXT = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/pjpeg'     => 'jpg',
    'image/png'       => 'png',
    'image/x-png'     => 'png',
];

// What the browser can open in a tab rather than send to Downloads.
const EMPDOC_INLINE_MIME = ['application/pdf', 'image/jpeg', 'image/png'];

// The categories the upload form offers. Add one here and it is
// immediately filterable — doc_type is a varchar, not an enum.
const EMPDOC_TYPES = [
    'resume'             => 'Resume / CV',
    'government_id'      => 'Government Document',
    'interview_sheet'    => 'Interview Sheet',
    'offer_letter'       => 'Offer Letter',
    'appointment_letter' => 'Appointment Letter',
    'relieving_letter'   => 'Relieving Letter',
    'experience_letter'  => 'Experience Letter',
    'other'              => 'Other',
];

// ── Schema probe ────────────────────────────────────────
// Every entry point checks this, so an un-migrated database shows a
// notice instead of a 500. Probed once per request.
function empDocSchemaReady(): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        getDb()->query('SELECT 1 FROM employee_documents LIMIT 0')->fetch();
        $ready = true;
    } catch (Exception $e) {
        $ready = false;
    }
    return $ready;
}

function empDocSchemaNotice(): string {
    return 'Employee Documents is not set up on this database yet — run '
         . 'migrations/2026-09-21_employee_documents.sql.';
}

// ── Permissions ─────────────────────────────────────────
function empDocCanView(): bool {
    return isSuperadmin() || hasTxn('employee_docs');
}
// Uploading and removing ride the same flag as viewing: whoever HR
// trusts with the file is who files into it.
function empDocCanManage(): bool {
    return empDocCanView();
}
// Removed documents — seeing them and putting them back — is superadmin
// only, so a removal cannot be quietly undone by whoever made it.
function empDocCanRestore(): bool {
    return isSuperadmin();
}

// ── Storage ─────────────────────────────────────────────
function empDocTypeLabel(?string $type): string {
    return EMPDOC_TYPES[(string)$type] ?? 'Other';
}

function empDocDir(string $bucketMonth): string {
    $month = preg_match('/^\d{4}-\d{2}$/', $bucketMonth) ? $bucketMonth : date('Y-m');
    return EMPDOC_UPLOAD_DIR . $month . '/';
}

// Belt and braces on top of the unguessable stored names: an .htaccess at
// the root of the folder refusing direct requests, so a government ID is
// reachable only through ?page=employee_doc_file&id=N and its permission
// check. Written once, when the folder is first created; ignored by nginx,
// where the same job belongs in the server config.
function empDocWriteDirGuard(): void {
    $guard = EMPDOC_UPLOAD_DIR . '.htaccess';
    if (is_file($guard)) return;
    @file_put_contents($guard,
        "# Employee documents are served only through ?page=employee_doc_file.\n"
      . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
      . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
}

// Where a row's file is. Rows written before month bucketing (there are
// none yet, but the policies module learned this the hard way) fall back
// to the flat folder.
function empDocPath(array $row): ?string {
    $bucketed = empDocDir((string)$row['bucket_month']) . $row['stored_name'];
    if (is_file($bucketed)) return $bucketed;
    $flat = EMPDOC_UPLOAD_DIR . $row['stored_name'];
    return is_file($flat) ? $flat : null;
}

function empDocIsInline(?string $mime): bool {
    return in_array((string)$mime, EMPDOC_INLINE_MIME, true);
}

// ── Queries ─────────────────────────────────────────────
// One row per document, joined to the employee it belongs to. The join is
// LEFT because the archive has to keep reading even if the employees row
// is gone — employee_code / employee_name on the document itself are the
// fallback, which is why they are copied at upload time.
//
// $f keys: search, types[], depts[], status[] ('serving'|'left'),
//          emp_id, include_deleted, only_deleted.
function empDocList(array $f): array {
    if (!empDocSchemaReady()) return [];
    $sql = 'SELECT d.*,
                   e.id            AS emp_row_id,
                   e.full_name     AS current_name,
                   e.is_active,
                   e.deactivated_at,
                   e.deactivation_reason,
                   e.department_id,
                   dep.department_name
            FROM   employee_documents d
            LEFT JOIN employees   e   ON e.id   = d.employee_id
            LEFT JOIN departments dep ON dep.id = e.department_id
            WHERE  1=1';
    $p = [];

    if (!empty($f['only_deleted'])) {
        $sql .= ' AND d.deleted_at IS NOT NULL';
    } elseif (empty($f['include_deleted'])) {
        $sql .= ' AND d.deleted_at IS NULL';
    }

    $empId = (int)($f['emp_id'] ?? 0);
    if ($empId > 0) { $sql .= ' AND d.employee_id = ?'; $p[] = $empId; }

    $search = trim((string)($f['search'] ?? ''));
    if ($search !== '') {
        $sql .= ' AND (d.employee_code LIKE ? OR d.employee_name LIKE ?
                       OR d.title LIKE ? OR d.original_name LIKE ? OR d.notes LIKE ?)';
        $like = "%{$search}%";
        array_push($p, $like, $like, $like, $like, $like);
    }

    $types = array_values(array_intersect((array)($f['types'] ?? []), array_keys(EMPDOC_TYPES)));
    if ($types && count($types) < count(EMPDOC_TYPES)) {
        $sql .= ' AND d.doc_type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
        foreach ($types as $t) $p[] = $t;
    }

    $depts = array_values(array_filter(array_map('intval', (array)($f['depts'] ?? []))));
    if ($depts) {
        $sql .= ' AND e.department_id IN (' . implode(',', array_fill(0, count($depts), '?')) . ')';
        foreach ($depts as $d) $p[] = $d;
    }

    // A document whose employees row has vanished counts as "left" — it is
    // certainly not someone still serving.
    $status = array_values(array_intersect((array)($f['status'] ?? []), ['serving', 'left']));
    if (count($status) === 1) {
        $sql .= $status[0] === 'serving'
            ? ' AND e.is_active = 1'
            : ' AND (e.is_active = 0 OR e.id IS NULL)';
    }

    $sql .= ' ORDER BY d.uploaded_at DESC, d.id DESC';
    try {
        $st = getDb()->prepare($sql);
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function empDocRow(int $id): ?array {
    if (!empDocSchemaReady() || $id < 1) return null;
    try {
        $st = getDb()->prepare(
            'SELECT d.*, e.is_active, e.full_name AS current_name
             FROM   employee_documents d
             LEFT JOIN employees e ON e.id = d.employee_id
             WHERE  d.id = ?'
        );
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        return null;
    }
}

// Headline numbers for the archive. The ex-staff pair is the one that
// matters — it counts employees who have LEFT and still have a file here,
// and the documents held for them, which is what this feature exists for.
function empDocStats(): array {
    $out = ['docs' => 0, 'employees' => 0, 'left_docs' => 0, 'left_staff' => 0, 'bytes' => 0];
    if (!empDocSchemaReady()) return $out;
    try {
        $r = getDb()->query(
            'SELECT COUNT(*)                          AS docs,
                    COUNT(DISTINCT d.employee_code)   AS employees,
                    COALESCE(SUM(d.file_size), 0)     AS bytes,
                    SUM(CASE WHEN e.is_active = 1 THEN 0 ELSE 1 END) AS left_docs,
                    COUNT(DISTINCT CASE WHEN e.is_active = 1 THEN NULL
                                        ELSE d.employee_code END)    AS left_staff
             FROM   employee_documents d
             LEFT JOIN employees e ON e.id = d.employee_id
             WHERE  d.deleted_at IS NULL'
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach ($out as $k => $_) $out[$k] = (int)($r[$k] ?? 0);
    } catch (Exception $e) { /* leave zeros */ }
    return $out;
}

// How many live documents each employee has, keyed by employees.id —
// used to put a count on the Employees list without a query per row.
function empDocCountsByEmployee(): array {
    if (!empDocSchemaReady()) return [];
    try {
        $rows = getDb()->query(
            'SELECT employee_id, COUNT(*) AS n
             FROM   employee_documents
             WHERE  deleted_at IS NULL AND employee_id IS NOT NULL
             GROUP  BY employee_id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) $out[(int)$r['employee_id']] = (int)$r['n'];
        return $out;
    } catch (Exception $e) {
        return [];
    }
}

// Every employee, serving or left, for the upload picker. The employee
// list page defaults to active-only; this one must not, because filing a
// leaver's paperwork after their last day is the normal case.
function empDocEmployeeOptions(): array {
    try {
        return getDb()->query(
            'SELECT e.id, e.employee_code, e.full_name, e.is_active, e.deactivated_at,
                    d.department_name
             FROM   employees e
             LEFT JOIN departments d ON d.id = e.department_id
             ORDER  BY e.is_active DESC, e.full_name'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

// ── Handler: upload ─────────────────────────────────────
function doUploadEmployeeDocs(): void {
    $back = 'index.php?page=employee_docs';
    if (!empDocCanManage() || !empDocSchemaReady()) {
        flash('error', 'You do not have permission to file employee documents.');
        header("Location: {$back}"); exit;
    }
    $empId   = (int)($_POST['employee_id'] ?? 0);
    $type    = (string)($_POST['doc_type'] ?? 'other');
    $title   = trim((string)($_POST['title']    ?? ''));
    $docDate = trim((string)($_POST['doc_date'] ?? ''));
    $notes   = trim((string)($_POST['notes']    ?? ''));
    if (!isset(EMPDOC_TYPES[$type])) $type = 'other';
    if ($docDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $docDate)) $docDate = '';

    $form = 'index.php?page=employee_doc_upload' . ($empId > 0 ? '&emp=' . $empId : '');

    $emp = getEmployee($empId);
    if (!$emp) {
        flash('error', 'Pick the employee this document belongs to.');
        header("Location: {$form}"); exit;
    }
    if (empty($_FILES['docs']['name']) || !is_array($_FILES['docs']['name'])) {
        flash('error', 'Pick at least one file to upload.');
        header("Location: {$form}"); exit;
    }

    $month = date('Y-m');
    $dir   = empDocDir($month);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    empDocWriteDirGuard();
    if (!is_dir($dir) || !is_writable($dir)) {
        flash('error', 'The document folder could not be written to — nothing was saved.');
        header("Location: {$form}"); exit;
    }

    $db = getDb();
    $ins = $db->prepare(
        'INSERT INTO employee_documents
            (employee_id, employee_code, employee_name, doc_type, title, doc_date,
             original_name, stored_name, bucket_month, mime_type, file_size, sha256,
             notes, uploaded_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $dupe  = $db->prepare(
        'SELECT original_name FROM employee_documents
         WHERE  employee_id = ? AND sha256 = ? AND deleted_at IS NULL LIMIT 1'
    );
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $saved    = 0;
    $rejected = [];
    $count    = count($_FILES['docs']['name']);

    for ($i = 0; $i < $count && $saved < EMPDOC_MAX_FILES; $i++) {
        $err  = (int)($_FILES['docs']['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        $orig = basename((string)$_FILES['docs']['name'][$i]);
        if ($orig === '') $orig = 'document';
        if ($err !== UPLOAD_ERR_OK) {
            $rejected[] = ['name' => $orig, 'reason' => uploadErrorReason($err)];
            continue;
        }
        $tmp = (string)$_FILES['docs']['tmp_name'][$i];
        if (!is_uploaded_file($tmp)) {
            $rejected[] = ['name' => $orig, 'reason' => 'the upload did not complete'];
            continue;
        }
        $size = (int)($_FILES['docs']['size'][$i] ?? 0);
        if ($size > EMPDOC_MAX_BYTES) {
            $rejected[] = ['name' => $orig,
                           'reason' => 'over the ' . (EMPDOC_MAX_BYTES / 1024 / 1024) . ' MB limit'];
            continue;
        }
        // The sniff decides both whether it is accepted AND the extension
        // it is stored under, so a .pdf holding something else is caught.
        $mime = (string)($finfo->file($tmp) ?: 'application/octet-stream');
        $ext  = EMPDOC_MIME_EXT[$mime] ?? null;
        if ($ext === null) {
            $rejected[] = ['name' => $orig, 'reason' => 'not a PDF, JPG or PNG'];
            continue;
        }
        $sha = (string)hash_file('sha256', $tmp);
        $dupe->execute([(int)$emp['id'], $sha]);
        if ($existing = $dupe->fetchColumn()) {
            $rejected[] = ['name' => $orig,
                           'reason' => 'already on file as "' . $existing . '"'];
            continue;
        }

        $stored = uniqid('empdoc_', true) . '.' . $ext;
        if (!move_uploaded_file($tmp, $dir . $stored)) {
            $rejected[] = ['name' => $orig, 'reason' => 'could not be saved'];
            continue;
        }
        try {
            $ins->execute([
                (int)$emp['id'],
                (string)$emp['employee_code'],
                (string)$emp['full_name'],
                $type,
                $title !== '' ? mb_substr($title, 0, 200) : null,
                $docDate !== '' ? $docDate : null,
                mb_substr(nameWithExt($orig, $ext), 0, 255),
                $stored,
                $month,
                $mime,
                $size,
                $sha,
                $notes !== '' ? $notes : null,
                myCode(),
            ]);
            $saved++;
        } catch (Exception $e) {
            @unlink($dir . $stored);
            $rejected[] = ['name' => $orig, 'reason' => 'could not be recorded'];
        }
    }
    // Anything past the per-submit cap never got looked at; say so rather
    // than letting it disappear silently.
    for (; $i < $count; $i++) {
        $orig = basename((string)$_FILES['docs']['name'][$i]);
        if ($orig !== '' && (int)($_FILES['docs']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $rejected[] = ['name' => $orig,
                           'reason' => 'over ' . EMPDOC_MAX_FILES . ' files in one upload'];
        }
    }

    $note = rejectedFilesNote($rejected);
    if ($saved > 0) {
        flash($rejected ? 'error' : 'success',
              $saved . ' document(s) filed against ' . $emp['employee_code']
            . ' — ' . $emp['full_name'] . '.' . $note);
        header('Location: index.php?page=employee_docs&emp=' . (int)$emp['id']); exit;
    }
    flash('error', 'Nothing was saved.' . ($note !== '' ? $note : ' Pick a PDF, JPG or PNG.'));
    header("Location: {$form}"); exit;
}

// ── Handler: remove (soft) ──────────────────────────────
function doDeleteEmployeeDoc(): void {
    $id  = (int)($_POST['id'] ?? 0);
    $row = empDocRow($id);
    $back = 'index.php?page=employee_docs'
          . ($row && $row['employee_id'] ? '&emp=' . (int)$row['employee_id'] : '');
    if (!empDocCanManage() || !$row) {
        flash('error', 'You do not have permission to remove employee documents.');
        header("Location: {$back}"); exit;
    }
    if ($row['deleted_at'] !== null) {
        flash('error', 'That document is already removed.');
        header("Location: {$back}"); exit;
    }
    $reason = trim((string)($_POST['reason'] ?? ''));
    try {
        getDb()->prepare(
            'UPDATE employee_documents
             SET    deleted_at = NOW(), deleted_by = ?, delete_reason = ?
             WHERE  id = ? AND deleted_at IS NULL'
        )->execute([myCode(), $reason !== '' ? mb_substr($reason, 0, 255) : null, $id]);
        // Soft on purpose: the file stays on disk and superadmin can put
        // the row back, because an archive a mis-click empties is no archive.
        flash('success', 'Document removed from the archive. A superadmin can restore it.');
    } catch (Exception $e) {
        flash('error', 'Could not remove that document: ' . $e->getMessage());
    }
    header("Location: {$back}"); exit;
}

// ── Handler: restore (superadmin) ───────────────────────
function doRestoreEmployeeDoc(): void {
    $id   = (int)($_POST['id'] ?? 0);
    $row  = empDocRow($id);
    $back = 'index.php?page=employee_docs&removed=1';
    if (!empDocCanRestore() || !$row) {
        flash('error', 'You do not have permission to restore employee documents.');
        header("Location: {$back}"); exit;
    }
    if (!empDocPath($row)) {
        flash('error', 'The file behind that row is no longer on disk — nothing to restore.');
        header("Location: {$back}"); exit;
    }
    try {
        getDb()->prepare(
            'UPDATE employee_documents
             SET    deleted_at = NULL, deleted_by = NULL, delete_reason = NULL
             WHERE  id = ?'
        )->execute([$id]);
        flash('success', 'Document restored to the archive.');
    } catch (Exception $e) {
        flash('error', 'Could not restore that document: ' . $e->getMessage());
    }
    header("Location: {$back}"); exit;
}

// ── Serve one document ──────────────────────────────────
// Runs before any HTML (index.php streams this page early). A removed
// document is readable only by the superadmin who can restore it.
function empDocServeFile(): void {
    if (!empDocCanView()) { http_response_code(403); echo 'Forbidden'; return; }
    $row = empDocRow((int)($_GET['id'] ?? 0));
    if (!$row) { http_response_code(404); echo 'Not found'; return; }
    if ($row['deleted_at'] !== null && !empDocCanRestore()) {
        http_response_code(404); echo 'Not found'; return;
    }
    $path = empDocPath($row);
    if (!$path) { http_response_code(404); echo 'File missing'; return; }

    $mime   = (string)($row['mime_type'] ?: 'application/octet-stream');
    $inline = empty($_GET['download']) && empDocIsInline($mime);
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
         . '; filename="' . str_replace('"', '', (string)$row['original_name']) . '"');
    header('Content-Length: ' . (int)filesize($path));
    header('Cache-Control: private, max-age=300');
    readfile($path);
    exit;
}

// ── Page: the archive ───────────────────────────────────
function pageEmployeeDocs(): void {
    if (!empDocCanView()) { echo '<div class="alert alert-error">Access denied.</div>'; return; }
    if (!empDocSchemaReady()) {
        echo '<div class="alert alert-error">' . h(empDocSchemaNotice()) . '</div>'; return;
    }
    $empId    = (int)($_GET['emp'] ?? 0);
    $search   = trim((string)($_GET['search'] ?? ''));
    $doLoad   = isset($_GET['filter']);
    $removed  = !empty($_GET['removed']) && empDocCanRestore();
    $depts    = getDepartments();

    $ALL_TYPES  = array_keys(EMPDOC_TYPES);
    $ALL_DEPTS  = array_map(fn($d) => (string)$d['id'], $depts);
    $ALL_STATUS = ['serving', 'left'];

    // First load shows everything — the archive is small enough to read
    // whole, and an empty page asking for a Filter click helps nobody.
    if ($doLoad) {
        $types  = array_values(array_intersect(array_map('strval', (array)($_GET['type']   ?? [])), $ALL_TYPES));
        $deptId = array_values(array_intersect(array_map('strval', (array)($_GET['dept']   ?? [])), $ALL_DEPTS));
        $status = array_values(array_intersect(array_map('strval', (array)($_GET['status'] ?? [])), $ALL_STATUS));
    } else {
        $types = $ALL_TYPES; $deptId = $ALL_DEPTS; $status = $ALL_STATUS;
    }

    $emp = $empId > 0 ? getEmployee($empId) : null;
    $docs = empDocList([
        'search'       => $search,
        'types'        => $types,
        'depts'        => $deptId,
        'status'       => $status,
        'emp_id'       => $emp ? (int)$emp['id'] : 0,
        'only_deleted' => $removed,
    ]);
    $stats = empDocStats();
?>
<div class="page-header">
    <h2><?= $emp ? 'Documents · ' . h($emp['full_name']) : 'Employee Documents' ?></h2>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php if ($emp): ?>
        <a href="?page=employee_docs" class="btn btn-ghost">← All Documents</a>
        <?php endif; ?>
        <?php if (empDocCanManage()): ?>
        <a href="?page=employee_doc_upload<?= $emp ? '&emp=' . (int)$emp['id'] : '' ?>" class="btn btn-primary">+ Upload Document</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($removed): ?>
<div class="alert alert-info">
    Showing <strong>removed</strong> documents. Their files are still on disk — restoring one puts it back in the archive.
    <a href="?page=employee_docs">← back to the archive</a>
</div>
<?php endif; ?>

<?php if ($emp): ?>
<div class="form-card" style="margin-bottom:18px">
    <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:center">
        <div><strong><code><?= h($emp['employee_code']) ?></code> — <?= h($emp['full_name']) ?></strong></div>
        <div>
            <?= (int)$emp['is_active']
                ? '<span class="badge badge-green">Serving</span>'
                : '<span class="badge badge-red">Left</span>' ?>
        </div>
        <div class="text-muted" style="font-size:12px">
            <?= count($docs) ?> document(s) on file
        </div>
        <?php if (canManageEmployees()): ?>
        <div style="margin-left:auto"><a href="?page=edit&id=<?= (int)$emp['id'] ?>" class="btn btn-sm btn-secondary">Employee Record</a></div>
        <?php endif; ?>
    </div>
    <?php if (!(int)$emp['is_active']): ?>
    <p class="hint" style="margin-top:10px">
        This employee has left. Their documents are kept here indefinitely — deactivating an employee never removes their file.
    </p>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="stats-grid-sm" style="margin-bottom:18px">
    <?php foreach ([
        ['Documents',    $stats['docs'],       ''],
        ['Employees',    $stats['employees'],  ''],
        ['Ex-Staff',     $stats['left_staff'], 'stat-red'],
        ['Ex-Staff Docs',$stats['left_docs'],  'stat-red'],
        ['Storage',      formatBytes($stats['bytes']), ''],
    ] as [$l, $v, $c]): ?>
    <div class="stat-card <?= $c ?>"><div class="stat-val"><?= h((string)$v) ?></div><div class="stat-lbl"><?= $l ?></div></div>
    <?php endforeach; ?>
</div>

<form method="GET" class="rpt-filter">
    <input type="hidden" name="page"   value="employee_docs">
    <input type="hidden" name="filter" value="1">
    <?php if ($removed): ?><input type="hidden" name="removed" value="1"><?php endif; ?>
    <span class="input-clear-wrap" style="flex:1 1 auto;min-width:200px">
        <input type="text" name="search" value="<?= h($search) ?>" placeholder="Search code, name, title, file..." class="form-control">
        <button type="button" class="input-clear-btn" aria-label="Clear search" tabindex="-1">&times;</button>
    </span>
    <?php
        $deptOptions = [];
        foreach ($depts as $d) $deptOptions[(string)$d['id']] = $d['department_name'];
        msFilterField('type',   'Document', EMPDOC_TYPES, $types, '210px');
        msFilterField('dept',   'Department', $deptOptions, $deptId);
        msFilterField('status', 'Staff', ['serving' => 'Serving', 'left' => 'Left'], $status, '160px', 'Both');
    ?>
    <button class="btn btn-primary">Filter</button>
    <a href="?page=employee_docs" class="btn btn-ghost">Clear</a>
    <?php if (empDocCanRestore() && !$removed): ?>
    <a href="?page=employee_docs&removed=1" class="btn btn-ghost btn-sm">Removed</a>
    <?php endif; ?>
</form>
<?php msFilterScript(); ?>
<?php endif; ?>

<div class="table-wrap" data-stack>
<table class="table">
    <thead>
        <tr>
            <?php if (!$emp): ?><th>Employee</th><?php endif; ?>
            <th>Document</th><th>Dated</th><th>File</th><th>Filed</th>
            <?php if (empDocCanManage() || $removed): ?><th>Actions</th><?php endif; ?>
        </tr>
    </thead>
    <tbody>
    <?php if (!$docs): ?>
        <tr><td colspan="6" class="empty-row">
            <?= $removed ? 'No removed documents.' : 'No documents on file yet.' ?>
        </td></tr>
    <?php else: foreach ($docs as $d):
        $isLeft  = $d['emp_row_id'] === null || !(int)$d['is_active'];
        $missing = empDocPath($d) === null;
    ?>
        <tr class="<?= $isLeft ? 'row-inactive' : '' ?>">
            <?php if (!$emp): ?>
            <td>
                <a href="?page=employee_docs&emp=<?= (int)$d['employee_id'] ?>">
                    <code><?= h($d['employee_code']) ?></code>
                </a><br>
                <?= h($d['employee_name']) ?>
                <?php if ($isLeft): ?>
                    <br><span class="badge badge-red">Left</span>
                    <?php if (!empty($d['deactivated_at'])): ?>
                    <span class="text-muted" style="font-size:11px"><?= date('d M Y', strtotime((string)$d['deactivated_at'])) ?></span>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if (!empty($d['department_name'])): ?>
                <br><span class="text-muted" style="font-size:11px"><?= h($d['department_name']) ?></span>
                <?php endif; ?>
            </td>
            <?php endif; ?>
            <td>
                <strong><?= h($d['title'] ?: empDocTypeLabel($d['doc_type'])) ?></strong><br>
                <span class="badge badge-blue"><?= h(empDocTypeLabel($d['doc_type'])) ?></span>
                <?php if (!empty($d['notes'])): ?>
                <br><span class="text-muted" style="font-size:11px"><?= h($d['notes']) ?></span>
                <?php endif; ?>
            </td>
            <td><?= !empty($d['doc_date']) ? date('d M Y', strtotime((string)$d['doc_date'])) : '—' ?></td>
            <td>
                <?php if ($missing): ?>
                    <span class="badge badge-red">File missing</span><br>
                    <span class="text-muted" style="font-size:11px"><?= h($d['original_name']) ?></span>
                <?php else: ?>
                    <a href="?page=employee_doc_file&id=<?= (int)$d['id'] ?>" target="_blank" rel="noopener"><?= h($d['original_name']) ?></a><br>
                    <span class="text-muted" style="font-size:11px"><?= h(formatBytes((int)$d['file_size'])) ?></span>
                <?php endif; ?>
            </td>
            <td>
                <?= h((string)($d['uploaded_by'] ?: '—')) ?><br>
                <span class="text-muted" style="font-size:11px"><?= date('d M Y H:i', strtotime((string)$d['uploaded_at'])) ?></span>
                <?php if ($d['deleted_at'] !== null): ?>
                <br><span class="badge badge-red">Removed</span>
                <span class="text-muted" style="font-size:11px">
                    <?= h((string)($d['deleted_by'] ?: '')) ?> · <?= date('d M Y', strtotime((string)$d['deleted_at'])) ?>
                </span>
                <?php if (!empty($d['delete_reason'])): ?>
                <br><span class="text-muted" style="font-size:11px"><?= h($d['delete_reason']) ?></span>
                <?php endif; ?>
                <?php endif; ?>
            </td>
            <?php if (empDocCanManage() || $removed): ?>
            <td class="actions">
                <?php if (!$missing): ?>
                <a href="?page=employee_doc_file&id=<?= (int)$d['id'] ?>&download=1" class="btn btn-sm btn-secondary">Download</a>
                <?php endif; ?>
                <?php if ($d['deleted_at'] === null && empDocCanManage()): ?>
                <form method="POST" class="inline-form"
                      onsubmit="return confirm('Remove this document from the archive? A superadmin can restore it.')">
                    <input type="hidden" name="action" value="delete_employee_doc">
                    <input type="hidden" name="id"     value="<?= (int)$d['id'] ?>">
                    <button class="btn btn-sm btn-danger">Remove</button>
                </form>
                <?php elseif ($d['deleted_at'] !== null && empDocCanRestore()): ?>
                <form method="POST" class="inline-form">
                    <input type="hidden" name="action" value="restore_employee_doc">
                    <input type="hidden" name="id"     value="<?= (int)$d['id'] ?>">
                    <button class="btn btn-sm btn-success">Restore</button>
                </form>
                <?php endif; ?>
            </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
<p class="table-count"><?= count($docs) ?> document(s)</p>
<?php
}

// ── Page: upload form ───────────────────────────────────
function pageEmployeeDocUpload(): void {
    if (!empDocCanManage()) { echo '<div class="alert alert-error">Access denied.</div>'; return; }
    if (!empDocSchemaReady()) {
        echo '<div class="alert alert-error">' . h(empDocSchemaNotice()) . '</div>'; return;
    }
    $empId = (int)($_GET['emp'] ?? 0);
    $staff = empDocEmployeeOptions();
?>
<div class="page-header">
    <h2>Upload Employee Document</h2>
    <a href="?page=employee_docs<?= $empId > 0 ? '&emp=' . $empId : '' ?>" class="btn btn-ghost">← Back</a>
</div>
<div class="form-card">
<form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="action" value="upload_employee_doc">
    <div class="form-grid">
        <div class="form-group">
            <label>Employee <span class="required">*</span></label>
            <select name="employee_id" class="form-control" required>
                <option value="">— Select —</option>
                <?php foreach ($staff as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= $empId === (int)$s['id'] ? 'selected' : '' ?>>
                    <?= h($s['employee_code'] . ' — ' . $s['full_name']) ?><?php
                        if (!(int)$s['is_active']) {
                            echo '  · LEFT';
                            if (!empty($s['deactivated_at'])) echo ' ' . date('M Y', strtotime((string)$s['deactivated_at']));
                        }
                    ?>
                </option>
                <?php endforeach; ?>
            </select>
            <span class="hint">Employees who have left are listed too — that is who this archive is for.</span>
        </div>
        <div class="form-group">
            <label>Document Type <span class="required">*</span></label>
            <select name="doc_type" class="form-control" required>
                <?php foreach (EMPDOC_TYPES as $val => $label): ?>
                <option value="<?= h($val) ?>"><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Title</label>
            <input type="text" name="title" class="form-control" maxlength="200"
                   placeholder="e.g. Aadhaar card, Panel round 2">
            <span class="hint">Optional. Blank shows the document type instead. Applies to every file in this upload.</span>
        </div>
        <div class="form-group">
            <label>Document Date</label>
            <input type="date" name="doc_date" class="form-control">
            <span class="hint">The date ON the document — interview date, letter date. Not today's date.</span>
        </div>
        <div class="form-group" style="grid-column:1/-1">
            <label>Notes</label>
            <textarea name="notes" class="form-control" rows="2"
                      placeholder="Anything the next person reading this file needs to know"></textarea>
        </div>
        <div class="form-group" style="grid-column:1/-1">
            <label>File(s) <span class="required">*</span></label>
            <input type="file" name="docs[]" class="form-control" multiple required
                   accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
            <span class="hint">
                PDF, JPG or PNG · max <?= EMPDOC_MAX_BYTES / 1024 / 1024 ?> MB per file ·
                up to <?= EMPDOC_MAX_FILES ?> files per upload. A file already on this employee's
                record is not stored twice.
            </span>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Upload</button>
        <a href="?page=employee_docs<?= $empId > 0 ? '&emp=' . $empId : '' ?>" class="btn btn-ghost">Cancel</a>
    </div>
</form>
</div>
<?php
}
