<?php
// =========================================================
// Issue Tracking Module — CRUD, comments, attachments, workflow
// =========================================================

define('UPLOAD_DIR', __DIR__ . '/../uploads/issues/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ISSUE_ATT_BUCKET_SIZE', 500);     // group N issue folders inside a "1-500" / "501-1000" parent

// Bucket folder name for an issue id, e.g. id=247 → "1-500", id=1500 → "1001-1500".
// Stops `uploads/issues/` from sprawling once issue counts get into the
// thousands; OS filesystem listings stay quick and the directory is
// human-skimmable. Pure function — given the same id, always the same
// answer, so reads can recompute it without storing extra metadata.
function issueBucketDir(int $issueId): string {
    $size  = ISSUE_ATT_BUCKET_SIZE;
    $start = (int) (floor(max(1, $issueId) - 1) / $size) * $size + 1;
    $end   = $start + $size - 1;
    return $start . '-' . $end;
}

// Resolve the on-disk path of an issue's attachment folder. New uploads
// always go into the bucketed path; reads prefer the bucketed path but
// fall back to the legacy un-bucketed `uploads/issues/{id}/` so files
// saved before the bucketing change keep serving without a migration.
function issueAttachmentDir(int $issueId, ?int $commentId, bool $forWrite = false): string {
    $bucket = UPLOAD_DIR . issueBucketDir($issueId) . '/' . $issueId . '/';
    if ($commentId) $bucket .= 'comments/' . $commentId . '/';
    if ($forWrite) return $bucket;
    if (is_dir($bucket)) return $bucket;
    // Legacy fallback (pre-bucket layout).
    $legacy = UPLOAD_DIR . $issueId . '/';
    if ($commentId) $legacy .= 'comments/' . $commentId . '/';
    return is_dir($legacy) ? $legacy : $bucket;
}
define('ALLOWED_EXTENSIONS', ['jpg','jpeg','png','gif','pdf','doc','docx','xls','xlsx','txt','csv','zip']);
define('ALLOWED_MIMES', [
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
    'gif'  => ['image/gif'],
    'pdf'  => ['application/pdf'],
    'doc'  => ['application/msword'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    'xls'  => ['application/vnd.ms-excel','application/octet-stream','application/x-ole-storage','application/CDFV2'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/zip','application/octet-stream'],
    'txt'  => ['text/plain'],
    'csv'  => ['text/csv','text/plain','application/csv'],
    'zip'  => ['application/zip','application/x-zip-compressed'],
]);

// ── Status transitions ────────────────────────────────────
// 'open' and 'investigation_in_process' were removed; new issues start at
// 'assigned_to_concerned' (DB default).
function getAllowedTransitions(string $from, string $categoryGroup = ''): array {
    if ($categoryGroup === 'incident') {
        $map = [
            'assigned_to_concerned'     => ['resolved'],
            'resolved'                  => ['closed', 'assigned_to_concerned'],
            'closed'                    => [],
        ];
    } else {
        $map = [
            'assigned_to_concerned'     => ['in_progress', 'waiting_for_customer'],
            'waiting_for_customer'      => ['assigned_to_concerned', 'in_progress'],
            'in_progress'               => ['resolved', 'assigned_to_concerned', 'waiting_for_customer'],
            'resolved'                  => ['closed', 'in_progress'],
            'closed'                    => [],
        ];
    }
    return $map[$from] ?? [];
}

function validateTransition(string $from, string $to, string $categoryGroup = ''): bool {
    return in_array($to, getAllowedTransitions($from, $categoryGroup));
}

function statusLabel(string $s): string {
    $labels = [
        'waiting_for_customer' => 'Waiting for Reporter', 'assigned_to_concerned' => 'Assigned',
        'in_progress' => 'In Progress', 'resolved' => 'Resolved', 'closed' => 'Closed',
    ];
    return $labels[$s] ?? $s;
}

function statusBadgeClass(string $s): string {
    $map = [
        'waiting_for_customer' => 'badge-purple', 'assigned_to_concerned' => 'badge-blue',
        'in_progress' => 'badge-yellow', 'resolved' => 'badge-green', 'closed' => 'badge-grey',
    ];
    return $map[$s] ?? 'badge-grey';
}

function priorityBadgeClass(string $p): string {
    $map = ['low' => 'badge-grey', 'medium' => 'badge-blue', 'high' => 'badge-yellow', 'critical' => 'badge-red'];
    return $map[$p] ?? 'badge-grey';
}

// ── Access check: can user see this issue? ────────────────
function canViewIssue(array $issue): bool {
    if (isSuperadmin() || canManageIssues()) return true;
    $code = myCode();
    if ($issue['reporter_code'] === $code) return true;
    if (myLocationId() > 0 && (int)($issue['location_id'] ?? 0) === myLocationId()) return true;
    $deptId = myDeptId();
    if ($deptId > 0) {
        $st = getDb()->prepare("SELECT 1 FROM issue_participants WHERE issue_id = ? AND department_id = ?");
        $st->execute([$issue['id'], $deptId]);
        if ($st->fetchColumn()) return true;
    }
    return false;
}

// ── File upload helper ────────────────────────────────────
function handleAttachments(int $issueId, ?int $commentId, string $uploadedBy): void {
    if (empty($_FILES['attachments']['name'][0])) return;

    // Always write to the bucketed layout (e.g. uploads/issues/1-500/247/).
    $dir = issueAttachmentDir($issueId, $commentId, true);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $db = getDb();
    $st = $db->prepare(
        "INSERT INTO issue_attachments (issue_id, comment_id, filename, stored_name, mime_type, file_size, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );

    $files = $_FILES['attachments'];
    for ($i = 0; $i < count($files['name']); $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
        if ($files['size'][$i] > MAX_FILE_SIZE) continue;

        $origName = basename($files['name'][$i]);
        $ext = mb_strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_EXTENSIONS)) continue;

        // MIME validation — allow multiple accepted types per extension
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($files['tmp_name'][$i]);
        $acceptedMimes = ALLOWED_MIMES[$ext] ?? [];
        if (empty($acceptedMimes) || !in_array($detectedMime, $acceptedMimes)) continue;

        $storedName = uniqid('att_', true) . '.' . $ext;
        if (move_uploaded_file($files['tmp_name'][$i], $dir . $storedName)) {
            $st->execute([$issueId, $commentId, $origName, $storedName, $detectedMime, $files['size'][$i], $uploadedBy]);
        }
    }
}

// ── Issue email notifications ─────────────────────────────
function notifyIssue(int $issueId, string $event, string $extraInfo = ''): void {
    $logFile = __DIR__ . '/../uploads/email_debug.log';
    $log = function($msg) use ($logFile) { @file_put_contents($logFile, date('Y-m-d H:i:s') . " {$msg}\n", FILE_APPEND); };
    try {
        $db = getDb();
        $st = $db->prepare(
            "SELECT i.*, c.category_name, l.location_name,
                    l.contact_email AS location_email,
                    re.full_name AS reporter_name
             FROM issues i
             LEFT JOIN issue_categories c ON i.category_id = c.id
             LEFT JOIN locations l ON i.location_id = l.location_id
             LEFT JOIN employees re ON i.reporter_code = re.employee_code
             WHERE i.id = ?"
        );
        $st->execute([$issueId]);
        $issue = $st->fetch(PDO::FETCH_ASSOC);
        if (!$issue) { $log("Issue WP-{$issueId} not found in DB"); return; }

        $log("Issue WP-{$issueId} event={$event} reporter={$issue['reporter_code']} location_email={$issue['location_email']}");

        // Collect recipients: reporter + employees in participant departments
        $codes = array_filter([$issue['reporter_code']]);
        $recipients = getEmployeeEmails($codes);
        $log("Reporter emails: " . count($recipients) . " — " . json_encode(array_column($recipients, 'email')));

        // Get participant department IDs and their employees
        $pst = $db->prepare("SELECT department_id FROM issue_participants WHERE issue_id = ?");
        $pst->execute([$issueId]);
        $deptIds = $pst->fetchAll(PDO::FETCH_COLUMN);
        $log("Participant dept IDs: " . implode(', ', $deptIds));
        if ($deptIds) {
            $deptRecipients = getEmployeeEmailsByDepts($deptIds);
            $existingEmails = array_column($recipients, 'email');
            foreach ($deptRecipients as $dr) {
                if (!in_array($dr['email'], $existingEmails)) {
                    $recipients[] = $dr;
                    $existingEmails[] = $dr['email'];
                }
            }

            // Also send to department email1 / email2
            $ph = implode(',', array_fill(0, count($deptIds), '?'));
            $deptEmailSt = $db->prepare("SELECT email1, email2 FROM departments WHERE id IN ({$ph})");
            $deptEmailSt->execute($deptIds);
            foreach ($deptEmailSt->fetchAll(PDO::FETCH_ASSOC) as $de) {
                foreach (['email1','email2'] as $col) {
                    $em = trim($de[$col] ?? '');
                    if ($em && !in_array($em, $existingEmails)) {
                        $recipients[] = ['employee_code' => '', 'full_name' => 'Department', 'email' => $em];
                        $existingEmails[] = $em;
                    }
                }
            }
        }
        $log("Total employee emails: " . count($recipients));

        // Also send to the location's contact email
        $locationEmail = trim($issue['location_email'] ?? '');
        $existingEmails = array_column($recipients, 'email');
        if ($locationEmail && !in_array($locationEmail, $existingEmails)) {
            $recipients[] = ['employee_code' => '', 'full_name' => $issue['location_name'] ?? 'Location', 'email' => $locationEmail];
            $existingEmails[] = $locationEmail;
        }

        if (empty($recipients)) { $log("No recipients — aborting"); return; }
        $log("Total recipients: " . count($recipients));

        // Build email
        $statusLbl  = statusLabel($issue['status']);
        $priorityLbl = ucfirst($issue['priority']);
        $appUrl = 'https://wp.aromen.biz/index.php?page=view_issue&id=' . $issueId;

        switch ($event) {
            case 'created':
                $subject = "DD - WP-{$issueId} Created: {$issue['summary']}";
                $heading = 'New Ticket Created';
                break;
            case 'updated':
                $subject = "DD - WP-{$issueId} Updated: {$issue['summary']}";
                $heading = 'Updated';
                break;
            case 'status_changed':
                $subject = "DD - WP-{$issueId} Status → {$statusLbl}: {$issue['summary']}";
                $heading = "Status Changed to {$statusLbl}";
                break;
            case 'comment':
                $subject = "DD - WP-{$issueId} New Comment: {$issue['summary']}";
                $heading = 'New Comment Added';
                break;
            default:
                $subject = "DD - WP-{$issueId}: {$issue['summary']}";
                $heading = 'Notification';
        }

        $body = "
        <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto'>
            <div style='background:#1a1d2e;color:#fff;padding:16px 20px;border-radius:8px 8px 0 0'>
                <h2 style='margin:0;font-size:16px'>DD Ticket — {$heading}</h2>
            </div>
            <div style='background:#f8f9fa;padding:20px;border:1px solid #dee2e6;border-top:0;border-radius:0 0 8px 8px'>
                <table style='width:100%;font-size:14px;border-collapse:collapse'>
                    <tr><td style='padding:6px 8px;font-weight:bold;width:120px'>Ticket</td><td style='padding:6px 8px'>WP-{$issueId} — " . htmlspecialchars($issue['summary']) . "</td></tr>
                    <tr><td style='padding:6px 8px;font-weight:bold'>Status</td><td style='padding:6px 8px'>{$statusLbl}</td></tr>
                    <tr><td style='padding:6px 8px;font-weight:bold'>Priority</td><td style='padding:6px 8px'>{$priorityLbl}</td></tr>
                    <tr><td style='padding:6px 8px;font-weight:bold'>Category</td><td style='padding:6px 8px'>" . htmlspecialchars($issue['category_name'] ?? 'N/A') . "</td></tr>
                    <tr><td style='padding:6px 8px;font-weight:bold'>Location</td><td style='padding:6px 8px'>" . htmlspecialchars($issue['location_name'] ?? '') . "</td></tr>
                    <tr><td style='padding:6px 8px;font-weight:bold'>Reporter</td><td style='padding:6px 8px'>" . htmlspecialchars($issue['reporter_name'] ?? '') . "</td></tr>
                </table>"
                . ($extraInfo ? "<div style='margin-top:12px;padding:10px;background:#fff;border:1px solid #dee2e6;border-radius:4px;font-size:13px'>{$extraInfo}</div>" : "")
                . "<div style='margin-top:16px;text-align:center'>
                    <a href='{$appUrl}' style='display:inline-block;background:#4f46e5;color:#fff;padding:10px 24px;border-radius:6px;text-decoration:none;font-size:14px'>View Ticket</a>
                </div>
            </div>
            <div style='text-align:center;padding:12px;font-size:11px;color:#999'>Work Pulse — Dangee Dums</div>
        </div>";

        // Enqueue all recipients onto the shared SmtpQueue so they're sent
        // after the response has been flushed to the user. Per-recipient
        // OK/FAILED is now logged by SmtpQueue::flush() in the PHP error
        // log (look for "SmtpQueue: → {email}"). We keep a "Queued for"
        // line here so this issue-debug log still shows the dispatch path.
        foreach ($recipients as $r) {
            sendSmtpEmailQuiet($r['email'], $subject, $body);
            $log("Queued for {$r['email']}");
        }
    } catch (Exception $e) {
        $log("EXCEPTION: " . $e->getMessage());
    }
}

// ── Category management (superadmin) ──────────────────────
function doSaveCategory(): void {
    $id    = (int)($_POST['category_id'] ?? 0);
    $group = $_POST['category_group'] ?? '';
    $name  = trim($_POST['category_name'] ?? '');

    if (!$name || !in_array($group, ['hr_issue', 'service_type', 'incident', 'advance_maintenance'])) {
        flash('error', 'Invalid category.');
        header('Location: index.php?page=manage_categories'); exit;
    }

    $db = getDb();
    if ($id > 0) {
        $db->prepare("UPDATE issue_categories SET category_group=?, category_name=? WHERE id=?")->execute([$group, $name, $id]);
    } else {
        $db->prepare("INSERT INTO issue_categories (category_group, category_name) VALUES (?, ?)")->execute([$group, $name]);
        $id = (int)$db->lastInsertId();
    }

    $deptIds = $_POST['role_depts'] ?? [];
    $db->prepare("DELETE FROM issue_category_roles WHERE category_id = ?")->execute([$id]);
    if (!empty($deptIds)) {
        $pst = $db->prepare("INSERT INTO issue_category_roles (category_id, department_id) VALUES (?, ?)");
        foreach ($deptIds as $did) {
            if ((int)$did > 0) $pst->execute([$id, (int)$did]);
        }
    }

    flash('success', $id ? 'Category saved.' : 'Category added.');
    header('Location: index.php?page=manage_categories'); exit;
}

function doDelCategory(): void {
    $id = (int)($_POST['category_id'] ?? 0);
    $db = getDb();
    $chk = $db->prepare("SELECT COUNT(*) FROM issues WHERE category_id = ?");
    $chk->execute([$id]);
    if ($chk->fetchColumn() > 0) {
        flash('error', 'Cannot delete: category is used by existing tickets. Deactivate instead.');
    } else {
        $db->prepare("DELETE FROM issue_categories WHERE id = ?")->execute([$id]);
        flash('success', 'Category deleted.');
    }
    header('Location: index.php?page=manage_categories'); exit;
}

// ── Helper: get categories grouped ────────────────────────
function getIssueCategories(): array {
    return getDb()->query(
        "SELECT id, category_group, category_name FROM issue_categories WHERE is_active = 1 ORDER BY category_group, category_name"
    )->fetchAll(PDO::FETCH_ASSOC);
}

// ── Issues list page ──────────────────────────────────────
// All issue statuses, in stable display order. Defaults checked on first
// visit = the three "open"-ish ones; resolved + closed start unchecked.
const ISSUE_STATUSES_ALL     = ['assigned_to_concerned','waiting_for_customer','in_progress','resolved','closed'];
const ISSUE_STATUSES_DEFAULT = ['assigned_to_concerned','waiting_for_customer','in_progress'];

// Resolve the multi-select status filter from $_GET. Returns a clean
// array intersected against the whitelist. If $_GET has no `status` key
// AT ALL, falls back to the open-only default. An explicit empty array
// (user unchecked everything) is honoured — the caller decides whether
// to show no rows or treat it as "all".
function issueStatusFilter(): array {
    if (!isset($_GET['status'])) return ISSUE_STATUSES_DEFAULT;
    $raw = is_array($_GET['status']) ? $_GET['status'] : [$_GET['status']];
    return array_values(array_intersect(ISSUE_STATUSES_ALL, $raw));
}

function pageIssues(): void {
    $db = getDb();

    $statusFilter = issueStatusFilter();
    $hasFilters   = isset($_GET['view']);
    $issues       = [];

    if ($hasFilters) {
        $where = [];
        $params = [];

        // Non-admin/superadmin: reporter OR same location OR department participant
        if (!isSuperadmin() && !canManageIssues()) {
            $code = myCode();
            $locId = myLocationId();
            $deptId = myDeptId();
            $visConds = ["i.reporter_code = ?"];
            $params[] = $code;
            if ($locId > 0) {
                $visConds[] = "i.location_id = ?";
                $params[] = $locId;
            }
            if ($deptId > 0) {
                $visConds[] = "EXISTS (SELECT 1 FROM issue_participants ip WHERE ip.issue_id = i.id AND ip.department_id = ?)";
                $params[] = $deptId;
            }
            $where[] = "(" . implode(' OR ', $visConds) . ")";
        }

        if ($statusFilter) {
            $ph = implode(',', array_fill(0, count($statusFilter), '?'));
            $where[] = "i.status IN ($ph)";
            foreach ($statusFilter as $s) $params[] = $s;
        } else {
            // User explicitly unchecked every status → no rows.
            $where[] = '1=0';
        }
        if (!empty($_GET['priority'])) {
            $where[] = "i.priority = ?";
            $params[] = $_GET['priority'];
        }
        if (!empty($_GET['category_id'])) {
            $where[] = "i.category_id = ?";
            $params[] = (int)$_GET['category_id'];
        }
        if (!empty($_GET['dept_id'])) {
            $where[] = "EXISTS (SELECT 1 FROM issue_participants ip WHERE ip.issue_id = i.id AND ip.department_id = ?)";
            $params[] = (int)$_GET['dept_id'];
        }
        if (!empty($_GET['location_id'])) {
            $where[] = "i.location_id = ?";
            $params[] = (int)$_GET['location_id'];
        }
        // Keyword search
        $q = trim($_GET['q'] ?? '');
        if ($q !== '') {
            if (preg_match('/^WP-?(\d+)$/i', $q, $m)) {
                $where[] = "i.id = ?";
                $params[] = (int)$m[1];
            } elseif (ctype_digit($q)) {
                $where[] = "i.id = ?";
                $params[] = (int)$q;
            } else {
                $where[] = "(i.summary LIKE ? OR i.description LIKE ?)";
                $params[] = "%{$q}%";
                $params[] = "%{$q}%";
            }
        }

        if (!empty($_GET['from_date'])) {
            $where[] = "DATE(i.created_at) >= ?";
            $params[] = $_GET['from_date'];
        }
        if (!empty($_GET['to_date'])) {
            $where[] = "DATE(i.created_at) <= ?";
            $params[] = $_GET['to_date'];
        }

        $sql = "SELECT i.*, c.category_name, c.category_group,
                       l.location_name, re.full_name AS reporter_name
                FROM issues i
                LEFT JOIN issue_categories c ON i.category_id = c.id
                LEFT JOIN locations l ON i.location_id = l.location_id
                LEFT JOIN employees re ON i.reporter_code = re.employee_code";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY i.created_at DESC LIMIT 200";

        $st = $db->prepare($sql);
        $st->execute($params);
        $issues = $st->fetchAll(PDO::FETCH_ASSOC);

        // Fetch participant departments for all listed issues
        if ($issues) {
            $ids = array_column($issues, 'id');
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $pst = $db->prepare(
                "SELECT ip.issue_id, d.department_name FROM issue_participants ip
                 LEFT JOIN departments d ON ip.department_id = d.id
                 WHERE ip.issue_id IN ({$ph}) ORDER BY ip.issue_id, d.department_name"
            );
            $pst->execute($ids);
            $partMap = [];
            foreach ($pst->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $partMap[$p['issue_id']][] = $p['department_name'] ?? '';
            }
            foreach ($issues as &$iss) {
                $iss['participants'] = $partMap[$iss['id']] ?? [];
            }
            unset($iss);
        }
    }

    $categories = getIssueCategories();
    $departments = getDepartments();
    $locations = getActiveLocations();
    $statusOptions = [
        'assigned_to_concerned' => 'Assigned',
        'waiting_for_customer'  => 'Waiting for Reporter',
        'in_progress'           => 'In Progress',
        'resolved'              => 'Resolved',
        'closed'                => 'Closed',
    ];
?>
<div class="page-header">
    <h2>Tickets</h2>
    <a href="?page=create_issue" class="btn btn-primary">+ New Ticket</a>
</div>

<!-- Filters -->
<form method="GET" style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px">
    <input type="hidden" name="page" value="issues">
    <input type="hidden" name="view" value="1">
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <span class="input-clear-wrap" style="flex:1 1 180px;min-width:180px">
            <input type="text" name="q" class="form-control" value="<?= h($_GET['q'] ?? '') ?>" placeholder="Search WP-11 or keyword">
            <button type="button" class="input-clear-btn" aria-label="Clear search" tabindex="-1">&times;</button>
        </span>
        <?php
        $issueStatusCount = count($statusFilter);
        $issueStatusBtnLabel = $issueStatusCount === 0
            ? 'None selected'
            : ($issueStatusCount === count($statusOptions)
                ? 'All statuses'
                : $issueStatusCount . ' selected');
        ?>
        <div style="position:relative">
            <button type="button" id="iss-status-btn" class="form-control"
                    style="width:170px;text-align:left;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:6px">
                <span id="iss-status-label"><?= h($issueStatusBtnLabel) ?></span>
                <span style="color:var(--muted);font-size:10px">▾</span>
            </button>
            <div id="iss-status-panel"
                 style="display:none;position:absolute;top:calc(100% + 4px);left:0;background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:6px 0;z-index:100;min-width:220px;box-shadow:0 8px 20px rgba(0,0,0,.35)">
                <label style="display:flex;align-items:center;gap:8px;padding:6px 12px;font-size:12px;color:var(--muted);border-bottom:1px solid var(--border);cursor:pointer">
                    <input type="checkbox" id="iss-status-all">
                    <span>Select all</span>
                </label>
                <?php foreach ($statusOptions as $val => $lbl): ?>
                <label style="display:flex;align-items:center;gap:8px;padding:6px 12px;cursor:pointer;font-size:13px">
                    <input type="checkbox" class="iss-status-cb" name="status[]" value="<?= h($val) ?>"
                           <?= in_array($val, $statusFilter, true) ? 'checked' : '' ?>>
                    <span><?= h($lbl) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <select name="priority" class="form-control" style="width:120px">
            <option value="">All Priority</option>
            <?php foreach (['low','medium','high','critical'] as $p): ?>
            <option value="<?= $p ?>" <?= ($_GET['priority'] ?? '') === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="category_id" class="form-control" style="width:200px">
            <option value="">All Categories</option>
            <?php
            $groupLabels = ['hr_issue'=>'HR Issue','service_type'=>'Service Type','advance_maintenance'=>'Advance Maintenance','incident'=>'Incident'];
            $curGroup = null;
            foreach ($categories as $cat):
                if ($cat['category_group'] !== $curGroup):
                    if ($curGroup !== null) echo '</optgroup>';
                    $curGroup = $cat['category_group'];
                    echo '<optgroup label="' . h($groupLabels[$curGroup] ?? ucfirst(str_replace('_',' ',$curGroup))) . '">';
                endif;
            ?>
            <option value="<?= $cat['id'] ?>" <?= ($_GET['category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>><?= h($cat['category_name']) ?></option>
            <?php endforeach; if ($curGroup !== null) echo '</optgroup>'; ?>
        </select>
        <select name="dept_id" class="form-control" style="width:170px">
            <option value="">All Departments</option>
            <?php foreach ($departments as $dept): ?>
            <option value="<?= $dept['id'] ?>" <?= ($_GET['dept_id'] ?? '') == $dept['id'] ? 'selected' : '' ?>><?= h($dept['department_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="location_id" class="form-control" style="width:170px">
            <option value="">All Locations</option>
            <?php foreach ($locations as $loc): ?>
            <option value="<?= $loc['location_id'] ?>" <?= ($_GET['location_id'] ?? '') == $loc['location_id'] ? 'selected' : '' ?>><?= h($loc['location_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input type="date" name="from_date" class="form-control" style="width:150px" value="<?= h($_GET['from_date'] ?? '') ?>" placeholder="From">
        <input type="date" name="to_date" class="form-control" style="width:150px" value="<?= h($_GET['to_date'] ?? '') ?>" placeholder="To">
        <button type="submit" class="btn btn-primary">View</button>
        <?php if ($hasFilters): ?>
        <a href="?page=export_issues&<?= http_build_query(array_filter($_GET, fn($k) => $k !== 'page', ARRAY_FILTER_USE_KEY)) ?>" class="btn btn-ghost btn-sm" target="_blank">Export CSV</a>
        <?php endif; ?>
    </div>
</form>
<script>
// Status checkbox-dropdown: same widget pattern as audit_summary —
// click the button to toggle the panel, click outside to close, "Select all"
// toggles every status, button label reflects the current count.
(function () {
    var btn   = document.getElementById('iss-status-btn');
    var panel = document.getElementById('iss-status-panel');
    var label = document.getElementById('iss-status-label');
    var all   = document.getElementById('iss-status-all');
    if (!btn || !panel || !label || !all) return;
    var boxes = panel.querySelectorAll('.iss-status-cb');

    function syncLabel() {
        var n = 0;
        boxes.forEach(function (b) { if (b.checked) n++; });
        if (n === 0)                  label.textContent = 'None selected';
        else if (n === boxes.length)  label.textContent = 'All statuses';
        else                          label.textContent = n + ' selected';
        all.checked = (n === boxes.length);
        all.indeterminate = (n > 0 && n < boxes.length);
    }
    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        panel.style.display = (panel.style.display === 'block') ? 'none' : 'block';
    });
    panel.addEventListener('click', function (e) { e.stopPropagation(); });
    document.addEventListener('click', function () { panel.style.display = 'none'; });
    all.addEventListener('change', function () {
        boxes.forEach(function (b) { b.checked = all.checked; });
        syncLabel();
    });
    boxes.forEach(function (b) { b.addEventListener('change', syncLabel); });
    syncLabel();
})();
</script>

<?php if (!$hasFilters): ?>
<div class="rpt-prompt">Apply filters and click <strong>View</strong> to load tickets.</div>
<?php elseif (empty($issues)): ?>
<div class="rpt-prompt">No tickets match the selected filters.</div>
<?php else: ?>
<div class="table-count" style="margin:8px 0 10px"><?= count($issues) ?> ticket(s) total</div>
<div class="table-wrap" data-stack>
    <table class="table">
        <thead>
            <tr>
                <th style="width:80px">#</th>
                <th>Summary</th>
                <th style="width:120px">Status</th>
                <th style="width:80px">Priority</th>
                <th style="width:150px">Category</th>
                <th style="width:130px">Location</th>
                <th style="width:120px">Reporter</th>
                <th>Participants</th>
                <th style="width:100px">Created</th>
                <th style="width:100px">Updated</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($issues as $i): ?>
            <tr>
                <td style="white-space:nowrap"><a href="?page=view_issue&id=<?= $i['id'] ?>" target="_blank" style="color:var(--accent)">WP-<?= $i['id'] ?></a></td>
                <td><a href="?page=view_issue&id=<?= $i['id'] ?>" target="_blank" style="color:var(--text);text-decoration:none"><?= h($i['summary']) ?></a></td>
                <td><span class="badge <?= statusBadgeClass($i['status']) ?>"><?= statusLabel($i['status']) ?></span></td>
                <td><span class="badge <?= priorityBadgeClass($i['priority']) ?>"><?= ucfirst($i['priority']) ?></span></td>
                <td><?= h($i['category_name'] ?? '—') ?></td>
                <td><?= h($i['location_name'] ?? '—') ?></td>
                <td><?= h($i['reporter_name'] ?? $i['reporter_code']) ?></td>
                <td style="font-size:12px"><?php
                    if (!empty($i['participants'])) {
                        foreach ($i['participants'] as $pn) {
                            echo '<span class="badge badge-grey" style="margin:1px">' . h($pn) . '</span>';
                        }
                    } else { echo '—'; }
                ?></td>
                <td class="text-muted" style="font-size:12px">
                    <?= date('d M Y', strtotime($i['created_at'])) ?><br>
                    <?= date('H:i', strtotime($i['created_at'])) ?>
                </td>
                <td class="text-muted" style="font-size:12px">
                    <?php if (!empty($i['updated_at'])): ?>
                        <?= date('d M Y', strtotime($i['updated_at'])) ?><br>
                        <?= date('H:i', strtotime($i['updated_at'])) ?>
                    <?php else: ?>—<?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php }

// ── Comments Feed: last comment per issue, filterable like the issues list ──
// Filter shape mirrors pageIssues(): same multi-select status dropdown
// (default = Assigned + Waiting for Reporter + In Progress per
// ISSUE_STATUSES_DEFAULT), priority/category/dept/location selects, search
// box, date range. Same "click View to load" empty state. Search also
// matches comment body, since that's what this feed is about.
// ── Page: Ticket Overview (Locations × Days grid) ────────
// Mirrors pageChecklistOverview's monthly grid but counts tickets created
// per location per day. Filters mirror pageIssues exactly so users can
// narrow the count (status, priority, category, dept, location, keyword).
// Click a day cell to jump to the filtered issues list for that day.
function pageIssueOverview(): void {
    if (!isSuperadmin() && !hasTxn('issue_summary') && !hasTxn('issues') && !canManageIssues()) {
        echo '<p>Access denied.</p>'; return;
    }
    $db = getDb();

    // ── Filter parsing ──
    $viewClicked    = !empty($_GET['view']);
    $month          = (int)($_GET['month'] ?? date('n'));
    $year           = (int)($_GET['year']  ?? date('Y'));
    if ($month < 1 || $month > 12)        $month = (int)date('n');
    if ($year  < 2020 || $year > 2099)    $year  = (int)date('Y');

    $statusFilter   = issueStatusFilter();
    $priorityFilter = trim((string)($_GET['priority']    ?? ''));
    $categoryId     = (int)($_GET['category_id'] ?? 0);
    $deptId         = (int)($_GET['dept_id']     ?? 0);
    $locFilter      = (int)($_GET['location_id'] ?? 0);
    $q              = trim((string)($_GET['q'] ?? ''));

    $monthStart  = sprintf('%04d-%02d-01', $year, $month);
    $monthEnd    = date('Y-m-t', strtotime($monthStart));
    $daysInMonth = (int)date('t',  strtotime($monthStart));
    $today       = date('Y-m-d');

    // ── Aggregate query: location_id × day → count ──
    $cell = [];
    $maxCount = 0;
    if ($viewClicked) {
        $where  = ['DATE(i.created_at) >= ?', 'DATE(i.created_at) <= ?', 'i.location_id IS NOT NULL'];
        $params = [$monthStart, $monthEnd];

        // Non-admin scope — same rules as pageIssues so counts match
        // what the user could see when they click through.
        if (!isSuperadmin() && !canManageIssues() && !hasTxn('issue_summary')) {
            $code       = myCode();
            $myLocId    = myLocationId();
            $myDeptId   = myDeptId();
            $visConds = ["i.reporter_code = ?"]; $params[] = $code;
            if ($myLocId  > 0) { $visConds[] = "i.location_id = ?"; $params[] = $myLocId;  }
            if ($myDeptId > 0) {
                $visConds[] = "EXISTS (SELECT 1 FROM issue_participants ip WHERE ip.issue_id = i.id AND ip.department_id = ?)";
                $params[]   = $myDeptId;
            }
            $where[] = '(' . implode(' OR ', $visConds) . ')';
        }

        if ($statusFilter) {
            $ph = implode(',', array_fill(0, count($statusFilter), '?'));
            $where[] = "i.status IN ($ph)";
            foreach ($statusFilter as $s) $params[] = $s;
        } else {
            $where[] = '1=0'; // explicit none → no rows
        }
        if ($priorityFilter !== '') { $where[] = 'i.priority = ?';    $params[] = $priorityFilter; }
        if ($categoryId > 0)        { $where[] = 'i.category_id = ?'; $params[] = $categoryId; }
        if ($deptId > 0)            {
            $where[] = 'EXISTS (SELECT 1 FROM issue_participants ip WHERE ip.issue_id = i.id AND ip.department_id = ?)';
            $params[] = $deptId;
        }
        if ($locFilter > 0)         { $where[] = 'i.location_id = ?'; $params[] = $locFilter; }
        if ($q !== '') {
            if      (preg_match('/^WP-?(\d+)$/i', $q, $m)) { $where[] = 'i.id = ?'; $params[] = (int)$m[1]; }
            elseif  (ctype_digit($q))                      { $where[] = 'i.id = ?'; $params[] = (int)$q; }
            else { $where[] = '(i.summary LIKE ? OR i.description LIKE ?)'; $params[] = "%{$q}%"; $params[] = "%{$q}%"; }
        }

        $sql = 'SELECT i.location_id, DAY(i.created_at) AS day, COUNT(*) AS cnt,
                       GROUP_CONCAT(i.id ORDER BY i.id SEPARATOR ",") AS ids
                FROM issues i WHERE ' . implode(' AND ', $where) . '
                GROUP BY i.location_id, DAY(i.created_at)';
        try {
            $st = $db->prepare($sql);
            $st->execute($params);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cnt = (int)$r['cnt'];
                $cell[(int)$r['location_id']][(int)$r['day']] = [
                    'cnt' => $cnt,
                    'ids' => (string)($r['ids'] ?? ''),
                ];
                if ($cnt > $maxCount) $maxCount = $cnt;
            }
        } catch (Exception $e) { /* fail open — empty grid */ }
    }

    $locations = getActiveLocations();
    if ($locFilter > 0) {
        $locations = array_values(array_filter($locations, fn($l) => (int)$l['location_id'] === $locFilter));
    }

    $categories    = getIssueCategories();
    $departments   = getDepartments();
    $allLocations  = getActiveLocations();
    $statusOptions = [
        'assigned_to_concerned' => 'Assigned',
        'waiting_for_customer'  => 'Waiting for Reporter',
        'in_progress'           => 'In Progress',
        'resolved'              => 'Resolved',
        'closed'                => 'Closed',
    ];

    // Deep-link base: preserve every active filter so clicking a cell
    // opens the issues page with the same context, just narrowed to that
    // location + day.
    $deepLinkBase = ['page' => 'issues', 'view' => '1'];
    foreach (['priority','category_id','dept_id','q'] as $k) {
        if (!empty($_GET[$k])) $deepLinkBase[$k] = $_GET[$k];
    }
    if ($statusFilter && $statusFilter !== ISSUE_STATUSES_DEFAULT) {
        $deepLinkBase['status'] = $statusFilter; // becomes status[]=
    }

    // Colour buckets for the day tile. 0 → blank (clean), 1-2 → green,
    // 3-5 → yellow, 6+ → red. Same visual rhythm as checklist_overview.
    $bucketFor = function (int $n, bool $future): string {
        if ($future)  return 'iov-future';
        if ($n <= 0)  return 'iov-zero';
        if ($n <= 2)  return 'iov-low';
        if ($n <= 5)  return 'iov-mid';
        return 'iov-high';
    };

    $statusCount    = count($statusFilter);
    $statusBtnLabel = $statusCount === 0
        ? 'None selected'
        : ($statusCount === count($statusOptions)
            ? 'All statuses'
            : $statusCount . ' selected');
?>
<div class="page-header"><h2>📋 Ticket Overview <small class="text-muted" style="font-weight:400">— all locations</small></h2></div>

<form method="GET" class="form-card" style="max-width:none;margin-bottom:14px">
    <input type="hidden" name="page" value="issue_overview">
    <input type="hidden" name="view" value="1">

    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px">
        <select name="month" class="form-control" style="width:140px">
            <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
            <?php endfor; ?>
        </select>
        <select name="year" class="form-control" style="width:100px">
            <?php $curY = (int)date('Y'); for ($y = $curY - 2; $y <= $curY + 1; $y++): ?>
                <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>

        <!-- Status multi-select -->
        <div style="position:relative">
            <button type="button" id="iov-status-btn" class="form-control"
                    style="width:170px;text-align:left;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:6px">
                <span id="iov-status-label"><?= h($statusBtnLabel) ?></span>
                <span style="color:var(--muted);font-size:10px">▾</span>
            </button>
            <div id="iov-status-panel"
                 style="display:none;position:absolute;top:calc(100% + 4px);left:0;background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:6px 0;z-index:100;min-width:220px;box-shadow:0 8px 20px rgba(0,0,0,.35)">
                <label style="display:flex;align-items:center;gap:8px;padding:6px 12px;font-size:12px;color:var(--muted);border-bottom:1px solid var(--border);cursor:pointer">
                    <input type="checkbox" id="iov-status-all">
                    <span>Select all</span>
                </label>
                <?php foreach ($statusOptions as $val => $lbl): ?>
                <label style="display:flex;align-items:center;gap:8px;padding:6px 12px;cursor:pointer;font-size:13px">
                    <input type="checkbox" class="iov-status-cb" name="status[]" value="<?= h($val) ?>"
                           <?= in_array($val, $statusFilter, true) ? 'checked' : '' ?>>
                    <span><?= h($lbl) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <select name="priority" class="form-control" style="width:120px">
            <option value="">All Priority</option>
            <?php foreach (['low','medium','high','critical'] as $p): ?>
            <option value="<?= $p ?>" <?= $priorityFilter === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="category_id" class="form-control" style="width:200px">
            <option value="">All Categories</option>
            <?php
            $groupLabels = ['hr_issue'=>'HR Issue','service_type'=>'Service Type','advance_maintenance'=>'Advance Maintenance','incident'=>'Incident'];
            $curGroup = null;
            foreach ($categories as $cat):
                if ($cat['category_group'] !== $curGroup):
                    if ($curGroup !== null) echo '</optgroup>';
                    $curGroup = $cat['category_group'];
                    echo '<optgroup label="' . h($groupLabels[$curGroup] ?? ucfirst(str_replace('_',' ',$curGroup))) . '">';
                endif;
            ?>
            <option value="<?= $cat['id'] ?>" <?= $categoryId === (int)$cat['id'] ? 'selected' : '' ?>><?= h($cat['category_name']) ?></option>
            <?php endforeach; if ($curGroup !== null) echo '</optgroup>'; ?>
        </select>

        <select name="dept_id" class="form-control" style="width:170px">
            <option value="">All Departments</option>
            <?php foreach ($departments as $dept): ?>
            <option value="<?= $dept['id'] ?>" <?= $deptId === (int)$dept['id'] ? 'selected' : '' ?>><?= h($dept['department_name']) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="location_id" class="form-control" style="width:170px">
            <option value="">All Locations</option>
            <?php foreach ($allLocations as $loc): ?>
            <option value="<?= $loc['location_id'] ?>" <?= $locFilter === (int)$loc['location_id'] ? 'selected' : '' ?>><?= h($loc['location_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <button type="submit" class="btn btn-primary">View</button>
        <a href="?page=issue_overview" class="btn btn-ghost">Reset</a>
    </div>
</form>

<script>
// Status checkbox-dropdown — wired once on every page load so the
// button is clickable even before the user submits the form.
(function () {
    var btn   = document.getElementById('iov-status-btn');
    var panel = document.getElementById('iov-status-panel');
    var label = document.getElementById('iov-status-label');
    var all   = document.getElementById('iov-status-all');
    if (!btn || !panel || !label || !all) return;
    var boxes = panel.querySelectorAll('.iov-status-cb');

    function syncLabel() {
        var n = 0; boxes.forEach(function (b) { if (b.checked) n++; });
        if (n === 0)                  label.textContent = 'None selected';
        else if (n === boxes.length)  label.textContent = 'All statuses';
        else                          label.textContent = n + ' selected';
        all.checked = (n === boxes.length);
        all.indeterminate = (n > 0 && n < boxes.length);
    }
    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        panel.style.display = (panel.style.display === 'block') ? 'none' : 'block';
    });
    panel.addEventListener('click', function (e) { e.stopPropagation(); });
    document.addEventListener('click', function () { panel.style.display = 'none'; });
    all.addEventListener('change', function () {
        boxes.forEach(function (b) { b.checked = all.checked; });
        syncLabel();
    });
    boxes.forEach(function (b) { b.addEventListener('change', syncLabel); });
    syncLabel();
})();
</script>

<?php if (!$viewClicked): ?>
<div class="rpt-prompt">Pick a month and click <strong>View</strong> to load the overview.</div>
<?php else: ?>

<style>
.iov-wrap { overflow-x:auto; border:1px solid var(--border); border-radius:8px; background:var(--surface); }
.iov { border-collapse:collapse; font-size:12px; min-width:100%; }
.iov th, .iov td { padding:0; }
.iov thead th { background:var(--surface); position:sticky; top:0; z-index:2; padding:8px 6px; border-bottom:1px solid var(--border); font-weight:600; color:var(--muted); text-align:center; }
.iov thead th.iov-loc-head { text-align:left; padding-left:14px; min-width:240px; position:sticky; left:0; z-index:3; background:var(--surface); }
.iov tbody td.iov-loc-name { padding:8px 14px; border-bottom:1px solid var(--border); font-weight:500; color:var(--text); position:sticky; left:0; background:var(--surface); z-index:1; min-width:240px; white-space:nowrap; }
.iov tbody td.iov-cell     { padding:4px 3px; border-bottom:1px solid var(--border); text-align:center; vertical-align:middle; }
.iov-tile { display:block; min-width:34px; padding:5px 3px; border-radius:5px; color:#fff; font-weight:700; font-size:11px; line-height:1.2; text-decoration:none; }
.iov-tile span { font-weight:400; font-size:10px; opacity:.85 }
.iov-zero   { background:#1f2937; color:#94a3b8; }
.iov-low    { background:var(--green); }
.iov-mid    { background:var(--yellow); color:#1a1612; }
.iov-high   { background:var(--red); }
.iov-future { background:#4b5563; opacity:.55; cursor:not-allowed; color:#cbd5e1; }
.iov-legend { display:flex;gap:14px;margin-top:10px;font-size:11px;color:var(--muted);align-items:center;flex-wrap:wrap; }
.iov-legend i { display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:5px;vertical-align:middle; }
</style>

<div class="report-header-box">
    <strong><?= h(date('F Y', mktime(0,0,0,$month,1,$year))) ?></strong>
    — <?= count($locations) ?> location<?= count($locations) === 1 ? '' : 's' ?>,
    <?= (int)$maxCount ?> max ticket<?= $maxCount === 1 ? '' : 's' ?> in any single cell.
</div>

<div class="iov-wrap">
<table class="iov">
    <thead>
        <tr>
            <th class="iov-loc-head">Location</th>
            <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                <th><?= $d ?></th>
            <?php endfor; ?>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($locations as $loc):
        $locId = (int)$loc['location_id'];
    ?>
        <tr>
            <td class="iov-loc-name"><?= h($loc['location_name']) ?></td>
            <?php for ($d = 1; $d <= $daysInMonth; $d++):
                $tileDate = sprintf('%04d-%02d-%02d', $year, $month, $d);
                $isFuture = ($tileDate > $today);
                $bucket   = $cell[$locId][$d] ?? null;
                $cnt      = (int)($bucket['cnt'] ?? 0);
                $idsCsv   = $bucket['ids'] ?? '';
                $cls      = $bucketFor($cnt, $isFuture);
                if ($cnt > 0 && $idsCsv !== '') {
                    $title = h(implode(', ', array_map(
                        fn($id) => 'WP-' . (int)$id,
                        explode(',', $idsCsv)
                    )));
                } else {
                    $title = h($cnt . ' ticket' . ($cnt === 1 ? '' : 's'));
                }
                $href     = '?' . http_build_query(array_merge($deepLinkBase, [
                    'from_date'   => $tileDate,
                    'to_date'     => $tileDate,
                    'location_id' => $locId,
                ]));
            ?>
                <td class="iov-cell">
                    <?php if ($isFuture): ?>
                        <span class="iov-tile iov-future" title="<?= $title ?>"><?= $d ?><br><span>—</span></span>
                    <?php elseif ($cnt === 0): ?>
                        <span class="iov-tile iov-zero" title="<?= $title ?>"><?= $d ?><br><span>0</span></span>
                    <?php else: ?>
                        <a class="iov-tile <?= $cls ?>" href="<?= h($href) ?>" title="<?= $title ?>">
                            <?= $d ?><br><span><?= $cnt ?></span>
                        </a>
                    <?php endif; ?>
                </td>
            <?php endfor; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<div class="iov-legend">
    <span><i style="background:var(--green)"></i> 1 – 2 tickets</span>
    <span><i style="background:var(--yellow)"></i> 3 – 5 tickets</span>
    <span><i style="background:var(--red)"></i> 6+ tickets</span>
    <span><i style="background:#1f2937"></i> No tickets</span>
    <span><i style="background:#4b5563"></i> Future</span>
    <span style="margin-left:auto">Click a non-zero tile to open the filtered ticket list for that day.</span>
</div>
<?php endif; ?>
<?php }

function pageIssueComments(): void {
    if (!isSuperadmin() && !hasTxn('issue_comments')) {
        flash('error', 'Access denied.');
        header('Location: index.php'); exit;
    }
    $db = getDb();

    $statusFilter = issueStatusFilter();
    $hasFilters   = isset($_GET['view']);
    $rows         = [];

    if ($hasFilters) {
        // Status applies inside the MAX subquery — it decides which issues
        // contribute their latest comment. Pushing it into the subquery
        // (rather than filtering after) keeps "latest comment" tied to
        // the issue's CURRENT status, not the status when the comment
        // was written.
        $innerWhere  = [];
        $innerParams = [];
        if ($statusFilter) {
            $ph = implode(',', array_fill(0, count($statusFilter), '?'));
            $innerWhere[] = "i2.status IN ($ph)";
            foreach ($statusFilter as $s) $innerParams[] = $s;
        } else {
            $innerWhere[] = '1=0';
        }

        $where  = [];
        $params = [];

        // Non-admin visibility — also pushed into both inner and outer so
        // the subquery never picks comments the user wouldn't be allowed
        // to see (otherwise the outer filter would silently drop rows
        // and the totals would mislead).
        if (!isSuperadmin() && !canManageIssues()) {
            $code   = myCode();
            $locId  = myLocationId();
            $deptId = myDeptId();
            $conds      = ['i.reporter_code = ?'];     $params[]      = $code;
            $innerConds = ['i2.reporter_code = ?'];    $innerParams[] = $code;
            if ($locId > 0) {
                $conds[]      = 'i.location_id = ?';   $params[]      = $locId;
                $innerConds[] = 'i2.location_id = ?';  $innerParams[] = $locId;
            }
            if ($deptId > 0) {
                $conds[]      = 'EXISTS (SELECT 1 FROM issue_participants ip WHERE ip.issue_id = i.id  AND ip.department_id = ?)';
                $params[]     = $deptId;
                $innerConds[] = 'EXISTS (SELECT 1 FROM issue_participants ip WHERE ip.issue_id = i2.id AND ip.department_id = ?)';
                $innerParams[] = $deptId;
            }
            $where[]      = '(' . implode(' OR ', $conds) . ')';
            $innerWhere[] = '(' . implode(' OR ', $innerConds) . ')';
        }

        // Issue-attribute filters — outer only (the subquery already
        // selected the comment; we just need to narrow which ones to show).
        if (!empty($_GET['priority'])) {
            $where[]  = 'i.priority = ?';
            $params[] = $_GET['priority'];
        }
        if (!empty($_GET['category_id'])) {
            $where[]  = 'i.category_id = ?';
            $params[] = (int)$_GET['category_id'];
        }
        if (!empty($_GET['dept_id'])) {
            $where[]  = 'EXISTS (SELECT 1 FROM issue_participants ip WHERE ip.issue_id = i.id AND ip.department_id = ?)';
            $params[] = (int)$_GET['dept_id'];
        }
        if (!empty($_GET['location_id'])) {
            $where[]  = 'i.location_id = ?';
            $params[] = (int)$_GET['location_id'];
        }
        // Search — same WP-XX / numeric-id rules as pageIssues, plus
        // comment-body match (this IS the comments feed after all).
        $q = trim($_GET['q'] ?? '');
        if ($q !== '') {
            if (preg_match('/^WP-?(\d+)$/i', $q, $m)) {
                $where[]  = 'i.id = ?';
                $params[] = (int)$m[1];
            } elseif (ctype_digit($q)) {
                $where[]  = 'i.id = ?';
                $params[] = (int)$q;
            } else {
                $where[]  = '(i.summary LIKE ? OR i.description LIKE ? OR ic.body LIKE ?)';
                $params[] = "%{$q}%";
                $params[] = "%{$q}%";
                $params[] = "%{$q}%";
            }
        }
        // Date range is over comment timestamp — most useful axis for
        // a comments feed ("show me last week's activity").
        if (!empty($_GET['from_date'])) {
            $where[]  = 'DATE(ic.created_at) >= ?';
            $params[] = $_GET['from_date'];
        }
        if (!empty($_GET['to_date'])) {
            $where[]  = 'DATE(ic.created_at) <= ?';
            $params[] = $_GET['to_date'];
        }

        $innerSql = 'SELECT MAX(ic2.id) FROM issue_comments ic2
                     JOIN issues i2 ON ic2.issue_id = i2.id
                     WHERE ' . implode(' AND ', $innerWhere) . '
                     GROUP BY ic2.issue_id';

        $sql = "SELECT ic.id AS comment_id, ic.issue_id, ic.author_code, ic.body,
                       ic.created_at AS comment_at,
                       i.summary, i.status, i.priority, i.location_id, i.reporter_code,
                       e.full_name AS author_name,
                       l.location_name,
                       c.category_name
                FROM issue_comments ic
                JOIN issues i ON ic.issue_id = i.id
                LEFT JOIN employees e        ON ic.author_code = e.employee_code
                LEFT JOIN locations l        ON i.location_id  = l.location_id
                LEFT JOIN issue_categories c ON i.category_id  = c.id
                WHERE ic.id IN ($innerSql)";
        if ($where) $sql .= ' AND ' . implode(' AND ', $where);
        $sql .= ' ORDER BY ic.created_at DESC LIMIT 200';

        $st = $db->prepare($sql);
        $st->execute(array_merge($innerParams, $params));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    $categories    = getIssueCategories();
    $departments   = getDepartments();
    $locations     = getActiveLocations();
    $statusOptions = [
        'assigned_to_concerned' => 'Assigned',
        'waiting_for_customer'  => 'Waiting for Reporter',
        'in_progress'           => 'In Progress',
        'resolved'              => 'Resolved',
        'closed'                => 'Closed',
    ];
?>
<div class="page-header">
    <h2>Comments Feed</h2>
</div>
<p class="text-muted" style="margin:-8px 0 14px;font-size:13px">
    Latest comment per ticket, newest first. Defaults to open tickets
    (Assigned · Waiting for Reporter · In Progress).
</p>

<!-- Filters — mirrors pageIssues() shape -->
<form method="GET" style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px">
    <input type="hidden" name="page" value="issue_comments">
    <input type="hidden" name="view" value="1">
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <span class="input-clear-wrap" style="flex:1 1 180px;min-width:180px">
            <input type="text" name="q" class="form-control" value="<?= h($_GET['q'] ?? '') ?>" placeholder="Search WP-11, keyword or comment text">
            <button type="button" class="input-clear-btn" aria-label="Clear search" tabindex="-1">&times;</button>
        </span>
        <?php
        $icStatusCount = count($statusFilter);
        $icStatusBtnLabel = $icStatusCount === 0
            ? 'None selected'
            : ($icStatusCount === count($statusOptions)
                ? 'All statuses'
                : $icStatusCount . ' selected');
        ?>
        <div style="position:relative">
            <button type="button" id="ic-status-btn" class="form-control"
                    style="width:170px;text-align:left;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:6px">
                <span id="ic-status-label"><?= h($icStatusBtnLabel) ?></span>
                <span style="color:var(--muted);font-size:10px">▾</span>
            </button>
            <div id="ic-status-panel"
                 style="display:none;position:absolute;top:calc(100% + 4px);left:0;background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:6px 0;z-index:100;min-width:220px;box-shadow:0 8px 20px rgba(0,0,0,.35)">
                <label style="display:flex;align-items:center;gap:8px;padding:6px 12px;font-size:12px;color:var(--muted);border-bottom:1px solid var(--border);cursor:pointer">
                    <input type="checkbox" id="ic-status-all">
                    <span>Select all</span>
                </label>
                <?php foreach ($statusOptions as $val => $lbl): ?>
                <label style="display:flex;align-items:center;gap:8px;padding:6px 12px;cursor:pointer;font-size:13px">
                    <input type="checkbox" class="ic-status-cb" name="status[]" value="<?= h($val) ?>"
                           <?= in_array($val, $statusFilter, true) ? 'checked' : '' ?>>
                    <span><?= h($lbl) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <select name="priority" class="form-control" style="width:120px">
            <option value="">All Priority</option>
            <?php foreach (['low','medium','high','critical'] as $p): ?>
            <option value="<?= $p ?>" <?= ($_GET['priority'] ?? '') === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="category_id" class="form-control" style="width:200px">
            <option value="">All Categories</option>
            <?php
            $groupLabels = ['hr_issue'=>'HR Issue','service_type'=>'Service Type','advance_maintenance'=>'Advance Maintenance','incident'=>'Incident'];
            $curGroup = null;
            foreach ($categories as $cat):
                if ($cat['category_group'] !== $curGroup):
                    if ($curGroup !== null) echo '</optgroup>';
                    $curGroup = $cat['category_group'];
                    echo '<optgroup label="' . h($groupLabels[$curGroup] ?? ucfirst(str_replace('_',' ',$curGroup))) . '">';
                endif;
            ?>
            <option value="<?= $cat['id'] ?>" <?= ($_GET['category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>><?= h($cat['category_name']) ?></option>
            <?php endforeach; if ($curGroup !== null) echo '</optgroup>'; ?>
        </select>
        <select name="dept_id" class="form-control" style="width:170px">
            <option value="">All Departments</option>
            <?php foreach ($departments as $dept): ?>
            <option value="<?= $dept['id'] ?>" <?= ($_GET['dept_id'] ?? '') == $dept['id'] ? 'selected' : '' ?>><?= h($dept['department_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="location_id" class="form-control" style="width:170px">
            <option value="">All Locations</option>
            <?php foreach ($locations as $loc): ?>
            <option value="<?= $loc['location_id'] ?>" <?= ($_GET['location_id'] ?? '') == $loc['location_id'] ? 'selected' : '' ?>><?= h($loc['location_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input type="date" name="from_date" class="form-control" style="width:150px" value="<?= h($_GET['from_date'] ?? '') ?>" placeholder="From">
        <input type="date" name="to_date"   class="form-control" style="width:150px" value="<?= h($_GET['to_date']   ?? '') ?>" placeholder="To">
        <button type="submit" class="btn btn-primary">View</button>
        <?php if ($hasFilters): ?>
        <a href="?page=issue_comments" class="btn btn-ghost btn-sm">Reset</a>
        <?php endif; ?>
    </div>
</form>
<script>
// Status checkbox-dropdown — twin of the pageIssues widget. IDs prefixed
// "ic-" so the two pages don't collide if both scripts ever co-exist.
(function () {
    var btn   = document.getElementById('ic-status-btn');
    var panel = document.getElementById('ic-status-panel');
    var label = document.getElementById('ic-status-label');
    var all   = document.getElementById('ic-status-all');
    if (!btn || !panel || !label || !all) return;
    var boxes = panel.querySelectorAll('.ic-status-cb');

    function syncLabel() {
        var n = 0;
        boxes.forEach(function (b) { if (b.checked) n++; });
        if (n === 0)                  label.textContent = 'None selected';
        else if (n === boxes.length)  label.textContent = 'All statuses';
        else                          label.textContent = n + ' selected';
        all.checked = (n === boxes.length);
        all.indeterminate = (n > 0 && n < boxes.length);
    }
    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        panel.style.display = (panel.style.display === 'block') ? 'none' : 'block';
    });
    panel.addEventListener('click', function (e) { e.stopPropagation(); });
    document.addEventListener('click', function () { panel.style.display = 'none'; });
    all.addEventListener('change', function () {
        boxes.forEach(function (b) { b.checked = all.checked; });
        syncLabel();
    });
    boxes.forEach(function (b) { b.addEventListener('change', syncLabel); });
    syncLabel();
})();
</script>

<?php if (!$hasFilters): ?>
<div class="rpt-prompt">Apply filters and click <strong>View</strong> to load comments.</div>
<?php elseif (empty($rows)): ?>
<div class="rpt-prompt">No comments match the selected filters.</div>
<?php else: ?>
<div class="table-count" style="margin:8px 0 10px"><?= count($rows) ?> open ticket(s) with comments</div>
<div class="table-wrap" data-stack>
    <table class="table">
        <thead>
            <tr>
                <th style="width:80px">Ticket</th>
                <th>Summary</th>
                <th style="width:110px">Status</th>
                <th style="width:80px">Priority</th>
                <th style="width:130px">Location</th>
                <th style="width:140px">Last Comment By</th>
                <th>Last Comment</th>
                <th style="width:100px">At</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td style="white-space:nowrap">
                    <a href="?page=view_issue&id=<?= (int)$r['issue_id'] ?>" target="_blank"
                       style="color:var(--accent)">WP-<?= (int)$r['issue_id'] ?></a>
                </td>
                <td>
                    <a href="?page=view_issue&id=<?= (int)$r['issue_id'] ?>" target="_blank"
                       style="color:var(--text);text-decoration:none"><?= h($r['summary']) ?></a>
                    <?php if (!empty($r['category_name'])): ?>
                    <div class="text-muted" style="font-size:11px"><?= h($r['category_name']) ?></div>
                    <?php endif; ?>
                </td>
                <td><span class="badge <?= statusBadgeClass($r['status']) ?>"><?= statusLabel($r['status']) ?></span></td>
                <td><span class="badge <?= priorityBadgeClass($r['priority']) ?>"><?= ucfirst($r['priority']) ?></span></td>
                <td><?= h($r['location_name'] ?? '—') ?></td>
                <td><?= h($r['author_name'] ?? $r['author_code']) ?></td>
                <td style="font-size:13px;white-space:pre-wrap;word-break:break-word"><?= h($r['body']) ?></td>
                <td class="text-muted" style="font-size:12px">
                    <?= date('d M Y', strtotime($r['comment_at'])) ?><br>
                    <?= date('H:i', strtotime($r['comment_at'])) ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php }

// ── View issue detail ─────────────────────────────────────
function pageViewIssue(): void {
    $id = (int)($_GET['id'] ?? 0);
    $db = getDb();

    $st = $db->prepare(
        "SELECT i.*, c.category_name, c.category_group, l.location_name,
                re.full_name AS reporter_name
         FROM issues i
         LEFT JOIN issue_categories c ON i.category_id = c.id
         LEFT JOIN locations l ON i.location_id = l.location_id
         LEFT JOIN employees re ON i.reporter_code = re.employee_code
         WHERE i.id = ?"
    );
    $st->execute([$id]);
    $issue = $st->fetch(PDO::FETCH_ASSOC);

    if (!$issue || !canViewIssue($issue)) {
        flash('error', 'Ticket not found or access denied.');
        header('Location: index.php?page=issues'); exit;
    }

    $comments = $db->prepare(
        "SELECT ic.*, e.full_name AS author_name FROM issue_comments ic
         LEFT JOIN employees e ON ic.author_code = e.employee_code
         WHERE ic.issue_id = ? ORDER BY ic.created_at ASC"
    );
    $comments->execute([$id]);
    $comments = $comments->fetchAll(PDO::FETCH_ASSOC);

    $attachments = $db->prepare("SELECT id, comment_id, filename, file_size FROM issue_attachments WHERE issue_id = ? ORDER BY created_at ASC");
    $attachments->execute([$id]);
    $attachments = $attachments->fetchAll(PDO::FETCH_ASSOC);

    $participants = $db->prepare(
        "SELECT ip.department_id, d.department_name FROM issue_participants ip
         LEFT JOIN departments d ON ip.department_id = d.id
         WHERE ip.issue_id = ?"
    );
    $participants->execute([$id]);
    $participants = $participants->fetchAll(PDO::FETCH_ASSOC);

    $statusLogs = $db->prepare(
        "SELECT sl.*, e.full_name AS changed_by_name
         FROM issue_status_logs sl
         LEFT JOIN employees e ON sl.changed_by = e.employee_code
         WHERE sl.issue_id = ? ORDER BY sl.changed_at ASC"
    );
    $statusLogs->execute([$id]);
    $statusLogs = $statusLogs->fetchAll(PDO::FETCH_ASSOC);

    $transitions = getAllowedTransitions($issue['status'], $issue['category_group'] ?? '');
?>
<div class="page-header">
    <h2>Ticket WP-<?= $issue['id'] ?></h2>
    <div style="display:flex;gap:8px">
        <a href="?page=my_time&issue_id=<?= $id ?>" class="btn btn-ghost btn-sm">Log Time</a>
        <?php if (canManageIssues()): ?>
        <a href="?page=edit_issue&id=<?= $id ?>" class="btn btn-ghost btn-sm">Edit</a>
        <?php endif; ?>
        <a href="?page=issues" class="btn btn-ghost btn-sm">Back to List</a>
    </div>
</div>

<div class="form-card" style="margin-bottom:16px">
    <h3 style="font-size:16px;margin-bottom:12px"><?= h($issue['summary']) ?></h3>
    <div class="form-grid" style="gap:10px">
        <div><span class="text-muted">Status:</span> <span class="badge <?= statusBadgeClass($issue['status']) ?>"><?= statusLabel($issue['status']) ?></span></div>
        <div><span class="text-muted">Priority:</span> <span class="badge <?= priorityBadgeClass($issue['priority']) ?>"><?= ucfirst($issue['priority']) ?></span></div>
        <div><span class="text-muted">Category:</span> <?= h($issue['category_name'] ?? '—') ?></div>
        <div><span class="text-muted">Location:</span> <?= h($issue['location_name'] ?? '—') ?></div>
        <div><span class="text-muted">Reporter:</span> <?= h($issue['reporter_name'] ?? $issue['reporter_code']) ?></div>
        <div><span class="text-muted">Created:</span> <?= date('d M Y H:i', strtotime($issue['created_at'])) ?></div>
        <div><span class="text-muted">Updated:</span> <?= date('d M Y H:i', strtotime($issue['updated_at'])) ?></div>
    </div>
    <?php if ($issue['description']): ?>
    <div style="margin-top:12px;padding:10px;background:var(--bg);border-radius:6px;font-size:13px;white-space:pre-wrap"><?= h($issue['description']) ?></div>
    <?php endif; ?>

    <?php if ($participants): ?>
    <div style="margin-top:10px">
        <span class="text-muted">Departments:</span>
        <?php foreach ($participants as $p): ?>
        <span class="badge badge-purple"><?= h($p['department_name'] ?? 'Unknown') ?></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php
    $issueAttachments = array_filter($attachments, fn($a) => $a['comment_id'] === null);
    if ($issueAttachments): ?>
    <div style="margin-top:10px">
        <span class="text-muted">Attachments:</span>
        <?php foreach ($issueAttachments as $a): ?>
        <a href="?page=download_issue_attachment&issue_id=<?= $id ?>&att_id=<?= $a['id'] ?>" class="btn btn-ghost btn-sm" style="margin:2px" target="_blank">
            <?= h($a['filename']) ?> <span class="text-muted">(<?= round($a['file_size']/1024) ?>KB)</span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($transitions): ?>
<div class="form-card" style="margin-bottom:16px;padding:12px">
    <span class="text-muted" style="margin-right:8px">Change Status:</span>
    <?php foreach ($transitions as $t): ?>
    <form method="POST" class="inline-form" style="margin-right:4px">
        <input type="hidden" name="action" value="transition_issue">
        <input type="hidden" name="issue_id" value="<?= $id ?>">
        <input type="hidden" name="new_status" value="<?= $t ?>">
        <button type="submit" class="btn btn-sm <?= statusBadgeClass($t) ?>" style="cursor:pointer"><?= statusLabel($t) ?></button>
    </form>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($statusLogs): ?>
<div class="form-card" style="margin-bottom:16px;padding:12px">
    <h3 style="font-size:14px;margin-bottom:10px">Status History</h3>
    <div class="table-wrap" data-stack>
        <table class="table" style="font-size:12px">
            <thead><tr><th>From</th><th>To</th><th>Changed By</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($statusLogs as $sl): ?>
            <tr>
                <td><?= $sl['old_status'] ? '<span class="badge ' . statusBadgeClass($sl['old_status']) . '">' . statusLabel($sl['old_status']) . '</span>' : '—' ?></td>
                <td><span class="badge <?= statusBadgeClass($sl['new_status']) ?>"><?= statusLabel($sl['new_status']) ?></span></td>
                <td><?= h($sl['changed_by_name'] ?? $sl['changed_by']) ?></td>
                <td class="text-muted"><?= date('d M Y H:i', strtotime($sl['changed_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Comments -->
<div style="margin-bottom:16px">
    <h3 style="font-size:14px;margin-bottom:10px">Comments (<?= count($comments) ?>)</h3>
    <?php foreach ($comments as $c): ?>
    <div class="form-card" style="margin-bottom:8px;padding:12px">
        <div style="display:flex;justify-content:space-between;margin-bottom:6px">
            <strong style="font-size:13px"><?= h($c['author_name'] ?? $c['author_code']) ?></strong>
            <span class="text-muted"><?= date('d M Y H:i', strtotime($c['created_at'])) ?></span>
        </div>
        <div style="font-size:13px;white-space:pre-wrap"><?= h($c['body']) ?></div>
        <?php
        $commentAtts = array_filter($attachments, fn($a) => $a['comment_id'] == $c['id']);
        if ($commentAtts): ?>
        <div style="margin-top:6px">
            <?php foreach ($commentAtts as $a): ?>
            <a href="?page=download_issue_attachment&issue_id=<?= $id ?>&att_id=<?= $a['id'] ?>" class="btn btn-ghost btn-sm" style="margin:2px" target="_blank">
                <?= h($a['filename']) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- Add comment -->
<div class="form-card">
    <h3 style="font-size:14px;margin-bottom:10px">Add Comment</h3>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="add_comment">
        <input type="hidden" name="issue_id" value="<?= $id ?>">
        <div class="form-group" style="margin-bottom:10px">
            <textarea name="body" class="form-control" rows="3" required placeholder="Write your comment..."></textarea>
        </div>
        <div class="form-group" style="margin-bottom:10px">
            <input type="file" name="attachments[]" class="form-control" multiple
                   accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv,.zip">
            <span class="hint">Optional attachments (max 5MB each)</span>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Post Comment</button>
    </form>
</div>

<?php
}

// ── Manage categories page (superadmin) ───────────────────
function pageManageCategories(): void {
    $db = getDb();
    $categories = $db->query("SELECT id, category_group, category_name, is_active FROM issue_categories ORDER BY category_group, sort_order, category_name")->fetchAll(PDO::FETCH_ASSOC);
    $departments = getDepartments();

    $roleMap = [];
    $roleRows = $db->query("SELECT category_id, department_id FROM issue_category_roles")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($roleRows as $rr) {
        $roleMap[(int)$rr['category_id']][] = (int)$rr['department_id'];
    }
?>
<div class="page-header"><h2>Ticket Categories</h2></div>

<div class="form-card" style="margin-bottom:16px;max-width:none">
    <h3 id="catFormTitle" style="font-size:14px;margin-bottom:12px">Add Category</h3>
    <form method="POST" id="catForm">
        <input type="hidden" name="action" value="save_category">
        <input type="hidden" name="category_id" id="catId" value="0">
        <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:10px">
            <div class="form-group">
                <label>Group</label>
                <select name="category_group" id="catGroup" class="form-control" required>
                    <option value="advance_maintenance">Advance Maintenance</option>
                    <option value="hr_issue">HR Issue</option>
                    <option value="service_type">Service Type</option>
                    <option value="incident">Incident</option>
                </select>
            </div>
            <div class="form-group" style="flex:1;min-width:200px">
                <label>Name</label>
                <input type="text" name="category_name" id="catName" class="form-control" required placeholder="Category name">
            </div>
        </div>
        <div class="form-group" style="margin-bottom:10px">
            <label>Department Roles</label>
            <div id="catDepts" style="columns:5 160px;column-gap:16px;margin-top:4px">
                <?php foreach ($departments as $dept): ?>
                <label class="checkbox-label" style="font-size:13px;display:flex;padding-top:0;margin-bottom:6px;break-inside:avoid">
                    <input type="checkbox" name="role_depts[]" value="<?= $dept['id'] ?>">
                    <?= h($dept['department_name']) ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div style="display:flex;gap:8px">
            <button type="submit" class="btn btn-primary" id="catSubmitBtn">Add</button>
            <button type="button" class="btn btn-ghost" id="catCancelBtn" style="display:none" onclick="resetCatForm()">Cancel</button>
        </div>
    </form>
</div>

<?php foreach (['hr_issue' => 'HR Issues', 'service_type' => 'Service Types', 'advance_maintenance' => 'Advance Maintenance', 'incident' => 'Incidents'] as $group => $label): ?>
<h3 style="font-size:13px;margin:14px 0 8px;color:var(--muted);text-transform:uppercase"><?= $label ?></h3>
<div class="table-wrap" data-stack style="margin-bottom:14px">
    <table class="table">
        <thead><tr><th style="width:50px">ID</th><th>Name</th><th>Departments</th><th style="width:80px">Status</th><th style="width:100px">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($categories as $cat): if ($cat['category_group'] !== $group) continue;
            $catDepts = $roleMap[(int)$cat['id']] ?? [];
        ?>
            <tr class="<?= $cat['is_active'] ? '' : 'row-inactive' ?>">
                <td><?= $cat['id'] ?></td>
                <td><?= h($cat['category_name']) ?></td>
                <td style="font-size:12px"><?php
                    if ($catDepts) {
                        foreach ($departments as $d) {
                            if (in_array((int)$d['id'], $catDepts)) {
                                echo '<span class="badge badge-purple" style="margin:1px">' . h($d['department_name']) . '</span>';
                            }
                        }
                    } else { echo '<span class="text-muted">None</span>'; }
                ?></td>
                <td><?= $cat['is_active'] ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-grey">Inactive</span>' ?></td>
                <td style="white-space:nowrap">
                    <button type="button" class="btn btn-sm" onclick='editCat(<?= json_encode([
                        "id" => (int)$cat["id"],
                        "group" => $cat["category_group"],
                        "name" => $cat["category_name"],
                        "depts" => $catDepts
                    ], JSON_HEX_APOS | JSON_HEX_TAG) ?>)'>Edit</button>
                    <form method="POST" class="inline-form" onsubmit="return confirm('Delete this category?')" style="display:inline">
                        <input type="hidden" name="action" value="del_category">
                        <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<script>
function editCat(cat) {
    document.getElementById('catId').value = cat.id;
    document.getElementById('catGroup').value = cat.group;
    document.getElementById('catName').value = cat.name;
    document.getElementById('catFormTitle').textContent = 'Edit Category #' + cat.id;
    document.getElementById('catSubmitBtn').textContent = 'Save';
    document.getElementById('catCancelBtn').style.display = '';
    // Set department checkboxes
    var boxes = document.querySelectorAll('#catDepts input[type=checkbox]');
    boxes.forEach(function(cb) { cb.checked = cat.depts.indexOf(parseInt(cb.value)) !== -1; });
    document.getElementById('catForm').scrollIntoView({behavior:'smooth'});
}
function resetCatForm() {
    document.getElementById('catId').value = '0';
    document.getElementById('catGroup').value = 'hr_issue';
    document.getElementById('catName').value = '';
    document.getElementById('catFormTitle').textContent = 'Add Category';
    document.getElementById('catSubmitBtn').textContent = 'Add';
    document.getElementById('catCancelBtn').style.display = 'none';
    var boxes = document.querySelectorAll('#catDepts input[type=checkbox]');
    boxes.forEach(function(cb) { cb.checked = false; });
}
</script>
<?php endforeach; ?>
<?php }

// ── Download attachment ────────────────────────────────────
function downloadIssueAttachment(): void {
    $issueId = (int)($_GET['issue_id'] ?? 0);
    $attId   = (int)($_GET['att_id']   ?? 0);

    $db = getDb();
    $st = $db->prepare("SELECT id, reporter_code, location_id FROM issues WHERE id = ?");
    $st->execute([$issueId]);
    $issue = $st->fetch(PDO::FETCH_ASSOC);

    if (!$issue || !canViewIssue($issue)) {
        http_response_code(403);
        echo 'Access denied';
        return;
    }

    serveAttachment($attId, $issueId);
}

function serveAttachment(int $attId, int $issueId): void {
    $db = getDb();
    $st = $db->prepare("SELECT comment_id, filename, stored_name, mime_type, file_size FROM issue_attachments WHERE id = ? AND issue_id = ?");
    $st->execute([$attId, $issueId]);
    $att = $st->fetch(PDO::FETCH_ASSOC);
    if (!$att) return;

    // Bucketed layout for new uploads, legacy un-bucketed path as fallback.
    $dir  = issueAttachmentDir($issueId, $att['comment_id'] ? (int)$att['comment_id'] : null, false);
    $path = $dir . $att['stored_name'];

    if (!file_exists($path)) return;

    header('Content-Type: ' . $att['mime_type']);
    header('Content-Disposition: inline; filename="' . $att['filename'] . '"');
    header('Content-Length: ' . $att['file_size']);

    readfile($path);
    exit;
}

// ── CSV export for issues ─────────────────────────────────
function exportIssues(): void {
    $db = getDb();
    $where = [];
    $params = [];

    if (!isSuperadmin() && !canManageIssues()) {
        $code = myCode();
        $locId = myLocationId();
        $deptId = myDeptId();
        $visConds = ["i.reporter_code = ?"];
        $params[] = $code;
        if ($locId > 0) {
            $visConds[] = "i.location_id = ?";
            $params[] = $locId;
        }
        if ($deptId > 0) {
            $visConds[] = "EXISTS (SELECT 1 FROM issue_participants ip WHERE ip.issue_id = i.id AND ip.department_id = ?)";
            $params[] = $deptId;
        }
        $where[] = "(" . implode(' OR ', $visConds) . ")";
    }

    $statusFilter = issueStatusFilter();
    if ($statusFilter) {
        $ph = implode(',', array_fill(0, count($statusFilter), '?'));
        $where[] = "i.status IN ($ph)";
        foreach ($statusFilter as $s) $params[] = $s;
    } else {
        $where[] = '1=0';
    }
    if (!empty($_GET['priority']))    { $where[] = "i.priority = ?";    $params[] = $_GET['priority']; }
    if (!empty($_GET['category_id'])) { $where[] = "i.category_id = ?"; $params[] = (int)$_GET['category_id']; }
    if (!empty($_GET['dept_id']))     { $where[] = "EXISTS (SELECT 1 FROM issue_participants ip WHERE ip.issue_id = i.id AND ip.department_id = ?)"; $params[] = (int)$_GET['dept_id']; }
    if (!empty($_GET['location_id'])) { $where[] = "i.location_id = ?"; $params[] = (int)$_GET['location_id']; }
    // Keyword search
    $q = trim($_GET['q'] ?? '');
    if ($q !== '') {
        if (preg_match('/^WP-?(\d+)$/i', $q, $m)) { $where[] = "i.id = ?"; $params[] = (int)$m[1]; }
        elseif (ctype_digit($q)) { $where[] = "i.id = ?"; $params[] = (int)$q; }
        else { $where[] = "(i.summary LIKE ? OR i.description LIKE ?)"; $params[] = "%{$q}%"; $params[] = "%{$q}%"; }
    }

    if (!empty($_GET['from_date']))   { $where[] = "DATE(i.created_at) >= ?"; $params[] = $_GET['from_date']; }
    if (!empty($_GET['to_date']))     { $where[] = "DATE(i.created_at) <= ?"; $params[] = $_GET['to_date']; }

    $sql = "SELECT i.id, c.category_group, c.category_name, i.summary, i.description, i.status, i.priority,
                   l.location_name, re.full_name AS reporter,
                   i.created_at, i.updated_at
            FROM issues i
            LEFT JOIN issue_categories c ON i.category_id = c.id
            LEFT JOIN locations l ON i.location_id = l.location_id
            LEFT JOIN employees re ON i.reporter_code = re.employee_code";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY i.created_at DESC";

    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $participants = [];
    if ($rows) {
        $ids = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pst = $db->prepare(
            "SELECT ip.issue_id, d.department_name FROM issue_participants ip
             LEFT JOIN departments d ON ip.department_id = d.id
             WHERE ip.issue_id IN ({$placeholders}) ORDER BY ip.issue_id, d.department_name"
        );
        $pst->execute($ids);
        foreach ($pst->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $participants[$p['issue_id']][] = $p['department_name'] ?? '';
        }
    }

    $comments = [];
    if ($rows) {
        $ids = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $cst = $db->prepare(
            "SELECT ic.issue_id, e.full_name, ic.body, ic.created_at
             FROM issue_comments ic
             LEFT JOIN employees e ON ic.author_code = e.employee_code
             WHERE ic.issue_id IN ({$placeholders})
             ORDER BY ic.issue_id, ic.created_at ASC"
        );
        $cst->execute($ids);
        foreach ($cst->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $date = date('d M Y H:i', strtotime($c['created_at']));
            $comments[$c['issue_id']][] = "[{$date}] " . ($c['full_name'] ?? 'Unknown') . ": " . $c['body'];
        }
    }

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="issues_export_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Ticket ID','Category Group','Category Name','Summary','Description','Status','Priority','Location','Reporter','Participants','Created At','Updated At','Comments'], escape: '');
    foreach ($rows as $r) {
        $cText = isset($comments[$r['id']]) ? implode(" | ", $comments[$r['id']]) : '';
        $pText = isset($participants[$r['id']]) ? implode(", ", $participants[$r['id']]) : '';
        fputcsv($out, [
            'WP-' . $r['id'], $r['category_group'] ?? '', $r['category_name'] ?? '', $r['summary'],
            $r['description'] ?? '', $r['status'], $r['priority'], $r['location_name'] ?? '',
            $r['reporter'] ?? '', $pText,
            $r['created_at'], $r['updated_at'], $cText
        ], escape: '');
    }
    fclose($out);
    exit;
}

// ── Issue Summary — department-wise ticket status counts ──
function pageIssueSummary(): void {
    $db = getDb();
    $statuses = ['assigned_to_concerned','waiting_for_customer','in_progress','resolved','closed'];
    $statusLabels = [];
    foreach ($statuses as $s) $statusLabels[$s] = statusLabel($s);

    $rows = $db->query(
        "SELECT ip.department_id, d.department_name, i.status, COUNT(*) AS cnt
         FROM issues i
         JOIN issue_participants ip ON ip.issue_id = i.id
         JOIN departments d ON ip.department_id = d.id
         GROUP BY ip.department_id, d.department_name, i.status
         ORDER BY d.department_name, i.status"
    )->fetchAll(PDO::FETCH_ASSOC);

    $grid = [];
    foreach ($rows as $r) {
        $did = (int)$r['department_id'];
        if (!isset($grid[$did])) {
            $grid[$did] = ['name' => $r['department_name'], 'counts' => array_fill_keys($statuses, 0), 'total' => 0];
        }
        if (isset($grid[$did]['counts'][$r['status']])) {
            $grid[$did]['counts'][$r['status']] = (int)$r['cnt'];
            $grid[$did]['total'] += (int)$r['cnt'];
        }
    }
?>
<div class="page-header"><h2>Ticket Summary</h2></div>
<?php if (empty($grid)): ?>
<div class="rpt-prompt">No tickets found.</div>
<?php else: ?>
<div class="table-wrap" data-stack>
    <table class="table">
        <thead>
            <tr>
                <th>Department</th>
                <?php foreach ($statuses as $s): ?>
                <th style="text-align:center"><?= $statusLabels[$s] ?></th>
                <?php endforeach; ?>
                <th style="text-align:center"><strong>Total</strong></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($grid as $did => $dept): ?>
            <tr>
                <td><?= h($dept['name']) ?></td>
                <?php foreach ($statuses as $s): $cnt = $dept['counts'][$s]; ?>
                <td style="text-align:center">
                    <?php if ($cnt > 0): ?>
                    <a href="?page=issues&view=1&dept_id=<?= $did ?>&status=<?= $s ?>" style="color:var(--accent)"><?= $cnt ?></a>
                    <?php else: ?>
                    <span class="text-muted">0</span>
                    <?php endif; ?>
                </td>
                <?php endforeach; ?>
                <td style="text-align:center"><strong>
                    <a href="?page=issues&view=1&dept_id=<?= $did ?>" style="color:var(--accent)"><?= $dept['total'] ?></a>
                </strong></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php }

// ===========================================================
// PAGE: Delete issues — superadmin only, bulk-by-ID
// ===========================================================
// Operator pastes a list of WP-N ids (or bare numbers) separated by
// commas, optionally with whitespace, and the handler removes each
// issue along with everything attached to it:
//   - issue_participants  (no FK CASCADE — explicit DELETE)
//   - issue_attachments   (FK CASCADE) + attachment files on disk
//   - issue_comments      (FK CASCADE)
//   - issue_status_logs   (FK CASCADE)
//   - the issues row itself
// Each id is processed in its own try/catch so a single bad row
// (already-deleted, FK leftover from a custom table, etc.) doesn't
// block the rest of the batch.

// Recursively rm -rf a directory. Defensive — only deletes paths
// inside our uploads/issues/ root so a typo can't escape.
function issuesRmRf(string $dir): void {
    $root = realpath(UPLOAD_DIR);
    $real = realpath($dir);
    if (!$root || !$real || strpos($real, $root) !== 0) return;
    if (!is_dir($real)) return;
    foreach (array_diff(scandir($real), ['.', '..']) as $f) {
        $p = $real . DIRECTORY_SEPARATOR . $f;
        if (is_dir($p) && !is_link($p)) issuesRmRf($p);
        else                            @unlink($p);
    }
    @rmdir($real);
}

function pageDeleteIssues(): void {
    if (!isSuperadmin()) { echo '<p>Access denied.</p>'; return; }
?>
<div class="page-header"><h2>🗑 Delete Tickets <span style="font-size:13px;color:var(--muted);font-weight:400">(Superadmin)</span></h2></div>

<div class="alert alert-error" style="margin-bottom:14px">
    <strong>Warning:</strong> this permanently removes the ticket and every comment, status log, participant, and attachment file linked to it.
    There is no undo. Make a database backup before running this on production data.
</div>

<form method="POST" class="form-card" style="max-width:760px"
      onsubmit="return confirm('Permanently delete the listed tickets? This cannot be undone.')">
    <input type="hidden" name="action" value="delete_issues_bulk">
    <div class="form-group">
        <label>Ticket IDs <span class="required">*</span></label>
        <textarea name="ids" rows="4" class="form-control" required
                  placeholder="WP-1, WP-4, WP-12"
                  style="font-family:Consolas,monospace"></textarea>
        <span class="hint">Comma-separated. The <code>WP-</code> prefix is optional. Whitespace is fine.</span>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-danger">Delete Listed Tickets</button>
        <a href="?page=issues" class="btn btn-ghost">Back to Tickets</a>
    </div>
</form>
<?php
}

// POST: parse the id list, delete each issue + everything attached.
function doDeleteIssuesBulk(): void {
    if (!isSuperadmin()) {
        flash('error', 'Access denied.');
        header('Location: index.php?page=delete_issues'); exit;
    }
    $raw = trim((string)($_POST['ids'] ?? ''));
    if ($raw === '') {
        flash('error', 'No ticket IDs provided.');
        header('Location: index.php?page=delete_issues'); exit;
    }
    // Tokenise — allow "WP-1, WP-4" / "WP-1,4" / "1, 4". Any token
    // that doesn't match (WP-?)<digits> is reported as invalid and
    // skipped; the rest still process.
    $tokens = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
    $ids       = [];
    $invalid   = [];
    foreach ($tokens as $tok) {
        if (preg_match('/^(?:WP-?)?(\d+)$/i', $tok, $m)) {
            $ids[] = (int)$m[1];
        } else {
            $invalid[] = $tok;
        }
    }
    $ids = array_values(array_unique(array_filter($ids, fn($n) => $n > 0)));
    if (!$ids) {
        flash('error', 'No valid IDs in input. Use the format WP-1, WP-4, …');
        header('Location: index.php?page=delete_issues'); exit;
    }

    $db = getDb();
    $deleted = [];
    $missing = [];
    $failed  = [];
    foreach ($ids as $issueId) {
        try {
            // Confirm the row exists; capture summary for the flash msg.
            $st = $db->prepare('SELECT id, summary FROM issues WHERE id = ?');
            $st->execute([$issueId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) { $missing[] = $issueId; continue; }

            // 1) Wipe attachment files from disk — read names FIRST
            // (the FK CASCADE on the DELETE below will drop the rows
            // before we get a chance to scan them).
            $ast = $db->prepare('SELECT stored_name, comment_id FROM issue_attachments WHERE issue_id = ?');
            $ast->execute([$issueId]);
            $atts = $ast->fetchAll(PDO::FETCH_ASSOC);
            foreach ($atts as $a) {
                $cid  = $a['comment_id'] ? (int)$a['comment_id'] : null;
                $dir  = issueAttachmentDir($issueId, $cid, false);
                $path = $dir . $a['stored_name'];
                if (is_file($path)) @unlink($path);
            }

            // 2) Issue participants — no FK CASCADE on this table.
            $db->prepare('DELETE FROM issue_participants WHERE issue_id = ?')->execute([$issueId]);

            // 3) The row itself — FK CASCADE wipes attachments,
            //    comments, and status logs in one go.
            $db->prepare('DELETE FROM issues WHERE id = ?')->execute([$issueId]);

            // 4) Empty bucketed + legacy issue dirs (no-op if files
            //    were already gone or the dir doesn't exist).
            issuesRmRf(UPLOAD_DIR . issueBucketDir($issueId) . '/' . $issueId);
            issuesRmRf(UPLOAD_DIR . $issueId);

            $deleted[] = 'WP-' . $issueId;
        } catch (Exception $e) {
            $failed[] = 'WP-' . $issueId . ' (' . $e->getMessage() . ')';
        }
    }

    $msgParts = [];
    if ($deleted) $msgParts[] = 'Deleted: ' . implode(', ', $deleted);
    if ($missing) $msgParts[] = 'Not found: ' . implode(', ', array_map(fn($n) => 'WP-' . $n, $missing));
    if ($invalid) $msgParts[] = 'Invalid input: ' . implode(', ', $invalid);
    if ($failed)  $msgParts[] = 'Failed: ' . implode('; ', $failed);

    flash($deleted ? 'success' : 'error', $msgParts ? implode(' · ', $msgParts) : 'Nothing happened.');
    header('Location: index.php?page=delete_issues'); exit;
}
