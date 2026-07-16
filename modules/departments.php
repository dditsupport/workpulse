<?php
// =========================================================
// Department CRUD — organizational data only (name + emails).
// Permissions moved to `roles` table; see modules/roles.php.
// =========================================================

function doSaveDepartment(): void {
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['department_name'] ?? '');
    if (!$name) { flash('error', 'Name required.'); header('Location: index.php?page=departments'); exit; }

    $email1 = trim($_POST['email1'] ?? '') ?: null;
    $email2 = trim($_POST['email2'] ?? '') ?: null;

    try {
        if ($id) {
            getDb()->prepare(
                'UPDATE departments SET department_name=?, email1=?, email2=? WHERE id=?'
            )->execute([$name, $email1, $email2, $id]);
        } else {
            getDb()->prepare(
                'INSERT INTO departments (department_name, email1, email2) VALUES (?, ?, ?)'
            )->execute([$name, $email1, $email2]);
        }
        flash('success', 'Department saved.');
    } catch (Exception $e) { flash('error', $e->getMessage()); }
    header('Location: index.php?page=departments'); exit;
}

function doDelDepartment(): void {
    try {
        getDb()->prepare('UPDATE departments SET is_active=0 WHERE id=?')
               ->execute([(int)($_POST['id'] ?? 0)]);
        flash('success', 'Removed.');
    } catch (Exception $e) { flash('error', $e->getMessage()); }
    header('Location: index.php?page=departments'); exit;
}

// ── Page: Departments ────────────────────────────────────
function pageDepartments(): void {
    $depts  = getDepartments();
    $editId = (int)($_GET['edit'] ?? 0);
    $editD  = $editId ? (array_values(array_filter($depts, fn($d) => (int)$d['id'] === $editId))[0] ?? null) : null;
?>
<div class="page-header"><h2>Departments</h2></div>
<div class="form-card" style="max-width:none;margin-bottom:20px">
    <div class="form-section-title"><?= $editD ? 'Edit Department' : 'Add Department' ?></div>
    <form method="POST">
        <input type="hidden" name="action" value="save_department">
        <?php if ($editD): ?><input type="hidden" name="id" value="<?= $editD['id'] ?>"> <?php endif; ?>
        <div class="form-grid" style="grid-template-columns:1fr 1fr 1fr">
            <div class="form-group">
                <label>Department Name <span class="required">*</span></label>
                <input type="text" name="department_name" class="form-control"
                       placeholder="Department name" required
                       value="<?= h($editD['department_name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Executive Email</label>
                <input type="email" name="email1" class="form-control" placeholder="Executive email"
                       value="<?= h($editD['email1'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Head Email</label>
                <input type="email" name="email2" class="form-control" placeholder="Head email"
                       value="<?= h($editD['email2'] ?? '') ?>">
            </div>
        </div>
        <div class="form-actions" style="margin-top:14px">
            <button class="btn btn-primary"><?= $editD ? 'Update' : 'Add' ?></button>
            <?php if ($editD): ?><a href="?page=departments" class="btn btn-ghost">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>
<div class="table-wrap" data-stack>
<table class="table">
    <thead>
        <tr>
            <th>Department Name</th>
            <th>Executive Email</th>
            <th>Head Email</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($depts)): ?>
        <tr><td colspan="4" class="empty-row">No departments yet. Add one above.</td></tr>
    <?php else: foreach ($depts as $d): ?>
        <tr>
            <td><?= h($d['department_name']) ?></td>
            <td style="font-size:12px"><?= $d['email1'] ? h($d['email1']) : '<span class="text-muted">—</span>' ?></td>
            <td style="font-size:12px"><?= $d['email2'] ? h($d['email2']) : '<span class="text-muted">—</span>' ?></td>
            <td class="actions">
                <a href="?page=departments&edit=<?= $d['id'] ?>" class="btn btn-sm btn-secondary">Edit</a>
                <form method="POST" class="inline-form" onsubmit="return confirm('Remove?')">
                    <input type="hidden" name="action" value="del_department">
                    <input type="hidden" name="id" value="<?= $d['id'] ?>">
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
