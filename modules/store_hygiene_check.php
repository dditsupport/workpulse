<?php
// =========================================================
// Store Hygiene Questions — admin CRUD for the question list
// that labels Store Hygiene photo uploads.
//
// The questions table feeds the upload form in image_annotations.php
// (annPageDay → upload card → "Question" dropdown). Each photo is
// stored with question_id + store_manager_code so observations are
// categorised at the source.
//
// This module only owns the question list — the upload flow itself,
// the per-day photo gallery, and pin/comment behaviour all live in
// modules/image_annotations.php.
//
// Pages:    store_hygiene_check_admin    (manage the question list)
// Actions:  save_sh_question             (admin add/edit a question)
//           del_sh_question              (admin toggle is_active)
// Schema:   migrations/import_2026_05_30_store_hygiene_check.sql
// =========================================================

// Permission gate for the question catalog admin page + its POST handlers.
// Superadmin or anyone holding txn_sh_questions can manage the list.
function shCheckCanManageQuestions(): bool {
    return isSuperadmin() || hasTxn('sh_questions');
}

function shCheckQuestions(bool $activeOnly = true): array {
    try {
        $sql = 'SELECT * FROM sh_check_questions';
        if ($activeOnly) $sql .= ' WHERE is_active = 1';
        $sql .= ' ORDER BY sort_order, id';
        return getDb()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

function shCheckQuestion(int $id): ?array {
    if ($id < 1) return null;
    try {
        $st = getDb()->prepare('SELECT * FROM sh_check_questions WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) { return null; }
}

// ── Admin page — manage questions ───────────────────────
function pageStoreHygieneCheckAdmin(): void {
    if (!shCheckCanManageQuestions()) { echo '<p>Access denied.</p>'; return; }
    $editId = (int)($_GET['edit'] ?? 0);
    $editQ  = $editId ? shCheckQuestion($editId) : null;
    $all    = shCheckQuestions(false);
?>
<div class="page-header" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <a href="?page=annotations" class="btn btn-sm btn-ghost">← Back to Store Hygiene</a>
    <h2 style="margin:0">Manage Store Hygiene Questions</h2>
</div>

<div class="form-card" style="max-width:none;margin-bottom:18px">
    <div class="form-section-title"><?= $editQ ? 'Edit question' : 'Add new question' ?></div>
    <form method="POST">
        <input type="hidden" name="action" value="save_sh_question">
        <?php if ($editQ): ?><input type="hidden" name="id" value="<?= (int)$editQ['id'] ?>"><?php endif; ?>
        <div class="form-grid" style="grid-template-columns:2fr 1fr 1fr">
            <div class="form-group">
                <label>Question Text <span class="required">*</span></label>
                <input type="text" name="question_text" class="form-control" maxlength="500" required
                       value="<?= h($editQ['question_text'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Answer Type <span class="required">*</span></label>
                <select name="answer_type" class="form-control" required>
                    <option value="yes_no"     <?= ($editQ['answer_type'] ?? '') === 'yes_no'     ? 'selected' : '' ?>>Yes / No</option>
                    <option value="rating_1_5" <?= ($editQ['answer_type'] ?? '') === 'rating_1_5' ? 'selected' : '' ?>>1 – 5 Rating</option>
                </select>
                <span class="hint">Used to categorise the question; not a separate input today.</span>
            </div>
            <div class="form-group">
                <label>Sort Order</label>
                <input type="number" step="1" min="0" name="sort_order" class="form-control"
                       value="<?= (int)($editQ['sort_order'] ?? (count($all) + 1) * 10) ?>">
            </div>
        </div>
        <div class="form-actions">
            <button class="btn btn-primary"><?= $editQ ? 'Update' : 'Add' ?></button>
            <?php if ($editQ): ?><a class="btn btn-ghost" href="?page=store_hygiene_check_admin">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>

<div class="table-wrap" data-stack>
    <table class="table">
        <thead>
            <tr>
                <th style="width:50px">#</th>
                <th>Question</th>
                <th style="width:130px">Answer Type</th>
                <th style="width:90px">Sort</th>
                <th style="width:90px">Active</th>
                <th style="width:170px">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($all)): ?>
            <tr><td colspan="6" class="empty-row">No questions yet.</td></tr>
        <?php else: foreach ($all as $i => $q): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= h($q['question_text']) ?></td>
                <td><?= $q['answer_type'] === 'yes_no' ? 'Yes / No' : '1 – 5 Rating' ?></td>
                <td><?= (int)$q['sort_order'] ?></td>
                <td><?= (int)$q['is_active'] ? '<span class="badge badge-green">On</span>' : '<span class="badge badge-grey">Off</span>' ?></td>
                <td class="actions">
                    <a class="btn btn-sm btn-secondary" href="?page=store_hygiene_check_admin&edit=<?= (int)$q['id'] ?>">Edit</a>
                    <form method="POST" class="inline-form" onsubmit="return confirm('<?= (int)$q['is_active'] ? 'Disable' : 'Enable' ?> this question?');">
                        <input type="hidden" name="action" value="del_sh_question">
                        <input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
                        <button class="btn btn-sm <?= (int)$q['is_active'] ? 'btn-danger' : 'btn-success' ?>"><?= (int)$q['is_active'] ? 'Disable' : 'Enable' ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?php
}

// ── Admin POST: save question ───────────────────────────
function doSaveShQuestion(): void {
    if (!shCheckCanManageQuestions()) { header('Location: index.php?page=annotations'); exit; }
    $id    = (int)($_POST['id'] ?? 0);
    $text  = trim((string)($_POST['question_text'] ?? ''));
    $type  = trim((string)($_POST['answer_type']   ?? ''));
    $sort  = (int)($_POST['sort_order'] ?? 0);
    if ($text === '' || !in_array($type, ['yes_no','rating_1_5'], true)) {
        flash('error', 'Question text and a valid answer type are required.');
        header('Location: index.php?page=store_hygiene_check_admin' . ($id ? '&edit=' . $id : '')); exit;
    }
    try {
        $db = getDb();
        if ($id > 0) {
            $db->prepare('UPDATE sh_check_questions SET question_text=?, answer_type=?, sort_order=? WHERE id=?')
               ->execute([$text, $type, $sort, $id]);
            flash('success', 'Question updated.');
        } else {
            $db->prepare('INSERT INTO sh_check_questions (question_text, answer_type, sort_order, is_active) VALUES (?,?,?,1)')
               ->execute([$text, $type, $sort]);
            flash('success', 'Question added.');
        }
    } catch (Exception $e) {
        flash('error', 'Save failed: ' . $e->getMessage());
    }
    header('Location: index.php?page=store_hygiene_check_admin'); exit;
}

// ── Admin POST: toggle active (acts as soft delete) ─────
function doDelShQuestion(): void {
    if (!shCheckCanManageQuestions()) { header('Location: index.php?page=annotations'); exit; }
    $id = (int)($_POST['id'] ?? 0);
    if ($id < 1) { header('Location: index.php?page=store_hygiene_check_admin'); exit; }
    try {
        getDb()->prepare('UPDATE sh_check_questions SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
        flash('success', 'Question status toggled.');
    } catch (Exception $e) {
        flash('error', 'Toggle failed: ' . $e->getMessage());
    }
    header('Location: index.php?page=store_hygiene_check_admin'); exit;
}
