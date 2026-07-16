<?php
// =========================================================
// Audit Attachment Annotations — pin/comment viewer over images
// attached to an audit response.
//   - DB tables: audit_image_pins + audit_image_pin_comments
//   - Page routes: audit_annotation_image / _serve / _thread
//   - POST actions: create_audit_annotation, add_audit_annotation_comment,
//                   resolve_audit_annotation, reopen_audit_annotation
//   - Files stay under uploads/audit/ (audit module owns the storage —
//     see auditAttachmentDir() in modules/audit.php for the layout)
//
// Permissions delegate to audit role gates (auditCanViewRow, auditCanAnnotate,
// auditCanResolveAnnotation).
//
// Schema: migrations/import_2026_05_29_audit_attachment_annotations.sql
// =========================================================

// ── Permission helpers ─────────────────────────────────
// Resolve the parent audits row for an audit_response_attachment id.
// Used by every gate on this surface.
function auditAnnAuditForAttachment(int $attId): ?array {
    if ($attId < 1) return null;
    try {
        $st = getDb()->prepare(
            'SELECT a.*, l.location_name, t.name AS template_name
             FROM   audit_response_attachments aa
             JOIN   audit_responses r ON r.id = aa.response_id
             JOIN   audits          a ON a.id = r.audit_id
             LEFT JOIN locations    l ON l.location_id = a.location_id
             LEFT JOIN audit_templates t ON t.id = a.template_id
             WHERE  aa.id = ?');
        $st->execute([$attId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) { return null; }
}

// Resolve the audit_response_attachment row by id, returning filename,
// mime, stored path bits, plus the parent audit_id (for storage path).
function auditAnnAttachment(int $attId): ?array {
    if ($attId < 1) return null;
    try {
        $st = getDb()->prepare(
            'SELECT aa.id, aa.filename, aa.stored_name, aa.mime_type, aa.file_size,
                    aa.uploaded_by, aa.uploaded_at,
                    a.id AS audit_id, a.location_id, a.audit_date
             FROM   audit_response_attachments aa
             JOIN   audit_responses r ON r.id = aa.response_id
             JOIN   audits          a ON a.id = r.audit_id
             WHERE  aa.id = ?');
        $st->execute([$attId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) { return null; }
}

function auditAnnIsImage(string $mime): bool {
    return stripos($mime, 'image/') === 0;
}

// ── DB fetchers ────────────────────────────────────────
function auditAnnPinsForAttachment(int $attId): array {
    try {
        $st = getDb()->prepare(
            "SELECT p.id, p.pin_number, p.x_percent, p.y_percent, p.status,
                    p.created_by, p.created_at, p.resolved_by, p.resolved_at,
                    e1.full_name AS creator_name,
                    e2.full_name AS resolver_name,
                    (SELECT COUNT(*) FROM audit_image_pin_comments c WHERE c.pin_id = p.id) AS comment_count
             FROM   audit_image_pins p
             LEFT JOIN employees e1 ON e1.employee_code = p.created_by
             LEFT JOIN employees e2 ON e2.employee_code = p.resolved_by
             WHERE  p.attachment_id = ?
             ORDER  BY p.pin_number"
        );
        $st->execute([$attId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

function auditAnnCommentsForPin(int $pinId): array {
    try {
        $st = getDb()->prepare(
            "SELECT c.id, c.employee_code, c.comment_text, c.created_at, e.full_name
             FROM   audit_image_pin_comments c
             LEFT JOIN employees e ON e.employee_code = c.employee_code
             WHERE  c.pin_id = ?
             ORDER  BY c.created_at ASC"
        );
        $st->execute([$pinId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

// Lookup helper used by both the comment + resolve POST handlers to
// resolve a pin to its parent attachment + audit (for permission).
function auditAnnPinContext(int $pinId): ?array {
    if ($pinId < 1) return null;
    try {
        $st = getDb()->prepare(
            'SELECT p.id, p.attachment_id, aa.response_id, r.audit_id
             FROM   audit_image_pins p
             JOIN   audit_response_attachments aa ON aa.id = p.attachment_id
             JOIN   audit_responses             r ON r.id  = aa.response_id
             WHERE  p.id = ?');
        $st->execute([$pinId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) { return null; }
}

// ── Pages ──────────────────────────────────────────────

// Full-size viewer with pin overlay + bottom-sheet (mobile) / side-panel
// (desktop) thread, scoped to a single audit attachment.
// Entry: ?page=audit_annotation_image&audit_att=NNN[&pin=N]
function pageAuditAnnotationImage(): void {
    $attId = (int)($_GET['audit_att'] ?? 0);
    $att   = auditAnnAttachment($attId);
    if (!$att) { echo '<div class="alert alert-error">Attachment not found.</div>'; return; }

    $auditRow = auditAnnAuditForAttachment($attId);
    if (!$auditRow || !auditCanViewRow($auditRow)) {
        echo '<div class="alert alert-error">Access denied.</div>'; return;
    }
    $canAnnotate = auditCanAnnotate($auditRow);
    $canResolve  = auditCanResolveAnnotation();
    $isImage     = auditAnnIsImage((string)$att['mime_type']);
    $pins        = $isImage ? auditAnnPinsForAttachment($attId) : [];
    $selectedPin = (int)($_GET['pin'] ?? 0);
    $imgUrl      = '?page=audit_annotation_serve&audit_att=' . $attId;
    $extLabel    = mb_strtoupper(pathinfo((string)$att['filename'], PATHINFO_EXTENSION) ?: 'FILE');

    // pin row -> pin_number lookup so a ?pin=N query auto-opens that pin
    $selectedPinId = 0;
    if ($selectedPin > 0) {
        foreach ($pins as $p) {
            if ((int)$p['pin_number'] === $selectedPin) { $selectedPinId = (int)$p['id']; break; }
        }
    }

    $pinsJs = array_map(fn($p) => [
        'id'           => (int)$p['id'],
        'pin_number'   => (int)$p['pin_number'],
        'x'            => (float)$p['x_percent'],
        'y'            => (float)$p['y_percent'],
        'status'       => $p['status'],
        'creator'      => $p['creator_name'] ?? $p['created_by'],
        'created_at'   => $p['created_at'],
        'resolver'     => $p['resolver_name'] ?? null,
        'resolved_at'  => $p['resolved_at'] ?? null,
        'comments'     => (int)$p['comment_count'],
    ], $pins);
?>
<div class="page-header" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <a href="?page=audit_view&id=<?= (int)$auditRow['id'] ?>" class="btn btn-sm btn-ghost">← Back to Audit</a>
    <h2 style="margin:0">Audit Attachment</h2>
    <span class="text-muted" style="font-size:13px"><?= h($att['filename']) ?></span>
    <span class="text-muted" style="font-size:12px;margin-left:auto">
        Audit <code><?= h($auditRow['audit_number'] ?? '#' . (int)$auditRow['id']) ?></code> &middot;
        <?= h($auditRow['location_name'] ?? '—') ?>
    </span>
</div>

<?php if ($isImage): ?>
<div id="aaViewer" class="ann-viewer">
    <div id="aaStage" class="ann-stage">
        <img id="aaImg" src="<?= h($imgUrl) ?>" alt="audit attachment">
        <div id="aaPins" class="ann-pins"></div>
    </div>
    <?php if ($canAnnotate): ?>
    <div class="ann-toolbar">
        <button id="aaDropBtn" type="button" class="btn btn-primary">+ Add Pin</button>
        <span id="aaHint" class="text-muted" style="font-size:12px"></span>
    </div>
    <?php endif; ?>
</div>
<?php else: ?>
<!-- Non-image attachment: render a simple file card and bail; pins/comments
     don't apply to PDFs in this surface. -->
<div class="form-card" style="max-width:none">
    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
        <div style="width:64px;height:64px;background:linear-gradient(180deg,#16161b,#1c1c24);border:1px solid var(--border);border-radius:8px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;flex-shrink:0">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" style="color:var(--muted)">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
            </svg>
            <span style="font-size:9px;font-weight:700;color:var(--accent);letter-spacing:.06em"><?= h($extLabel) ?></span>
        </div>
        <div style="flex:1 1 240px;min-width:0">
            <div style="font-weight:600;word-break:break-word"><?= h($att['filename']) ?></div>
            <div class="text-muted" style="font-size:12px;margin-top:2px">
                <?= h(number_format(((int)$att['file_size'])/1024, 0)) ?> KB &middot;
                <?= h($att['mime_type']) ?>
            </div>
        </div>
        <div style="display:flex;gap:8px">
            <a href="?page=download_audit_attachment&audit_id=<?= (int)$auditRow['id'] ?>&att_id=<?= (int)$att['id'] ?>" target="_blank" class="btn btn-primary">Open</a>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($isImage): ?>
<!-- Bottom sheet / side panel for the comment thread -->
<div id="aaSheet" class="ann-sheet" hidden>
    <div class="ann-sheet-head">
        <div id="aaSheetTitle" style="font-weight:600">Pin</div>
        <button type="button" id="aaSheetClose" class="btn btn-sm btn-ghost" aria-label="Close">×</button>
    </div>
    <div id="aaSheetMeta" class="ann-sheet-meta"></div>
    <div id="aaSheetActions" class="ann-sheet-actions"></div>
    <div id="aaSheetBody" class="ann-sheet-body">
        <div id="aaComments"></div>
        <?php if ($canAnnotate): ?>
        <form id="aaCommentForm" data-no-disable class="ann-comment-form">
            <textarea id="aaCommentInput" placeholder="Add a comment…" rows="2" class="form-control"></textarea>
            <button type="submit" class="btn btn-primary btn-sm">Send</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<style>
/* Reuse the same visual rules as the Store-Hygiene viewer (.ann-viewer,
   .ann-stage, .ann-pin, .ann-sheet, …) — those live in modules/styles.php
   and are loaded on every page, so this file doesn't need to redeclare
   them. The only audit-specific tweak below is the canvas wrapper. */
.ann-viewer { display:flex; flex-direction:column; gap:10px }
.ann-stage  { position:relative; max-width:100%; background:#0a0a0c; border:1px solid var(--border); border-radius:8px; overflow:hidden; user-select:none; -webkit-user-select:none }
.ann-stage img { display:block; max-width:100%; width:100%; height:auto; pointer-events:none }
.ann-pins   { position:absolute; inset:0; pointer-events:none }
.ann-pin    { position:absolute; transform:translate(-50%, -50%); width:30px; height:30px; border-radius:50%;
              display:inline-flex; align-items:center; justify-content:center;
              font-size:13px; font-weight:700; color:#fff; cursor:pointer; pointer-events:auto;
              box-shadow:0 2px 8px rgba(0,0,0,.5); transition:transform .12s }
.ann-pin:hover { transform:translate(-50%, -50%) scale(1.12) }
.ann-pin-open     { background:var(--red);   border:2px solid #fff }
.ann-pin-resolved { background:#6b7280;      border:2px solid rgba(255,255,255,.5); text-decoration:line-through }
.ann-pin-active   { outline:3px solid var(--yellow); outline-offset:2px }
.ann-toolbar { display:flex; gap:10px; align-items:center; flex-wrap:wrap }
.ann-stage.ann-dropmode { cursor:crosshair }
.ann-stage.ann-dropmode .ann-pin { cursor:not-allowed }

.ann-sheet { position:fixed; right:0; top:0; bottom:0; width:min(420px, 100%); background:var(--surface);
             border-left:1px solid var(--border); box-shadow:-6px 0 24px rgba(0,0,0,.5);
             display:flex; flex-direction:column; z-index:100; transform:translateX(100%); transition:transform .22s ease }
.ann-sheet[data-open="1"] { transform:translateX(0) }
.ann-sheet-head { display:flex; align-items:center; justify-content:space-between; padding:12px 14px; border-bottom:1px solid var(--border) }
.ann-sheet-meta { padding:10px 14px; font-size:12px; color:var(--muted); border-bottom:1px solid var(--border) }
.ann-sheet-actions { display:flex; gap:8px; padding:10px 14px; border-bottom:1px solid var(--border); flex-wrap:wrap }
.ann-sheet-actions:empty { display:none }
.ann-sheet-body { flex:1 1 auto; display:flex; flex-direction:column; overflow:hidden }
#aaComments { flex:1 1 auto; overflow-y:auto; padding:10px 14px }
.ann-comment { padding:8px 0; border-bottom:1px dashed var(--border) }
.ann-comment:last-child { border-bottom:none }
.ann-comment-author { font-size:12px; font-weight:600; color:var(--text) }
.ann-comment-time   { font-size:10px; color:var(--muted); margin-left:6px }
.ann-comment-text   { font-size:13px; color:var(--text); white-space:pre-wrap; margin-top:2px }
.ann-comment-form   { display:flex; gap:8px; padding:10px 14px; border-top:1px solid var(--border) }
.ann-comment-form textarea { flex:1 1 auto; resize:vertical; min-height:42px; max-height:140px }

@media(max-width:700px){
    .ann-sheet { left:0; right:0; top:auto; bottom:0; width:auto; max-height:80vh; border-left:none; border-top:1px solid var(--border);
                 border-radius:14px 14px 0 0; transform:translateY(100%); box-shadow:0 -6px 24px rgba(0,0,0,.5) }
    .ann-sheet[data-open="1"] { transform:translateY(0) }
}
</style>

<script>
(function(){
    const PINS         = <?= json_encode($pinsJs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?>;
    const ATT_ID       = <?= (int)$attId ?>;
    const CAN_ANNOTATE = <?= $canAnnotate ? 'true' : 'false' ?>;
    const CAN_RESOLVE  = <?= $canResolve  ? 'true' : 'false' ?>;
    const SELECTED_PIN_ID = <?= (int)$selectedPinId ?>;

    const stage   = document.getElementById('aaStage');
    const img     = document.getElementById('aaImg');
    const pinsEl  = document.getElementById('aaPins');
    const dropBtn = document.getElementById('aaDropBtn');
    const hintEl  = document.getElementById('aaHint');
    const sheet   = document.getElementById('aaSheet');
    const sheetTitle   = document.getElementById('aaSheetTitle');
    const sheetMeta    = document.getElementById('aaSheetMeta');
    const sheetActions = document.getElementById('aaSheetActions');
    const commentsEl   = document.getElementById('aaComments');
    const closeBtn     = document.getElementById('aaSheetClose');
    const commentForm  = document.getElementById('aaCommentForm');
    const commentInput = document.getElementById('aaCommentInput');

    let dropMode = false;
    let activePinId = null;
    const localPins = PINS.slice();

    function escapeHtml(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    function renderPins() {
        pinsEl.innerHTML = '';
        localPins.forEach(p => {
            const el = document.createElement('button');
            el.type = 'button';
            el.className = 'ann-pin ' + (p.status === 'resolved' ? 'ann-pin-resolved' : 'ann-pin-open');
            if (p.id === activePinId) el.classList.add('ann-pin-active');
            el.style.left = p.x + '%';
            el.style.top  = p.y + '%';
            el.textContent = p.pin_number;
            el.title = 'Pin #' + p.pin_number + (p.status === 'resolved' ? ' (resolved)' : '') + ' · ' + (p.comments) + ' comment' + (p.comments === 1 ? '' : 's');
            el.addEventListener('click', ev => { ev.preventDefault(); ev.stopPropagation(); openPin(p.id); });
            pinsEl.appendChild(el);
        });
    }

    function setDropMode(on) {
        dropMode = !!on;
        if (dropBtn) {
            dropBtn.textContent = dropMode ? 'Cancel' : '+ Add Pin';
            dropBtn.classList.toggle('btn-secondary', dropMode);
            dropBtn.classList.toggle('btn-primary', !dropMode);
        }
        stage.classList.toggle('ann-dropmode', dropMode);
        if (hintEl) hintEl.textContent = dropMode ? 'Tap on the image to drop a pin.' : '';
    }

    if (dropBtn) dropBtn.addEventListener('click', () => setDropMode(!dropMode));

    stage.addEventListener('click', async (ev) => {
        if (!dropMode || !CAN_ANNOTATE) return;
        const rect = img.getBoundingClientRect();
        const x = ((ev.clientX - rect.left) / rect.width)  * 100;
        const y = ((ev.clientY - rect.top)  / rect.height) * 100;
        if (x < 0 || x > 100 || y < 0 || y > 100) return;
        const text = window.prompt('First comment for this pin:');
        if (text == null) { setDropMode(false); return; }
        const comment = String(text).trim();
        if (!comment) { setDropMode(false); return; }
        const fd = new FormData();
        fd.append('action', 'create_audit_annotation');
        fd.append('audit_att', ATT_ID);
        fd.append('x_percent', x.toFixed(2));
        fd.append('y_percent', y.toFixed(2));
        fd.append('comment_text', comment);
        const res = await fetch('index.php', { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
        const json = await res.json().catch(() => null);
        if (!json || !json.ok) { alert('Failed to create pin: ' + (json && json.error ? json.error : 'unknown')); setDropMode(false); return; }
        localPins.push({ id: json.pin_id, pin_number: json.pin_number, x: x, y: y, status: 'open',
                         creator: json.creator || 'You', created_at: json.created_at,
                         resolver: null, resolved_at: null, comments: 1 });
        setDropMode(false);
        activePinId = json.pin_id;
        renderPins();
        openPin(json.pin_id);
    });

    function openPin(id) {
        const p = localPins.find(p => p.id === id);
        if (!p) return;
        activePinId = id;
        renderPins();
        sheetTitle.textContent = 'Pin #' + p.pin_number + (p.status === 'resolved' ? ' · Resolved' : ' · Open');
        sheetMeta.innerHTML = 'Created by <strong>' + escapeHtml(p.creator) + '</strong> · ' + escapeHtml(new Date(p.created_at).toLocaleString())
            + (p.status === 'resolved' && p.resolver ? '<br>Resolved by <strong>' + escapeHtml(p.resolver) + '</strong> · ' + escapeHtml(new Date(p.resolved_at).toLocaleString()) : '');
        sheetActions.innerHTML = '';
        if (CAN_RESOLVE) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-sm ' + (p.status === 'resolved' ? 'btn-secondary' : 'btn-success');
            btn.textContent = p.status === 'resolved' ? 'Reopen' : 'Mark Resolved';
            btn.addEventListener('click', () => toggleResolve(id, p.status));
            sheetActions.appendChild(btn);
        }
        commentsEl.innerHTML = '<div class="text-muted" style="font-size:12px;padding:8px 0">Loading…</div>';
        sheet.dataset.open = '1';
        sheet.hidden = false;
        loadComments(id);
    }

    function closeSheet() {
        sheet.dataset.open = '0';
        activePinId = null;
        renderPins();
        setTimeout(() => { if (sheet.dataset.open !== '1') sheet.hidden = true; }, 240);
    }
    if (closeBtn) closeBtn.addEventListener('click', closeSheet);

    async function loadComments(id) {
        const res = await fetch('index.php?page=audit_annotation_thread&pin=' + id, { headers: { 'Accept': 'application/json' } });
        const json = await res.json().catch(() => null);
        if (!json || !json.ok) { commentsEl.innerHTML = '<div class="text-muted">Failed to load.</div>'; return; }
        if (!json.comments.length) { commentsEl.innerHTML = '<div class="text-muted" style="font-size:12px;padding:8px 0">No comments yet.</div>'; return; }
        commentsEl.innerHTML = json.comments.map(c =>
            '<div class="ann-comment">' +
                '<span class="ann-comment-author">' + escapeHtml(c.full_name || c.employee_code) + '</span>' +
                '<span class="ann-comment-time">' + escapeHtml(new Date(c.created_at).toLocaleString()) + '</span>' +
                '<div class="ann-comment-text">' + escapeHtml(c.comment_text) + '</div>' +
            '</div>').join('');
        commentsEl.scrollTop = commentsEl.scrollHeight;
    }

    if (commentForm) {
        commentForm.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            if (!activePinId) return;
            const text = (commentInput.value || '').trim();
            if (!text) return;
            const fd = new FormData();
            fd.append('action', 'add_audit_annotation_comment');
            fd.append('pin_id', activePinId);
            fd.append('comment_text', text);
            const res = await fetch('index.php', { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
            const json = await res.json().catch(() => null);
            if (!json || !json.ok) { alert('Failed: ' + (json && json.error ? json.error : 'unknown')); return; }
            commentInput.value = '';
            const p = localPins.find(p => p.id === activePinId);
            if (p) p.comments = (p.comments || 0) + 1;
            renderPins();
            loadComments(activePinId);
        });
    }

    async function toggleResolve(id, currentStatus) {
        const action = currentStatus === 'resolved' ? 'reopen_audit_annotation' : 'resolve_audit_annotation';
        const fd = new FormData();
        fd.append('action', action);
        fd.append('pin_id', id);
        const res = await fetch('index.php', { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
        const json = await res.json().catch(() => null);
        if (!json || !json.ok) { alert('Failed: ' + (json && json.error ? json.error : 'unknown')); return; }
        const p = localPins.find(p => p.id === id);
        if (p) {
            p.status      = json.status;
            p.resolver    = json.resolver_name || null;
            p.resolved_at = json.resolved_at || null;
        }
        renderPins();
        openPin(id);
    }

    renderPins();
    if (SELECTED_PIN_ID) openPin(SELECTED_PIN_ID);
})();
</script>
<?php endif; /* isImage */ ?>
<?php
}

// Streams the audit attachment file. Same path as downloadAuditAttachment
// but routed through here so the viewer can fetch via a clean URL that
// also enforces the audit-row permission gate (rather than reusing the
// download endpoint and confusing the page action map).
function pageAuditAnnotationServe(): void {
    if (!isLoggedIn()) { http_response_code(403); echo 'Forbidden'; return; }
    $attId = (int)($_GET['audit_att'] ?? 0);
    $att   = auditAnnAttachment($attId);
    if (!$att) { http_response_code(404); echo 'Not found'; return; }
    $auditRow = auditAnnAuditForAttachment($attId);
    if (!$auditRow || !auditCanViewRow($auditRow)) { http_response_code(403); echo 'Forbidden'; return; }
    $path = auditAttachmentPath((int)$auditRow['id'], $auditRow, (string)$att['stored_name']);
    if (!$path) { http_response_code(404); echo 'File missing'; return; }
    header('Content-Type: ' . ($att['mime_type'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=600');
    header('X-Content-Type-Options: nosniff');
    if (!empty($_GET['dl'])) {
        $name = preg_replace('/[\r\n"]/', '', (string)$att['filename']);
        header('Content-Disposition: attachment; filename="' . $name . '"');
    }
    readfile($path);
}

// JSON: pin's comments. Used by the viewer's bottom sheet.
function pageAuditAnnotationThread(): void {
    header('Content-Type: application/json');
    if (!isLoggedIn()) { http_response_code(403); echo '{"ok":false,"error":"forbidden"}'; return; }
    $pinId = (int)($_GET['pin'] ?? 0);
    $ctx   = auditAnnPinContext($pinId);
    if (!$ctx) { http_response_code(404); echo '{"ok":false,"error":"not found"}'; return; }
    $auditRow = auditGetById((int)$ctx['audit_id']);
    if (!$auditRow || !auditCanViewRow($auditRow)) { http_response_code(403); echo '{"ok":false,"error":"forbidden"}'; return; }
    echo json_encode(['ok' => true, 'comments' => auditAnnCommentsForPin($pinId)]);
}

// ── POST handlers (JSON for AJAX) ─────────────────────
function auditAnnJsonOk(array $extra = []): void {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['ok' => true], $extra));
    exit;
}
function auditAnnJsonFail(string $msg, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function doCreateAuditAnnotation(): void {
    $attId   = (int)($_POST['audit_att']    ?? 0);
    $x       = (float)($_POST['x_percent']  ?? -1);
    $y       = (float)($_POST['y_percent']  ?? -1);
    $comment = trim($_POST['comment_text']  ?? '');
    if ($attId < 1 || $x < 0 || $x > 100 || $y < 0 || $y > 100) auditAnnJsonFail('bad coordinates');
    if ($comment === '') auditAnnJsonFail('comment required');

    $auditRow = auditAnnAuditForAttachment($attId);
    if (!$auditRow) auditAnnJsonFail('attachment not found', 404);
    if (!auditCanAnnotate($auditRow)) auditAnnJsonFail('forbidden', 403);

    $db = getDb();
    try {
        $db->beginTransaction();
        // Compute next pin_number — race-protected by the UNIQUE KEY on
        // (attachment_id, pin_number). Two concurrent creates will collide
        // on the index; the loser surfaces via the catch.
        $maxSt = $db->prepare('SELECT COALESCE(MAX(pin_number), 0) FROM audit_image_pins WHERE attachment_id = ?');
        $maxSt->execute([$attId]);
        $next = (int)$maxSt->fetchColumn() + 1;
        $db->prepare(
            'INSERT INTO audit_image_pins
                (attachment_id, pin_number, x_percent, y_percent, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$attId, $next, $x, $y, 'open', myCode()]);
        $pinId = (int)$db->lastInsertId();
        $db->prepare(
            'INSERT INTO audit_image_pin_comments (pin_id, employee_code, comment_text) VALUES (?, ?, ?)'
        )->execute([$pinId, myCode(), $comment]);
        $db->commit();

        $emp = $db->prepare('SELECT full_name FROM employees WHERE employee_code = ?');
        $emp->execute([myCode()]);
        $creator = $emp->fetchColumn() ?: myCode();

        auditAnnJsonOk([
            'pin_id'     => $pinId,
            'pin_number' => $next,
            'creator'    => $creator,
            'created_at' => date('Y-m-d\TH:i:s'),
        ]);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        auditAnnJsonFail($e->getMessage(), 500);
    }
}

function doAddAuditAnnotationComment(): void {
    $pinId   = (int)($_POST['pin_id'] ?? 0);
    $comment = trim($_POST['comment_text'] ?? '');
    if ($pinId < 1)      auditAnnJsonFail('bad pin');
    if ($comment === '') auditAnnJsonFail('comment required');

    $ctx = auditAnnPinContext($pinId);
    if (!$ctx) auditAnnJsonFail('not found', 404);
    $auditRow = auditGetById((int)$ctx['audit_id']);
    if (!$auditRow || !auditCanAnnotate($auditRow)) auditAnnJsonFail('forbidden', 403);

    try {
        getDb()->prepare(
            'INSERT INTO audit_image_pin_comments (pin_id, employee_code, comment_text) VALUES (?, ?, ?)'
        )->execute([$pinId, myCode(), $comment]);
        auditAnnJsonOk();
    } catch (Exception $e) { auditAnnJsonFail($e->getMessage(), 500); }
}

function doResolveAuditAnnotation(): void {
    $pinId = (int)($_POST['pin_id'] ?? 0);
    if ($pinId < 1) auditAnnJsonFail('bad pin');
    $ctx = auditAnnPinContext($pinId);
    if (!$ctx) auditAnnJsonFail('not found', 404);
    // Resolve is gated by the dedicated txn_audit_annotation_resolve role,
    // not by the general workflow-actor gate. Recipients without this role
    // can still address pins by commenting.
    if (!auditCanResolveAnnotation()) auditAnnJsonFail('forbidden', 403);
    $auditRow = auditGetById((int)$ctx['audit_id']);
    if (!$auditRow) auditAnnJsonFail('not found', 404);
    try {
        getDb()->prepare(
            "UPDATE audit_image_pins
             SET status='resolved', resolved_by=?, resolved_at=NOW()
             WHERE id=? AND status='open'"
        )->execute([myCode(), $pinId]);
        $emp = getDb()->prepare('SELECT full_name FROM employees WHERE employee_code = ?');
        $emp->execute([myCode()]);
        $name = $emp->fetchColumn() ?: myCode();
        auditAnnJsonOk(['status' => 'resolved', 'resolver_name' => $name, 'resolved_at' => date('Y-m-d\TH:i:s')]);
    } catch (Exception $e) { auditAnnJsonFail($e->getMessage(), 500); }
}

function doReopenAuditAnnotation(): void {
    $pinId = (int)($_POST['pin_id'] ?? 0);
    if ($pinId < 1) auditAnnJsonFail('bad pin');
    $ctx = auditAnnPinContext($pinId);
    if (!$ctx) auditAnnJsonFail('not found', 404);
    if (!auditCanResolveAnnotation()) auditAnnJsonFail('forbidden', 403);
    $auditRow = auditGetById((int)$ctx['audit_id']);
    if (!$auditRow) auditAnnJsonFail('not found', 404);
    try {
        getDb()->prepare(
            "UPDATE audit_image_pins
             SET status='open', resolved_by=NULL, resolved_at=NULL
             WHERE id=? AND status='resolved'"
        )->execute([$pinId]);
        auditAnnJsonOk(['status' => 'open', 'resolver_name' => null, 'resolved_at' => null]);
    } catch (Exception $e) { auditAnnJsonFail($e->getMessage(), 500); }
}
