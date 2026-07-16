<?php
// =========================================================
// Role CRUD + transaction-level access toggles
// Permissions (txn_*) were split out of `departments` into
// their own `roles` table. Each employee is assigned a
// single role via employees.role_id.
// Gate: isSuperadmin() || hasTxn('dept_roles')
// =========================================================

// Section labels mirror the sidebar nav groups in buildNav() —
// renaming a sidebar group? rename it here too so the Roles page
// stays in sync.
$txnGroups = [
    'Administration'   => ['txn_dashboard' => 'Dashboard', 'txn_devices' => 'Devices',
                           'txn_manage_passwords' => 'Passwords', 'txn_settings' => 'Settings',
                           'txn_dept_roles' => 'Roles', 'txn_dependencies' => 'Dependencies'],
    'HRMS'             => ['txn_employees' => 'Employees', 'txn_departments' => 'Departments',
                           'txn_locations' => 'Locations', 'txn_attendance' => 'Attendance',
                           'txn_approve_punches' => 'Approve Punches', 'txn_failed_punches' => 'Punch Issues'],
    'Ticket Management' => ['txn_issues' => 'View Tickets', 'txn_create_issue' => 'Create Ticket',
                           'txn_edit_issue' => 'Edit Ticket', 'txn_issue_summary' => 'Ticket Summary',
                           'txn_issue_comments' => 'Comments Feed', 'txn_manage_categories' => 'Ticket Categories',
                           'txn_ticket_scheduler' => 'Ticket Scheduler'],
    'Discount'         => ['txn_offer' => 'Offer Coupon', 'txn_coupon_redeemed' => 'Coupon Redeemed',
                           'txn_generate_coupons' => 'Generate Coupons', 'txn_generate_vouchers' => 'Generate Vouchers'],
    'Task Management'  => ['txn_checklist' => 'Store Checklist', 'txn_manage_tasks' => 'Manage Tasks',
                           'txn_checklist_report' => 'Checklist Report', 'txn_checklist_audit' => 'Checklist Audit',
                           'txn_checklist_validate' => 'Validate Checklist'],
    'Audits'           => ['txn_audit_create' => 'Create Audit', 'txn_audit_approve' => 'Approve Audit',
                           'txn_audit_operation' => 'Operation Review',
                           'txn_audit_management' => 'Management Approve',
                           'txn_audit_annotation_resolve' => 'Audit Annotation · Resolve',
                           'txn_audit_admin' => 'Audit Admin', 'txn_audit_view' => 'View All Audits',
                           'txn_audit_summary' => 'Audit Summary'],
    'Store Operations' => ['txn_outlet_directory' => 'Outlet Directory', 'txn_shelf_life' => 'Shelf Life',
                           'txn_shelf_life_upload' => 'Shelf Life Upload', 'txn_store_hours' => 'Store Hours',
                           'txn_price_tags' => 'Price Tags', 'txn_transactions_report' => 'Banking Cash Deposit Report'],
    'Policy & Violation' => ['txn_policy_admin' => 'Policy Admin',
                              'txn_policy_dashboard' => 'Policy Consent Dashboard',
                              'txn_violations_view' => 'View All Violations',
                              'txn_record_violation' => 'Record Violation',
                              'txn_reset_violation_counter' => 'Reset Counter',
                              'txn_violation_admin' => 'Violation Admin'],
    'Price Variation'  => ['txn_price_variation' => 'Submit Variation',
                           'txn_price_variation_confirm' => 'POC Confirm Variation',
                           'txn_price_variation_admin' => 'Variation Admin'],
    'Time Tracking'    => ['txn_time_report' => 'Time Tracking Report'],
];

function allTxnCols(): array {
    global $txnGroups;
    $cols = [];
    foreach ($txnGroups as $items) {
        foreach ($items as $col => $label) $cols[$col] = $label;
    }
    return $cols;
}

function getRoles(): array {
    return getDb()->query('SELECT * FROM roles WHERE is_active=1 ORDER BY role_name')->fetchAll();
}

function doSaveRole(): void {
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['role_name'] ?? '');
    if (!$name) { flash('error', 'Role name required.'); header('Location: index.php?page=roles'); exit; }

    $txns = [];
    foreach (allTxnCols() as $col => $label) {
        $txns[$col] = isset($_POST[$col]) ? 1 : 0;
    }

    $txnCols   = array_keys($txns);
    $txnValues = array_values($txns);

    try {
        if ($id) {
            $setClauses = implode(', ', array_map(fn($c) => "{$c}=?", $txnCols));
            getDb()->prepare(
                "UPDATE roles SET role_name=?, {$setClauses} WHERE id=?"
            )->execute([$name, ...$txnValues, $id]);
        } else {
            $colList      = implode(', ', $txnCols);
            $placeholders = implode(', ', array_fill(0, count($txnCols), '?'));
            getDb()->prepare(
                "INSERT INTO roles (role_name, {$colList})
                 VALUES (?, {$placeholders})"
            )->execute([$name, ...$txnValues]);
        }
        flash('success', 'Role saved.');
    } catch (Exception $e) { flash('error', $e->getMessage()); }
    header('Location: index.php?page=roles'); exit;
}

function doDelRole(): void {
    try {
        getDb()->prepare('UPDATE roles SET is_active=0 WHERE id=?')
               ->execute([(int)($_POST['id'] ?? 0)]);
        flash('success', 'Removed.');
    } catch (Exception $e) { flash('error', $e->getMessage()); }
    header('Location: index.php?page=roles'); exit;
}

// ── Page: Roles ──────────────────────────────────────────
function pageRoles(): void {
    global $txnGroups;
    $roles  = getRoles();
    $editId = (int)($_GET['edit'] ?? 0);
    $editR  = $editId ? (array_values(array_filter($roles, fn($r) => (int)$r['id'] === $editId))[0] ?? null) : null;
?>
<div class="page-header"><h2>Roles</h2></div>
<div class="form-card" style="max-width:none;margin-bottom:20px">
    <div class="form-section-title"><?= $editR ? 'Edit Role' : 'Add Role' ?></div>
    <form method="POST">
        <input type="hidden" name="action" value="save_role">
        <?php if ($editR): ?><input type="hidden" name="id" value="<?= $editR['id'] ?>"><?php endif; ?>
        <div class="form-grid" style="grid-template-columns:1fr">
            <div class="form-group">
                <label>Role Name <span class="required">*</span></label>
                <input type="text" name="role_name" class="form-control"
                       placeholder="e.g. HR Manager, Checklist Auditor" required
                       value="<?= h($editR['role_name'] ?? '') ?>">
            </div>
        </div>
        <div class="form-section-title" style="margin-top:12px">Page Access</div>
        <?php foreach ($txnGroups as $groupName => $items): ?>
        <div style="margin-bottom:10px">
            <div style="font-size:12px;font-weight:600;color:var(--muted);margin-bottom:4px"><?= $groupName ?></div>
            <div style="columns:5 180px;column-gap:16px">
                <?php foreach ($items as $col => $label): ?>
                <label class="checkbox-label" style="font-size:13px;display:flex;padding-top:0;margin-bottom:6px;break-inside:avoid">
                    <input type="checkbox" name="<?= $col ?>"
                           <?= ($editR[$col] ?? 0) ? 'checked' : '' ?>>
                    <?= $label ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <div class="form-actions" style="margin-top:14px">
            <button class="btn btn-primary"><?= $editR ? 'Update' : 'Add' ?></button>
            <?php if ($editR): ?><a href="?page=roles" class="btn btn-ghost">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>
<div class="table-wrap" data-stack>
<table class="table">
    <thead>
        <tr>
            <th>Role Name</th>
            <?php foreach ($txnGroups as $groupName => $items): ?>
            <th style="text-align:center;font-size:11px"><?= $groupName ?></th>
            <?php endforeach; ?>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($roles)): ?>
        <tr><td colspan="<?= 1 + count($txnGroups) + 1 ?>" class="empty-row">No roles yet. Add one above.</td></tr>
    <?php else: foreach ($roles as $r): ?>
        <tr>
            <td><?= h($r['role_name']) ?></td>
            <?php foreach ($txnGroups as $groupName => $items):
                $on = 0; $total = count($items);
                foreach ($items as $col => $lbl) { if ($r[$col] ?? 0) $on++; }
            ?>
            <td style="text-align:center;font-size:11px">
                <?php if ($on === $total): ?>
                    <span class="badge badge-green"><?= $on ?>/<?= $total ?></span>
                <?php elseif ($on > 0): ?>
                    <span class="badge badge-yellow"><?= $on ?>/<?= $total ?></span>
                <?php else: ?>
                    <span class="badge badge-grey">0/<?= $total ?></span>
                <?php endif; ?>
            </td>
            <?php endforeach; ?>
            <td class="actions">
                <a href="?page=roles&edit=<?= $r['id'] ?>" class="btn btn-sm btn-secondary">Edit</a>
                <form method="POST" class="inline-form" onsubmit="return confirm('Remove?')">
                    <input type="hidden" name="action" value="del_role">
                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                    <button class="btn btn-sm btn-danger">Remove</button>
                </form>
            </td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
<?php
}
