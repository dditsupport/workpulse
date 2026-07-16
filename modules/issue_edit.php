<?php
// =========================================================
// Issue Edit — manager-only update and edit page
// Depends on issues.php (notifyIssue, canManageIssues, etc.)
// =========================================================

// ── Update issue ─────────────────────────────────────────
function doUpdateIssue(): void {
    $id         = (int)($_POST['issue_id'] ?? 0);
    $summary    = trim($_POST['summary'] ?? '');
    $desc       = trim($_POST['description'] ?? '');
    $priority   = $_POST['priority'] ?? 'medium';
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $locationId = (int)($_POST['location_id'] ?? 0);

    if (!$summary || !$locationId) {
        flash('error', 'Summary and location required.');
        header("Location: index.php?page=edit_issue&id={$id}"); exit;
    }

    $db = getDb();
    $st = $db->prepare(
        "UPDATE issues SET summary=?, description=?, priority=?, category_id=?, location_id=?
         WHERE id=?"
    );
    $st->execute([$summary, $desc, $priority, $categoryId ?: null, $locationId, $id]);

    // Update participant departments (replace all)
    $deptIds = $_POST['participant_depts'] ?? [];
    $db->prepare("DELETE FROM issue_participants WHERE issue_id = ?")->execute([$id]);
    if (!empty($deptIds)) {
        $pst = $db->prepare("INSERT IGNORE INTO issue_participants (issue_id, department_id) VALUES (?, ?)");
        foreach ($deptIds as $did) {
            if ((int)$did > 0) $pst->execute([$id, (int)$did]);
        }
    }

    notifyIssue($id, 'updated');

    flash('success', 'Ticket updated.');
    header("Location: index.php?page=view_issue&id={$id}"); exit;
}

// ── Edit issue page ──────────────────────────────────────
function pageEditIssue(): void {
    if (!canManageIssues()) { flash('error', 'Access denied.'); header('Location: index.php?page=issues'); exit; }

    $id = (int)($_GET['id'] ?? 0);
    $db = getDb();
    $st = $db->prepare("SELECT id, summary, description, priority, category_id, location_id FROM issues WHERE id = ?");
    $st->execute([$id]);
    $issue = $st->fetch(PDO::FETCH_ASSOC);
    if (!$issue) { flash('error', 'Not found.'); header('Location: index.php?page=issues'); exit; }

    $categories = getIssueCategories();
    $locations  = getActiveLocations();
    $departments = getDepartments();

    $pst = $db->prepare("SELECT department_id FROM issue_participants WHERE issue_id = ?");
    $pst->execute([$id]);
    $currentDepts = $pst->fetchAll(PDO::FETCH_COLUMN);
?>
<div class="page-header"><h2>Edit Ticket WP-<?= $id ?></h2></div>
<div class="form-card">
    <form method="POST">
        <input type="hidden" name="action" value="update_issue">
        <input type="hidden" name="issue_id" value="<?= $id ?>">
        <div class="form-grid">
            <div class="form-group">
                <label>Category</label>
                <select name="category_id" class="form-control">
                    <option value="">— Select —</option>
                    <optgroup label="HR Issue">
                        <?php foreach ($categories as $c): if ($c['category_group'] === 'hr_issue'): ?>
                        <option value="<?= $c['id'] ?>" <?= $issue['category_id'] == $c['id'] ? 'selected' : '' ?>><?= h($c['category_name']) ?></option>
                        <?php endif; endforeach; ?>
                    </optgroup>
                    <optgroup label="Service Type">
                        <?php foreach ($categories as $c): if ($c['category_group'] === 'service_type'): ?>
                        <option value="<?= $c['id'] ?>" <?= $issue['category_id'] == $c['id'] ? 'selected' : '' ?>><?= h($c['category_name']) ?></option>
                        <?php endif; endforeach; ?>
                    </optgroup>
                    <optgroup label="Advance Maintenance">
                        <?php foreach ($categories as $c): if ($c['category_group'] === 'advance_maintenance'): ?>
                        <option value="<?= $c['id'] ?>" <?= $issue['category_id'] == $c['id'] ? 'selected' : '' ?>><?= h($c['category_name']) ?></option>
                        <?php endif; endforeach; ?>
                    </optgroup>
                    <optgroup label="Incident">
                        <?php foreach ($categories as $c): if ($c['category_group'] === 'incident'): ?>
                        <option value="<?= $c['id'] ?>" <?= $issue['category_id'] == $c['id'] ? 'selected' : '' ?>><?= h($c['category_name']) ?></option>
                        <?php endif; endforeach; ?>
                    </optgroup>
                </select>
            </div>
            <div class="form-group" style="display:flex;gap:12px">
                <div style="flex:1">
                    <label>Location <span class="required">*</span></label>
                    <select name="location_id" class="form-control" required>
                        <option value="">— Select —</option>
                        <?php foreach ($locations as $loc): ?>
                        <option value="<?= $loc['location_id'] ?>" <?= $issue['location_id'] == $loc['location_id'] ? 'selected' : '' ?>><?= h($loc['location_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex:1">
                    <label>Priority</label>
                    <select name="priority" class="form-control">
                        <?php foreach (['low','medium','high','critical'] as $p): ?>
                        <option value="<?= $p ?>" <?= $issue['priority'] === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <label>Summary <span class="required">*</span></label>
                <input type="text" name="summary" class="form-control" required value="<?= h($issue['summary']) ?>" maxlength="300">
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <label>Description</label>
                <textarea name="description" class="form-control" rows="4"><?= h($issue['description'] ?? '') ?></textarea>
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <label>Participant Departments</label>
                <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:4px">
                    <?php foreach ($departments as $dept): ?>
                    <label class="checkbox-label" style="font-size:13px">
                        <input type="checkbox" name="participant_depts[]" value="<?= $dept['id'] ?>"
                               <?= in_array($dept['id'], $currentDepts) ? 'checked' : '' ?>>
                        <?= h($dept['department_name']) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save Changes</button>
            <a href="?page=view_issue&id=<?= $id ?>" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
<?php }
