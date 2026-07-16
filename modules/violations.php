<?php
// =========================================================
// Violations Module — outlet-level violation tracking with
// escalating penalties + manager remarks + counter reset log.
// Roles:
//   txn_record_violation        — record a new violation (NOT for Retail Sales dept)
//   txn_violations_view         — view all locations' violations
//   txn_reset_violation_counter — manually reset (loc, category) counter
//   txn_violation_admin         — edit category corrective-action master text
// Location-bound users (employees.location_id) see read-only + can add a
// "manager" remark on violations against their location.
// =========================================================

define('VIOL_UPLOAD_DIR', __DIR__ . '/../uploads/violations/');
define('VIOL_MAX_FILE',   5 * 1024 * 1024);
define('VIOL_ALLOWED_EXT', ['jpg','jpeg','png','gif']);

// ── Permission helpers ──────────────────────────────────
function violationCanRecord(): bool {
    if (strcasecmp(myDeptName(), 'Retail Sales') === 0) return false;
    return isSuperadmin() || hasTxn('record_violation');
}
function violationCanViewAll(): bool { return isSuperadmin() || hasTxn('violations_view'); }
function violationCanReset(): bool   { return isSuperadmin() || hasTxn('reset_violation_counter'); }
function violationCanAdmin(): bool   { return isSuperadmin() || hasTxn('violation_admin'); }
function violationCanDelete(): bool  { return isSuperadmin(); }

function violationCanViewRow(array $v): bool {
    if (violationCanViewAll()) return true;
    if (myLocationId() > 0 && (int)$v['location_id'] === myLocationId()) return true;
    if (!empty($v['recorded_by']) && $v['recorded_by'] === myCode()) return true;
    return false;
}

// Can the current user add a remark on this violation?
// Policy: super admin / view-all role can add admin remarks; the location's
// own user (location-bound, no view-all) can add a manager remark.
function violationCanAddRemark(array $v): bool {
    if (violationCanViewAll()) return true;
    if (myLocationId() > 0 && (int)$v['location_id'] === myLocationId()) return true;
    return false;
}

// ── Data fetchers ───────────────────────────────────────
const VIOL_CAT_COLS = 'id, name, slug, penalty_type, corrective_action_text,
                       needs_shortage_amount, needs_custom_amount, custom_amount_label,
                       sort_order, is_active, created_at';

function violationGetCategories(bool $activeOnly = true): array {
    $sql = 'SELECT ' . VIOL_CAT_COLS . ' FROM violation_categories';
    if ($activeOnly) $sql .= ' WHERE is_active=1';
    $sql .= ' ORDER BY sort_order, name';
    return getDb()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function violationGetCategory(int $id): ?array {
    $st = getDb()->prepare('SELECT ' . VIOL_CAT_COLS . ' FROM violation_categories WHERE id=?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function violationGetCategoryBySlug(string $slug): ?array {
    $st = getDb()->prepare('SELECT ' . VIOL_CAT_COLS . ' FROM violation_categories WHERE slug=?');
    $st->execute([$slug]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function violationGetTiers(int $categoryId): array {
    $st = getDb()->prepare(
        'SELECT id, category_id, tier_number, amount, action_type, action_label
         FROM violation_category_tiers WHERE category_id=? ORDER BY tier_number'
    );
    $st->execute([$categoryId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function violationGet(int $id): ?array {
    $st = getDb()->prepare(
        'SELECT v.*, c.name AS category_name, c.slug AS category_slug, c.penalty_type,
                c.corrective_action_text, l.location_name,
                e.full_name AS employee_name
         FROM violations v
         LEFT JOIN violation_categories c ON v.category_id = c.id
         LEFT JOIN locations l ON v.location_id = l.location_id
         LEFT JOIN employees e ON v.employee_code = e.employee_code
         WHERE v.id = ?'
    );
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Last reset time for a (location, category) — null if never reset.
function violationLastResetAt(int $locId, int $catId): ?string {
    $st = getDb()->prepare(
        'SELECT reset_at FROM violation_counter_resets
         WHERE location_id=? AND category_id=? ORDER BY reset_at DESC LIMIT 1'
    );
    $st->execute([$locId, $catId]);
    $r = $st->fetchColumn();
    return $r ?: null;
}

// Active (non-deleted) violation count since last reset for (location, category).
function violationCurrentCount(int $locId, int $catId): int {
    $lastReset = violationLastResetAt($locId, $catId);
    $sql = 'SELECT COUNT(*) FROM violations
            WHERE location_id=? AND category_id=? AND deleted_at IS NULL';
    $params = [$locId, $catId];
    if ($lastReset) {
        $sql .= ' AND created_at > ?';
        $params[] = $lastReset;
    }
    $st = getDb()->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn();
}

// Compute amount/action for the next violation given category + tier number.
// Returns ['amount', 'action_type', 'action_label'].
function violationComputeAction(array $cat, int $nextTier, ?float $shortageAmt, ?float $customAmt): array {
    $type = $cat['penalty_type'] ?? 'fixed_tier';
    $slug = $cat['slug'] ?? '';

    if ($type === 'fixed_tier') {
        $tiers = violationGetTiers((int)$cat['id']);
        // exact tier match
        foreach ($tiers as $t) {
            if ((int)$t['tier_number'] === $nextTier) {
                return [
                    'amount' => (float)($t['amount'] ?? 0),
                    'action_type' => $t['action_type'],
                    'action_label' => $t['action_label'],
                ];
            }
        }
        // Beyond highest defined tier — repeat last tier (typically show_cause).
        if ($tiers) {
            $last = end($tiers);
            return [
                'amount' => (float)($last['amount'] ?? 0),
                'action_type' => $last['action_type'],
                'action_label' => $last['action_label'],
            ];
        }
        return ['amount' => 0, 'action_type' => 'penalty', 'action_label' => '—'];
    }

    if ($type === 'amount_based' && $slug === 'cash_short') {
        $amt = (float)($shortageAmt ?? 0);
        if ($amt < 500)         $penalty = 50;
        elseif ($amt <= 1000)   $penalty = 100;
        elseif ($amt <= 1500)   $penalty = 150;
        else                    $penalty = 200;

        if ($nextTier == 3) {
            return ['amount' => $penalty, 'action_type' => 'intimation_letter',
                    'action_label' => "₹{$penalty} penalty + Intimation Letter (3rd violation)"];
        }
        if ($nextTier >= 4) {
            return ['amount' => $penalty, 'action_type' => 'show_cause',
                    'action_label' => "₹{$penalty} penalty + Show Cause Notice"];
        }
        return ['amount' => $penalty, 'action_type' => 'penalty',
                'action_label' => "₹{$penalty} penalty (shortage ₹" . number_format($amt, 2) . ")"];
    }

    if ($type === 'escalating' && $slug === 'banking_break') {
        if ($nextTier == 1)      $amt = 10;
        elseif ($nextTier == 2)  $amt = 15;
        elseif ($nextTier == 3)  $amt = 30;
        else                     $amt = 30 + 20 * ($nextTier - 3);
        return ['amount' => $amt, 'action_type' => 'penalty',
                'action_label' => "₹{$amt} deduction"];
    }

    if ($type === 'escalating' && $slug === 'trade_item_display') {
        if ($nextTier == 1) return ['amount' => 0, 'action_type' => 'warning',
                                    'action_label' => 'Verbal warning'];
        if ($nextTier == 2) return ['amount' => 0, 'action_type' => 'written_warning',
                                    'action_label' => 'Written warning'];
        if ($nextTier == 3)      $amt = 25;
        elseif ($nextTier == 4)  $amt = 50;
        elseif ($nextTier == 5)  $amt = 75;
        else                     $amt = 75 + 25 * ($nextTier - 5);
        return ['amount' => $amt, 'action_type' => 'penalty',
                'action_label' => "₹{$amt} deduction"];
    }

    if ($type === 'custom_amount') {
        $amt = (float)($customAmt ?? 0);
        $lbl = $cat['custom_amount_label'] ?? 'Amount';
        return ['amount' => $amt, 'action_type' => 'penalty',
                'action_label' => "₹" . number_format($amt, 2) . " — " . $lbl];
    }

    if ($type === 'workflow_only') {
        return ['amount' => 0, 'action_type' => 'workflow',
                'action_label' => 'Recorded for follow-up'];
    }

    return ['amount' => 0, 'action_type' => 'penalty', 'action_label' => '—'];
}

// ── Display helpers ─────────────────────────────────────
function violationActionBadge(string $actionType): string {
    $map = [
        'penalty'           => ['Penalty',          'badge-yellow'],
        'intimation_letter' => ['Intimation Letter','badge-orange'],
        'show_cause'        => ['Show Cause',       'badge-red'],
        'warning'           => ['Verbal Warning',   'badge-grey'],
        'written_warning'   => ['Written Warning',  'badge-yellow'],
        'workflow'          => ['Follow-up',        'badge-grey'],
    ];
    [$lbl, $cls] = $map[$actionType] ?? [$actionType, 'badge-grey'];
    return '<span class="badge ' . $cls . '">' . h($lbl) . '</span>';
}

// ── POST handlers ───────────────────────────────────────
function doCreateViolation(): void {
    if (!violationCanRecord()) { flash('error', 'Access denied.'); header('Location: index.php?page=violations'); exit; }

    $catId    = (int)($_POST['category_id'] ?? 0);
    $locId    = (int)($_POST['location_id'] ?? 0);
    $empCode  = trim($_POST['employee_code'] ?? '');
    $shortage = (($_POST['shortage_amount'] ?? '') !== '') ? (float)$_POST['shortage_amount'] : null;
    $custom   = (($_POST['custom_amount']   ?? '') !== '') ? (float)$_POST['custom_amount']   : null;
    $notes    = trim($_POST['notes'] ?? '');

    $cat = violationGetCategory($catId);
    if (!$cat || !$locId) {
        flash('error', 'Category and location are required.');
        header('Location: index.php?page=violation_record'); exit;
    }
    if ($cat['needs_shortage_amount'] && ($shortage === null || $shortage < 0)) {
        flash('error', 'Shortage amount required for this category.');
        header('Location: index.php?page=violation_record'); exit;
    }
    if ($cat['needs_custom_amount'] && ($custom === null || $custom < 0)) {
        flash('error', ($cat['custom_amount_label'] ?: 'Amount') . ' required for this category.');
        header('Location: index.php?page=violation_record'); exit;
    }

    $current = violationCurrentCount($locId, $catId);
    $nextTier = $current + 1;
    $action = violationComputeAction($cat, $nextTier, $shortage, $custom);

    // ── Image attachment (optional) ──────────────────────
    $attName = null; $attStored = null;
    if (!empty($_FILES['attachment']['name']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $origName = basename($_FILES['attachment']['name']);
        $ext = mb_strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, VIOL_ALLOWED_EXT)) {
            flash('error', 'Invalid file type. Allowed: jpg, jpeg, png, gif.');
            header('Location: index.php?page=violation_record&category_id=' . $catId); exit;
        }
        if ($_FILES['attachment']['size'] > VIOL_MAX_FILE) {
            flash('error', 'File too large. Max 5 MB.');
            header('Location: index.php?page=violation_record&category_id=' . $catId); exit;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES['attachment']['tmp_name']);
        $allowedMimes = ['image/jpeg','image/png','image/gif'];
        if (!in_array($mime, $allowedMimes)) {
            flash('error', 'Invalid image content.');
            header('Location: index.php?page=violation_record&category_id=' . $catId); exit;
        }
        if (!is_dir(VIOL_UPLOAD_DIR)) mkdir(VIOL_UPLOAD_DIR, 0755, true);
        $attStored = uniqid('viol_', true) . '.' . $ext;
        $attName = $origName;
        move_uploaded_file($_FILES['attachment']['tmp_name'], VIOL_UPLOAD_DIR . $attStored);
    }

    $st = getDb()->prepare(
        'INSERT INTO violations
           (category_id, location_id, employee_code, recorded_by, counter_value,
            penalty_amount, action_type, action_label, shortage_amount, custom_amount, notes,
            attachment_name, attachment_stored)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        $catId, $locId, $empCode ?: null, myCode(), $nextTier,
        $action['amount'], $action['action_type'], $action['action_label'],
        $shortage, $custom, $notes ?: null,
        $attName, $attStored,
    ]);
    $newId = (int)getDb()->lastInsertId();

    flash('success', 'Violation recorded — ' . $action['action_label']);
    header('Location: index.php?page=violation_view&id=' . $newId); exit;
}

function doAddViolationRemark(): void {
    $vid    = (int)($_POST['violation_id'] ?? 0);
    $remark = trim($_POST['remark'] ?? '');
    if (!$vid || $remark === '') {
        flash('error', 'Remark text required.');
        header('Location: index.php?page=violation_view&id=' . $vid); exit;
    }
    $v = violationGet($vid);
    if (!$v) { flash('error', 'Not found.'); header('Location: index.php?page=violations'); exit; }
    if (!violationCanAddRemark($v)) { flash('error', 'Access denied.'); header('Location: index.php?page=violation_view&id=' . $vid); exit; }

    $role = violationCanViewAll() ? 'admin' : 'manager';
    $st = getDb()->prepare(
        'INSERT INTO violation_remarks (violation_id, remark_by, remark_role, remark) VALUES (?, ?, ?, ?)'
    );
    $st->execute([$vid, myCode(), $role, $remark]);

    flash('success', 'Remark added.');
    header('Location: index.php?page=violation_view&id=' . $vid); exit;
}

function doDeleteViolation(): void {
    if (!violationCanDelete()) { flash('error', 'Access denied.'); header('Location: index.php?page=violations'); exit; }
    $vid    = (int)($_POST['violation_id'] ?? 0);
    $reason = trim($_POST['delete_reason'] ?? '');
    if (!$vid) { flash('error', 'Invalid request.'); header('Location: index.php?page=violations'); exit; }

    $st = getDb()->prepare(
        'UPDATE violations SET deleted_at=NOW(), deleted_by=?, delete_reason=? WHERE id=? AND deleted_at IS NULL'
    );
    $st->execute([myCode(), $reason ?: null, $vid]);

    flash('success', 'Violation deleted. Counter recomputed.');
    header('Location: index.php?page=violations'); exit;
}

function doResetViolationCounter(): void {
    if (!violationCanReset()) { flash('error', 'Access denied.'); header('Location: index.php?page=violation_counter_reset'); exit; }
    $locId  = (int)($_POST['location_id'] ?? 0);
    $catId  = (int)($_POST['category_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    if (!$locId || !$catId) {
        flash('error', 'Location and category required.');
        header('Location: index.php?page=violation_counter_reset'); exit;
    }

    $current = violationCurrentCount($locId, $catId);
    $st = getDb()->prepare(
        'INSERT INTO violation_counter_resets
           (location_id, category_id, counter_value_before_reset, reset_by, reason)
         VALUES (?, ?, ?, ?, ?)'
    );
    $st->execute([$locId, $catId, $current, myCode(), $reason ?: null]);

    flash('success', "Counter reset (was {$current}).");
    header('Location: index.php?page=violation_counter_reset'); exit;
}

function doSaveViolationCategory(): void {
    if (!violationCanAdmin()) { flash('error', 'Access denied.'); header('Location: index.php?page=violation_categories'); exit; }
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $text = trim($_POST['corrective_action_text'] ?? '');
    if (!$id) { flash('error', 'Invalid category.'); header('Location: index.php?page=violation_categories'); exit; }
    if ($name === '') {
        flash('error', 'Name / question is required.');
        header('Location: index.php?page=violation_categories&edit=' . $id); exit;
    }
    if (mb_strlen($name) > 120) $name = mb_substr($name, 0, 120);

    $db = getDb();
    $db->prepare('UPDATE violation_categories SET name=?, corrective_action_text=? WHERE id=?')
       ->execute([$name, $text, $id]);

    // Update tier amounts (only fixed_tier rows); accept tier_amount[<tier_id>] inputs.
    if (!empty($_POST['tier_amount']) && is_array($_POST['tier_amount'])) {
        foreach ($_POST['tier_amount'] as $tierId => $val) {
            $tierId = (int)$tierId;
            $val = trim((string)$val);
            $amt = ($val === '') ? null : (float)$val;
            $db->prepare('UPDATE violation_category_tiers SET amount=? WHERE id=? AND category_id=?')
               ->execute([$amt, $tierId, $id]);
        }
    }

    flash('success', 'Category saved.');
    header('Location: index.php?page=violation_categories&edit=' . $id); exit;
}

// ── Pages ───────────────────────────────────────────────
function pageViolations(): void {
    $db = getDb();
    $viewClicked = !empty($_GET['view']);
    $filterLoc  = (int)($_GET['location_id'] ?? 0);
    $filterCat  = (int)($_GET['category_id'] ?? 0);
    // Default range: 1st of current month → today. Empty inputs (cleared
    // and View'd) fall back to the same defaults.
    $filterFrom = trim($_GET['from'] ?? '');
    $filterTo   = trim($_GET['to'] ?? '');
    if ($filterFrom === '') $filterFrom = date('Y-m-01');
    if ($filterTo   === '') $filterTo   = date('Y-m-d');
    $showDeleted = !empty($_GET['show_deleted']) && violationCanViewAll();
    $rows = [];

    if ($viewClicked) {
        $sql = 'SELECT v.*, c.name AS category_name, c.slug AS category_slug,
                       l.location_name, e.full_name AS employee_name
                FROM violations v
                LEFT JOIN violation_categories c ON v.category_id = c.id
                LEFT JOIN locations l ON v.location_id = l.location_id
                LEFT JOIN employees e ON v.employee_code = e.employee_code
                WHERE 1=1';
        $p = [];

        if (!$showDeleted) $sql .= ' AND v.deleted_at IS NULL';

        // Scope: if user can't view all, restrict to their location or their own records.
        if (!violationCanViewAll()) {
            $clauses = [];
            if (myLocationId() > 0)   { $clauses[] = 'v.location_id=?'; $p[] = myLocationId(); }
            if (myCode() !== '')      { $clauses[] = 'v.recorded_by=?'; $p[] = myCode(); }
            if (!$clauses) {
                // No legitimate scope — show nothing.
                $sql .= ' AND 0';
            } else {
                $sql .= ' AND (' . implode(' OR ', $clauses) . ')';
            }
        }

        if ($filterLoc) { $sql .= ' AND v.location_id=?'; $p[] = $filterLoc; }
        if ($filterCat) { $sql .= ' AND v.category_id=?'; $p[] = $filterCat; }
        if ($filterFrom !== '') { $sql .= ' AND v.created_at >= ?'; $p[] = $filterFrom . ' 00:00:00'; }
        if ($filterTo   !== '') { $sql .= ' AND v.created_at <= ?'; $p[] = $filterTo   . ' 23:59:59'; }

        $sql .= ' ORDER BY v.created_at DESC LIMIT 300';
        $st = $db->prepare($sql);
        $st->execute($p);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    $cats = violationGetCategories(false);
    $locs = getActiveLocations();
?>
<div class="page-header">
    <h2>Violations</h2>
    <div class="actions">
        <?php if (violationCanRecord()): ?>
            <a href="?page=violation_record" class="btn btn-primary">+ Record Violation</a>
        <?php endif; ?>
    </div>
</div>

<form method="GET" class="filter-bar" style="margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input type="hidden" name="page" value="violations">
    <input type="hidden" name="view" value="1">
    <?php
    // Location-bound users (no violations_view/admin txn) are server-side
    // scoped to their own store — hide the dropdown so the UI matches.
    if (violationCanViewAll() || violationCanAdmin()):
    ?>
    <select name="location_id" class="form-control" style="width:200px">
        <option value="0">— All locations —</option>
        <?php foreach ($locs as $l): ?>
        <option value="<?= $l['location_id'] ?>" <?= $filterLoc === (int)$l['location_id'] ? 'selected' : '' ?>>
            <?= h($l['location_name']) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <select name="category_id" class="form-control" style="width:200px">
        <option value="0">— All categories —</option>
        <?php foreach ($cats as $c): ?>
        <option value="<?= $c['id'] ?>" <?= $filterCat === (int)$c['id'] ? 'selected' : '' ?>>
            <?= h($c['name']) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <input type="date" id="vio-from-date" name="from" class="form-control" style="width:150px" value="<?= h($filterFrom) ?>">
    <input type="date" id="vio-to-date" name="to" class="form-control" style="width:150px" value="<?= h($filterTo) ?>">
    <?php if (violationCanViewAll()): ?>
    <label style="display:flex;align-items:center;gap:4px;font-size:12px">
        <input type="checkbox" name="show_deleted" value="1" <?= $showDeleted ? 'checked' : '' ?>> Show deleted
    </label>
    <?php endif; ?>
    <button class="btn btn-primary btn-sm">View</button>
</form>
<script>
(function () {
    var fromEl = document.getElementById('vio-from-date');
    var toEl   = document.getElementById('vio-to-date');
    if (!fromEl || !toEl) return;
    fromEl.addEventListener('change', function () {
        if (!fromEl.value) return;
        var parts = fromEl.value.split('-');
        if (parts.length !== 3) return;
        var y = parseInt(parts[0], 10), m = parseInt(parts[1], 10);
        if (!y || !m) return;
        var last = new Date(y, m, 0);
        var yyyy = last.getFullYear();
        var mm   = ('0' + (last.getMonth() + 1)).slice(-2);
        var dd   = ('0' + last.getDate()).slice(-2);
        toEl.value = yyyy + '-' + mm + '-' + dd;
    });
})();
</script>

<?php if (!$viewClicked): ?>
<div class="rpt-prompt">Choose filters above and click <strong>View</strong> to load results.</div>
<?php elseif (empty($rows)): ?>
<div class="rpt-prompt">No violations match these filters.</div>
<?php else: ?>
<div class="table-wrap" data-stack>
    <table class="table">
        <thead>
            <tr>
                <th style="width:50px">#</th>
                <th>Recorded</th>
                <th>Location</th>
                <th>Category</th>
                <th>Tier</th>
                <th>Action</th>
                <th>Amount</th>
                <th>Recorded By</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr <?= $r['deleted_at'] ? 'style="opacity:0.55"' : '' ?>>
                <td><?= $r['id'] ?></td>
                <td class="text-muted"><?= date('d M Y H:i', strtotime($r['created_at'])) ?></td>
                <td><?= h($r['location_name'] ?? '—') ?></td>
                <td><?= h($r['category_name'] ?? '—') ?></td>
                <td><span class="badge badge-grey">#<?= (int)$r['counter_value'] ?></span></td>
                <td><?= violationActionBadge($r['action_type']) ?> <span class="text-muted" style="font-size:11px"><?= h($r['action_label']) ?></span></td>
                <td><?= ((float)$r['penalty_amount'] > 0) ? '₹' . number_format((float)$r['penalty_amount'], 2) : '—' ?></td>
                <td class="text-muted"><?= h($r['recorded_by']) ?></td>
                <td><a href="?page=violation_view&id=<?= $r['id'] ?>" class="btn btn-ghost btn-sm">Open</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php
}

function pageViolationRecord(): void {
    if (!violationCanRecord()) {
        flash('error', 'Access denied. Retail Sales staff cannot record violations.');
        header('Location: index.php?page=violations'); exit;
    }
    $cats = violationGetCategories();
    $locs = getActiveLocations();
    $emps = getEmployeesLite();
    $selectedCatId = (int)($_GET['category_id'] ?? 0);
    $selectedCat = $selectedCatId ? violationGetCategory($selectedCatId) : null;
?>
<div class="page-header"><h2>Record Violation</h2></div>

<div class="form-card">
    <form method="POST" id="violation-form" enctype="multipart/form-data">
        <input type="hidden" name="action" value="create_violation">

        <div class="form-group" style="margin-bottom:14px">
            <label>Category <span class="required">*</span></label>
            <select name="category_id" class="form-control" required onchange="window.location.href='?page=violation_record&category_id='+this.value">
                <option value="">— Select category —</option>
                <?php foreach ($cats as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $selectedCatId === (int)$c['id'] ? 'selected' : '' ?>>
                    <?= h($c['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" style="margin-bottom:14px">
            <label>Location <span class="required">*</span></label>
            <div style="position:relative" id="loc-combo">
                <input type="text" id="loc-search" class="form-control"
                       placeholder="Type location name to search…" autocomplete="off">
                <input type="hidden" name="location_id" id="loc-id" value="">
                <div id="loc-dropdown"
                     style="position:absolute;top:100%;left:0;right:0;background:var(--surface);
                            border:1px solid var(--border);border-radius:6px;max-height:240px;
                            overflow-y:auto;display:none;z-index:50;margin-top:2px;
                            box-shadow:0 4px 12px rgba(0,0,0,.25)"></div>
            </div>
        </div>

        <?php if ($selectedCat && $selectedCat['needs_shortage_amount']): ?>
        <div class="form-group" style="margin-bottom:14px">
            <label>Cash shortage amount (₹) <span class="required">*</span></label>
            <input type="number" name="shortage_amount" class="form-control" step="0.01" min="0" required>
            <span class="hint">Penalty is bracketed: &lt;500=₹50, 500-1000=₹100, 1000-1500=₹150, 1500+=₹200</span>
        </div>
        <?php else: ?>
        <input type="hidden" name="shortage_amount" value="">
        <?php endif; ?>

        <?php if ($selectedCat && $selectedCat['needs_custom_amount']): ?>
        <div class="form-group" style="margin-bottom:14px">
            <label><?= h($selectedCat['custom_amount_label'] ?: 'Amount (₹)') ?> <span class="required">*</span></label>
            <input type="number" name="custom_amount" class="form-control" step="0.01" min="0" required>
        </div>
        <?php else: ?>
        <input type="hidden" name="custom_amount" value="">
        <?php endif; ?>

        <div class="form-group" style="margin-bottom:14px">
            <label style="display:flex;align-items:center;gap:8px">
                Employee (optional)
                <a href="#" id="view-consent-history" target="_blank" style="font-size:11px;color:var(--accent);text-decoration:none;display:none;align-items:center;gap:3px">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13"  x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/></svg>
                    View consent history
                </a>
            </label>
            <div style="position:relative" id="emp-combo">
                <input type="text" id="emp-search" class="form-control"
                       placeholder="Type name or EMP code to search…" autocomplete="off">
                <input type="hidden" name="employee_code" id="emp-code" value="">
                <div id="emp-dropdown"
                     style="position:absolute;top:100%;left:0;right:0;background:var(--surface);
                            border:1px solid var(--border);border-radius:6px;max-height:240px;
                            overflow-y:auto;display:none;z-index:50;margin-top:2px;
                            box-shadow:0 4px 12px rgba(0,0,0,.25)"></div>
            </div>
            <script>
            // Show "View consent history" link only after an employee is picked.
            (function () {
                const empCode = document.getElementById('emp-code');
                const link    = document.getElementById('view-consent-history');
                if (!empCode || !link) return;
                const sync = () => {
                    if (empCode.value) {
                        link.href = '?page=policy_consent_history&emp=' + encodeURIComponent(empCode.value);
                        link.style.display = 'inline-flex';
                    } else {
                        link.removeAttribute('href');
                        link.style.display = 'none';
                    }
                };
                // The existing combo writes to #emp-code via input events on the search box;
                // poll lightly so we don't have to refactor the picker.
                setInterval(sync, 400);
            })();
            </script>
        </div>
        <style>
            .combo-opt:hover { background: var(--bg); }
        </style>
        <script>
        (function () {
            const EMPS = <?= json_encode(array_map(static fn($e) => [
                'value' => $e['employee_code'],
                'label' => $e['full_name'] . ' (' . $e['employee_code'] . ')',
                'haystack' => mb_strtolower($e['full_name'] . ' ' . $e['employee_code']),
            ], $emps), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const LOCS = <?= json_encode(array_map(static fn($l) => [
                'value' => (string)$l['location_id'],
                'label' => $l['location_name'],
                'haystack' => mb_strtolower($l['location_name']),
            ], $locs), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

            const esc = s => String(s).replace(/[<>&"']/g,
                c => ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;',"'":'&#39;'}[c]));

            function initCombobox(searchId, hiddenId, listId, data) {
                const search = document.getElementById(searchId);
                const hidden = document.getElementById(hiddenId);
                const list   = document.getElementById(listId);
                if (!search || !hidden || !list) return;

                function render() {
                    const q = search.value.toLowerCase().trim();
                    const matches = data.filter(d => !q || d.haystack.includes(q)).slice(0, 50);

                    if (matches.length === 0) {
                        list.innerHTML = '<div style="padding:8px 12px;color:var(--muted);font-size:13px">No matches</div>';
                    } else {
                        list.innerHTML = matches.map(d =>
                            '<div class="combo-opt" data-value="' + esc(d.value) + '" data-label="' + esc(d.label) + '"'
                            + ' style="padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid var(--border)">'
                            + esc(d.label) + '</div>'
                        ).join('');
                    }
                    list.style.display = 'block';
                }

                search.addEventListener('focus', render);
                search.addEventListener('input', () => { hidden.value = ''; render(); });
                search.addEventListener('blur',  () => setTimeout(() => list.style.display = 'none', 150));

                list.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    const opt = e.target.closest('.combo-opt');
                    if (!opt) return;
                    search.value = opt.dataset.label;
                    hidden.value = opt.dataset.value;
                    list.style.display = 'none';
                });
            }

            initCombobox('emp-search', 'emp-code', 'emp-dropdown', EMPS);
            initCombobox('loc-search', 'loc-id',   'loc-dropdown', LOCS);

            // Location is required — block submit if not chosen.
            const form = document.getElementById('violation-form');
            if (form) {
                form.addEventListener('submit', (e) => {
                    const locId = document.getElementById('loc-id');
                    if (!locId.value) {
                        e.preventDefault();
                        alert('Please select a location from the list.');
                        document.getElementById('loc-search').focus();
                    }
                });
            }
        })();
        </script>

        <div class="form-group" style="margin-bottom:14px">
            <label>Notes</label>
            <textarea name="notes" class="form-control" rows="3" maxlength="2000" placeholder="Context, evidence references, etc."></textarea>
        </div>

        <div class="form-group" style="margin-bottom:14px">
            <label>Attachment (image, optional)</label>
            <input type="file" name="attachment" class="form-control" accept="image/jpeg,image/png,image/gif">
            <span class="hint">JPG, PNG, or GIF — max 5 MB.</span>
        </div>

        <?php if ($selectedCat): ?>
        <div style="margin-top:14px;padding:12px 14px;background:var(--bg);border-left:3px solid var(--accent);border-radius:4px;font-size:13px;line-height:1.6;color:var(--text)">
            <div style="font-weight:600;margin-bottom:6px;color:var(--text)"><?= h($selectedCat['name']) ?> — Corrective Action Steps</div>
            <div style="white-space:pre-wrap;color:var(--text)"><?= h($selectedCat['corrective_action_text'] ?? '—') ?></div>
        </div>
        <?php endif; ?>

        <div class="form-actions" style="margin-top:14px">
            <button type="submit" class="btn btn-primary"<?= $selectedCat ? '' : ' disabled' ?>>Record Violation</button>
            <a href="?page=violations" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
<?php
}

function pageViolationView(): void {
    $id = (int)($_GET['id'] ?? 0);
    $v = violationGet($id);
    if (!$v) { echo '<div class="rpt-prompt">Violation not found.</div>'; return; }
    if (!violationCanViewRow($v)) { echo '<div class="rpt-prompt">Access denied.</div>'; return; }

    // Remarks
    $st = getDb()->prepare(
        'SELECT r.*, e.full_name FROM violation_remarks r
         LEFT JOIN employees e ON r.remark_by = e.employee_code
         WHERE r.violation_id=? ORDER BY r.created_at ASC'
    );
    $st->execute([$id]);
    $remarks = $st->fetchAll(PDO::FETCH_ASSOC);

    // Was the counter reset between this violation's created_at and now?
    $stR = getDb()->prepare(
        'SELECT reset_at, reset_by, counter_value_before_reset, reason
         FROM violation_counter_resets
         WHERE location_id=? AND category_id=? AND reset_at > ?
         ORDER BY reset_at ASC'
    );
    $stR->execute([$v['location_id'], $v['category_id'], $v['created_at']]);
    $subsequentResets = $stR->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="page-header" style="display:flex;justify-content:space-between;align-items:center">
    <h2>Violation #<?= $v['id'] ?></h2>
    <a href="?page=violations" class="btn btn-ghost btn-sm">← Back to list</a>
</div>

<?php if ($v['deleted_at']): ?>
<div class="alert alert-error" style="margin-bottom:12px">
    Deleted on <?= date('d M Y H:i', strtotime($v['deleted_at'])) ?> by <?= h($v['deleted_by']) ?>
    <?php if ($v['delete_reason']): ?> — <?= h($v['delete_reason']) ?><?php endif; ?>
</div>
<?php endif; ?>

<div class="form-card" style="max-width:none;margin-bottom:14px">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">
        <div><span class="text-muted">Category:</span><br><strong><?= h($v['category_name']) ?></strong></div>
        <div><span class="text-muted">Location:</span><br><strong><?= h($v['location_name'] ?? '—') ?></strong></div>
        <div><span class="text-muted">Counter (this incident):</span><br><strong>#<?= (int)$v['counter_value'] ?></strong></div>
        <div><span class="text-muted">Action:</span><br><?= violationActionBadge($v['action_type']) ?> <?= h($v['action_label']) ?></div>
        <div><span class="text-muted">Penalty:</span><br><strong><?= ((float)$v['penalty_amount'] > 0) ? '₹' . number_format((float)$v['penalty_amount'], 2) : '—' ?></strong></div>
        <?php if ($v['shortage_amount'] !== null): ?>
        <div><span class="text-muted">Cash shortage:</span><br>₹<?= number_format((float)$v['shortage_amount'], 2) ?></div>
        <?php endif; ?>
        <?php if ($v['custom_amount'] !== null): ?>
        <div><span class="text-muted">Custom amount:</span><br>₹<?= number_format((float)$v['custom_amount'], 2) ?></div>
        <?php endif; ?>
        <?php if ($v['employee_code']): ?>
        <div><span class="text-muted">Employee:</span><br><?= h($v['employee_name'] ?: $v['employee_code']) ?></div>
        <?php endif; ?>
        <div><span class="text-muted">Recorded by:</span><br><?= h($v['recorded_by']) ?> · <?= date('d M Y H:i', strtotime($v['created_at'])) ?></div>
    </div>
    <?php if ($v['notes']): ?>
    <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--border)">
        <span class="text-muted">Notes:</span><br>
        <div style="white-space:pre-wrap"><?= h($v['notes']) ?></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($v['attachment_stored'])): ?>
    <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--border)">
        <span class="text-muted">Attachment:</span><br>
        <a href="?page=download_violation_attachment&id=<?= (int)$v['id'] ?>" target="_blank" style="display:inline-block;margin-top:6px">
            <img src="?page=download_violation_attachment&id=<?= (int)$v['id'] ?>" alt="<?= h($v['attachment_name']) ?>"
                 style="max-width:280px;max-height:280px;border:1px solid var(--border);border-radius:4px;display:block">
            <span class="text-muted" style="font-size:12px"><?= h($v['attachment_name']) ?></span>
        </a>
    </div>
    <?php endif; ?>
</div>

<?php if ($subsequentResets): ?>
<div class="form-card" style="max-width:none;margin-bottom:14px;background:#fff3cd;border-color:#ffeaa7">
    <div style="font-size:13px;font-weight:600;margin-bottom:6px">Counter resets after this violation</div>
    <?php foreach ($subsequentResets as $r): ?>
    <div style="font-size:12px;color:#856404;margin-bottom:4px">
        • <?= date('d M Y H:i', strtotime($r['reset_at'])) ?> — reset by <?= h($r['reset_by']) ?>
        (was at #<?= (int)$r['counter_value_before_reset'] ?>)
        <?php if ($r['reason']): ?> · <?= h($r['reason']) ?><?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<h3 style="font-size:14px;margin:16px 0 8px">Remarks</h3>
<?php if ($remarks): foreach ($remarks as $r): ?>
<div style="margin-bottom:10px;padding:10px 12px;background:#f8f9fa;border-radius:4px;border-left:3px solid <?= $r['remark_role'] === 'admin' ? '#dc3545' : '#3b82f6' ?>">
    <div style="font-size:12px;color:#6c757d;margin-bottom:4px">
        <strong><?= h($r['full_name'] ?: $r['remark_by']) ?></strong>
        <span class="badge <?= $r['remark_role'] === 'admin' ? 'badge-red' : 'badge-grey' ?>" style="margin-left:6px"><?= h(ucfirst($r['remark_role'])) ?></span>
        · <?= date('d M Y H:i', strtotime($r['created_at'])) ?>
    </div>
    <div style="white-space:pre-wrap"><?= h($r['remark']) ?></div>
</div>
<?php endforeach; else: ?>
<div class="text-muted" style="font-size:13px;margin-bottom:10px">No remarks yet.</div>
<?php endif; ?>

<?php if (violationCanAddRemark($v) && !$v['deleted_at']): ?>
<form method="POST" class="form-card" style="max-width:none">
    <input type="hidden" name="action" value="add_violation_remark">
    <input type="hidden" name="violation_id" value="<?= $v['id'] ?>">
    <div class="form-group">
        <label>Add a remark</label>
        <textarea name="remark" class="form-control" rows="2" required maxlength="2000" placeholder="Your response or context..."></textarea>
    </div>
    <div class="form-actions"><button class="btn btn-primary btn-sm">Add Remark</button></div>
</form>
<?php endif; ?>

<?php if (violationCanDelete() && !$v['deleted_at']): ?>
<form method="POST" class="form-card" style="max-width:none;margin-top:14px;border-color:#f5c6cb"
      onsubmit="return confirm('Delete this violation? Counter will recompute.')">
    <input type="hidden" name="action" value="delete_violation">
    <input type="hidden" name="violation_id" value="<?= $v['id'] ?>">
    <div class="form-group">
        <label style="color:#dc3545">Super admin: delete violation</label>
        <input type="text" name="delete_reason" class="form-control" maxlength="500" placeholder="Reason (optional)">
    </div>
    <div class="form-actions"><button class="btn btn-danger btn-sm">Delete Violation</button></div>
</form>
<?php endif; ?>
<?php
}

function pageViolationCategories(): void {
    if (!violationCanAdmin()) { echo '<div class="rpt-prompt">Access denied.</div>'; return; }
    $cats = violationGetCategories(false);
    $editId = (int)($_GET['edit'] ?? 0);
    $editCat = $editId ? violationGetCategory($editId) : null;
    $editTiers = $editCat ? violationGetTiers((int)$editCat['id']) : [];
?>
<div class="page-header"><h2>Violation Categories</h2></div>

<?php if ($editCat): ?>
<div class="form-card" style="max-width:none;margin-bottom:18px">
    <form method="POST">
        <input type="hidden" name="action" value="save_violation_category">
        <input type="hidden" name="id" value="<?= $editCat['id'] ?>">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
            <div class="text-muted" style="font-size:12px">Type: <?= h($editCat['penalty_type']) ?> · Slug: <?= h($editCat['slug']) ?></div>
            <a href="?page=violation_categories" class="btn btn-ghost btn-sm">Close</a>
        </div>
        <div class="form-group" style="margin-bottom:14px">
            <label>Name / Question <span class="required">*</span></label>
            <input type="text" name="name" class="form-control" required maxlength="120"
                   value="<?= h($editCat['name']) ?>"
                   placeholder="e.g. Is background music not started or volume too low?">
        </div>
        <div class="form-group">
            <label>Corrective Action Text</label>
            <textarea name="corrective_action_text" class="form-control" rows="8"><?= h($editCat['corrective_action_text'] ?? '') ?></textarea>
        </div>
        <?php if ($editTiers): ?>
        <div style="margin-top:12px">
            <div style="font-weight:600;margin-bottom:6px;font-size:13px">Tier amounts</div>
            <table class="table" style="max-width:480px">
                <thead><tr><th>Tier</th><th>Action</th><th>Amount (₹)</th></tr></thead>
                <tbody>
                <?php foreach ($editTiers as $t): ?>
                <tr>
                    <td>#<?= (int)$t['tier_number'] ?></td>
                    <td><?= h($t['action_label']) ?></td>
                    <td>
                        <?php if (in_array($t['action_type'], ['penalty'], true)): ?>
                        <input type="number" name="tier_amount[<?= $t['id'] ?>]" class="form-control"
                               step="0.01" min="0" value="<?= h((string)$t['amount']) ?>" style="width:120px">
                        <?php else: ?>
                        <span class="text-muted">— (non-monetary)</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <div class="form-actions" style="margin-top:14px">
            <button class="btn btn-primary">Save</button>
            <a href="?page=violation_categories" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="table-wrap" data-stack>
    <table class="table">
        <thead>
            <tr>
                <th>#</th>
                <th>Name</th>
                <th>Type</th>
                <th>Slug</th>
                <th>Active</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($cats as $c): ?>
            <tr>
                <td><?= $c['id'] ?></td>
                <td><strong><?= h($c['name']) ?></strong></td>
                <td><span class="badge badge-grey"><?= h($c['penalty_type']) ?></span></td>
                <td class="text-muted"><?= h($c['slug']) ?></td>
                <td><?= $c['is_active'] ? '<span class="badge badge-green">Yes</span>' : '<span class="badge badge-red">No</span>' ?></td>
                <td><a href="?page=violation_categories&edit=<?= $c['id'] ?>" class="btn btn-secondary btn-sm">Edit</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php
}

function pageViolationCounterReset(): void {
    if (!violationCanReset()) { echo '<div class="rpt-prompt">Access denied.</div>'; return; }
    $cats = violationGetCategories();
    $locs = getActiveLocations();

    // Read history with optional filters
    $filterLoc = (int)($_GET['location_id'] ?? 0);
    $filterCat = (int)($_GET['category_id'] ?? 0);

    $sql = 'SELECT r.*, l.location_name, c.name AS category_name,
                   e.full_name AS reset_by_name
            FROM violation_counter_resets r
            LEFT JOIN locations l ON r.location_id = l.location_id
            LEFT JOIN violation_categories c ON r.category_id = c.id
            LEFT JOIN employees e ON r.reset_by = e.employee_code
            WHERE 1=1';
    $p = [];
    if ($filterLoc) { $sql .= ' AND r.location_id=?'; $p[] = $filterLoc; }
    if ($filterCat) { $sql .= ' AND r.category_id=?'; $p[] = $filterCat; }
    $sql .= ' ORDER BY r.reset_at DESC LIMIT 200';
    $st = getDb()->prepare($sql);
    $st->execute($p);
    $log = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="page-header"><h2>Reset Violation Counter</h2></div>

<div class="form-card" style="max-width:none;margin-bottom:18px">
    <form method="POST">
        <input type="hidden" name="action" value="reset_violation_counter">
        <div class="form-grid">
            <div class="form-group">
                <label>Location <span class="required">*</span></label>
                <select name="location_id" class="form-control" required>
                    <option value="">— Select —</option>
                    <?php foreach ($locs as $l): ?>
                    <option value="<?= $l['location_id'] ?>"><?= h($l['location_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Category <span class="required">*</span></label>
                <select name="category_id" class="form-control" required>
                    <option value="">— Select —</option>
                    <?php foreach ($cats as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <label>Reason</label>
                <input type="text" name="reason" class="form-control" maxlength="500" placeholder="e.g. Quarterly compliance review, employee transferred">
            </div>
        </div>
        <div class="form-actions" style="margin-top:10px">
            <button class="btn btn-primary" onclick="return confirm('Reset counter? Future violations will start from #1.')">Reset Counter</button>
        </div>
    </form>
</div>

<h3 style="font-size:14px;margin:18px 0 8px">Reset history</h3>
<form method="GET" class="filter-bar" style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap">
    <input type="hidden" name="page" value="violation_counter_reset">
    <select name="location_id" class="form-control" style="width:200px" onchange="this.form.submit()">
        <option value="0">— All locations —</option>
        <?php foreach ($locs as $l): ?>
        <option value="<?= $l['location_id'] ?>" <?= $filterLoc === (int)$l['location_id'] ? 'selected' : '' ?>>
            <?= h($l['location_name']) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <select name="category_id" class="form-control" style="width:200px" onchange="this.form.submit()">
        <option value="0">— All categories —</option>
        <?php foreach ($cats as $c): ?>
        <option value="<?= $c['id'] ?>" <?= $filterCat === (int)$c['id'] ? 'selected' : '' ?>>
            <?= h($c['name']) ?>
        </option>
        <?php endforeach; ?>
    </select>
</form>

<?php if (empty($log)): ?>
<div class="rpt-prompt">No counter resets recorded yet.</div>
<?php else: ?>
<div class="table-wrap" data-stack>
    <table class="table">
        <thead>
            <tr>
                <th>When</th>
                <th>Location</th>
                <th>Category</th>
                <th>Counter at reset</th>
                <th>Reset by</th>
                <th>Reason</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($log as $r): ?>
            <tr>
                <td class="text-muted"><?= date('d M Y H:i', strtotime($r['reset_at'])) ?></td>
                <td><?= h($r['location_name'] ?? '—') ?></td>
                <td><?= h($r['category_name'] ?? '—') ?></td>
                <td><span class="badge badge-grey">#<?= (int)$r['counter_value_before_reset'] ?></span></td>
                <td><?= h($r['reset_by_name'] ?: $r['reset_by']) ?></td>
                <td class="text-muted"><?= h($r['reason'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php
}

// ── Download violation attachment ───────────────────────
function downloadViolationAttachment(): void {
    $id = (int)($_GET['id'] ?? 0);
    $v = violationGet($id);
    if (!$v || empty($v['attachment_stored'])) return;
    if (!violationCanViewRow($v)) return;

    $path = VIOL_UPLOAD_DIR . $v['attachment_stored'];
    if (!file_exists($path)) return;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($path);
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . $v['attachment_name'] . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}
