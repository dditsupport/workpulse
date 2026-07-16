<?php
// =========================================================
// Employee CRUD + page renderers
// =========================================================

function doCreateEmployee(): void {
    $code = mb_strtoupper(trim($_POST['employee_code'] ?? ''));
    $name = trim($_POST['full_name'] ?? '');
    if (!$code || !$name) { flash('error', 'Code and name required.'); header('Location: index.php?page=create'); exit; }
    try {
        $db = getDb();
        $chk = $db->prepare('SELECT id FROM employees WHERE employee_code = ?');
        $chk->execute([$code]);
        if ($chk->fetch()) { flash('error', "Code {$code} already exists."); header('Location: index.php?page=create'); exit; }
        $channel = $_POST['otp_channel'] ?? 'none';
        if (!in_array($channel, ['none', 'email', 'sms'], true)) $channel = 'none';
        $staffType = $_POST['staff_type'] ?? 'retail';
        if (!in_array($staffType, ['retail', 'ho', 'factory'], true)) $staffType = 'retail';
        $db->prepare('INSERT INTO employees
            (employee_code,full_name,department_id,staff_type,role_id,phone,email,join_date,portal_password,
             otp_channel,match_threshold,location_id,enrollment_status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,\'pending_enrollment\')')
            ->execute([$code, $name,
                trim($_POST['department_id']    ?? '') !== '' ? (int)$_POST['department_id'] : null,
                $staffType,
                trim($_POST['role_id']          ?? '') !== '' ? (int)$_POST['role_id']       : null,
                trim($_POST['phone']            ?? '') ?: null,
                trim($_POST['email']            ?? '') ?: null,
                trim($_POST['join_date']        ?? '') ?: null,
                trim($_POST['portal_password']  ?? '') ?: '123456',
                $channel,
                trim($_POST['match_threshold'] ?? '') !== '' ? (int)$_POST['match_threshold'] : 50,
                trim($_POST['location_id']      ?? '') !== '' ? (int)$_POST['location_id'] : null,
            ]);
        flash('success', "Employee {$code} — {$name} created.");
        header('Location: index.php?page=employees');
    } catch (Exception $e) { flash('error', $e->getMessage()); header('Location: index.php?page=create'); }
    exit;
}

function doUpdateEmployee(): void {
    $id = (int)($_POST['id'] ?? 0);

    // If not superadmin, preserve existing match_threshold
    if (!isSuperadmin()) {
        $existing = getDb()->prepare('SELECT match_threshold FROM employees WHERE id = ?');
        $existing->execute([$id]);
        $cur = $existing->fetch(PDO::FETCH_ASSOC);
        $threshold = $cur['match_threshold'];
    } else {
        $threshold = trim($_POST['match_threshold'] ?? '') !== '' ? (int)$_POST['match_threshold'] : null;
    }

    try {
        $channel = $_POST['otp_channel'] ?? 'none';
        if (!in_array($channel, ['none', 'email', 'sms'], true)) $channel = 'none';
        $staffType = $_POST['staff_type'] ?? 'retail';
        if (!in_array($staffType, ['retail', 'ho', 'factory'], true)) $staffType = 'retail';

        // Password is optional on edit — preserve existing when blank or absent
        // (non-superadmin forms don't render the password field at all)
        $newPassword = trim($_POST['portal_password'] ?? '');

        $sql = 'UPDATE employees SET full_name=?,department_id=?,staff_type=?,role_id=?,phone=?,email=?,
            join_date=?,otp_channel=?,match_threshold=?,location_id=?';
        $params = [
            trim($_POST['full_name']       ?? ''),
            trim($_POST['department_id']   ?? '') !== '' ? (int)$_POST['department_id'] : null,
            $staffType,
            trim($_POST['role_id']         ?? '') !== '' ? (int)$_POST['role_id']       : null,
            trim($_POST['phone']           ?? '') ?: null,
            trim($_POST['email']           ?? '') ?: null,
            trim($_POST['join_date']       ?? '') ?: null,
            $channel,
            $threshold,
            trim($_POST['location_id']     ?? '') !== '' ? (int)$_POST['location_id'] : null,
        ];
        if ($newPassword !== '') {
            $sql .= ',portal_password=?';
            $params[] = $newPassword;
        }
        if (isSuperadmin()) {
            $sql .= ',otp_device_bypass=?';
            $params[] = isset($_POST['otp_device_bypass']) ? 1 : 0;
        }
        $sql .= ',updated_at=NOW() WHERE id=?';
        $params[] = $id;
        getDb()->prepare($sql)->execute($params);
        flash('success', 'Employee updated.'); header('Location: index.php?page=employees');
    } catch (Exception $e) { flash('error', $e->getMessage()); header("Location: index.php?page=edit&id={$id}"); }
    exit;
}

function doToggleActive(): void {
    $id = (int)($_POST['id'] ?? 0); $cur = (int)($_POST['is_active'] ?? 0); $new = $cur ? 0 : 1;
    getDb()->prepare('UPDATE employees SET is_active=?,deactivated_at=?,deactivation_reason=?,updated_at=NOW() WHERE id=?')
        ->execute([$new, $new ? null : date('Y-m-d H:i:s'), $new ? null : 'Deactivated via portal', $id]);
    flash('success', 'Status updated.'); header('Location: index.php?page=employees'); exit;
}

// ── Page: Employee List ──────────────────────────────────
function pageEmployees(): void {
    $search    = trim($_GET['search']   ?? '');
    $location  = trim($_GET['location'] ?? '');
    $doLoad    = isset($_GET['filter']);
    $stats     = getStats();
    $depts     = getDepartments();
    $locs      = getActiveLocations();

    // Multi-select filters. On first load (no `?filter=1`) we apply sensible
    // defaults — all statuses + all departments + Active only — and run the
    // query straight away so the user lands on a populated list of active
    // employees instead of an empty page asking for a Filter click.
    $ALL_STATUSES = ['pending_enrollment', 'partial', 'active'];
    $ALL_DEPT_IDS = array_map(fn($d) => (string)$d['id'], $depts);

    if ($doLoad) {
        $statuses    = array_values(array_intersect(array_map('strval', (array)($_GET['status'] ?? [])), $ALL_STATUSES));
        $deptIds     = array_values(array_intersect(array_map('strval', (array)($_GET['dept']   ?? [])), $ALL_DEPT_IDS));
        $activeFlags = array_values(array_intersect(array_map('strval', (array)($_GET['active'] ?? [])), ['0', '1']));
    } else {
        $statuses    = $ALL_STATUSES;
        $deptIds     = $ALL_DEPT_IDS;
        $activeFlags = ['1'];
    }

    $employees = getEmployees($search, $statuses, $deptIds, $location, $activeFlags);

    $exportQs = http_build_query([
        'page'     => 'export_employees_csv',
        'filter'   => 1,
        'search'   => $search,
        'status'   => $statuses,
        'dept'     => $deptIds,
        'location' => $location,
        'active'   => $activeFlags,
    ]);
?>
<div class="page-header">
    <h2>Employees</h2>
    <?php if (canManageEmployees()): ?>
    <a href="?page=create" class="btn btn-primary">+ Add Employee</a>
    <?php endif; ?>
</div>
<div class="stats-grid-sm" style="margin-bottom:18px">
    <?php foreach ([
        ['Total',     $stats['total'],     ''],
        ['Enrolled',  $stats['enrolled'],  'stat-green'],
        ['Partial',   $stats['partial'],   'stat-yellow'],
        ['Pending',   $stats['pending'],   'stat-blue'],
        ['Inactive',  $stats['inactive'],  'stat-red'],
        ['OTP',       $stats['otp'],       'stat-purple'],
        ['Locations', $stats['locations'], ''],
        ['Devices',   $stats['devices'],   ''],
    ] as [$l, $v, $c]): ?>
    <div class="stat-card <?= $c ?>"><div class="stat-val"><?= $v ?></div><div class="stat-lbl"><?= $l ?></div></div>
    <?php endforeach; ?>
</div>
<form method="GET" class="rpt-filter">
    <input type="hidden" name="page"   value="employees">
    <input type="hidden" name="filter" value="1">
    <span class="input-clear-wrap" style="flex:1 1 auto;min-width:200px">
        <input type="text" name="search" value="<?= h($search) ?>" placeholder="Search code, name..." class="form-control">
        <button type="button" class="input-clear-btn" aria-label="Clear search" tabindex="-1">&times;</button>
    </span>
    <?php
        $statusLabels = ['pending_enrollment' => 'Pending', 'partial' => 'Partial', 'active' => 'Active'];
        $activeLabels = ['1' => 'Active', '0' => 'Inactive'];
    ?>
    <!-- Department (multi-select) -->
    <div class="ms-filter" data-label="Department" style="width:200px">
        <button type="button" class="form-control ms-toggle" aria-haspopup="listbox" aria-expanded="false">Department</button>
        <div class="ms-panel" role="listbox">
            <label class="ms-row ms-all-row"><input type="checkbox" class="ms-all"> <span>Select all</span></label>
            <div class="ms-divider"></div>
            <?php foreach ($depts as $d): ?>
            <label class="ms-row">
                <input type="checkbox" name="dept[]" value="<?= (int)$d['id'] ?>"
                    <?= in_array((string)$d['id'], $deptIds, true) ? 'checked' : '' ?>>
                <span><?= h($d['department_name']) ?></span>
            </label>
            <?php endforeach; ?>
        </div>
    </div>
    <!-- Status (multi-select) -->
    <div class="ms-filter" data-label="Status" style="width:170px">
        <button type="button" class="form-control ms-toggle" aria-haspopup="listbox" aria-expanded="false">Status</button>
        <div class="ms-panel" role="listbox">
            <label class="ms-row ms-all-row"><input type="checkbox" class="ms-all"> <span>Select all</span></label>
            <div class="ms-divider"></div>
            <?php foreach ($statusLabels as $v => $l): ?>
            <label class="ms-row">
                <input type="checkbox" name="status[]" value="<?= h($v) ?>"
                    <?= in_array($v, $statuses, true) ? 'checked' : '' ?>>
                <span><?= h($l) ?></span>
            </label>
            <?php endforeach; ?>
        </div>
    </div>
    <!-- Active / Inactive (multi-select) -->
    <div class="ms-filter" data-label="Active" style="width:160px">
        <button type="button" class="form-control ms-toggle" aria-haspopup="listbox" aria-expanded="false">Active</button>
        <div class="ms-panel" role="listbox">
            <label class="ms-row ms-all-row"><input type="checkbox" class="ms-all"> <span>Both</span></label>
            <div class="ms-divider"></div>
            <?php foreach ($activeLabels as $v => $l): ?>
            <label class="ms-row">
                <input type="checkbox" name="active[]" value="<?= h((string)$v) ?>"
                    <?= in_array((string)$v, $activeFlags, true) ? 'checked' : '' ?>>
                <span><?= h($l) ?></span>
            </label>
            <?php endforeach; ?>
        </div>
    </div>
    <select name="location" class="form-control" style="width:200px">
        <option value="">All Locations</option>
        <option value="none" <?= $location === 'none' ? 'selected' : '' ?>>— No self-claim —</option>
        <?php foreach ($locs as $l): ?>
        <option value="<?= (int)$l['location_id'] ?>" <?= $location === (string)$l['location_id'] ? 'selected' : '' ?>>
            <?= h($l['location_name']) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-primary">Filter</button>
    <a href="?page=employees" class="btn btn-ghost">Clear</a>
    <?php if (canManageEmployees()): ?>
    <a href="?<?= h($exportQs) ?>" class="btn btn-ghost btn-sm" target="_blank">Export CSV</a>
    <?php endif; ?>
</form>

<style>
.ms-filter{position:relative;display:inline-block;vertical-align:top}
.ms-toggle{display:flex;align-items:center;justify-content:space-between;gap:6px;width:100%;cursor:pointer;text-align:left;padding-right:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ms-toggle::after{content:'\25BE';opacity:.55;font-size:11px;flex:0 0 auto;margin-left:6px}
.ms-filter.open .ms-toggle::after{transform:rotate(180deg)}
.ms-panel{display:none;position:absolute;top:calc(100% + 4px);left:0;min-width:100%;max-height:280px;overflow-y:auto;background:var(--surface,#1f1f23);color:var(--text,#eee);border:1px solid var(--border,#444);border-radius:6px;box-shadow:0 8px 24px rgba(0,0,0,.4);z-index:50;padding:6px 0;white-space:nowrap}
.ms-filter.open .ms-panel{display:block}
.ms-row{display:flex;align-items:center;gap:8px;padding:6px 12px;cursor:pointer;font-size:13px;font-weight:400;margin:0}
.ms-row:hover{background:rgba(255,255,255,.06)}
.ms-row input{margin:0;cursor:pointer}
.ms-row span{flex:1;min-width:0}
.ms-all-row{font-weight:600}
.ms-divider{height:1px;background:var(--border,#444);margin:4px 0}
</style>
<script>
(function(){
    document.querySelectorAll('.ms-filter').forEach(initMs);
    // Click outside closes all open dropdowns.
    document.addEventListener('click', function(e){
        document.querySelectorAll('.ms-filter.open').forEach(function(f){
            if (!f.contains(e.target)) {
                f.classList.remove('open');
                var t = f.querySelector('.ms-toggle');
                if (t) t.setAttribute('aria-expanded', 'false');
            }
        });
    });
    function initMs(f) {
        var label  = f.dataset.label || 'Filter';
        var toggle = f.querySelector('.ms-toggle');
        var allBox = f.querySelector('.ms-all');
        var boxes  = Array.prototype.slice.call(f.querySelectorAll('input[type="checkbox"]:not(.ms-all)'));
        if (!toggle || !boxes.length) return;
        function refreshLabel() {
            var checked = boxes.filter(function(b){ return b.checked; });
            var n = checked.length, total = boxes.length;
            var text;
            if      (n === 0)     text = label + ': None';
            else if (n === total) text = label + ': All';
            else if (n === 1)     text = label + ': ' + (checked[0].parentNode.querySelector('span') || {}).textContent;
            else                  text = label + ': ' + n + ' selected';
            toggle.textContent = text;
        }
        function refreshAll() {
            if (!allBox) return;
            allBox.checked = boxes.every(function(b){ return b.checked; });
            allBox.indeterminate = !allBox.checked && boxes.some(function(b){ return b.checked; });
        }
        toggle.addEventListener('click', function(e){
            e.stopPropagation();
            var willOpen = !f.classList.contains('open');
            // Close any other open dropdown.
            document.querySelectorAll('.ms-filter.open').forEach(function(o){
                if (o !== f) {
                    o.classList.remove('open');
                    var ot = o.querySelector('.ms-toggle');
                    if (ot) ot.setAttribute('aria-expanded', 'false');
                }
            });
            f.classList.toggle('open', willOpen);
            toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });
        if (allBox) {
            allBox.addEventListener('change', function(){
                boxes.forEach(function(b){ b.checked = allBox.checked; });
                refreshLabel();
            });
        }
        boxes.forEach(function(b){
            b.addEventListener('change', function(){
                refreshAll();
                refreshLabel();
            });
        });
        refreshAll();
        refreshLabel();
    }
})();
</script>

<div class="table-wrap" data-stack>
<table class="table">
    <thead>
        <tr>
            <th>Code</th><th>Name</th><th>Department</th><th>Phone</th>
            <th>Location</th><th>Threshold</th><th>Status</th>
            <?php if (canManageEmployees()): ?><th>Actions</th><?php endif; ?>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($employees)): ?>
        <tr><td colspan="8" class="empty-row">No employees found.</td></tr>
    <?php else: foreach ($employees as $e): ?>
        <tr class="<?= !$e['is_active'] ? 'row-inactive' : '' ?>">
            <td><code><?= h($e['employee_code']) ?></code></td>
            <td><?= h($e['full_name']) ?></td>
            <td><?= h($e['department_name'] ?? '—') ?></td>
            <td><?= h($e['phone']      ?? '—') ?></td>
            <td><?= h($e['location_name'] ?? '—') ?></td>
            <td><?= $e['match_threshold'] !== null ? $e['match_threshold'] : '<span class="text-muted">global</span>' ?></td>
            <td><?= statusBadge($e['enrollment_status'], (int)$e['is_active']) ?></td>
            <?php if (canManageEmployees()): ?>
            <td class="actions">
                <a href="?page=edit&id=<?= $e['id'] ?>" class="btn btn-sm btn-secondary">Edit</a>
                <form method="POST" class="inline-form"
                      onsubmit="return confirm('<?= $e['is_active'] ? 'Deactivate' : 'Activate' ?> this employee?')">
                    <input type="hidden" name="action"    value="toggle_active">
                    <input type="hidden" name="id"        value="<?= $e['id'] ?>">
                    <input type="hidden" name="is_active" value="<?= $e['is_active'] ?>">
                    <button class="btn btn-sm <?= $e['is_active'] ? 'btn-danger' : 'btn-success' ?>">
                        <?= $e['is_active'] ? 'Deactivate' : 'Activate' ?>
                    </button>
                </form>
            </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
<p class="table-count"><?= count($employees) ?> employee(s)</p>
<?php
}

// ── Page: Employee Create/Edit Form ──────────────────────
function pageEmpForm(?array $emp): void {
    if (!canManageEmployees()) { pageEmployees(); return; }
    $isEdit = $emp !== null;
    $depts  = getDepartments();
    $locs   = getActiveLocations();
    $roles  = getDb()->query('SELECT id, role_name FROM roles WHERE is_active=1 ORDER BY role_name')->fetchAll();
?>
<div class="page-header">
    <h2><?= $isEdit ? 'Edit Employee' : 'Add Employee' ?></h2>
    <a href="?page=employees" class="btn btn-ghost">← Back</a>
</div>
<div class="form-card">
<form method="POST">
    <input type="hidden" name="action" value="<?= $isEdit ? 'update_employee' : 'create_employee' ?>">
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $emp['id'] ?>"> <?php endif; ?>
    <div class="form-grid">
        <div class="form-group">
            <label>Employee Code <span class="required">*</span></label>
            <?php if ($isEdit): ?>
                <input class="form-control" value="<?= h($emp['employee_code']) ?>" readonly>
            <?php else: ?>
                <input type="text" name="employee_code" class="form-control" required
                       style="text-transform:uppercase" placeholder="e.g. EMP001">
            <?php endif; ?>
        </div>
        <div class="form-group">
            <label>Full Name <span class="required">*</span></label>
            <input type="text" name="full_name" class="form-control" required value="<?= h($emp['full_name'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>Department</label>
            <select name="department_id" class="form-control">
                <option value="">— Select —</option>
                <?php if (empty($depts)): ?>
                    <option value="" disabled>No departments configured</option>
                <?php else: foreach ($depts as $d): ?>
                    <option value="<?= $d['id'] ?>"
                        <?= (int)($emp['department_id'] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>>
                        <?= h($d['department_name']) ?>
                    </option>
                <?php endforeach; endif; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Staff Type <span class="required">*</span>
                <span class="hint">— used for policy audience routing</span>
            </label>
            <?php $st = $emp['staff_type'] ?? 'retail'; ?>
            <select name="staff_type" class="form-control" required>
                <option value="retail"  <?= $st === 'retail'  ? 'selected' : '' ?>>Retail</option>
                <option value="ho"      <?= $st === 'ho'      ? 'selected' : '' ?>>HO</option>
                <option value="factory" <?= $st === 'factory' ? 'selected' : '' ?>>Factory</option>
            </select>
        </div>
        <div class="form-group">
            <label>Role <span class="text-muted" style="font-weight:normal;font-size:11px">(page access)</span></label>
            <select name="role_id" class="form-control">
                <option value="">— None —</option>
                <?php if (empty($roles)): ?>
                    <option value="" disabled>No roles configured</option>
                <?php else: foreach ($roles as $r): ?>
                    <option value="<?= $r['id'] ?>"
                        <?= (int)($emp['role_id'] ?? 0) === (int)$r['id'] ? 'selected' : '' ?>>
                        <?= h($r['role_name']) ?>
                    </option>
                <?php endforeach; endif; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Phone</label>
            <input type="text" name="phone" class="form-control" value="<?= h($emp['phone'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" class="form-control" value="<?= h($emp['email'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>Join Date</label>
            <input type="date" name="join_date" class="form-control" value="<?= h($emp['join_date'] ?? ($isEdit ? '' : date('Y-m-d'))) ?>">
        </div>
        <?php if (isSuperadmin()): ?>
        <div class="form-group">
            <label>Portal Password <span class="hint">Employee uses this to view their own punches</span></label>
            <input type="text" name="portal_password" class="form-control"
                   placeholder="e.g. emp123" value="<?= h($emp['portal_password'] ?? '') ?>">
            <span class="hint">Default: 123456 (if left blank on create)</span>
        </div>
        <?php endif; ?>
        <div class="form-group">
            <label>Match Threshold <span class="hint">0-100, blank = global</span></label>
            <input type="number" name="match_threshold" min="0" max="100" class="form-control"
                    value="<?= h($emp['match_threshold'] ?? ($isEdit ? '' : '50')) ?>"
                    <?= !isSuperadmin() ? 'disabled style="opacity:0.5;cursor:not-allowed"' : '' ?>>
            <?php if (!isSuperadmin()): ?>
            <input type="hidden" name="match_threshold" value="<?= h($emp['match_threshold'] ?? ($isEdit ? '' : '50')) ?>">
            <?php endif; ?>
        </div>
        <div class="form-group">
            <label>Location</label>
            <select name="location_id" class="form-control">
                <option value="">— Select —</option>
                <?php foreach ($locs as $l): ?>
                <option value="<?= $l['location_id'] ?>" <?= (int)($emp['location_id'] ?? 0) === (int)$l['location_id'] ? 'selected' : '' ?>>
                    <?= h($l['location_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <div style="margin-top:6px;padding:8px 10px;background:rgba(201,168,0,.10);border:1px solid rgba(201,168,0,.30);border-radius:6px;font-size:11px;color:var(--yellow);line-height:1.4;display:flex;gap:8px;align-items:flex-start">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    <line x1="12" y1="9"  x2="12" y2="13"/>
                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
                <span><strong>Note:</strong> If you select a location for this employee, the employee will be able to view all issues for that location. Leave blank for HO and Factory staff who shouldn't be scoped to a single location.</span>
            </div>
        </div>
    </div>
    <div class="form-section-title">OTP Settings
        <span class="hint" style="text-transform:none;letter-spacing:0">
            — OTP is sent to the <strong>location's</strong> contact, not the employee's number
        </span>
    </div>
    <div class="form-grid">
        <div class="form-group">
            <label>OTP Channel</label>
            <?php $ch = $emp['otp_channel'] ?? 'none'; ?>
            <select name="otp_channel" class="form-control">
                <option value="none"  <?= $ch === 'none'  ? 'selected' : '' ?>>Disabled</option>
                <option value="email" <?= $ch === 'email' ? 'selected' : '' ?>>Email</option>
                <option value="sms"   <?= $ch === 'sms'   ? 'selected' : '' ?>>SMS</option>
            </select>
        </div>
    </div>
    <?php if (isSuperadmin() && $isEdit): ?>
    <div class="form-section-title">Device Bypass</div>
    <div class="form-grid">
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="otp_device_bypass"
                       <?= ($emp['otp_device_bypass'] ?? 0) ? 'checked' : '' ?>>
                OTP Device Bypass
            </label>
            <span class="hint">When enabled, this employee can punch via OTP without a registered biometric device.</span>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($isEdit): ?>
    <div class="form-section-title">Enrollment Status</div>
    <div class="enrollment-info">
        <div class="enroll-item"><span class="enroll-label">MFS500</span>
            <span class="badge <?= !empty($emp['template_mfs500_base64']) ? 'badge-green' : 'badge-grey' ?>">
                <?= !empty($emp['template_mfs500_base64']) ? '✓ Enrolled' : '✗ Not enrolled' ?>
            </span>
        </div>
        <div class="enroll-item"><span class="enroll-label">FM220</span>
            <span class="badge <?= !empty($emp['template_fm220_base64']) ? 'badge-green' : 'badge-grey' ?>">
                <?= !empty($emp['template_fm220_base64']) ? '✓ Enrolled' : '✗ Not enrolled' ?>
            </span>
        </div>
        <div class="enroll-item">
            <span class="enroll-label">Overall</span>
            <?= statusBadge($emp['enrollment_status'], (int)$emp['is_active']) ?>
        </div>
    </div>
    <?php endif; ?>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Create Employee' ?></button>
        <a href="?page=employees" class="btn btn-ghost">Cancel</a>
    </div>
</form>
</div>
<?php
}

// ── Export employees CSV ────────────────────────────────
function exportEmployeesCsv(): void {
    if (!canManageEmployees()) { flash('error', 'Access denied.'); header('Location: index.php'); exit; }

    $ALL_STATUSES = ['pending_enrollment', 'partial', 'active'];
    $statuses     = array_values(array_intersect(array_map('strval', (array)($_GET['status'] ?? [])), $ALL_STATUSES));
    $deptIds      = array_values(array_filter(array_map('intval', (array)($_GET['dept'] ?? []))));
    $activeFlags  = array_values(array_intersect(array_map('strval', (array)($_GET['active'] ?? [])), ['0', '1']));

    $employees = getEmployees(
        trim($_GET['search']   ?? ''),
        $statuses,
        $deptIds,
        trim($_GET['location'] ?? ''),
        $activeFlags
    );

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="employees_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['employee_code','full_name','department_name','phone','email','join_date',
                    'enrollment_status','MFS500_enrolled','FM220_enrolled',
                    'otp_channel','is_active','location_id'], escape: '');
    foreach ($employees as $e) {
        fputcsv($out, [
            $e['employee_code'],
            $e['full_name'],
            $e['department_name'] ?? '',
            $e['phone'] ?? '',
            $e['email'] ?? '',
            $e['join_date'] ?? '',
            $e['enrollment_status'],
            ($e['mfs500_enrolled'] ?? 0) ? 'Y' : 'N',
            ($e['fm220_enrolled'] ?? 0) ? 'Y' : 'N',
            $e['otp_channel'] ?? 'none',
            $e['is_active'] ? 'Y' : 'N',
            $e['location_id'] ?? '',
        ], escape: '');
    }
    fclose($out);
    exit;
}

// ── Page: My Profile (read-only, available to all logged-in users) ──
function pageProfile(): void {
    $code = myCode();
    if ($code === '') {
        flash('error', 'Profile only available for employee logins.');
        header('Location: index.php'); exit;
    }
    $db = getDb();
    $st = $db->prepare(
        'SELECT e.employee_code, e.full_name, e.phone, e.email, e.join_date,
                e.enrollment_status, e.otp_channel, e.is_active,
                e.template_mfs500_base64, e.template_fm220_base64,
                e.location_id, e.created_at,
                d.department_name,
                l.location_name
         FROM employees e
         LEFT JOIN departments d ON e.department_id = d.id
         LEFT JOIN locations   l ON e.location_id   = l.location_id
         WHERE e.employee_code = ?'
    );
    $st->execute([$code]);
    $emp = $st->fetch(PDO::FETCH_ASSOC);
    if (!$emp) {
        flash('error', 'Profile not found.');
        header('Location: index.php'); exit;
    }
    $mfs = !empty($emp['template_mfs500_base64']);
    $fm2 = !empty($emp['template_fm220_base64']);

    $rows = [
        ['Employee Code',   h($emp['employee_code'])],
        ['Full Name',       h($emp['full_name'])],
        ['Department',      h($emp['department_name'] ?? '—')],
        ['Phone',           h($emp['phone'] ?? '—')],
        ['Email',           h($emp['email'] ?? '—')],
        ['Join Date',       $emp['join_date'] ? date('d M Y', strtotime($emp['join_date'])) : '—'],
        ['Location',        h($emp['location_name'] ?? '—')],
        ['Enrollment Status', '<span class="badge badge-' . ($emp['enrollment_status'] === 'fully_enrolled' ? 'green' : ($emp['enrollment_status'] === 'pending' ? 'yellow' : 'grey')) . '">' . h(str_replace('_', ' ', $emp['enrollment_status'] ?? '')) . '</span>'],
        ['MFS500 Enrolled', $mfs ? '<span class="badge badge-green">Y</span>' : '<span class="badge badge-grey">N</span>'],
        ['FM220 Enrolled',  $fm2 ? '<span class="badge badge-green">Y</span>' : '<span class="badge badge-grey">N</span>'],
        ['OTP Channel',     ($emp['otp_channel'] ?? 'none') === 'none'
                                ? '<span class="badge badge-grey">Disabled</span>'
                                : '<span class="badge badge-green">' . h(mb_strtoupper($emp['otp_channel'])) . '</span>'],
        ['Active',          ($emp['is_active'] ?? 0) ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-red">Inactive</span>'],
        ['Created',         $emp['created_at'] ? date('d M Y H:i', strtotime($emp['created_at'])) : '—'],
    ];
?>
<div class="page-header"><h2>My Profile</h2></div>
<div class="form-card" style="max-width:620px">
    <div class="form-section-title">Employee Information</div>
    <table class="table" style="margin-top:8px">
        <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
            <th style="width:40%;text-align:left;font-weight:600;background:var(--bg);color:var(--muted);text-transform:uppercase;font-size:11px"><?= $r[0] ?></th>
            <td><?= $r[1] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="hint" style="margin-top:10px">
        This is a read-only view of your employee record. Contact HR/IT to update details.
    </p>
</div>
<?php
}
