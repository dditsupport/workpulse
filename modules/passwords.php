<?php
// =========================================================
// Password Management — superadmin reset + user self-change
// =========================================================

// ── Superadmin: reset employee password ───────────────────
function doResetPassword(): void {
    $empCode = trim($_POST['employee_code'] ?? '');
    $newPass = trim($_POST['new_password'] ?? '');

    if (!$empCode || !$newPass) {
        flash('error', 'Employee code and new password required.');
        header('Location: index.php?page=manage_passwords'); exit;
    }

    $db = getDb();
    $st = $db->prepare("UPDATE employees SET portal_password = ? WHERE employee_code = ?");
    $st->execute([$newPass, $empCode]);

    if ($st->rowCount() > 0) {
        flash('success', "Password reset for {$empCode}.");
    } else {
        flash('error', 'Employee not found or no change.');
    }
    header('Location: index.php?page=manage_passwords'); exit;
}

// ── User: change own password ─────────────────────────────
function doChangePassword(): void {
    $current = trim($_POST['current_password'] ?? '');
    $newPass = trim($_POST['new_password'] ?? '');
    $confirm = trim($_POST['confirm_password'] ?? '');

    if (!$current || !$newPass) {
        flash('error', 'All fields are required.');
        header('Location: index.php?page=change_password'); exit;
    }
    if ($newPass !== $confirm) {
        flash('error', 'New passwords do not match.');
        header('Location: index.php?page=change_password'); exit;
    }
    if (strlen($newPass) < 4) {
        flash('error', 'Password must be at least 4 characters.');
        header('Location: index.php?page=change_password'); exit;
    }

    $db = getDb();
    $st = $db->prepare("SELECT portal_password FROM employees WHERE employee_code = ?");
    $st->execute([myCode()]);
    $emp = $st->fetch(PDO::FETCH_ASSOC);

    if (!$emp || $emp['portal_password'] !== $current) {
        flash('error', 'Current password is incorrect.');
        header('Location: index.php?page=change_password'); exit;
    }

    $db->prepare("UPDATE employees SET portal_password = ? WHERE employee_code = ?")->execute([$newPass, myCode()]);
    flash('success', 'Password changed successfully.');
    header('Location: index.php?page=change_password'); exit;
}

// ── Manage passwords page (superadmin) ────────────────────
function pageManagePasswords(): void {
    $db = getDb();
    $search = trim($_GET['q'] ?? '');
    $employees = [];

    if ($search) {
        $st = $db->prepare(
            "SELECT e.employee_code, e.full_name, d.department_name, e.is_active, e.portal_password
             FROM employees e
             LEFT JOIN departments d ON e.department_id = d.id
             WHERE e.employee_code LIKE ? OR e.full_name LIKE ?
             ORDER BY e.full_name LIMIT 50"
        );
        $like = "%{$search}%";
        $st->execute([$like, $like]);
        $employees = $st->fetchAll(PDO::FETCH_ASSOC);
    }
?>
<div class="page-header"><h2>🔐 Password Management</h2></div>

<!-- Search -->
<form method="GET" class="filter-bar" style="margin-bottom:14px">
    <input type="hidden" name="page" value="manage_passwords">
    <input type="text" name="q" class="form-control" style="width:300px" value="<?= h($search) ?>"
           placeholder="Search by code or name...">
    <button type="submit" class="btn btn-primary">Search</button>
</form>

<?php if ($search && empty($employees)): ?>
<div class="rpt-prompt">No employees found matching "<?= h($search) ?>".</div>
<?php elseif ($employees): ?>
<div class="table-wrap" data-stack>
    <table class="table">
        <thead>
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Department</th>
                <th>Status</th>
                <th>Has Password</th>
                <th style="width:300px">Reset Password</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($employees as $emp): ?>
            <tr class="<?= $emp['is_active'] ? '' : 'row-inactive' ?>">
                <td><code><?= h($emp['employee_code']) ?></code></td>
                <td><?= h($emp['full_name']) ?></td>
                <td><?= h($emp['department_name'] ?? '—') ?></td>
                <td><?= $emp['is_active'] ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-grey">Inactive</span>' ?></td>
                <td><?= $emp['portal_password'] ? '<span class="badge badge-green">Yes</span>' : '<span class="badge badge-grey">No</span>' ?></td>
                <td>
                    <form method="POST" style="display:flex;gap:6px" onsubmit="return confirm('Reset password for <?= h($emp['employee_code']) ?>?')">
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="employee_code" value="<?= h($emp['employee_code']) ?>">
                        <input type="text" name="new_password" class="form-control" style="width:160px" required placeholder="New password">
                        <button type="submit" class="btn btn-danger btn-sm">Reset</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php elseif (!$search): ?>
<div class="rpt-prompt">Search for an employee to reset their password.</div>
<?php endif; ?>
<?php }

// ── Change own password page ──────────────────────────────
function pageChangePassword(): void {
?>
<div class="page-header"><h2>🔑 Change Password</h2></div>
<div class="form-card" style="max-width:400px">
    <form method="POST">
        <input type="hidden" name="action" value="change_password">
        <div class="form-group" style="margin-bottom:12px">
            <label>Current Password <span class="required">*</span></label>
            <input type="password" name="current_password" class="form-control" required>
        </div>
        <div class="form-group" style="margin-bottom:12px">
            <label>New Password <span class="required">*</span></label>
            <input type="password" name="new_password" class="form-control" required minlength="4">
        </div>
        <div class="form-group" style="margin-bottom:12px">
            <label>Confirm New Password <span class="required">*</span></label>
            <input type="password" name="confirm_password" class="form-control" required minlength="4">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Change Password</button>
        </div>
    </form>
</div>
<?php }
