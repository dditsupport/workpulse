<?php
// =========================================================
// Employee Documents — the HRMS personnel file
//
// The paperwork a hire arrives with — resume, government ID, the
// interview sheet the panel filled in, the signed offer — kept against
// the person inside the app instead of in a desk folder or a mail
// thread.
//
// The reason it exists is the employee who has LEFT. That is when the
// file is asked for: a background-verification call from their next
// employer, a PF or gratuity query, an audit asking who interviewed
// them, a re-hire enquiry two years on — and that is exactly when the
// desk folder has already been cleared out.
//
// TWO MASTERS, because "who left" is older than this system:
//
//   · employees      — serving staff, and anyone deactivated here.
//                      Deactivating does nothing to their documents: the
//                      archive lists a leaver exactly as it lists
//                      serving staff.
//   · past_employees — the people the archive holds paperwork for who
//                      have no `employees` row at all. Someone who
//                      joined in 2018 and left in 2021 was never
//                      enrolled here, and must not be added to
//                      `employees` just to hold their file — that would
//                      put a dead name in every dropdown, headcount and
//                      export in the app. So they get a master of their
//                      own, reference data only: no login, no
//                      attendance, no payroll, nothing but the archive.
//
// A document hangs off exactly one of the two (employee_id or
// past_employee_id), and carries a copy of the code and name either way
// so it still reads if the master row is later edited.
//
// Files live under uploads/employee_docs/{YYYY-MM}/ and are only ever
// served through ?page=employee_doc_file&id=N — never by direct URL.
//
// Permissions:
//   · txn_employee_docs — see the archive, upload, remove a document,
//     and maintain the past-employee master. Not implied by
//     txn_employees: a government ID is a narrower thing than the
//     employee master, so it is granted by hand on Roles.
//   · superadmin — additionally sees removed documents and restores them.
//
// Removing is a SOFT delete: the row is hidden and the file stays on
// disk. A retention archive a mis-click can empty is not an archive.
//
// Schema: migrations/2026-09-21_employee_documents.sql
//         migrations/2026-09-21_past_employees.sql
// =========================================================

define('EMPDOC_UPLOAD_DIR', __DIR__ . '/../uploads/employee_docs/');
define('EMPDOC_MAX_BYTES',  15 * 1024 * 1024);  // 15 MB per file
define('EMPDOC_MAX_FILES',  10);                // per submit
define('PASTEMP_CSV_MAX_BYTES', 5 * 1024 * 1024);
define('PASTEMP_CSV_MAX_ROWS',  5000);

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
// notice instead of a 500. Both migrations are probed together — the
// second one adds the column every query below selects, so a database
// with only the first applied is not a working half, it is broken.
// Probed once per request.
function empDocSchemaReady(): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        getDb()->query('SELECT past_employee_id FROM employee_documents LIMIT 0')->fetch();
        getDb()->query('SELECT 1 FROM past_employees LIMIT 0')->fetch();
        $ready = true;
    } catch (Exception $e) {
        $ready = false;
    }
    return $ready;
}

function empDocSchemaNotice(): string {
    return 'Employee Documents is not set up on this database yet — run '
         . 'migrations/2026-09-21_employee_documents.sql and then '
         . 'migrations/2026-09-21_past_employees.sql.';
}

// ── Permissions ─────────────────────────────────────────
function empDocCanView(): bool {
    return isSuperadmin() || hasTxn('employee_docs');
}
// Uploading, removing and maintaining the past-employee master ride the
// same flag as viewing: whoever HR trusts with the file is who files
// into it.
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

// ── Subjects: the two masters, read as one ──────────────
// A document belongs to a `employees` row or a `past_employees` row.
// Everything that isn't storage talks in terms of a SUBJECT so the
// difference stays in these few functions.

// The upload form's <option> values, and what comes back in $_POST.
// "e:12" = employees.id 12, "p:3" = past_employees.id 3.
function empDocParseSubject(string $raw): ?array {
    if (!preg_match('/^([ep]):(\d+)$/', trim($raw), $m)) return null;
    $id = (int)$m[2];
    if ($id < 1) return null;
    return ['kind' => $m[1] === 'e' ? 'current' : 'past', 'id' => $id];
}

// Flatten one row of empDocList() / empDocRow() into the fields the
// pages print, whichever master it came from.
function empDocRowSubject(array $d): array {
    $isPast = !empty($d['past_employee_id']);
    if ($isPast) {
        $left = $d['past_row_id'] !== null ? (string)($d['past_leave_date'] ?? '') : '';
        return [
            'kind'      => 'past',
            'id'        => (int)$d['past_employee_id'],
            'link'      => '?page=employee_docs&past=' . (int)$d['past_employee_id'],
            'code'      => (string)$d['employee_code'],
            'name'      => (string)$d['employee_name'],
            'dept'      => (string)($d['past_department'] ?? ''),
            'left'      => true,
            'left_date' => $left,
            'orphan'    => $d['past_row_id'] === null,
        ];
    }
    // A document whose employees row has vanished counts as left — it is
    // certainly not someone still serving.
    return [
        'kind'      => 'current',
        'id'        => (int)$d['employee_id'],
        'link'      => '?page=employee_docs&emp=' . (int)$d['employee_id'],
        'code'      => (string)$d['employee_code'],
        'name'      => (string)$d['employee_name'],
        'dept'      => (string)($d['department_name'] ?? ''),
        'left'      => $d['emp_row_id'] === null || !(int)$d['is_active'],
        'left_date' => (string)($d['deactivated_at'] ?? ''),
        'orphan'    => $d['emp_row_id'] === null,
    ];
}

// ── Queries ─────────────────────────────────────────────
// One row per document, joined to BOTH masters — exactly one of the two
// joins matches. Both are LEFT because the archive has to keep reading
// even if the master row is gone: employee_code / employee_name on the
// document itself are the fallback, which is why they are copied at
// upload time.
//
// $f keys: search, types[], depts[], status[] ('serving'|'left'),
//          emp_id, past_id, include_deleted, only_deleted.
function empDocList(array $f): array {
    if (!empDocSchemaReady()) return [];
    $sql = 'SELECT d.*,
                   e.id            AS emp_row_id,
                   e.full_name     AS current_name,
                   e.is_active,
                   e.deactivated_at,
                   e.department_id,
                   dep.department_name,
                   p.id              AS past_row_id,
                   p.full_name       AS past_name,
                   p.department_name AS past_department,
                   p.designation     AS past_designation,
                   p.leave_date      AS past_leave_date
            FROM   employee_documents d
            LEFT JOIN employees      e   ON e.id   = d.employee_id
            LEFT JOIN departments    dep ON dep.id = e.department_id
            LEFT JOIN past_employees p   ON p.id   = d.past_employee_id
            WHERE  1=1';
    $p = [];

    if (!empty($f['only_deleted'])) {
        $sql .= ' AND d.deleted_at IS NOT NULL';
    } elseif (empty($f['include_deleted'])) {
        $sql .= ' AND d.deleted_at IS NULL';
    }

    $empId  = (int)($f['emp_id']  ?? 0);
    $pastId = (int)($f['past_id'] ?? 0);
    if ($empId  > 0) { $sql .= ' AND d.employee_id = ?';      $p[] = $empId;  }
    if ($pastId > 0) { $sql .= ' AND d.past_employee_id = ?'; $p[] = $pastId; }

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

    // Department: an `employees` row carries a department id, a past
    // employee only the name the old record used. Match on either, so
    // filtering by department does not silently drop the past staff.
    $depts = array_values(array_filter(array_map('intval', (array)($f['depts'] ?? []))));
    if ($depts) {
        $names = [];
        foreach (getDepartments() as $row) {
            if (in_array((int)$row['id'], $depts, true)) $names[] = (string)$row['department_name'];
        }
        $idPh = implode(',', array_fill(0, count($depts), '?'));
        if ($names) {
            $namePh = implode(',', array_fill(0, count($names), '?'));
            $sql   .= " AND (e.department_id IN ($idPh) OR p.department_name IN ($namePh))";
            foreach ($depts as $d) $p[] = $d;
            foreach ($names as $n) $p[] = $n;
        } else {
            $sql .= " AND e.department_id IN ($idPh)";
            foreach ($depts as $d) $p[] = $d;
        }
    }

    // Past employees are left by definition, and so is a document whose
    // employees row has vanished.
    $status = array_values(array_intersect((array)($f['status'] ?? []), ['serving', 'left']));
    if (count($status) === 1) {
        $sql .= $status[0] === 'serving'
            ? ' AND d.past_employee_id IS NULL AND e.is_active = 1'
            : ' AND (d.past_employee_id IS NOT NULL OR e.is_active = 0 OR e.id IS NULL)';
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
            'SELECT d.*,
                    e.id AS emp_row_id, e.full_name AS current_name, e.is_active, e.deactivated_at,
                    dep.department_name,
                    p.id AS past_row_id, p.full_name AS past_name,
                    p.department_name AS past_department, p.leave_date AS past_leave_date
             FROM   employee_documents d
             LEFT JOIN employees      e   ON e.id   = d.employee_id
             LEFT JOIN departments    dep ON dep.id = e.department_id
             LEFT JOIN past_employees p   ON p.id   = d.past_employee_id
             WHERE  d.id = ?'
        );
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        return null;
    }
}

// Headline numbers for the archive. The ex-staff pair is the one that
// matters — everyone who has LEFT and still has a file here, past
// employees included, and the documents held for them.
function empDocStats(): array {
    $out = ['docs' => 0, 'employees' => 0, 'left_docs' => 0, 'left_staff' => 0, 'bytes' => 0];
    if (!empDocSchemaReady()) return $out;
    try {
        $r = getDb()->query(
            'SELECT COUNT(*)                        AS docs,
                    COUNT(DISTINCT d.employee_code) AS employees,
                    COALESCE(SUM(d.file_size), 0)   AS bytes,
                    SUM(CASE WHEN d.past_employee_id IS NULL AND e.is_active = 1
                             THEN 0 ELSE 1 END)     AS left_docs,
                    COUNT(DISTINCT CASE WHEN d.past_employee_id IS NULL AND e.is_active = 1
                                        THEN NULL ELSE d.employee_code END) AS left_staff
             FROM   employee_documents d
             LEFT JOIN employees e ON e.id = d.employee_id
             WHERE  d.deleted_at IS NULL'
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach ($out as $k => $_) $out[$k] = (int)($r[$k] ?? 0);
    } catch (Exception $e) { /* leave zeros */ }
    return $out;
}

// How many documents each serving-system employee has, keyed by
// employees.id — used to put a count on the Employees list without a
// query per row. $includeRemoved counts soft-deleted rows too, which is
// what "does anything still point at this record?" has to ask.
function empDocCountsByEmployee(bool $includeRemoved = false): array {
    return empDocCountsBy('employee_id', $includeRemoved);
}
// The same for the past-employee master, keyed by past_employees.id.
function empDocCountsByPastEmployee(bool $includeRemoved = false): array {
    return empDocCountsBy('past_employee_id', $includeRemoved);
}
function empDocCountsBy(string $col, bool $includeRemoved = false): array {
    if (!empDocSchemaReady()) return [];
    if (!in_array($col, ['employee_id', 'past_employee_id'], true)) return [];
    try {
        $live = $includeRemoved ? '' : ' deleted_at IS NULL AND';
        $rows = getDb()->query(
            "SELECT {$col} AS subject_id, COUNT(*) AS n
             FROM   employee_documents
             WHERE {$live} {$col} IS NOT NULL
             GROUP  BY {$col}"
        )->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) $out[(int)$r['subject_id']] = (int)$r['n'];
        return $out;
    } catch (Exception $e) {
        return [];
    }
}

// Both masters, for the upload picker — ['current' => [...], 'past' => [...]].
// The employee list page defaults to active-only; this one must not,
// because filing a leaver's paperwork after their last day is the normal
// case.
function empDocSubjectOptions(): array {
    $out = ['current' => [], 'past' => []];
    try {
        $out['current'] = getDb()->query(
            'SELECT e.id, e.employee_code, e.full_name, e.is_active, e.deactivated_at,
                    d.department_name
             FROM   employees e
             LEFT JOIN departments d ON d.id = e.department_id
             ORDER  BY e.is_active DESC, e.full_name'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* leave empty */ }
    $out['past'] = pastEmpList('');
    return $out;
}

// =========================================================
// Past-employee master
// =========================================================
function pastEmpList(string $search = ''): array {
    if (!empDocSchemaReady()) return [];
    try {
        $sql = 'SELECT * FROM past_employees WHERE 1=1';
        $p   = [];
        $search = trim($search);
        if ($search !== '') {
            $sql .= ' AND (employee_code LIKE ? OR full_name LIKE ?
                           OR department_name LIKE ? OR designation LIKE ?
                           OR location_name LIKE ?)';
            $like = "%{$search}%";
            array_push($p, $like, $like, $like, $like, $like);
        }
        $st = getDb()->prepare($sql . ' ORDER BY full_name');
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function pastEmpRow(int $id): ?array {
    if (!empDocSchemaReady() || $id < 1) return null;
    try {
        $st = getDb()->prepare('SELECT * FROM past_employees WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        return null;
    }
}

// The one row an employee_code matches, or null. Used by the CSV import
// so re-running it updates rather than duplicating, and by the form to
// warn about a code already in the master.
function pastEmpByCode(string $code, int $exceptId = 0): ?array {
    $code = trim($code);
    if ($code === '' || !empDocSchemaReady()) return null;
    try {
        $st = getDb()->prepare(
            'SELECT * FROM past_employees WHERE employee_code = ? AND id <> ? ORDER BY id LIMIT 1'
        );
        $st->execute([$code, $exceptId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        return null;
    }
}

// Old registers are written in whatever the person typing them used:
// 2018-07-01, 01-07-2018, 01/07/2018, 1.7.2018. Day-first when it is
// ambiguous, because that is how the office writes dates. Returns
// 'YYYY-MM-DD', or null when it is not a date at all.
function pastEmpParseDate(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') return null;
    if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $raw, $m)) {
        [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    } elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})$/', $raw, $m)) {
        [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        if ($y < 100) $y += $y < 70 ? 2000 : 1900;
    } else {
        return null;
    }
    return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
}

// ── Handler: save one past employee ─────────────────────
function doSavePastEmployee(): void {
    $back = 'index.php?page=past_employees';
    if (!empDocCanManage() || !empDocSchemaReady()) {
        flash('error', 'You do not have permission to edit the past-employee master.');
        header("Location: {$back}"); exit;
    }
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['full_name'] ?? ''));
    $code = trim((string)($_POST['employee_code'] ?? ''));
    $form = 'index.php?page=past_employee_form' . ($id ? '&id=' . $id : '');
    if ($name === '') {
        flash('error', 'A name is required — everything else can be filled in later.');
        header("Location: {$form}"); exit;
    }
    $join  = pastEmpParseDate((string)($_POST['join_date']  ?? ''));
    $leave = pastEmpParseDate((string)($_POST['leave_date'] ?? ''));
    if ($join && $leave && $leave < $join) {
        flash('error', 'The leaving date is before the joining date.');
        header("Location: {$form}"); exit;
    }
    $vals = [
        $code !== '' ? mb_substr($code, 0, 50) : null,
        mb_substr($name, 0, 100),
        trim((string)($_POST['department_name'] ?? '')) ?: null,
        trim((string)($_POST['designation']     ?? '')) ?: null,
        trim((string)($_POST['location_name']   ?? '')) ?: null,
        trim((string)($_POST['phone']           ?? '')) ?: null,
        trim((string)($_POST['email']           ?? '')) ?: null,
        $join,
        $leave,
        trim((string)($_POST['leave_reason']    ?? '')) ?: null,
        trim((string)($_POST['notes']           ?? '')) ?: null,
    ];
    try {
        if ($id > 0) {
            getDb()->prepare(
                'UPDATE past_employees
                 SET    employee_code=?, full_name=?, department_name=?, designation=?,
                        location_name=?, phone=?, email=?, join_date=?, leave_date=?,
                        leave_reason=?, notes=?, updated_by=?
                 WHERE  id=?'
            )->execute([...$vals, myCode(), $id]);
            flash('success', 'Past employee updated.');
        } else {
            getDb()->prepare(
                'INSERT INTO past_employees
                    (employee_code, full_name, department_name, designation, location_name,
                     phone, email, join_date, leave_date, leave_reason, notes, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([...$vals, myCode()]);
            $id = (int)getDb()->lastInsertId();
            flash('success', $name . ' added to the past-employee master — file their documents from here.');
            header('Location: index.php?page=employee_doc_upload&past=' . $id); exit;
        }
    } catch (Exception $e) {
        flash('error', 'Could not save: ' . $e->getMessage());
        header("Location: {$form}"); exit;
    }
    header("Location: {$back}"); exit;
}

// ── Handler: delete one past employee ───────────────────
// Refused while documents are filed against them: this master exists to
// label those documents, so removing it would orphan them.
function doDelPastEmployee(): void {
    $back = 'index.php?page=past_employees';
    $id   = (int)($_POST['id'] ?? 0);
    if (!empDocCanManage() || !($row = pastEmpRow($id))) {
        flash('error', 'You do not have permission to remove past employees.');
        header("Location: {$back}"); exit;
    }
    try {
        $st = getDb()->prepare('SELECT COUNT(*) FROM employee_documents WHERE past_employee_id = ?');
        $st->execute([$id]);
        $n = (int)$st->fetchColumn();
        if ($n > 0) {
            flash('error', $row['full_name'] . ' still has ' . $n . ' document(s) on file. '
                         . 'Remove those first — deleting the record would leave them unlabelled.');
            header("Location: {$back}"); exit;
        }
        getDb()->prepare('DELETE FROM past_employees WHERE id = ?')->execute([$id]);
        flash('success', $row['full_name'] . ' removed from the past-employee master.');
    } catch (Exception $e) {
        flash('error', 'Could not remove: ' . $e->getMessage());
    }
    header("Location: {$back}"); exit;
}

// ── CSV import ──────────────────────────────────────────
// A 2018 backlog is hundreds of names; typing them in one form at a time
// is not a plan. Keyed on the code when a row has one, so a corrected
// sheet can be fed back in and updates rather than duplicates.
function pastEmpCsvColumns(): array {
    return [
        'employee_code'   => ['label' => 'Code',        'required' => false,
                              'aliases' => ['code', 'employee code', 'emp code', 'empcode']],
        'full_name'       => ['label' => 'Name',        'required' => true,
                              'aliases' => ['name', 'full name', 'employee name']],
        'department_name' => ['label' => 'Department',  'required' => false,
                              'aliases' => ['department', 'dept']],
        'designation'     => ['label' => 'Designation', 'required' => false,
                              'aliases' => ['designation', 'role', 'post']],
        'location_name'   => ['label' => 'Location',    'required' => false,
                              'aliases' => ['location', 'outlet', 'store', 'branch']],
        'phone'           => ['label' => 'Phone',       'required' => false,
                              'aliases' => ['phone', 'mobile', 'contact']],
        'email'           => ['label' => 'Email',       'required' => false,
                              'aliases' => ['email', 'mail']],
        'join_date'       => ['label' => 'Join Date',   'required' => false,
                              'aliases' => ['join date', 'joining date', 'doj', 'date of joining']],
        'leave_date'      => ['label' => 'Leave Date',  'required' => false,
                              'aliases' => ['leave date', 'leaving date', 'dol', 'exit date',
                                            'date of leaving', 'relieving date']],
        'leave_reason'    => ['label' => 'Leave Reason','required' => false,
                              'aliases' => ['leave reason', 'reason', 'exit reason']],
        'notes'           => ['label' => 'Notes',       'required' => false,
                              'aliases' => ['notes', 'remark', 'remarks']],
    ];
}

function pastEmpCsvHeaderLine(): string {
    return implode(',', array_map(fn($c) => $c['label'], pastEmpCsvColumns()));
}

// Same normalisation the inward register uses: drop a trailing
// parenthetical note, fold underscores/hyphens to spaces, lowercase.
function pastEmpNormalizeHeader($raw): string {
    $h = trim((string)$raw);
    $h = preg_replace('/\s*\([^)]*\)\s*$/u', '', $h);
    $h = str_replace(['_', '-'], ' ', $h);
    $h = preg_replace('/\s+/u', ' ', $h);
    return strtolower(trim((string)$h));
}

// Header cells → [canonical key => column position], plus the labels of
// any REQUIRED column the sheet does not carry. Order does not matter and
// spare columns are ignored, so an export from the old system can be fed
// in with its extra columns left in place.
function pastEmpMapCsvHeader(array $header): array {
    $spec = pastEmpCsvColumns();
    $idx  = [];
    foreach ($header as $pos => $cell) {
        $norm = pastEmpNormalizeHeader($cell);
        if ($norm === '') continue;
        foreach ($spec as $key => $def) {
            if (isset($idx[$key])) continue;
            if ($norm === strtolower($def['label']) || in_array($norm, $def['aliases'], true)) {
                $idx[$key] = $pos;
                break;
            }
        }
    }
    $missing = [];
    foreach ($spec as $key => $def) {
        if ($def['required'] && !isset($idx[$key])) $missing[] = $def['label'];
    }
    return [$idx, $missing];
}

function doPastEmployeesSampleCsv(): void {
    if (!empDocCanManage()) { http_response_code(403); echo 'Access denied.'; return; }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="past_employees_sample.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens it cleanly
    fputcsv($out, array_map(fn($c) => $c['label'], pastEmpCsvColumns()), ',', '"', '');
    // Name is the only column that must be filled. The three rows show
    // the shapes that turn up in an old register: a full record, one with
    // no code at all, and dates written the office way.
    fputcsv($out, ['1042', 'Ramesh Chauhan', 'Retail Ops', 'Counter Sales', 'Satellite',
                   '9876543210', '', '2018-04-02', '2021-11-30', 'Resigned', ''], ',', '"', '');
    fputcsv($out, ['', 'Kiran Desai', 'Accounts', 'Accounts Assistant', 'Head Office',
                   '', 'kiran@example.com', '01-06-2018', '15-03-2020', 'Resigned',
                   'No code in the old register'], ',', '"', '');
    fputcsv($out, ['1108', 'Suresh Patel', 'Factory', 'Helper', 'Factory',
                   '', '', '12/01/2019', '30/06/2022', 'Contract ended', ''], ',', '"', '');
    fclose($out);
    exit;
}

function doImportPastEmployees(): void {
    $back = 'index.php?page=past_employees';
    if (!empDocCanManage() || !empDocSchemaReady()) {
        flash('error', 'You do not have permission to import past employees.');
        header("Location: {$back}"); exit;
    }
    $file = $_FILES['csv'] ?? null;
    if (!$file || !is_uploaded_file($file['tmp_name'] ?? '')) {
        flash('error', 'Pick a CSV file to import.');
        header("Location: {$back}"); exit;
    }
    if ($file['size'] > PASTEMP_CSV_MAX_BYTES) {
        flash('error', 'File too large (max ' . (PASTEMP_CSV_MAX_BYTES / 1024 / 1024) . ' MB).');
        header("Location: {$back}"); exit;
    }
    if (strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        flash('error', 'Only .csv files are allowed. Save the sheet as CSV and try again.');
        header("Location: {$back}"); exit;
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    if (!in_array($mime, ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'], true)) {
        flash('error', 'That does not look like a CSV (mime: ' . $mime . ').');
        header("Location: {$back}"); exit;
    }
    $fh = fopen($file['tmp_name'], 'r');
    if (!$fh) { flash('error', 'Could not read the file.'); header("Location: {$back}"); exit; }

    // Strip the BOM Excel writes, else the first header cell won't match.
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($fh);

    $header = fgetcsv($fh, null, ',', '"', '');
    if (!$header) {
        fclose($fh);
        flash('error', 'The CSV has no header row. Expected: ' . pastEmpCsvHeaderLine());
        header("Location: {$back}"); exit;
    }
    [$idx, $missing] = pastEmpMapCsvHeader($header);
    if ($missing) {
        fclose($fh);
        flash('error', 'CSV missing columns: ' . implode(', ', $missing)
                     . '. Expected header: ' . pastEmpCsvHeaderLine());
        header("Location: {$back}"); exit;
    }
    $cell = fn(array $r, string $key) => isset($idx[$key]) ? trim((string)($r[$idx[$key]] ?? '')) : '';

    $db = getDb();
    $ins = $db->prepare(
        'INSERT INTO past_employees
            (employee_code, full_name, department_name, designation, location_name,
             phone, email, join_date, leave_date, leave_reason, notes, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $upd = $db->prepare(
        'UPDATE past_employees
         SET    full_name=?, department_name=?, designation=?, location_name=?,
                phone=?, email=?, join_date=?, leave_date=?, leave_reason=?, notes=?,
                updated_by=?
         WHERE  id=?'
    );

    $added = 0; $updated = 0; $errors = []; $line = 1; $rows = 0;
    while (($r = fgetcsv($fh, null, ',', '"', '')) !== false) {
        $line++;
        if (count(array_filter($r, fn($v) => trim((string)$v) !== '')) === 0) continue;
        if (++$rows > PASTEMP_CSV_MAX_ROWS) {
            $errors[] = 'Stopped at ' . PASTEMP_CSV_MAX_ROWS . ' rows — split the sheet and import the rest.';
            break;
        }
        $name = $cell($r, 'full_name');
        if ($name === '') { $errors[] = "Line {$line}: Name is required."; continue; }

        $rawJoin  = $cell($r, 'join_date');
        $rawLeave = $cell($r, 'leave_date');
        $join     = pastEmpParseDate($rawJoin);
        $leave    = pastEmpParseDate($rawLeave);
        if ($rawJoin  !== '' && $join  === null) $errors[] = "Line {$line}: Join Date \"{$rawJoin}\" is not a date — kept blank.";
        if ($rawLeave !== '' && $leave === null) $errors[] = "Line {$line}: Leave Date \"{$rawLeave}\" is not a date — kept blank.";

        $code = mb_substr($cell($r, 'employee_code'), 0, 50);
        $vals = [
            mb_substr($name, 0, 100),
            $cell($r, 'department_name') ?: null,
            $cell($r, 'designation')     ?: null,
            $cell($r, 'location_name')   ?: null,
            $cell($r, 'phone')           ?: null,
            $cell($r, 'email')           ?: null,
            $join,
            $leave,
            $cell($r, 'leave_reason')    ?: null,
            $cell($r, 'notes')           ?: null,
        ];
        try {
            // A code already in the master is the SAME person — update
            // them, so a corrected sheet can be re-imported safely. A row
            // with no code has nothing to match on and is always added.
            $existing = $code !== '' ? pastEmpByCode($code) : null;
            if ($existing) {
                $upd->execute([...$vals, myCode(), (int)$existing['id']]);
                $updated++;
            } else {
                $ins->execute([$code !== '' ? $code : null, ...$vals, myCode()]);
                $added++;
            }
        } catch (Exception $e) {
            $errors[] = "Line {$line}: could not be saved.";
        }
    }
    fclose($fh);

    $msg = "Import done — {$added} added, {$updated} updated.";
    if ($errors) {
        $shown = array_slice($errors, 0, 10);
        $msg  .= ' ' . count($errors) . ' problem(s): ' . implode(' ', $shown)
              .  (count($errors) > 10 ? ' …' : '');
    }
    flash($errors ? 'error' : 'success', $msg);
    header("Location: {$back}"); exit;
}

// ── Handler: upload documents ───────────────────────────
function doUploadEmployeeDocs(): void {
    $back = 'index.php?page=employee_docs';
    if (!empDocCanManage() || !empDocSchemaReady()) {
        flash('error', 'You do not have permission to file employee documents.');
        header("Location: {$back}"); exit;
    }
    $subject = empDocParseSubject((string)($_POST['subject'] ?? ''));
    $type    = (string)($_POST['doc_type'] ?? 'other');
    $title   = trim((string)($_POST['title']    ?? ''));
    $docDate = trim((string)($_POST['doc_date'] ?? ''));
    $notes   = trim((string)($_POST['notes']    ?? ''));
    if (!isset(EMPDOC_TYPES[$type])) $type = 'other';
    if ($docDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $docDate)) $docDate = '';

    $form = 'index.php?page=employee_doc_upload';
    if ($subject) {
        $form .= $subject['kind'] === 'past' ? '&past=' . $subject['id'] : '&emp=' . $subject['id'];
    }

    // Resolve the subject against whichever master it names.
    $who = null;
    if ($subject && $subject['kind'] === 'current') {
        $row = getEmployee($subject['id']);
        if ($row) $who = ['employee_id' => (int)$row['id'], 'past_employee_id' => null,
                          'code' => (string)$row['employee_code'], 'name' => (string)$row['full_name'],
                          'link' => '&emp=' . (int)$row['id']];
    } elseif ($subject) {
        $row = pastEmpRow($subject['id']);
        if ($row) $who = ['employee_id' => null, 'past_employee_id' => (int)$row['id'],
                          'code' => (string)($row['employee_code'] ?: '—'), 'name' => (string)$row['full_name'],
                          'link' => '&past=' . (int)$row['id']];
    }
    if (!$who) {
        flash('error', 'Pick the person this document belongs to. '
                     . 'If they pre-date this system, add them under Past Employees first.');
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
            (employee_id, past_employee_id, employee_code, employee_name, doc_type, title, doc_date,
             original_name, stored_name, bucket_month, mime_type, file_size, sha256,
             notes, uploaded_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    // Same person, same bytes = the file is already here. Keyed on
    // whichever master column identifies them.
    $dupeCol = $who['employee_id'] !== null ? 'employee_id' : 'past_employee_id';
    $dupeVal = $who['employee_id'] ?? $who['past_employee_id'];
    $dupe    = $db->prepare(
        "SELECT original_name FROM employee_documents
         WHERE  {$dupeCol} = ? AND sha256 = ? AND deleted_at IS NULL LIMIT 1"
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
        $dupe->execute([$dupeVal, $sha]);
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
                $who['employee_id'],
                $who['past_employee_id'],
                $who['code'],
                $who['name'],
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
              $saved . ' document(s) filed against ' . $who['code'] . ' — ' . $who['name'] . '.' . $note);
        header('Location: index.php?page=employee_docs' . $who['link']); exit;
    }
    flash('error', 'Nothing was saved.' . ($note !== '' ? $note : ' Pick a PDF, JPG or PNG.'));
    header("Location: {$form}"); exit;
}

// ── Handler: remove a document (soft) ───────────────────
function doDeleteEmployeeDoc(): void {
    $id  = (int)($_POST['id'] ?? 0);
    $row = empDocRow($id);
    $back = 'index.php?page=employee_docs';
    if ($row) {
        $s = empDocRowSubject($row);
        $back .= ($s['kind'] === 'past' ? '&past=' : '&emp=') . $s['id'];
    }
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

// ── Handler: restore a document (superadmin) ────────────
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
    $empId   = (int)($_GET['emp']  ?? 0);
    $pastId  = (int)($_GET['past'] ?? 0);
    $search  = trim((string)($_GET['search'] ?? ''));
    $doLoad  = isset($_GET['filter']);
    $removed = !empty($_GET['removed']) && empDocCanRestore();
    $depts   = getDepartments();

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

    // One person's file, from whichever master.
    $who = null;
    if ($pastId > 0 && ($row = pastEmpRow($pastId))) {
        $who = ['kind' => 'past', 'id' => $pastId, 'qs' => '&past=' . $pastId,
                'code' => (string)($row['employee_code'] ?: '—'), 'name' => (string)$row['full_name'],
                'left' => true, 'left_date' => (string)($row['leave_date'] ?? ''),
                'sub'  => trim(implode(' · ', array_filter([
                              (string)($row['designation'] ?? ''),
                              (string)($row['department_name'] ?? ''),
                              (string)($row['location_name'] ?? '')])))];
    } elseif ($empId > 0 && ($row = getEmployee($empId))) {
        $who = ['kind' => 'current', 'id' => $empId, 'qs' => '&emp=' . $empId,
                'code' => (string)$row['employee_code'], 'name' => (string)$row['full_name'],
                'left' => !(int)$row['is_active'], 'left_date' => '', 'sub' => ''];
    }

    $docs = empDocList([
        'search'       => $search,
        'types'        => $types,
        'depts'        => $deptId,
        'status'       => $status,
        'emp_id'       => $who && $who['kind'] === 'current' ? $who['id'] : 0,
        'past_id'      => $who && $who['kind'] === 'past'    ? $who['id'] : 0,
        'only_deleted' => $removed,
    ]);
    $stats = empDocStats();
?>
<div class="page-header">
    <h2><?= $who ? 'Documents · ' . h($who['name']) : 'Employee Documents' ?></h2>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php if ($who): ?>
        <a href="?page=employee_docs" class="btn btn-ghost">← All Documents</a>
        <?php elseif (empDocCanManage()): ?>
        <a href="?page=past_employees" class="btn btn-ghost">Past Employees</a>
        <?php endif; ?>
        <?php if (empDocCanManage()): ?>
        <a href="?page=employee_doc_upload<?= $who ? $who['qs'] : '' ?>" class="btn btn-primary">+ Upload Document</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($removed): ?>
<div class="alert alert-info">
    Showing <strong>removed</strong> documents. Their files are still on disk — restoring one puts it back in the archive.
    <a href="?page=employee_docs">← back to the archive</a>
</div>
<?php endif; ?>

<?php if ($who): ?>
<div class="form-card" style="margin-bottom:18px">
    <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:center">
        <div><strong><code><?= h($who['code']) ?></code> — <?= h($who['name']) ?></strong></div>
        <div>
            <?php if ($who['kind'] === 'past'): ?>
                <span class="badge badge-purple">Past Employee</span>
            <?php elseif ($who['left']): ?>
                <span class="badge badge-red">Left</span>
            <?php else: ?>
                <span class="badge badge-green">Serving</span>
            <?php endif; ?>
        </div>
        <?php if (!empty($who['sub'])): ?>
        <div class="text-muted" style="font-size:12px"><?= h($who['sub']) ?></div>
        <?php endif; ?>
        <?php if (!empty($who['left_date'])): ?>
        <div class="text-muted" style="font-size:12px">Left <?= date('d M Y', strtotime($who['left_date'])) ?></div>
        <?php endif; ?>
        <div class="text-muted" style="font-size:12px"><?= count($docs) ?> document(s) on file</div>
        <div style="margin-left:auto">
            <?php if ($who['kind'] === 'past' && empDocCanManage()): ?>
            <a href="?page=past_employee_form&id=<?= (int)$who['id'] ?>" class="btn btn-sm btn-secondary">Edit Record</a>
            <?php elseif ($who['kind'] === 'current' && canManageEmployees()): ?>
            <a href="?page=edit&id=<?= (int)$who['id'] ?>" class="btn btn-sm btn-secondary">Employee Record</a>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($who['left']): ?>
    <p class="hint" style="margin-top:10px">
        <?= $who['kind'] === 'past'
            ? 'This person pre-dates the current system — they exist only in the past-employee master, which is what labels their documents.'
            : 'This employee has left. Their documents are kept here indefinitely — deactivating an employee never removes their file.' ?>
    </p>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="stats-grid-sm" style="margin-bottom:18px">
    <?php foreach ([
        ['Documents',     $stats['docs'],       ''],
        ['People',        $stats['employees'],  ''],
        ['Ex-Staff',      $stats['left_staff'], 'stat-red'],
        ['Ex-Staff Docs', $stats['left_docs'],  'stat-red'],
        ['Storage',       formatBytes($stats['bytes']), ''],
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
        msFilterField('status', 'Staff', ['serving' => 'Serving', 'left' => 'Left / Past'], $status, '170px', 'Both');
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
            <?php if (!$who): ?><th>Person</th><?php endif; ?>
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
        $s       = empDocRowSubject($d);
        $missing = empDocPath($d) === null;
    ?>
        <tr class="<?= $s['left'] ? 'row-inactive' : '' ?>">
            <?php if (!$who): ?>
            <td>
                <a href="<?= h($s['link']) ?>"><code><?= h($s['code'] !== '' ? $s['code'] : '—') ?></code></a><br>
                <?= h($s['name']) ?>
                <?php if ($s['kind'] === 'past'): ?>
                    <br><span class="badge badge-purple">Past Employee</span>
                <?php elseif ($s['left']): ?>
                    <br><span class="badge badge-red">Left</span>
                <?php endif; ?>
                <?php if ($s['left_date'] !== ''): ?>
                <span class="text-muted" style="font-size:11px"><?= date('d M Y', strtotime($s['left_date'])) ?></span>
                <?php endif; ?>
                <?php if ($s['dept'] !== ''): ?>
                <br><span class="text-muted" style="font-size:11px"><?= h($s['dept']) ?></span>
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
    $empId  = (int)($_GET['emp']  ?? 0);
    $pastId = (int)($_GET['past'] ?? 0);
    $picked = $pastId > 0 ? 'p:' . $pastId : ($empId > 0 ? 'e:' . $empId : '');
    $backQs = $pastId > 0 ? '&past=' . $pastId : ($empId > 0 ? '&emp=' . $empId : '');
    $opts   = empDocSubjectOptions();
?>
<div class="page-header">
    <h2>Upload Employee Document</h2>
    <a href="?page=employee_docs<?= h($backQs) ?>" class="btn btn-ghost">← Back</a>
</div>
<div class="form-card">
<form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="action" value="upload_employee_doc">
    <div class="form-grid">
        <div class="form-group">
            <label>Person <span class="required">*</span></label>
            <select name="subject" class="form-control" required>
                <option value="">— Select —</option>
                <optgroup label="Current system — serving &amp; deactivated">
                    <?php foreach ($opts['current'] as $s): ?>
                    <option value="e:<?= (int)$s['id'] ?>" <?= $picked === 'e:' . (int)$s['id'] ? 'selected' : '' ?>>
                        <?= h($s['employee_code'] . ' — ' . $s['full_name']) ?><?php
                            if (!(int)$s['is_active']) {
                                echo '  · LEFT';
                                if (!empty($s['deactivated_at'])) echo ' ' . date('M Y', strtotime((string)$s['deactivated_at']));
                            }
                        ?>
                    </option>
                    <?php endforeach; ?>
                </optgroup>
                <optgroup label="Past employees — not in the current system">
                    <?php if (!$opts['past']): ?>
                    <option value="" disabled>None added yet — use Past Employees to add them</option>
                    <?php else: foreach ($opts['past'] as $s): ?>
                    <option value="p:<?= (int)$s['id'] ?>" <?= $picked === 'p:' . (int)$s['id'] ? 'selected' : '' ?>>
                        <?= h(($s['employee_code'] ?: 'no code') . ' — ' . $s['full_name']) ?><?php
                            if (!empty($s['leave_date'])) echo '  · left ' . date('M Y', strtotime((string)$s['leave_date']));
                        ?>
                    </option>
                    <?php endforeach; endif; ?>
                </optgroup>
            </select>
            <span class="hint">
                Someone who left before this system went live has no employee record here —
                <a href="?page=past_employee_form">add them as a past employee</a> and they appear in the second group.
            </span>
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
                up to <?= EMPDOC_MAX_FILES ?> files per upload. A file already on this person's
                record is not stored twice.
            </span>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Upload</button>
        <a href="?page=employee_docs<?= h($backQs) ?>" class="btn btn-ghost">Cancel</a>
    </div>
</form>
</div>
<?php
}

// ── Page: past-employee master ──────────────────────────
function pagePastEmployees(): void {
    if (!empDocCanManage()) { echo '<div class="alert alert-error">Access denied.</div>'; return; }
    if (!empDocSchemaReady()) {
        echo '<div class="alert alert-error">' . h(empDocSchemaNotice()) . '</div>'; return;
    }
    $search = trim((string)($_GET['search'] ?? ''));
    $rows   = pastEmpList($search);
    $counts = empDocCountsByPastEmployee();       // live, for the badge
    // Including removed documents, because a soft-deleted one is still on
    // disk and still restorable — deleting the record under it would leave
    // it unlabelled. Same rule doDelPastEmployee() enforces, so the button
    // only shows where the delete will actually go through.
    $held   = empDocCountsByPastEmployee(true);
?>
<div class="page-header">
    <h2>Past Employees</h2>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="?page=employee_docs" class="btn btn-ghost">← Documents</a>
        <a href="?page=past_employee_form" class="btn btn-primary">+ Add Past Employee</a>
    </div>
</div>
<div class="alert alert-info">
    People whose paperwork the archive holds but who have <strong>no record in the current system</strong> —
    typically anyone who left before it went live. This master exists only to label their documents:
    no login, no attendance, no payroll, and they appear in no other list in the app.
    Anyone still on the Employees page belongs there, not here.
</div>

<form method="GET" class="rpt-filter">
    <input type="hidden" name="page" value="past_employees">
    <span class="input-clear-wrap" style="flex:1 1 auto;min-width:200px">
        <input type="text" name="search" value="<?= h($search) ?>" placeholder="Search code, name, department, designation..." class="form-control">
        <button type="button" class="input-clear-btn" aria-label="Clear search" tabindex="-1">&times;</button>
    </span>
    <button class="btn btn-primary">Search</button>
    <a href="?page=past_employees" class="btn btn-ghost">Clear</a>
</form>

<div class="form-card" style="margin-bottom:18px">
    <div class="form-section-title">Import from CSV</div>
    <form method="POST" enctype="multipart/form-data"
          style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <input type="hidden" name="action" value="import_past_employees">
        <div class="form-group" style="flex:1 1 280px;margin:0">
            <label>CSV file</label>
            <input type="file" name="csv" class="form-control" accept=".csv,text/csv" required>
            <span class="hint">
                Header: <code><?= h(pastEmpCsvHeaderLine()) ?></code> — Name is the only one that must be filled.
                Dates may be written 2018-04-02 or 02-04-2018. A row whose Code already exists updates that
                record instead of adding a second copy, so a corrected sheet can be imported again.
            </span>
        </div>
        <div style="display:flex;gap:8px;margin-bottom:2px">
            <button class="btn btn-primary">Import</button>
            <a href="?page=past_employees_sample" class="btn btn-ghost">Sample CSV</a>
        </div>
    </form>
</div>

<div class="table-wrap" data-stack>
<table class="table">
    <thead>
        <tr>
            <th>Code</th><th>Name</th><th>Department / Designation</th><th>Location</th>
            <th>Joined</th><th>Left</th><th>Docs</th><th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
        <tr><td colspan="8" class="empty-row">
            <?= $search !== '' ? 'No past employee matches that search.' : 'No past employees added yet.' ?>
        </td></tr>
    <?php else: foreach ($rows as $r):
        $n     = $counts[(int)$r['id']] ?? 0;
        $anyOn = ($held[(int)$r['id']] ?? 0) > 0;
    ?>
        <tr>
            <td><code><?= h($r['employee_code'] ?: '—') ?></code></td>
            <td><?= h($r['full_name']) ?></td>
            <td>
                <?= h($r['department_name'] ?: '—') ?>
                <?php if (!empty($r['designation'])): ?>
                <br><span class="text-muted" style="font-size:11px"><?= h($r['designation']) ?></span>
                <?php endif; ?>
            </td>
            <td><?= h($r['location_name'] ?: '—') ?></td>
            <td><?= !empty($r['join_date'])  ? date('d M Y', strtotime((string)$r['join_date']))  : '—' ?></td>
            <td>
                <?= !empty($r['leave_date']) ? date('d M Y', strtotime((string)$r['leave_date'])) : '—' ?>
                <?php if (!empty($r['leave_reason'])): ?>
                <br><span class="text-muted" style="font-size:11px"><?= h($r['leave_reason']) ?></span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($n > 0): ?>
                <a href="?page=employee_docs&past=<?= (int)$r['id'] ?>" class="badge badge-blue"><?= $n ?></a>
                <?php else: ?>
                <span class="text-muted">0</span>
                <?php endif; ?>
            </td>
            <td class="actions">
                <a href="?page=employee_doc_upload&past=<?= (int)$r['id'] ?>" class="btn btn-sm btn-primary">Upload</a>
                <a href="?page=past_employee_form&id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-secondary">Edit</a>
                <?php if (!$anyOn): ?>
                <form method="POST" class="inline-form"
                      onsubmit="return confirm('Delete this past-employee record?')">
                    <input type="hidden" name="action" value="del_past_employee">
                    <input type="hidden" name="id"     value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-sm btn-danger">Delete</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
<p class="table-count"><?= count($rows) ?> past employee(s)</p>
<?php
}

// ── Page: past-employee add / edit ──────────────────────
function pagePastEmployeeForm(): void {
    if (!empDocCanManage()) { echo '<div class="alert alert-error">Access denied.</div>'; return; }
    if (!empDocSchemaReady()) {
        echo '<div class="alert alert-error">' . h(empDocSchemaNotice()) . '</div>'; return;
    }
    $id     = (int)($_GET['id'] ?? 0);
    $r      = $id > 0 ? pastEmpRow($id) : null;
    $isEdit = $r !== null;
?>
<div class="page-header">
    <h2><?= $isEdit ? 'Edit Past Employee' : 'Add Past Employee' ?></h2>
    <a href="?page=past_employees" class="btn btn-ghost">← Back</a>
</div>
<div class="form-card">
<form method="POST">
    <input type="hidden" name="action" value="save_past_employee">
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><?php endif; ?>
    <div class="form-grid">
        <div class="form-group">
            <label>Full Name <span class="required">*</span></label>
            <input type="text" name="full_name" class="form-control" required maxlength="100"
                   value="<?= h($r['full_name'] ?? '') ?>">
            <span class="hint">The only field that must be filled — an old record may carry nothing else.</span>
        </div>
        <div class="form-group">
            <label>Employee Code</label>
            <input type="text" name="employee_code" class="form-control" maxlength="50"
                   value="<?= h($r['employee_code'] ?? '') ?>" placeholder="Their code in the old register">
            <span class="hint">Leave blank if the old record has none. It need not be unique — codes get reissued.</span>
        </div>
        <div class="form-group">
            <label>Department</label>
            <input type="text" name="department_name" class="form-control" maxlength="100"
                   value="<?= h($r['department_name'] ?? '') ?>">
            <span class="hint">Typed, not picked: the department they worked in may no longer exist.</span>
        </div>
        <div class="form-group">
            <label>Designation</label>
            <input type="text" name="designation" class="form-control" maxlength="100"
                   value="<?= h($r['designation'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>Location / Outlet</label>
            <input type="text" name="location_name" class="form-control" maxlength="100"
                   value="<?= h($r['location_name'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>Phone</label>
            <input type="text" name="phone" class="form-control" maxlength="20"
                   value="<?= h($r['phone'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" class="form-control" maxlength="100"
                   value="<?= h($r['email'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>Join Date</label>
            <input type="date" name="join_date" class="form-control" value="<?= h($r['join_date'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>Leave Date</label>
            <input type="date" name="leave_date" class="form-control" value="<?= h($r['leave_date'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>Leaving Reason</label>
            <input type="text" name="leave_reason" class="form-control" maxlength="255"
                   value="<?= h($r['leave_reason'] ?? '') ?>" placeholder="Resigned, contract ended, …">
        </div>
        <div class="form-group" style="grid-column:1/-1">
            <label>Notes</label>
            <textarea name="notes" class="form-control" rows="2"><?= h($r['notes'] ?? '') ?></textarea>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Add &amp; Upload Documents' ?></button>
        <a href="?page=past_employees" class="btn btn-ghost">Cancel</a>
        <?php if ($isEdit): ?>
        <a href="?page=employee_docs&past=<?= (int)$r['id'] ?>" class="btn btn-secondary">Documents</a>
        <?php endif; ?>
    </div>
</form>
</div>
<?php
}
