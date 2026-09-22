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
//
// Keyed by what the file IS, never by the name it arrived with — a phone
// or chat app will happily hand over a PNG called ".jpeg", and refusing
// that is refusing a photo someone meant to share. The value is the
// extension it gets stored under, so that can never be one the uploader
// chose either.
define('EVENT_PHOTO_MIME_EXT', [
    'image/jpeg'  => 'jpg',
    'image/pjpeg' => 'jpg',
    'image/png'   => 'png',
    'image/x-png' => 'png',
    'image/gif'   => 'gif',
    'image/webp'  => 'webp',
    'image/heic'  => 'heic',
    'image/heif'  => 'heic',
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
// Does this file carry a HEIC/HEIF brand in its ISO-BMFF header? Used
// only when the magic database shrugs and calls it octet-stream: bytes
// 4..8 are "ftyp" and the brand that follows says which flavour.
function epLooksLikeHeic(string $path): bool {
    $fh = @fopen($path, 'rb');
    if (!$fh) return false;
    $head = (string)fread($fh, 16);
    fclose($fh);
    if (strlen($head) < 12 || substr($head, 4, 4) !== 'ftyp') return false;
    $brand = mb_strtolower(substr($head, 8, 4));
    return in_array($brand, ['heic', 'heix', 'heif', 'hevc', 'hevx', 'mif1', 'msf1'], true);
}

// Turn a stored HEIC into a JPEG beside it when this PHP can read HEIC at
// all, and return what the row should point at. Imagick only manages it
// when built against libheif, which plenty of shared hosts are not — so
// failure is expected and silent: the photo stays HEIC and the gallery
// falls back to its download card, exactly as before.
//
// Returns [storedName, mimeType].
function epConvertHeicIfPossible(string $dir, string $stored, string $mime): array {
    if ($mime !== 'image/heic' && $mime !== 'image/heif') return [$stored, $mime];
    if (!class_exists('Imagick')) return [$stored, $mime];
    $jpg = preg_replace('/\.[^.]+$/', '', $stored) . '.jpg';
    try {
        $im = new Imagick($dir . $stored);
        $im->setImageFormat('jpeg');
        $im->setImageCompressionQuality(85);
        // Phone photos carry their rotation in EXIF; baking it in now means
        // the gallery does not have to know about orientation at all.
        if (method_exists($im, 'autoOrient')) $im->autoOrient();
        $ok = $im->writeImage($dir . $jpg);
        $im->clear();
        if (!$ok || !file_exists($dir . $jpg)) return [$stored, $mime];
    } catch (Throwable $e) {
        // No HEIC delegate, or a file Imagick cannot read — keep the original.
        @unlink($dir . $jpg);
        return [$stored, $mime];
    }
    @unlink($dir . $stored);
    return [$jpg, 'image/jpeg'];
}

// Why a file this gallery cannot take was turned away. HEIC is accepted
// here (phones produce it), so the only reasons left are genuinely not
// pictures.
function epUnsupportedTypeReason(string $mime): string {
    $allowed = 'send a JPG, PNG, GIF, WebP or HEIC';
    if ($mime === '' || $mime === 'application/octet-stream') {
        return 'not a readable image — it may have been damaged in transfer; ' . $allowed;
    }
    if (stripos($mime, 'video/') === 0) {
        return 'a video, and only photos can be uploaded here — ' . $allowed;
    }
    if ($mime === 'application/pdf') {
        return 'a PDF, and only photos can be uploaded here — ' . $allowed;
    }
    return 'a ' . $mime . ' file, which is not a photo — ' . $allowed;
}

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

    // Nothing is skipped in silence: a photo that does not arrive is the
    // whole reason someone opened this page, so each one that fails says
    // which file it was and what to do about it.
    $rejected = [];
    for ($i = 0; $i < $n; $i++) {
        $orig = basename((string)$_FILES['photos']['name'][$i]);
        if ($orig === '') $orig = 'photo';
        $err = $_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK) {
            if ($err !== UPLOAD_ERR_NO_FILE) {
                $rejected[] = ['name' => $orig, 'reason' => uploadErrorReason((int)$err)];
                $skipped++;
            }
            continue;
        }
        $mime = (string)($finfo->file($_FILES['photos']['tmp_name'][$i]) ?: '');
        // iOS sometimes ships HEIC that older magic databases can only call
        // a blob of bytes. The HEIC brand sits in the file's own header, so
        // read that rather than trusting the name.
        if ($mime === 'application/octet-stream' && epLooksLikeHeic($_FILES['photos']['tmp_name'][$i])) {
            $mime = 'image/heic';
        }
        $ext = EVENT_PHOTO_MIME_EXT[$mime] ?? null;
        if ($ext === null) {
            $rejected[] = ['name' => $orig, 'reason' => epUnsupportedTypeReason($mime)];
            $skipped++; continue;
        }
        if ($_FILES['photos']['size'][$i] > EVENT_PHOTO_MAX_FILE_SIZE) {
            $rejected[] = ['name' => $orig, 'reason' =>
                formatBytes((int)$_FILES['photos']['size'][$i]) . ' — over the '
                . formatBytes(EVENT_PHOTO_MAX_FILE_SIZE) . ' limit for one photo'];
            $skipped++; continue;
        }
        $orig   = nameWithExt($orig, $ext);
        $stored = uniqid('ev_', true) . '.' . $ext;
        if (!move_uploaded_file($_FILES['photos']['tmp_name'][$i], $dir . $stored)) {
            $rejected[] = ['name' => $orig, 'reason' => 'the server could not store it — please try again'];
            $skipped++; continue;
        }
        // A HEIC that reached us unconverted (the browser could not decode
        // it) shows as a download card, not a picture. Convert it here when
        // the platform can, so the gallery has something to paint.
        [$stored, $mime] = epConvertHeicIfPossible($dir, $stored, $mime);
        $st->execute([
            ($caption !== '' ? mb_substr($caption, 0, 200) : null),
            $eventDate, $orig, $stored, $mime, (int)filesize($dir . $stored), $me,
        ]);
        $saved++;
    }

    $note = rejectedFilesNote($rejected);
    if ($saved > 0) {
        flash($note !== '' ? 'error' : 'success', $saved . ' photo(s) uploaded.' . $note);
    } else {
        flash('error', $note !== '' ? ltrim($note) : 'Nothing uploaded — pick at least one photo.');
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
.ep-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:9999;align-items:center;justify-content:center;padding:16px}
.ep-modal.active{display:flex}
.ep-modal-content{background:var(--surface);border-radius:10px;padding:16px;max-width:min(560px,92vw);max-height:90vh;overflow:auto;position:relative;border:1px solid var(--border)}
.ep-modal-close{position:absolute;top:8px;right:14px;font-size:24px;color:var(--muted);cursor:pointer;z-index:1}
.ep-modal-close:hover{color:var(--text)}
.ep-modal-title{font-size:14px;margin-bottom:10px;padding-right:24px;color:var(--text)}
/* Lightbox: no surface panel around the photo. The shared 560px-wide box
   squeezed a tall photo into a narrow column, so on a short landscape
   screen the image ran past the viewport and could only be scrolled to.
   Here the photo sizes itself against BOTH viewport axes and the chrome
   floats over the backdrop, so it always fits whatever the screen shape. */
.ep-lightbox .ep-modal-content{background:none;border:0;border-radius:0;padding:0;max-width:none;max-height:none;overflow:visible;display:flex;flex-direction:column;align-items:center;gap:8px}
.ep-lightbox .ep-modal-close{position:fixed;top:6px;right:14px;font-size:30px;line-height:1;color:#e5e7eb;text-shadow:0 1px 4px rgba(0,0,0,.9)}
.ep-lightbox .ep-modal-close:hover{color:#fff}
.ep-lightbox .ep-modal-title{margin:0;padding:0 32px;max-width:96vw;text-align:center;color:#e5e7eb;text-shadow:0 1px 4px rgba(0,0,0,.9)}
.ep-lightbox-img{display:block;width:auto;height:auto;max-width:96vw;max-height:calc(100vh - 88px);max-height:calc(100dvh - 88px);border-radius:6px;object-fit:contain}
/* Short/landscape viewports (phone turned sideways, laptop in a small
   window): claw back the vertical space the caption row costs. */
@media (max-height:640px){
    .ep-modal{padding:8px}
    .ep-lightbox .ep-modal-title{font-size:12px}
    .ep-lightbox-img{max-height:calc(100vh - 56px);max-height:calc(100dvh - 56px)}
}
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
        <form method="POST" enctype="multipart/form-data" id="epUploadForm">
            <input type="hidden" name="action" value="upload_event_photos">
            <div style="display:flex;flex-direction:column;gap:12px">
                <div>
                    <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Photos <span class="required">*</span></label>
                    <input type="file" name="photos[]" class="form-control ep-files" accept="image/*" multiple required style="width:100%">
                    <small class="text-muted">JPG, PNG, GIF, WEBP or HEIC · up to 10 MB each · pick several at once
                    · big photos are shrunk and iPhone HEIC is converted before upload</small>
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
<?php renderPhotoCompressJs('epUploadForm', '.ep-files', false); ?>
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
<div id="epLightbox" class="ep-modal ep-lightbox" onclick="epClose(event)">
    <div class="ep-modal-content">
        <span class="ep-modal-close" onclick="epClose()">&times;</span>
        <h4 id="epLightboxCap" class="ep-modal-title"></h4>
        <img id="epLightboxImg" class="ep-lightbox-img" src="" alt="">
    </div>
</div>
<script>
function epOpen(id, cap) {
    var capEl = document.getElementById('epLightboxCap');
    document.getElementById('epLightboxImg').src = '?page=event_photo&id=' + id;
    capEl.textContent = cap || '';
    // An empty caption would still eat a row of height on a short screen.
    capEl.style.display = cap ? '' : 'none';
    document.getElementById('epLightbox').classList.add('active');
}
function epClose(e) {
    // The content box is transparent now, so anything but the photo itself
    // counts as clicking the backdrop.
    if (e && e.target && e.target.id === 'epLightboxImg') return;
    document.getElementById('epLightbox').classList.remove('active');
    document.getElementById('epLightboxImg').src = '';
}
</script>
<?php endif;
}
