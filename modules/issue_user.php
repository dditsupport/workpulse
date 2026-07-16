<?php
// =========================================================
// Issue User Actions — create, comment, transition
// Depends on issues.php (handleAttachments, notifyIssue, canViewIssue, etc.)
// =========================================================

// ── Create issue ──────────────────────────────────────────
function doCreateIssue(): void {
    $summary    = trim($_POST['summary'] ?? '');
    $desc       = trim($_POST['description'] ?? '');
    $priority   = $_POST['priority'] ?? 'medium';
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $locationId = (int)($_POST['location_id'] ?? 0);
    if (!$summary || !$locationId) {
        flash('error', 'Summary and location are required.');
        header('Location: index.php?page=create_issue'); exit;
    }

    $db = getDb();
    $st = $db->prepare(
        "INSERT INTO issues (summary, description, priority, category_id, location_id, reporter_code)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $st->execute([$summary, $desc, $priority, $categoryId ?: null, $locationId, myCode()]);
    $issueId = (int)$db->lastInsertId();

    // Auto-assign participant departments from category roles
    if ($categoryId > 0) {
        $deptIds = getCategoryRoles($categoryId);
        if ($deptIds) {
            $pst = $db->prepare("INSERT IGNORE INTO issue_participants (issue_id, department_id) VALUES (?, ?)");
            foreach ($deptIds as $did) {
                $pst->execute([$issueId, $did]);
            }
        }
    }

    // Log initial status — new issues default to 'assigned_to_concerned' (DB default)
    $db->prepare("INSERT INTO issue_status_logs (issue_id, old_status, new_status, changed_by) VALUES (?, '', 'assigned_to_concerned', ?)")
       ->execute([$issueId, myCode()]);

    // Handle attachments
    handleAttachments($issueId, null, myCode());

    // Email notification
    notifyIssue($issueId, 'created');

    flash('success', "Ticket WP-{$issueId} created.");
    header("Location: index.php?page=view_issue&id={$issueId}"); exit;
}

// ── Transition issue status ───────────────────────────────
function doTransitionIssue(): void {
    $id     = (int)($_POST['issue_id'] ?? 0);
    $newSt  = $_POST['new_status'] ?? '';
    $db     = getDb();

    $st = $db->prepare(
        "SELECT i.*, c.category_group FROM issues i
         LEFT JOIN issue_categories c ON i.category_id = c.id
         WHERE i.id = ?"
    );
    $st->execute([$id]);
    $issue = $st->fetch(PDO::FETCH_ASSOC);
    if (!$issue) { flash('error', 'Ticket not found.'); header('Location: index.php?page=issues'); exit; }

    if (!canViewIssue($issue)) {
        flash('error', 'Access denied.');
        header('Location: index.php?page=issues'); exit;
    }

    if (!validateTransition($issue['status'], $newSt, $issue['category_group'] ?? '')) {
        flash('error', 'Invalid status transition.');
        header("Location: index.php?page=view_issue&id={$id}"); exit;
    }

    $resolvedAt = ($newSt === 'resolved') ? date('Y-m-d H:i:s') : $issue['resolved_at'];
    $db->prepare("UPDATE issues SET status = ?, resolved_at = ? WHERE id = ?")->execute([$newSt, $resolvedAt, $id]);

    $db->prepare("INSERT INTO issue_status_logs (issue_id, old_status, new_status, changed_by) VALUES (?, ?, ?, ?)")
       ->execute([$id, $issue['status'], $newSt, myCode()]);

    $oldLabel = statusLabel($issue['status']);
    $newLabel = statusLabel($newSt);
    notifyIssue($id, 'status_changed', "Status changed from <strong>{$oldLabel}</strong> to <strong>{$newLabel}</strong> by " . htmlspecialchars(myName()));

    flash('success', 'Status updated to ' . statusLabel($newSt) . '.');
    header("Location: index.php?page=view_issue&id={$id}"); exit;
}

// ── Add comment ───────────────────────────────────────────
function doAddComment(): void {
    $issueId = (int)($_POST['issue_id'] ?? 0);
    $body    = trim($_POST['body'] ?? '');

    if (!$body) {
        flash('error', 'Comment cannot be empty.');
        header("Location: index.php?page=view_issue&id={$issueId}"); exit;
    }

    $db = getDb();
    $st = $db->prepare("SELECT id, reporter_code, location_id FROM issues WHERE id = ?");
    $st->execute([$issueId]);
    $issue = $st->fetch(PDO::FETCH_ASSOC);
    if (!$issue || !canViewIssue($issue)) {
        flash('error', 'Access denied.');
        header('Location: index.php?page=issues'); exit;
    }

    $st = $db->prepare("INSERT INTO issue_comments (issue_id, author_code, body) VALUES (?, ?, ?)");
    $st->execute([$issueId, myCode(), $body]);
    $commentId = (int)$db->lastInsertId();

    handleAttachments($issueId, $commentId, myCode());

    $commentPreview = htmlspecialchars(mb_substr($body, 0, 200));
    notifyIssue($issueId, 'comment', "<strong>" . htmlspecialchars(myName()) . "</strong> commented:<br>" . nl2br($commentPreview));

    flash('success', 'Comment added.');
    header("Location: index.php?page=view_issue&id={$issueId}"); exit;
}

// ── Create issue page ─────────────────────────────────────
function pageCreateIssue(): void {
    $categories = getIssueCategories();
    $locations  = getActiveLocations();
    $myLocId    = myLocationId();

    // Build category → departments JSON for JS
    $db = getDb();
    $catRoles = $db->query("SELECT cr.category_id, d.department_name
        FROM issue_category_roles cr
        LEFT JOIN departments d ON cr.department_id = d.id
        ORDER BY cr.category_id, d.department_name")->fetchAll(PDO::FETCH_ASSOC);
    $catDeptMap = [];
    foreach ($catRoles as $cr) {
        $catDeptMap[(int)$cr['category_id']][] = $cr['department_name'];
    }
?>
<div class="page-header"><h2>New Ticket</h2></div>
<div class="form-card" style="max-width:600px">
    <form method="POST" enctype="multipart/form-data" id="issueCreateForm">
        <input type="hidden" name="action" value="create_issue">

        <!-- Step 1: Location -->
        <div class="form-group" style="grid-column:1/-1">
            <label>Location <span class="required">*</span></label>
            <select name="location_id" id="locSelect" class="form-control" required onchange="issueReveal()">
                <option value="">— Select Location —</option>
                <?php foreach ($locations as $loc): ?>
                <option value="<?= $loc['location_id'] ?>" <?= $myLocId === (int)$loc['location_id'] ? 'selected' : '' ?>>
                    <?= h($loc['location_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Step 2: Category (revealed after Location) -->
        <div id="issueStep2" style="display:none">
            <div class="form-group" style="grid-column:1/-1;margin-top:14px">
                <label>Category <span class="required">*</span></label>
                <select name="category_id" id="categorySelect" class="form-control" onchange="issueReveal();showParticipants()">
                    <option value="">— Select Category —</option>
                    <?php
                    $groupLabels = ['hr_issue'=>'HR Issue','service_type'=>'Service Type','advance_maintenance'=>'Advance Maintenance','incident'=>'Incident'];
                    $curGroup = null;
                    foreach ($categories as $c):
                        if ($c['category_group'] !== $curGroup):
                            if ($curGroup !== null) echo '</optgroup>';
                            $curGroup = $c['category_group'];
                            echo '<optgroup label="' . h($groupLabels[$curGroup] ?? ucfirst(str_replace('_',' ',$curGroup))) . '">';
                        endif;
                    ?>
                    <option value="<?= $c['id'] ?>"><?= h($c['category_name']) ?></option>
                    <?php endforeach; if ($curGroup !== null) echo '</optgroup>'; ?>
                </select>
                <div id="participantBadges" style="margin-top:6px"></div>
            </div>
        </div>

        <!-- Step 3: Priority + Summary + Description + Attachment (revealed after Category) -->
        <div id="issueStep3" style="display:none">
            <div class="form-group" style="grid-column:1/-1;margin-top:14px">
                <label>Priority</label>
                <select name="priority" class="form-control">
                    <option value="low" selected>Low</option>
                    <option value="medium">Medium</option>
                    <option value="high">High</option>
                    <option value="critical">Critical</option>
                </select>
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <label>Summary <span class="required">*</span></label>
                <input type="text" name="summary" class="form-control" maxlength="300" placeholder="Brief ticket description">
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <label>Description</label>
                <textarea name="description" class="form-control" rows="4" placeholder="Detailed description (optional)"></textarea>
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <label>Attachments</label>
                <input type="file" name="attachments[]" id="issue-attachments" class="form-control" multiple
                       accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv,.zip">
                <span class="hint">Max 5MB per file. Allowed: jpg, png, gif, pdf, doc, xls, xlsx, txt, csv, zip. Photos are auto-compressed in your browser before upload.</span>
                <div id="issue-att-status" style="font-size:11px;margin-top:4px;color:var(--muted);min-height:14px"></div>
            </div>
            <div class="form-actions" style="margin-top:14px">
                <button type="submit" class="btn btn-primary" id="issue-submit-btn">Create Ticket</button>
                <a href="?page=issues" class="btn btn-ghost">Cancel</a>
            </div>
        </div>
    </form>
</div>
<script>
var catDeptMap = <?= json_encode($catDeptMap, JSON_HEX_TAG) ?>;
function issueReveal() {
    var loc = document.getElementById('locSelect').value;
    var cat = document.getElementById('categorySelect') ? document.getElementById('categorySelect').value : '';
    document.getElementById('issueStep2').style.display = loc ? 'block' : 'none';
    document.getElementById('issueStep3').style.display = (loc && cat) ? 'block' : 'none';
    // Toggle required on summary only when visible
    var sumEl = document.querySelector('input[name="summary"]');
    if (sumEl) sumEl.required = !!(loc && cat);
}
function showParticipants() {
    var sel = document.getElementById('categorySelect');
    var box = document.getElementById('participantBadges');
    if (!sel || !box) return;
    var catId = sel.value;
    if (!catId || !catDeptMap[catId]) { box.innerHTML = ''; return; }
    var depts = catDeptMap[catId];
    box.innerHTML = '<span class="text-muted" style="font-size:12px;margin-right:4px">Participants:</span>' +
        depts.map(function(d){ return '<span class="badge badge-purple" style="margin:1px;font-size:11px">' + d + '</span>'; }).join('');
}
// Run on load (covers case where location pre-selected from myLocationId)
document.addEventListener('DOMContentLoaded', issueReveal);

// ── Client-side image compression for attachments ────────────────
// Same logic as the bank-deposit form — but this input is multi-file
// and accepts non-image types too (PDF, doc, xls, zip…). We iterate
// every selected file, compress only images larger than the skip
// threshold, and rebuild input.files with the mix of compressed
// images + untouched non-images. Submit is gated until all
// compressions resolve.
(function () {
    var input  = document.getElementById('issue-attachments');
    var status = document.getElementById('issue-att-status');
    var form   = document.getElementById('issueCreateForm');
    var submit = document.getElementById('issue-submit-btn');
    if (!input || !form || !submit) return;

    var MAX_EDGE     = 1600;
    var SKIP_BELOW   = 600 * 1024;
    var JPEG_QUALITY = 0.75;
    var IMAGE_RE     = /^image\/(jpeg|png|gif|webp|heic|heif)$/i;
    var compressing  = false;

    function fmtSize(b) {
        if (b < 1024) return b + ' B';
        if (b < 1024 * 1024) return (b / 1024).toFixed(1) + ' KB';
        return (b / 1024 / 1024).toFixed(2) + ' MB';
    }
    function setBusy(msg)        { compressing = true;  submit.disabled = true;  status.style.color = 'var(--muted)'; status.textContent = msg; }
    function setDone(msg, color) { compressing = false; submit.disabled = false; status.style.color = color || 'var(--muted)'; status.textContent = msg; }

    function setFiles(fileArr) {
        try {
            var dt = new DataTransfer();
            fileArr.forEach(function (f) { dt.items.add(f); });
            input.files = dt.files;
            return true;
        } catch (e) { return false; }
    }

    function decode(file) {
        if (typeof createImageBitmap === 'function') {
            try { return createImageBitmap(file, { imageOrientation: 'from-image' }); }
            catch (e) { return createImageBitmap(file); }
        }
        return new Promise(function (resolve, reject) {
            var url = URL.createObjectURL(file);
            var img = new Image();
            img.onload  = function () { URL.revokeObjectURL(url); resolve(img); };
            img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('image decode failed')); };
            img.src = url;
        });
    }

    // Returns a Promise<File> — either a new compressed File or the
    // original passed back unchanged.
    function compressOne(file) {
        if (!IMAGE_RE.test(file.type)) return Promise.resolve(file);
        if (file.size <= SKIP_BELOW)   return Promise.resolve(file);
        return decode(file).then(function (bmp) {
            var w = bmp.width || bmp.naturalWidth;
            var h = bmp.height || bmp.naturalHeight;
            if (!w || !h) return file;
            var scale = Math.min(1, MAX_EDGE / Math.max(w, h));
            var tw = Math.round(w * scale), th = Math.round(h * scale);
            var canvas = document.createElement('canvas');
            canvas.width = tw; canvas.height = th;
            canvas.getContext('2d').drawImage(bmp, 0, 0, tw, th);
            return new Promise(function (resolve) {
                canvas.toBlob(function (blob) {
                    if (!blob || blob.size >= file.size) { resolve(file); return; }
                    var nameBase = (file.name || 'photo').replace(/\.(png|jpe?g|gif|webp|heic|heif)$/i, '');
                    resolve(new File([blob], nameBase + '.jpg', { type: 'image/jpeg', lastModified: Date.now() }));
                }, 'image/jpeg', JPEG_QUALITY);
            });
        }).catch(function () { return file; });
    }

    input.addEventListener('change', function () {
        var files = Array.from(input.files || []);
        if (!files.length) { setDone(''); return; }

        var origTotal = files.reduce(function (n, f) { return n + f.size; }, 0);
        var compressedAny = files.some(function (f) { return IMAGE_RE.test(f.type) && f.size > SKIP_BELOW; });
        if (!compressedAny) {
            setDone(files.length + ' file(s) selected — ' + fmtSize(origTotal) + ' (no compression needed).');
            return;
        }

        setBusy('Compressing photo(s)… please wait.');
        Promise.all(files.map(compressOne)).then(function (out) {
            var newTotal = out.reduce(function (n, f) { return n + f.size; }, 0);
            if (!setFiles(out)) {
                setDone('Could not replace selected files in this browser — uploading originals (' + fmtSize(origTotal) + ').', 'var(--yellow)');
                return;
            }
            if (newTotal < origTotal) {
                setDone(out.length + ' file(s) ready — ' + fmtSize(origTotal) + ' → ' + fmtSize(newTotal)
                    + ' (' + Math.round((1 - newTotal / origTotal) * 100) + '% smaller).', 'var(--green)');
            } else {
                setDone(out.length + ' file(s) ready — ' + fmtSize(newTotal) + '.');
            }
        }).catch(function (err) {
            setDone('Could not compress — uploading originals (' + fmtSize(origTotal) + '). ' + (err && err.message ? err.message : ''), 'var(--yellow)');
        });
    });

    form.addEventListener('submit', function (e) {
        if (compressing) {
            e.preventDefault();
            status.style.color = 'var(--yellow)';
            status.textContent = 'Still compressing — please wait a moment and try again.';
            return;
        }
        submit.disabled = true;
        submit.textContent = 'Submitting…';
    });
})();
</script>
<?php }
