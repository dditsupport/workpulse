<?php
// =========================================================
// Event Photos — company photo wall
//
// Viewing is open to every logged-in user: no txn_* role gates the page.
// Uploading needs txn_event_photo_upload; deleting is superadmin-only.
//
// Files live under uploads/event_photos/{YYYY-MM}/ (month bucket, same
// idiom as the checklist attachments) and are only ever served through
// ?page=event_photo&id=N, never by direct URL.
// =========================================================

define('EVENT_PHOTO_UPLOAD_DIR', __DIR__ . '/../uploads/event_photos/');
define('EVENT_PHOTO_MAX_FILE_SIZE', 10 * 1024 * 1024);
define('EVENT_PHOTO_PER_PAGE', 60);
// SVG is deliberately absent: it can carry script and we serve inline.
define('EVENT_PHOTO_ALLOWED_MIME', [
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
    'gif'  => ['image/gif'],
    'webp' => ['image/webp'],
    'heic' => ['image/heic', 'image/heif', 'application/octet-stream'],
    'heif' => ['image/heif', 'image/heic', 'application/octet-stream'],
]);
// What a browser can actually paint. HEIC uploads are kept (phones produce
// them) but listed as a download card rather than a broken <img>.
define('EVENT_PHOTO_RENDERABLE', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

// May the current user add photos? Viewing needs nothing; deleting is
// superadmin-only and deliberately has no flag of its own.
function canUploadEventPhotos(): bool {
    return isSuperadmin() || hasTxn('event_photo_upload');
}

function eventPhotoDir(string $ymd): string {
    $month = preg_match('/^(\d{4}-\d{2})/', $ymd, $m) ? $m[1] : date('Y-m');
    return EVENT_PHOTO_UPLOAD_DIR . $month . '/';
}

function eventPhotoPath(array $row): ?string {
    $p = eventPhotoDir((string)$row['bucket_date']) . $row['stored_name'];
    return file_exists($p) ? $p : null;
}

function eventPhotoIsRenderable(string $mime): bool {
    return in_array($mime, EVENT_PHOTO_RENDERABLE, true);
}

// ── Upload (txn_event_photo_upload) ───────────────────────
function doUploadEventPhotos(): void {
    $back = 'index.php?page=event_photos';
    if (!canUploadEventPhotos()) {
        flash('error', 'You do not have permission to upload photos.');
        header("Location: {$back}"); exit;
    }
    $caption   = trim($_POST['caption'] ?? '');
    $eventDate = trim($_POST['event_date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) $eventDate = date('Y-m-d');

    if (empty($_FILES['photos']['name']) || !is_array($_FILES['photos']['name'])) {
        flash('error', 'Pick at least one photo to upload.');
        header("Location: {$back}"); exit;
    }

    $dir = eventPhotoDir($eventDate);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $db = getDb();
    $st = $db->prepare(
        'INSERT INTO event_photos (caption, event_date, filename, stored_name, mime_type, file_size, uploaded_by)
         VALUES (?,?,?,?,?,?,?)');
    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    $me      = myCode();
    $saved   = 0; $skipped = 0;
    $n       = count($_FILES['photos']['name']);

    for ($i = 0; $i < $n; $i++) {
        if (($_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { $skipped++; continue; }
        if ($_FILES['photos']['size'][$i] > EVENT_PHOTO_MAX_FILE_SIZE) { $skipped++; continue; }
        $orig = basename((string)$_FILES['photos']['name'][$i]);
        $ext  = mb_strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $ok   = EVENT_PHOTO_ALLOWED_MIME[$ext] ?? null;
        if (!$ok) { $skipped++; continue; }
        $mime = $finfo->file($_FILES['photos']['tmp_name'][$i]) ?: 'application/octet-stream';
        if (!in_array($mime, $ok, true)) { $skipped++; continue; }
        // Trust the sniffed extension, not the sent one, for octet-stream HEIC.
        if ($mime === 'application/octet-stream') $mime = 'image/heic';

        $stored = uniqid('ev_', true) . '.' . $ext;
        if (!move_uploaded_file($_FILES['photos']['tmp_name'][$i], $dir . $stored)) { $skipped++; continue; }
        $st->execute([
            ($caption !== '' ? mb_substr($caption, 0, 200) : null),
            $eventDate, $orig, $stored, $mime, (int)$_FILES['photos']['size'][$i], $me,
        ]);
        $saved++;
    }

    if ($saved > 0) {
        flash('success', $saved . ' photo(s) uploaded.' . ($skipped ? " {$skipped} skipped (too large or not an image)." : ''));
    } else {
        flash('error', 'Nothing uploaded — files must be images under 10 MB.');
    }
    header("Location: {$back}"); exit;
}

// ── Delete (superadmin only) ──────────────────────────────
function doDeleteEventPhoto(): void {
    $back = 'index.php?page=event_photos';
    if (!isSuperadmin()) {
        flash('error', 'Only a superadmin can delete photos.');
        header("Location: {$back}"); exit;
    }
    $id = (int)($_POST['photo_id'] ?? 0);
    $db = getDb();
    $st = $db->prepare('SELECT id, stored_name, event_date AS bucket_date FROM event_photos WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        flash('error', 'Photo not found.');
        header("Location: {$back}"); exit;
    }
    $path = eventPhotoPath($row);
    if ($path) @unlink($path);
    $db->prepare('DELETE FROM event_photos WHERE id = ?')->execute([$id]);
    flash('success', 'Photo deleted.');
    header("Location: {$back}"); exit;
}

// ── Serve one image (any logged-in user) ──────────────────
function serveEventPhoto(): void {
    $id = (int)($_GET['id'] ?? 0);
    $st = getDb()->prepare(
        'SELECT filename, stored_name, mime_type, file_size, event_date AS bucket_date FROM event_photos WHERE id = ?');
    try { $st->execute([$id]); } catch (Exception $e) { http_response_code(404); echo 'Not found'; return; }
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo 'Not found'; return; }
    $path = eventPhotoPath($row);
    if (!$path) { http_response_code(404); echo 'File missing'; return; }

    $disp = eventPhotoIsRenderable((string)$row['mime_type']) ? 'inline' : 'attachment';
    header('Content-Type: ' . $row['mime_type']);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . $disp . '; filename="' . str_replace('"', '', $row['filename']) . '"');
    header('Content-Length: ' . (int)filesize($path));
    header('Cache-Control: private, max-age=86400');
    readfile($path);
    exit;
}

// ── Gallery page ──────────────────────────────────────────
function pageEventPhotos(): void {
    $db = getDb();
    $q  = trim($_GET['q'] ?? '');
    $pg = max(1, (int)($_GET['p'] ?? 1));
    $off = ($pg - 1) * EVENT_PHOTO_PER_PAGE;

    $where = []; $params = [];
    if ($q !== '') {
        $where[] = '(p.caption LIKE ? OR e.full_name LIKE ?)';
        $params[] = "%{$q}%"; $params[] = "%{$q}%";
    }
    $sql = 'FROM event_photos p LEFT JOIN employees e ON e.employee_code = p.uploaded_by';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);

    try {
        $cs = $db->prepare('SELECT COUNT(*) ' . $sql);
        $cs->execute($params);
        $total = (int)$cs->fetchColumn();

        $st = $db->prepare(
            'SELECT p.id, p.caption, p.event_date, p.event_date AS bucket_date, p.filename,
                    p.stored_name, p.mime_type, p.file_size, p.uploaded_by, p.uploaded_at,
                    e.full_name AS uploader_name ' . $sql .
            ' ORDER BY p.event_date DESC, p.id DESC LIMIT ' . (int)EVENT_PHOTO_PER_PAGE . ' OFFSET ' . (int)$off);
        $st->execute($params);
        $photos = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        echo '<div class="alert alert-error">Event photos table not found. Run migration_2026_07_16_event_photos.sql.</div>';
        return;
    }

    $pages = max(1, (int)ceil($total / EVENT_PHOTO_PER_PAGE));
    // Group into month headings, preserving the query's ordering.
    $byMonth = [];
    foreach ($photos as $p) { $byMonth[date('F Y', strtotime((string)$p['event_date']))][] = $p; }
?>
<style>
.ep-modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.7);z-index:9999;align-items:center;justify-content:center}
.ep-modal.active{display:flex}
.ep-modal-content{background:var(--surface);border-radius:10px;padding:16px;max-width:min(560px,92vw);max-height:90vh;overflow:auto;position:relative;border:1px solid var(--border)}
.ep-modal-close{position:absolute;top:8px;right:14px;font-size:24px;color:var(--muted);cursor:pointer;z-index:1}
.ep-modal-close:hover{color:var(--text)}
.ep-modal-title{font-size:14px;margin-bottom:10px;padding-right:24px;color:var(--text)}
.ep-lightbox-img{display:block;max-width:92vw;max-height:80vh;border-radius:6px;object-fit:contain}
</style>
<script>
// One Escape handler for every ep-modal on the page. Lives here rather than
// beside either modal: both are rendered conditionally, so a handler next to
// one of them would go missing (e.g. no Escape at all on an empty gallery).
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('.ep-modal.active').forEach(function (m) { m.classList.remove('active'); });
});
</script>

<div class="page-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <h2 style="margin:0">Event Photos</h2>
    <?php if (canUploadEventPhotos()): ?>
    <button type="button" class="btn btn-primary btn-sm" onclick="epOpenUpload()">+ Upload</button>
    <?php endif; ?>
</div>

<form class="rpt-filter" method="GET" style="margin-bottom:14px">
    <input type="hidden" name="page" value="event_photos">
    <span class="input-clear-wrap" style="flex:1 1 auto;min-width:200px">
        <input type="text" name="q" class="form-control" placeholder="Search caption or uploader..." value="<?= h($q) ?>">
        <button type="button" class="input-clear-btn" aria-label="Clear search" tabindex="-1">&times;</button>
    </span>
    <button class="btn btn-primary" type="submit">Search</button>
</form>

<?php if (canUploadEventPhotos()): ?>
<!-- Upload modal -->
<div id="epUploadModal" class="ep-modal" onclick="epCloseUpload(event)">
    <div class="ep-modal-content">
        <span class="ep-modal-close" onclick="epCloseUpload()">&times;</span>
        <h4 class="ep-modal-title">Upload photos</h4>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_event_photos">
            <div style="display:flex;flex-direction:column;gap:12px">
                <div>
                    <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Photos <span class="required">*</span></label>
                    <input type="file" name="photos[]" class="form-control" accept="image/*" multiple required style="width:100%">
                    <small class="text-muted">JPG, PNG, GIF, WEBP or HEIC · up to 10 MB each · pick several at once</small>
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Event date</label>
                    <input type="date" name="event_date" class="form-control" style="width:100%" value="<?= h(date('Y-m-d')) ?>">
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Caption</label>
                    <input type="text" name="caption" class="form-control" maxlength="200" style="width:100%"
                           placeholder="e.g. Diwali celebration at the factory">
                </div>
                <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:4px">
                    <button type="button" class="btn btn-secondary" onclick="epCloseUpload()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Upload</button>
                </div>
            </div>
        </form>
    </div>
</div>
<script>
function epOpenUpload(){document.getElementById('epUploadModal').classList.add('active');}
function epCloseUpload(e){
    if(!e||e.target===document.getElementById('epUploadModal')||e.target.classList.contains('ep-modal-close'))
        document.getElementById('epUploadModal').classList.remove('active');
}
</script>
<?php endif; ?>

<?php if (!$photos): ?>
<div class="alert alert-info"><?= $q !== '' ? 'No photos match that search.' : 'No photos yet — be the first to upload one.' ?></div>
<?php else: ?>
<?php foreach ($byMonth as $month => $rows): ?>
<div class="table-wrap" style="padding:16px;margin-bottom:14px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <strong><?= h($month) ?></strong>
        <span class="text-muted" style="font-size:12px"><?= count($rows) ?> photo(s)</span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px">
        <?php foreach ($rows as $p): $renderable = eventPhotoIsRenderable((string)$p['mime_type']); ?>
        <div style="border:1px solid var(--border);border-radius:8px;overflow:hidden;background:var(--bg)">
            <?php if ($renderable): ?>
            <a href="#" onclick="epOpen(<?= (int)$p['id'] ?>,<?= h(json_encode($p['caption'] ?? '')) ?>);return false">
                <img src="?page=event_photo&id=<?= (int)$p['id'] ?>" alt="<?= h($p['caption'] ?? $p['filename']) ?>"
                     loading="lazy" style="width:100%;height:150px;object-fit:cover;display:block">
            </a>
            <?php else: ?>
            <a href="?page=event_photo&id=<?= (int)$p['id'] ?>"
               style="display:flex;align-items:center;justify-content:center;height:150px;text-decoration:none;color:var(--text-muted);font-size:12px;text-align:center;padding:8px">
                <?= h(mb_strtoupper(pathinfo((string)$p['filename'], PATHINFO_EXTENSION))) ?> image<br>tap to download
            </a>
            <?php endif; ?>
            <div style="padding:8px">
                <?php if (!empty($p['caption'])): ?>
                <div style="font-size:12px;margin-bottom:4px;word-break:break-word"><?= h($p['caption']) ?></div>
                <?php endif; ?>
                <div class="text-muted" style="font-size:11px">
                    <?= h($p['uploader_name'] ?: $p['uploaded_by']) ?> · <?= date('d M Y', strtotime((string)$p['event_date'])) ?>
                </div>
                <?php if (isSuperadmin()): ?>
                <form method="POST" style="margin-top:6px"
                      onsubmit="return confirm('Delete this photo permanently?')">
                    <input type="hidden" name="action" value="delete_event_photo">
                    <input type="hidden" name="photo_id" value="<?= (int)$p['id'] ?>">
                    <button type="submit" class="btn btn-ghost btn-sm" style="width:100%">Delete</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<?php if ($pages > 1): ?>
<div style="display:flex;gap:8px;align-items:center;justify-content:center;margin-bottom:14px">
    <?php $qs = $q !== '' ? '&q=' . urlencode($q) : ''; ?>
    <a class="btn btn-ghost btn-sm" style="<?= $pg <= 1 ? 'visibility:hidden' : '' ?>"
       href="?page=event_photos&p=<?= $pg - 1 ?><?= $qs ?>">&lsaquo; Prev</a>
    <span class="text-muted" style="font-size:12px">Page <?= $pg ?> of <?= $pages ?></span>
    <a class="btn btn-ghost btn-sm" style="<?= $pg >= $pages ? 'visibility:hidden' : '' ?>"
       href="?page=event_photos&p=<?= $pg + 1 ?><?= $qs ?>">Next &rsaquo;</a>
</div>
<?php endif; ?>
<div class="table-count"><?= $total ?> photo(s)</div>

<!-- Lightbox -->
<div id="epLightbox" class="ep-modal" onclick="epClose(event)">
    <div class="ep-modal-content">
        <span class="ep-modal-close" onclick="epClose()">&times;</span>
        <h4 id="epLightboxCap" class="ep-modal-title"></h4>
        <img id="epLightboxImg" class="ep-lightbox-img" src="" alt="">
    </div>
</div>
<script>
function epOpen(id, cap) {
    document.getElementById('epLightboxImg').src = '?page=event_photo&id=' + id;
    document.getElementById('epLightboxCap').textContent = cap || '';
    document.getElementById('epLightbox').classList.add('active');
}
function epClose(e) {
    if (e && e.target !== document.getElementById('epLightbox')
          && !e.target.classList.contains('ep-modal-close')) return;
    document.getElementById('epLightbox').classList.remove('active');
    document.getElementById('epLightboxImg').src = '';
}
</script>
<?php endif;
}
