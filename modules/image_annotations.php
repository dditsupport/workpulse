<?php
// =========================================================
// Image Annotations — ClickUp-style numbered pins on photos.
// Phase 2 of the WorkPulse roadmap.
//
// Schema:    migrations/2026_05_08_phase2_image_annotations.sql
// Storage:   uploads/annotations/YYYY-MM/{stored}.jpg|.png|...
// Mobile-first: tap-to-drop pins, bottom-sheet comment thread,
// camera capture for upload.
// =========================================================

// ── Constants ────────────────────────────────────────────
define('ANN_UPLOAD_DIR',     __DIR__ . '/../uploads/annotations/');
define('ANN_MAX_BYTES',      15 * 1024 * 1024); // 15 MB (phone camera + small PDFs)
// Images get the pin-overlay viewer; everything else is treated as a
// downloadable attachment (gallery shows a file-type icon).
define('ANN_IMAGE_EXT',      ['jpg','jpeg','png','gif','webp','heic','heif']);
define('ANN_IMAGE_MIME',     ['image/jpeg','image/png','image/gif','image/webp','image/heic','image/heif']);
define('ANN_DOC_EXT',        ['pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv']);
define('ANN_DOC_MIME',       [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'text/plain',
    'text/csv',
]);
define('ANN_ALLOWED_EXT',    array_merge(ANN_IMAGE_EXT,  ANN_DOC_EXT));
define('ANN_ALLOWED_MIME',   array_merge(ANN_IMAGE_MIME, ANN_DOC_MIME));

function annIsImage(string $mime): bool {
    return in_array(strtolower($mime), ANN_IMAGE_MIME, true);
}

// ── Permission helpers ───────────────────────────────────
function annCanCreate(): bool {
    return isSuperadmin() || hasTxn('annotation_create');
}
function annCanResolve(): bool {
    return isSuperadmin() || hasTxn('annotation_resolve');
}
// Comment permission: txn_annotation_comment OR the user is assigned
// to that specific location (so a store's own staff can always reply
// even if the global comment txn isn't granted).
function annCanComment(int $locId): bool {
    if (isSuperadmin() || hasTxn('annotation_comment')) return true;
    return $locId > 0 && myLocationId() === $locId;
}
// Anyone who can create/resolve OR is assigned to that location can view.
function annCanView(int $locId): bool {
    if (annCanCreate() || annCanResolve()) return true;
    if (hasTxn('annotation_comment')) return true;
    return $locId > 0 && myLocationId() === $locId;
}
// Locations the current user can see at all.
function annVisibleLocations(): array {
    $all = getActiveLocations();
    if (annCanCreate() || annCanResolve() || hasTxn('annotation_comment')) {
        return $all;
    }
    $myLoc = myLocationId();
    return array_values(array_filter($all, fn($l) => (int)$l['location_id'] === $myLoc));
}

// ── Imagick helpers ──────────────────────────────────────
// All optional. When the imagick PHP extension isn't loaded these
// functions no-op and the pipeline keeps the original file as-is.

function annHasImagick(): bool {
    return extension_loaded('imagick') && class_exists('Imagick');
}

// Filename of a thumbnail that mirrors the original's path:
// 'ann_xyz.jpg'  →  'ann_xyz_thumb.jpg'
function annThumbName(string $stored): string {
    $out = preg_replace('/(\.[^.]+)$/', '_thumb$1', $stored);
    return $out ?: ($stored . '_thumb.jpg');
}

// Normalises a freshly-uploaded image:
//   1. Auto-orient via EXIF (phone photos arrive rotated otherwise)
//   2. Strip metadata (EXIF + GPS — privacy)
//   3. Cap longest edge at 2400 px (storage savings)
//   4. Convert HEIC / HEIF → JPEG so non-Safari browsers can display it
// Returns the (possibly new) stored filename + mime + size.
function annNormalizeUpload(string $bucketDir, string $stored, string $mime, int $origSize): array {
    if (!annHasImagick()) {
        return ['stored' => $stored, 'mime' => $mime, 'size' => $origSize];
    }
    $srcPath = $bucketDir . $stored;
    if (!is_file($srcPath)) {
        return ['stored' => $stored, 'mime' => $mime, 'size' => $origSize];
    }
    try {
        $im = new Imagick($srcPath);

        // 1. Auto-orient
        $orient = $im->getImageOrientation();
        if ($orient && $orient !== Imagick::ORIENTATION_TOPLEFT) {
            switch ($orient) {
                case Imagick::ORIENTATION_BOTTOMRIGHT: $im->rotateImage('#000', 180);  break;
                case Imagick::ORIENTATION_RIGHTTOP:    $im->rotateImage('#000', 90);   break;
                case Imagick::ORIENTATION_LEFTBOTTOM:  $im->rotateImage('#000', -90);  break;
                case Imagick::ORIENTATION_TOPRIGHT:    $im->flopImage();               break;
                case Imagick::ORIENTATION_BOTTOMLEFT:  $im->flipImage();               break;
                case Imagick::ORIENTATION_LEFTTOP:     $im->flopImage(); $im->rotateImage('#000', 90); break;
                case Imagick::ORIENTATION_RIGHTBOTTOM: $im->flopImage(); $im->rotateImage('#000', -90); break;
            }
            $im->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
        }

        // 2. Strip metadata. ICC colour profile is preserved separately so
        //    colours don't shift on wide-gamut sources.
        try { $profile = $im->getImageProfile('icc'); } catch (Exception $e) { $profile = null; }
        $im->stripImage();
        if ($profile) { try { $im->profileImage('icc', $profile); } catch (Exception $e) {} }

        // 3. Cap dimensions
        $w = $im->getImageWidth();
        $h = $im->getImageHeight();
        $MAX = 2400;
        if ($w > $MAX || $h > $MAX) {
            if ($w >= $h) $im->thumbnailImage($MAX, 0);
            else          $im->thumbnailImage(0, $MAX);
        }

        // 4. HEIC/HEIF → JPEG. Re-extension the stored file too so the URL
        //    served to browsers ends in .jpg.
        $isHeic = in_array(strtolower($mime), ['image/heic','image/heif'], true);
        if ($isHeic) {
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(85);
            $newStored = preg_replace('/\.(heic|heif)$/i', '.jpg', $stored);
            if ($newStored === $stored) $newStored = $stored . '.jpg';
            $newPath = $bucketDir . $newStored;
            $im->writeImage($newPath);
            if ($newPath !== $srcPath) @unlink($srcPath);
            $stored = $newStored;
            $mime   = 'image/jpeg';
        } else {
            // In-place rewrite — keeps original format (jpg/png/webp/gif).
            $im->writeImage($srcPath);
        }

        $im->clear(); $im->destroy();
        clearstatcache(true, $bucketDir . $stored);
        $size = filesize($bucketDir . $stored) ?: $origSize;
        return ['stored' => $stored, 'mime' => $mime, 'size' => $size];
    } catch (Exception $e) {
        // Anything imagick-related that fails leaves the original alone.
        return ['stored' => $stored, 'mime' => $mime, 'size' => $origSize];
    }
}

// Generates a 480-px-wide JPEG thumbnail next to the original file.
// Returns true on success. No-op if imagick missing.
function annGenerateThumb(string $bucketDir, string $stored): bool {
    if (!annHasImagick()) return false;
    $srcPath   = $bucketDir . $stored;
    $thumbName = annThumbName($stored);
    $thumbPath = $bucketDir . $thumbName;
    if (!is_file($srcPath)) return false;
    try {
        $im = new Imagick($srcPath);
        $im->thumbnailImage(480, 0);   // 480px wide, height auto
        $im->setImageFormat('jpeg');
        $im->setImageCompressionQuality(80);
        $im->stripImage();
        $im->writeImage($thumbPath);
        $im->clear(); $im->destroy();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// ── Path resolver (month-bucketed, location-foldered) ──
// uploads/annotations/YYYY-MM/{location-slug}/{stored}
function annMonthBucket(string $when): string {
    $ts = strtotime($when);
    return date('Y-m', $ts ?: time());
}
function annLocationSlug(int $locId): string {
    static $cache = [];
    if (isset($cache[$locId])) return $cache[$locId];
    $name = '';
    try {
        $st = getDb()->prepare('SELECT location_name FROM locations WHERE location_id = ?');
        $st->execute([$locId]);
        $name = (string)($st->fetchColumn() ?: '');
    } catch (Exception $e) {}
    $slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($name));
    $slug = trim((string)$slug, '-');
    if ($slug === '') $slug = 'loc-' . $locId;
    return $cache[$locId] = $slug;
}
function annAttachmentPath(string $when, string $stored, int $locId): string {
    return ANN_UPLOAD_DIR . annMonthBucket($when) . '/' . annLocationSlug($locId) . '/' . $stored;
}

// ── Notification (Phase 2 = email; Phase 3 will swap to notify()) ──
function annNotify(int $annotationId, string $event): void {
    if (!function_exists('sendSmtpEmailQuiet')) return;
    try {
        $st = getDb()->prepare(
            "SELECT a.id, a.pin_number, a.status, a.created_by, a.resolved_by,
                    i.id AS image_id, i.location_id, i.image_date, i.original_name,
                    l.location_name, l.contact_email
             FROM   image_annotations a
             JOIN   annotation_images i ON i.id = a.image_id
             JOIN   locations l ON l.location_id = i.location_id
             WHERE  a.id = ?"
        );
        $st->execute([$annotationId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;

        // Recipient set varies by event.
        $recips = [];
        if ($row['contact_email']) $recips[] = $row['contact_email'];

        // Pin creator's email
        $emp = getDb()->prepare('SELECT email FROM employees WHERE employee_code = ?');
        $emp->execute([$row['created_by']]);
        if ($e = $emp->fetchColumn()) $recips[] = $e;

        // Anyone who has commented on this pin
        $cs = getDb()->prepare(
            'SELECT DISTINCT e.email
             FROM annotation_comments c
             JOIN employees e ON e.employee_code = c.employee_code
             WHERE c.annotation_id = ? AND e.email IS NOT NULL AND e.email <> \'\''
        );
        $cs->execute([$annotationId]);
        foreach ($cs->fetchAll(PDO::FETCH_COLUMN) as $em) $recips[] = $em;

        $recips = array_unique(array_filter(array_map('trim', $recips)));
        if (!$recips) return;

        $url = 'index.php?page=annotation_image&id=' . (int)$row['image_id'] . '&pin=' . (int)$row['id'];
        $subjects = [
            'pin_created'   => 'New pin #' . $row['pin_number'] . ' on ' . $row['location_name'] . ' photo',
            'comment_added' => 'New comment on pin #' . $row['pin_number'] . ' (' . $row['location_name'] . ')',
            'resolved'      => 'Pin #' . $row['pin_number'] . ' resolved (' . $row['location_name'] . ')',
            'reopened'      => 'Pin #' . $row['pin_number'] . ' reopened (' . $row['location_name'] . ')',
        ];
        $subject = $subjects[$event] ?? ('Annotation update: pin #' . $row['pin_number']);
        $body = '<p>' . htmlspecialchars($subject) . '</p>'
              . '<p>Image: ' . htmlspecialchars($row['original_name']) . ' &middot; '
              . htmlspecialchars($row['image_date']) . '</p>'
              . '<p><a href="' . htmlspecialchars($url) . '">Open in WorkPulse</a></p>';
        foreach ($recips as $to) sendSmtpEmailQuiet($to, $subject, $body);
    } catch (Exception $e) { /* best-effort */ }
}

// ── DB fetchers ──────────────────────────────────────────
function annLocationListWithCounts(): array {
    $locs = annVisibleLocations();
    if (!$locs) return [];
    $ids = array_map(fn($l) => (int)$l['location_id'], $locs);
    $in  = implode(',', $ids);
    try {
        $st = getDb()->query(
            "SELECT i.location_id,
                    COUNT(DISTINCT i.id) AS image_count,
                    MAX(i.uploaded_at)   AS last_upload,
                    SUM(CASE WHEN a.status='open' THEN 1 ELSE 0 END) AS open_pins
             FROM   annotation_images i
             LEFT JOIN image_annotations a ON a.image_id = i.id
             WHERE  i.deleted_at IS NULL AND i.location_id IN ($in)
             GROUP  BY i.location_id"
        );
        $stats = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $stats[(int)$r['location_id']] = $r;
    } catch (Exception $e) { $stats = []; }
    foreach ($locs as &$l) {
        $s = $stats[(int)$l['location_id']] ?? null;
        $l['image_count'] = $s ? (int)$s['image_count'] : 0;
        $l['open_pins']   = $s ? (int)$s['open_pins']   : 0;
        $l['last_upload'] = $s ? $s['last_upload']      : null;
    }
    unset($l);
    usort($locs, fn($a, $b) => strcasecmp((string)$a['location_name'], (string)$b['location_name']));
    return $locs;
}

function annDatesForLocation(int $locId): array {
    try {
        $st = getDb()->prepare(
            "SELECT image_date,
                    COUNT(*) AS image_count,
                    MAX(uploaded_at) AS last_upload,
                    (SELECT COUNT(*) FROM image_annotations a
                       JOIN annotation_images i2 ON i2.id = a.image_id
                       WHERE i2.location_id = annotation_images.location_id
                         AND i2.image_date = annotation_images.image_date
                         AND a.status = 'open') AS open_pins
             FROM   annotation_images
             WHERE  location_id = ? AND deleted_at IS NULL
             GROUP  BY image_date
             ORDER  BY image_date DESC"
        );
        $st->execute([$locId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

function annImagesForDay(int $locId, string $date): array {
    try {
        $st = getDb()->prepare(
            "SELECT i.id, i.original_name, i.stored_name, i.mime_type, i.size_bytes,
                    i.caption, i.uploaded_by, i.uploaded_at,
                    i.question_id, i.store_manager_code,
                    e.full_name  AS uploader_name,
                    sm.full_name AS store_manager_name,
                    q.question_text,
                    (SELECT COUNT(*) FROM image_annotations a WHERE a.image_id = i.id) AS pin_count,
                    (SELECT COUNT(*) FROM image_annotations a WHERE a.image_id = i.id AND a.status = 'open') AS open_pins
             FROM   annotation_images i
             LEFT JOIN employees e            ON e.employee_code  = i.uploaded_by
             LEFT JOIN employees sm           ON sm.employee_code = i.store_manager_code
             LEFT JOIN sh_check_questions q   ON q.id             = i.question_id
             WHERE  i.location_id = ? AND i.image_date = ? AND i.deleted_at IS NULL
             ORDER  BY i.uploaded_at DESC"
        );
        $st->execute([$locId, $date]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

function annImage(int $imageId): ?array {
    try {
        $st = getDb()->prepare(
            "SELECT i.*, l.location_name
             FROM   annotation_images i
             JOIN   locations l ON l.location_id = i.location_id
             WHERE  i.id = ? AND i.deleted_at IS NULL"
        );
        $st->execute([$imageId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) { return null; }
}

function annPinsForImage(int $imageId): array {
    try {
        $st = getDb()->prepare(
            "SELECT a.id, a.pin_number, a.x_percent, a.y_percent, a.status,
                    a.created_by, a.created_at, a.resolved_by, a.resolved_at,
                    e1.full_name AS creator_name,
                    e2.full_name AS resolver_name,
                    (SELECT COUNT(*) FROM annotation_comments c WHERE c.annotation_id = a.id) AS comment_count
             FROM   image_annotations a
             LEFT JOIN employees e1 ON e1.employee_code = a.created_by
             LEFT JOIN employees e2 ON e2.employee_code = a.resolved_by
             WHERE  a.image_id = ?
             ORDER  BY a.pin_number"
        );
        $st->execute([$imageId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

function annCommentsForPin(int $annotationId): array {
    try {
        $st = getDb()->prepare(
            "SELECT c.id, c.employee_code, c.comment_text, c.created_at, e.full_name
             FROM   annotation_comments c
             LEFT JOIN employees e ON e.employee_code = c.employee_code
             WHERE  c.annotation_id = ?
             ORDER  BY c.created_at ASC"
        );
        $st->execute([$annotationId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

// ── Pages ────────────────────────────────────────────────

// Small thumbnail chip used inside the day form's per-question photo strip.
// Clicks through to the existing single-image viewer with pin overlay so
// txn_annotation_comment users can drop pins and comment from there.
function annDayPhotoChip(array $im): void {
    $isImage = annIsImage((string)$im['mime_type']);
    $hasOpen = (int)($im['open_pins'] ?? 0) > 0;
    $hasAny  = (int)($im['pin_count'] ?? 0) > 0;
    $href    = '?page=annotation_image&id=' . (int)$im['id'];
    $title   = ($im['original_name'] ?? '')
             . (!empty($im['store_manager_name']) ? "\nSM: " . $im['store_manager_name'] : '')
             . (!empty($im['caption']) ? "\n" . $im['caption'] : '');
?>
<a class="sh-photo-chip" href="<?= h($href) ?>" title="<?= h($title) ?>">
    <?php if ($isImage): ?>
        <img src="?page=annotation_serve&id=<?= (int)$im['id'] ?>&t=thumb" alt="">
    <?php else:
        $ext = strtolower(pathinfo((string)$im['original_name'], PATHINFO_EXTENSION) ?: 'file');
    ?>
        <span class="sh-photo-doc"><?= h(mb_strtoupper($ext)) ?></span>
    <?php endif; ?>
    <?php if ($hasOpen): ?>
        <span class="sh-photo-badge"><?= (int)$im['open_pins'] ?></span>
    <?php elseif ($hasAny): ?>
        <span class="sh-photo-badge sh-photo-badge-done">✓</span>
    <?php endif; ?>
</a>
<?php
}

function pageAnnotations(): void {
    $locId = (int)($_GET['loc']  ?? 0);
    $date  = trim($_GET['date'] ?? '');

    if ($locId > 0 && !annCanView($locId)) {
        echo '<div class="alert alert-error">Access denied for this location.</div>'; return;
    }
    if ($locId > 0 && $date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        annPageDay($locId, $date);
        return;
    }
    if ($locId > 0) {
        annPageLocation($locId);
        return;
    }
    annPageLocations();
}

function annPageLocations(): void {
    $locs = annLocationListWithCounts();
?>
<div class="page-header">
    <h2 style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3" fill="currentColor"/></svg>
        Store Hygiene
        <span class="text-muted" style="font-size:13px;font-weight:400">(Image Annotations)</span>
    </h2>
</div>

<?php if (isSuperadmin()): ?>
<div class="form-card" style="max-width:none;margin-bottom:14px;border-left:3px solid var(--red)">
    <div class="form-section-title" style="color:var(--red)">⚠️ Delete Store Hygiene Images for a Month</div>
    <form method="POST" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap"
          onsubmit="return confirm('Delete ALL Store Hygiene photos (and every pin + comment + file) for the selected month across every location? This cannot be undone.');">
        <input type="hidden" name="action" value="delete_annotation_images_by_month">
        <div class="form-group">
            <label>Month</label>
            <select name="month" class="form-control" style="width:140px" required>
                <?php $curM = (int)date('m'); for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $m === $curM ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Year</label>
            <select name="year" class="form-control" style="width:100px" required>
                <?php $curY = (int)date('Y'); for ($y = 2024; $y <= $curY + 1; $y++): ?>
                <option value="<?= $y ?>" <?= $y === $curY ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-danger-solid"
                title="Delete all Store Hygiene photos (and their pins/comments/files) for the selected month">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="3 6 5 6 21 6"/>
                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                <path d="M10 11v6"/>
                <path d="M14 11v6"/>
                <path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/>
            </svg>
            Delete Month Data
        </button>
    </form>
</div>
<?php endif; ?>

<?php if (empty($locs)): ?>
<div class="rpt-prompt">No locations available to you.</div>
<?php else: ?>
<div class="ann-grid">
    <?php foreach ($locs as $l):
        $hasOpen = ($l['open_pins'] ?? 0) > 0;
    ?>
    <a class="ann-card" href="?page=annotations&loc=<?= (int)$l['location_id'] ?>">
        <div class="ann-card-name"><?= h($l['location_name']) ?></div>
        <div class="ann-card-meta">
            <span><?= (int)($l['image_count'] ?? 0) ?> photo<?= (int)$l['image_count'] === 1 ? '' : 's' ?></span>
            <?php if ($hasOpen): ?>
                <span class="badge badge-red"><?= (int)$l['open_pins'] ?> open</span>
            <?php endif; ?>
        </div>
        <?php if (!empty($l['last_upload'])): ?>
            <div class="ann-card-time">Last: <?= h(relAge($l['last_upload'])) ?> ago</div>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<style>
.ann-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:12px; margin-top:8px }
.ann-card { display:block; padding:14px 16px; background:var(--surface); border:1px solid var(--border); border-radius:8px; text-decoration:none; color:var(--text); transition:border-color .15s, transform .08s }
.ann-card:hover { border-color:var(--accent); transform:translateY(-1px) }
.ann-card-name { font-size:14px; font-weight:600; margin-bottom:6px }
.ann-card-meta { display:flex; gap:8px; align-items:center; font-size:12px; color:var(--muted) }
.ann-card-time { font-size:11px; color:var(--muted); margin-top:6px }
@media(max-width:480px){ .ann-grid { grid-template-columns:1fr } }
</style>
<?php
}

function annPageLocation(int $locId): void {
    $loc  = null;
    foreach (getActiveLocations() as $l) if ((int)$l['location_id'] === $locId) { $loc = $l; break; }
    $name = $loc ? $loc['location_name'] : ('Location #' . $locId);
    $dates = annDatesForLocation($locId);
?>
<div class="page-header" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <a href="?page=annotations" class="btn btn-sm btn-ghost">← Back</a>
    <h2 style="margin:0"><?= h($name) ?></h2>
    <?php if (annCanCreate()): ?>
    <a href="?page=annotations&loc=<?= $locId ?>&date=<?= h(date('Y-m-d')) ?>" class="btn btn-primary" style="margin-left:auto">+ Today's photos</a>
    <?php endif; ?>
</div>

<?php if (empty($dates)): ?>
<div class="rpt-prompt">No photos uploaded yet for this location.
    <?php if (annCanCreate()): ?>
        <br><a class="btn btn-primary" style="margin-top:10px" href="?page=annotations&loc=<?= $locId ?>&date=<?= h(date('Y-m-d')) ?>">Upload first photo</a>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="table-wrap" data-stack>
    <table class="table">
        <thead>
            <tr>
                <th>Date</th>
                <th style="width:90px">Photos</th>
                <th style="width:100px">Open Pins</th>
                <th style="width:130px">Last Upload</th>
                <th style="width:80px"></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($dates as $d): ?>
            <tr>
                <td><strong><?= h(date('d M Y (D)', strtotime($d['image_date']))) ?></strong></td>
                <td><?= (int)$d['image_count'] ?></td>
                <td>
                    <?php if ((int)$d['open_pins'] > 0): ?>
                        <span class="badge badge-red"><?= (int)$d['open_pins'] ?> open</span>
                    <?php else: ?>
                        <span class="badge badge-green">All clear</span>
                    <?php endif; ?>
                </td>
                <td class="text-muted" style="font-size:11px"><?= h(relAge($d['last_upload'])) ?> ago</td>
                <td><a href="?page=annotations&loc=<?= $locId ?>&date=<?= h($d['image_date']) ?>" class="btn btn-sm btn-secondary">Open</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php
}

function annPageDay(int $locId, string $date): void {
    $loc = null;
    foreach (getActiveLocations() as $l) if ((int)$l['location_id'] === $locId) { $loc = $l; break; }
    $name = $loc ? $loc['location_name'] : ('Location #' . $locId);
    $images = annImagesForDay($locId, $date);
?>
<div class="page-header" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <a href="?page=annotations&loc=<?= $locId ?>" class="btn btn-sm btn-ghost">← Back</a>
    <h2 style="margin:0"><?= h($name) ?></h2>
    <form method="GET" id="annDateForm" style="display:flex;align-items:center;gap:6px;margin-left:auto">
        <input type="hidden" name="page" value="annotations">
        <input type="hidden" name="loc"  value="<?= $locId ?>">
        <label for="annDateInput" class="text-muted" style="font-size:12px">Date</label>
        <input type="date" id="annDateInput" name="date" class="form-control"
               style="width:170px" max="<?= h(date('Y-m-d')) ?>"
               value="<?= h($date) ?>" onchange="this.form.submit()">
        <?php if ($date !== date('Y-m-d')): ?>
            <a href="?page=annotations&loc=<?= $locId ?>&date=<?= h(date('Y-m-d')) ?>"
               class="btn btn-sm btn-ghost" title="Jump to today">Today</a>
        <?php endif; ?>
    </form>
</div>

<?php
// Audit-style batch layout: one Store Manager mapping at top, then every
// active question as a row with per-question file inputs. A user with
// txn_annotation_create uploads one or many photos per question in a
// single Save. txn_annotation_comment users (and superadmin) drop pins
// and reply via the existing annotation viewer once photos exist.
$questions = function_exists('shCheckQuestions') ? shCheckQuestions(true) : [];

// Group existing images by question_id so each row can show its own
// photo strip. Photos with no question_id (legacy uploads from before
// the 2026-05-30 migration) get a synthetic "Unassigned" bucket below.
$imagesByQ  = [];
$unassigned = [];
foreach ($images as $im) {
    $qid = (int)($im['question_id'] ?? 0);
    if ($qid > 0) {
        if (!isset($imagesByQ[$qid])) $imagesByQ[$qid] = [];
        $imagesByQ[$qid][] = $im;
    } else {
        $unassigned[] = $im;
    }
}

// Pre-fill SM from the most recent photo on this day so re-opens of the
// form don't force a re-pick. The user can change it before saving.
$preSmCode = '';
foreach ($images as $im) {
    if (!empty($im['store_manager_code'])) { $preSmCode = (string)$im['store_manager_code']; break; }
}

$canCreate = annCanCreate();
if ($canCreate):
    $departments  = function_exists('getDepartments')   ? getDepartments()   : [];
    $allEmployees = function_exists('getEmployeesLite') ? getEmployeesLite() : [];
    $allEmployees = array_values(array_filter($allEmployees, fn($e) => !empty($e['is_active'])));
    $defaultDeptId = 0;
    foreach ($departments as $d) {
        if (strcasecmp($d['department_name'] ?? '', 'Retail Sales') === 0) {
            $defaultDeptId = (int)$d['id']; break;
        }
    }
    // dept_id (string key, "0" = all) → [{code,name}, …] for client-side filtering
    $empMap = ['0' => []];
    foreach ($allEmployees as $e) {
        $entry = ['code' => (string)$e['employee_code'], 'name' => (string)$e['full_name']];
        $did   = (string)((int)($e['department_id'] ?? 0));
        $empMap['0'][] = $entry;
        if (!isset($empMap[$did])) $empMap[$did] = [];
        $empMap[$did][] = $entry;
    }
    $empMapJson = json_encode($empMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
endif;
?>

<?php
$canManageQuestions = function_exists('shCheckCanManageQuestions') && shCheckCanManageQuestions();
?>
<?php if (empty($questions) && $canCreate): ?>
<div class="alert alert-error" style="margin-bottom:14px">
    No active questions configured.
    <?php if ($canManageQuestions): ?>
        <a href="?page=store_hygiene_check_admin" style="color:inherit;text-decoration:underline">Manage questions</a> to add some before uploading.
    <?php else: ?>
        Ask an administrator (Store Hygiene · Manage Questions) to publish questions before uploading.
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($questions) || !empty($images)): ?>
<form method="POST" enctype="multipart/form-data" id="annUploadForm">
    <input type="hidden" name="action"      value="upload_annotation_image">
    <input type="hidden" name="location_id" value="<?= $locId ?>">
    <input type="hidden" name="image_date"  value="<?= h($date) ?>">

    <?php if ($canCreate && !empty($questions)): ?>
    <!-- Employee mapping card — one SM covers every photo uploaded in this save. -->
    <div class="form-card" style="max-width:none;margin-bottom:14px">
        <div class="form-section-title" style="margin-top:0;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <span>Store visit details</span>
            <?php if ($canManageQuestions): ?>
                <a href="?page=store_hygiene_check_admin" class="btn btn-sm btn-ghost" style="margin-left:auto">⚙️ Manage questions</a>
            <?php endif; ?>
        </div>
        <div class="form-grid" style="grid-template-columns:repeat(3,1fr)">
            <div class="form-group">
                <label>Date</label>
                <input type="text" class="form-control" value="<?= h(date('d-m-Y', strtotime($date))) ?>" readonly>
                <span class="hint">Change the date from the picker above.</span>
            </div>
            <div class="form-group">
                <label>Department</label>
                <select id="annDeptFilter" class="form-control">
                    <option value="0">All departments</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= (int)$d['id'] ?>" <?= ($defaultDeptId === (int)$d['id']) ? 'selected' : '' ?>><?= h($d['department_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="hint">Narrows the Store Manager list.</span>
            </div>
            <div class="form-group">
                <label>Store Manager <span class="required">*</span></label>
                <select name="store_manager_code" id="annSmSelect" class="form-control" required>
                    <option value="">— Select —</option>
                </select>
                <span class="hint" id="annSmHint" data-presm="<?= h($preSmCode) ?>"></span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Per-question rows — file input + existing photos inline. -->
    <div class="table-wrap" data-stack>
        <table class="table sh-q-table">
            <thead>
                <tr>
                    <th style="width:46px">#</th>
                    <th>Question</th>
                    <th style="min-width:220px">Photos</th>
                    <?php if ($canCreate && !empty($questions)): ?>
                        <th style="width:240px">Add photos</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($questions as $qi => $q):
                $qid = (int)$q['id'];
                $ims = $imagesByQ[$qid] ?? [];
            ?>
                <tr>
                    <td><?= $qi + 1 ?></td>
                    <td><div class="sh-q-cell"><?= h($q['question_text']) ?></div></td>
                    <td>
                        <?php if ($ims): ?>
                            <div class="sh-photo-strip">
                            <?php foreach ($ims as $im): annDayPhotoChip($im); endforeach; ?>
                            </div>
                        <?php else: ?>
                            <span class="text-muted" style="font-size:12px">No photos yet.</span>
                        <?php endif; ?>
                    </td>
                    <?php if ($canCreate): ?>
                    <td>
                        <input type="file" class="form-control" name="photos[<?= $qid ?>][]" multiple capture="environment"
                               accept="image/*,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.presentationml.presentation,text/plain,text/csv,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv"
                               style="font-size:11px">
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!empty($unassigned)): ?>
                <tr>
                    <td>—</td>
                    <td><div class="sh-q-cell text-muted" style="font-style:italic">Unassigned (legacy uploads)</div></td>
                    <td>
                        <div class="sh-photo-strip">
                        <?php foreach ($unassigned as $im): annDayPhotoChip($im); endforeach; ?>
                        </div>
                    </td>
                    <?php if ($canCreate): ?><td></td><?php endif; ?>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($canCreate && !empty($questions)): ?>
    <div class="form-actions" style="position:sticky;bottom:0;z-index:50;margin-top:14px;padding:12px 14px;background:var(--surface);border:1px solid var(--border);border-radius:8px;box-shadow:0 -4px 12px rgba(0,0,0,.25)">
        <button class="btn btn-primary" type="submit">Save</button>
        <a class="btn btn-ghost" href="?page=annotations&loc=<?= $locId ?>">Cancel</a>
        <span class="text-muted" style="margin-left:auto;font-size:12px">
            Pick a Store Manager, attach photos under each question, then Save. Comments / pins are added later via the photo viewer.
        </span>
    </div>
    <?php endif; ?>
</form>

<?php if ($canCreate && !empty($questions)): ?>
<script>
(function () {
    var empMap = <?= $empMapJson ?>;
    var dept   = document.getElementById('annDeptFilter');
    var sm     = document.getElementById('annSmSelect');
    var hint   = document.getElementById('annSmHint');
    if (!dept || !sm) return;
    var preSm = (hint && hint.dataset.presm) || '';

    function refresh(retainCurrent) {
        var key  = String(parseInt(dept.value, 10) || 0);
        var list = empMap[key] || empMap['0'] || [];
        var cur  = retainCurrent ? sm.value : (sm.value || preSm);
        sm.innerHTML = '<option value="">— Select —</option>';
        list.forEach(function (e) {
            var o = document.createElement('option');
            o.value = e.code;
            o.textContent = e.name + ' (' + e.code + ')';
            if (e.code === cur) o.selected = true;
            sm.appendChild(o);
        });
        if (hint) hint.textContent = list.length + ' employee(s) available.';
    }
    dept.addEventListener('change', function () { refresh(false); });
    refresh(false);
})();
</script>
<?php endif; ?>
<?php endif; ?>

<style>
/* Per-question photo strip inside the day form. Each chip is a small
   square thumbnail with an "open pin" badge in the corner and a SM tag
   on hover. */
.sh-q-table .sh-q-cell { line-height:1.4 }
.sh-photo-strip { display:flex; flex-wrap:wrap; gap:6px; align-items:flex-start }
.sh-photo-chip { position:relative; display:block; width:64px; height:64px; border-radius:6px; overflow:hidden; background:#0f0f12; border:1px solid var(--border); text-decoration:none; flex-shrink:0 }
.sh-photo-chip:hover { border-color:var(--accent) }
.sh-photo-chip img { width:100%; height:100%; object-fit:cover; display:block }
.sh-photo-chip .sh-photo-badge { position:absolute; top:3px; right:3px; min-width:18px; height:18px; padding:0 5px; border-radius:9px; background:var(--red); color:#fff; font-size:10px; font-weight:700; display:inline-flex; align-items:center; justify-content:center; box-shadow:0 1px 3px rgba(0,0,0,.5) }
.sh-photo-chip .sh-photo-badge-done { background:var(--green); color:#0e1f17 }
.sh-photo-chip .sh-photo-doc { width:100%; height:100%; display:flex; align-items:center; justify-content:center; color:var(--accent); font-size:10px; font-weight:700; background:linear-gradient(180deg,#16161b,#1c1c24); letter-spacing:.04em }
</style>
<?php
}

// Full-size viewer with pin overlay + bottom-sheet (mobile) / side-panel (desktop) thread.
function pageAnnotationImage(): void {
    $imageId = (int)($_GET['id'] ?? 0);
    $im = annImage($imageId);
    if (!$im) { echo '<div class="alert alert-error">Image not found.</div>'; return; }
    if (!annCanView((int)$im['location_id'])) {
        echo '<div class="alert alert-error">Access denied.</div>'; return;
    }
    $isImage      = annIsImage((string)$im['mime_type']);
    $pins         = $isImage ? annPinsForImage($imageId) : [];
    $selectedPin  = (int)($_GET['pin'] ?? 0);
    $canResolve   = annCanResolve();
    $canComment   = annCanComment((int)$im['location_id']);
    $canDelete    = isSuperadmin();
    $imgUrl       = '?page=annotation_serve&id=' . $imageId;
    $isPdf        = strtolower((string)$im['mime_type']) === 'application/pdf';
    $extLabel     = mb_strtoupper(pathinfo((string)$im['original_name'], PATHINFO_EXTENSION) ?: 'FILE');

    // For JS — we hand the pin list as JSON so the canvas can render
    // without an additional roundtrip on first load.
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
    <a href="?page=annotations&loc=<?= (int)$im['location_id'] ?>&date=<?= h($im['image_date']) ?>" class="btn btn-sm btn-ghost">← Back</a>
    <h2 style="margin:0"><?= h($im['location_name']) ?></h2>
    <span class="text-muted" style="font-size:13px"><?= h(date('d M Y', strtotime($im['image_date']))) ?></span>
    <?php if ($canDelete): ?>
    <form method="POST" style="margin-left:auto" onsubmit="return confirm('Delete this photo and all its pins/comments? This cannot be undone.');">
        <input type="hidden" name="action" value="delete_annotation_image">
        <input type="hidden" name="image_id" value="<?= $imageId ?>">
        <button class="btn btn-sm btn-danger-solid" type="submit">Delete photo</button>
    </form>
    <?php endif; ?>
</div>

<?php if (!empty($im['caption'])): ?>
<div class="text-muted" style="margin-bottom:10px;font-size:13px"><?= h($im['caption']) ?></div>
<?php endif; ?>

<?php if ($isImage): ?>
<div id="annViewer" class="ann-viewer">
    <div id="annStage" class="ann-stage">
        <img id="annImg" src="<?= h($imgUrl) ?>" alt="annotation">
        <div id="annPins" class="ann-pins"></div>
    </div>
    <?php if (annCanCreate()): ?>
    <div class="ann-toolbar">
        <button id="annDropBtn" type="button" class="btn btn-primary">+ Add Pin</button>
        <span id="annHint" class="text-muted" style="font-size:12px"></span>
    </div>
    <?php endif; ?>
</div>
<?php else: ?>
<!-- Non-image: render a file card. Pins/comments don't apply to documents,
     so the comment thread is hidden. PDFs get an inline iframe preview;
     other docs get an Open / Download button only. -->
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
            <div style="font-weight:600;word-break:break-word"><?= h($im['original_name']) ?></div>
            <div class="text-muted" style="font-size:12px;margin-top:2px">
                <?= h(number_format(((int)$im['size_bytes'])/1024, 0)) ?> KB &middot;
                <?= h($im['mime_type']) ?> &middot;
                uploaded <?= h(relAge($im['uploaded_at'])) ?> ago
            </div>
        </div>
        <div style="display:flex;gap:8px">
            <a href="<?= h($imgUrl) ?>" target="_blank" class="btn btn-primary">Open</a>
            <a href="<?= h($imgUrl) ?>&dl=1" download="<?= h($im['original_name']) ?>" class="btn btn-secondary">Download</a>
        </div>
    </div>
    <?php if ($isPdf): ?>
    <div style="margin-top:14px;border:1px solid var(--border);border-radius:6px;overflow:hidden;background:#0a0a0c">
        <iframe src="<?= h($imgUrl) ?>" style="display:block;width:100%;height:75vh;border:0" title="<?= h($im['original_name']) ?>"></iframe>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($isImage): ?>
<!-- Bottom sheet / side panel (one element, CSS swaps layout) -->
<div id="annSheet" class="ann-sheet" hidden>
    <div class="ann-sheet-head">
        <div id="annSheetTitle" style="font-weight:600">Pin</div>
        <button type="button" id="annSheetClose" class="btn btn-sm btn-ghost" aria-label="Close">×</button>
    </div>
    <div id="annSheetMeta" class="ann-sheet-meta"></div>
    <div id="annSheetActions" class="ann-sheet-actions"></div>
    <div id="annSheetBody" class="ann-sheet-body">
        <div id="annComments"></div>
        <?php if ($canComment): ?>
        <form id="annCommentForm" data-no-disable class="ann-comment-form">
            <textarea id="annCommentInput" placeholder="Add a comment…" rows="2" class="form-control"></textarea>
            <button type="submit" class="btn btn-primary btn-sm">Send</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<style>
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

/* Sheet: side panel on desktop, bottom sheet on mobile */
.ann-sheet { position:fixed; right:0; top:0; bottom:0; width:min(420px, 100%); background:var(--surface);
             border-left:1px solid var(--border); box-shadow:-6px 0 24px rgba(0,0,0,.5);
             display:flex; flex-direction:column; z-index:100; transform:translateX(100%); transition:transform .22s ease }
.ann-sheet[data-open="1"] { transform:translateX(0) }
.ann-sheet-head { display:flex; align-items:center; justify-content:space-between; padding:12px 14px; border-bottom:1px solid var(--border) }
.ann-sheet-meta { padding:10px 14px; font-size:12px; color:var(--muted); border-bottom:1px solid var(--border) }
.ann-sheet-actions { display:flex; gap:8px; padding:10px 14px; border-bottom:1px solid var(--border); flex-wrap:wrap }
.ann-sheet-actions:empty { display:none }
.ann-sheet-body { flex:1 1 auto; display:flex; flex-direction:column; overflow:hidden }
#annComments { flex:1 1 auto; overflow-y:auto; padding:10px 14px }
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
    const PINS    = <?= json_encode($pinsJs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?>;
    const IMAGE_ID = <?= (int)$imageId ?>;
    const CAN_CREATE  = <?= annCanCreate() ? 'true' : 'false' ?>;
    const CAN_COMMENT = <?= $canComment ? 'true' : 'false' ?>;
    const CAN_RESOLVE = <?= $canResolve ? 'true' : 'false' ?>;
    const SELECTED_PIN_ID = <?= (int)$selectedPin ?>;

    const stage   = document.getElementById('annStage');
    const img     = document.getElementById('annImg');
    const pinsEl  = document.getElementById('annPins');
    const dropBtn = document.getElementById('annDropBtn');
    const hintEl  = document.getElementById('annHint');
    const sheet   = document.getElementById('annSheet');
    const sheetTitle   = document.getElementById('annSheetTitle');
    const sheetMeta    = document.getElementById('annSheetMeta');
    const sheetActions = document.getElementById('annSheetActions');
    const commentsEl   = document.getElementById('annComments');
    const closeBtn     = document.getElementById('annSheetClose');
    const commentForm  = document.getElementById('annCommentForm');
    const commentInput = document.getElementById('annCommentInput');

    let dropMode = false;
    let activeAnnotationId = null;
    const localPins = PINS.slice();

    function escapeHtml(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    function renderPins() {
        pinsEl.innerHTML = '';
        localPins.forEach(p => {
            const el = document.createElement('button');
            el.type = 'button';
            el.className = 'ann-pin ' + (p.status === 'resolved' ? 'ann-pin-resolved' : 'ann-pin-open');
            if (p.id === activeAnnotationId) el.classList.add('ann-pin-active');
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
        if (hintEl) hintEl.textContent = dropMode ? 'Tap on the photo to drop a pin.' : '';
    }

    if (dropBtn) dropBtn.addEventListener('click', () => setDropMode(!dropMode));

    stage.addEventListener('click', async (ev) => {
        if (!dropMode || !CAN_CREATE) return;
        const rect = img.getBoundingClientRect();
        const x = ((ev.clientX - rect.left) / rect.width)  * 100;
        const y = ((ev.clientY - rect.top)  / rect.height) * 100;
        if (x < 0 || x > 100 || y < 0 || y > 100) return;
        const text = window.prompt('First comment for this pin:');
        if (text == null) { setDropMode(false); return; }
        const comment = String(text).trim();
        if (!comment) { setDropMode(false); return; }
        const fd = new FormData();
        fd.append('action', 'create_annotation');
        fd.append('image_id', IMAGE_ID);
        fd.append('x_percent', x.toFixed(2));
        fd.append('y_percent', y.toFixed(2));
        fd.append('comment_text', comment);
        const res = await fetch('index.php', { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
        const json = await res.json().catch(() => null);
        if (!json || !json.ok) { alert('Failed to create pin: ' + (json && json.error ? json.error : 'unknown')); setDropMode(false); return; }
        localPins.push({ id: json.annotation_id, pin_number: json.pin_number, x: x, y: y, status: 'open',
                         creator: json.creator || 'You', created_at: json.created_at,
                         resolver: null, resolved_at: null, comments: 1 });
        setDropMode(false);
        activeAnnotationId = json.annotation_id;
        renderPins();
        openPin(json.annotation_id);
    });

    function openPin(id) {
        const p = localPins.find(p => p.id === id);
        if (!p) return;
        activeAnnotationId = id;
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
        activeAnnotationId = null;
        renderPins();
        // Remove from DOM after the slide-out animation.
        setTimeout(() => { if (sheet.dataset.open !== '1') sheet.hidden = true; }, 240);
    }
    closeBtn.addEventListener('click', closeSheet);

    async function loadComments(id) {
        const res = await fetch('index.php?page=annotation_thread&id=' + id, { headers: { 'Accept': 'application/json' } });
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
            if (!activeAnnotationId) return;
            const text = (commentInput.value || '').trim();
            if (!text) return;
            const fd = new FormData();
            fd.append('action', 'add_annotation_comment');
            fd.append('annotation_id', activeAnnotationId);
            fd.append('comment_text', text);
            const res = await fetch('index.php', { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
            const json = await res.json().catch(() => null);
            if (!json || !json.ok) { alert('Failed: ' + (json && json.error ? json.error : 'unknown')); return; }
            commentInput.value = '';
            // Bump pin's comment count in local cache.
            const p = localPins.find(p => p.id === activeAnnotationId);
            if (p) p.comments = (p.comments || 0) + 1;
            renderPins();
            loadComments(activeAnnotationId);
        });
    }

    async function toggleResolve(id, currentStatus) {
        const action = currentStatus === 'resolved' ? 'reopen_annotation' : 'resolve_annotation';
        const fd = new FormData();
        fd.append('action', action);
        fd.append('annotation_id', id);
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
        openPin(id); // refresh the panel
    }

    renderPins();
    if (SELECTED_PIN_ID) openPin(SELECTED_PIN_ID);
})();
</script>
<?php endif; /* isImage */ ?>
<?php
}

// Streams the image file. Authorisation: must be allowed to view the
// owning location. Honors ?t=thumb to serve the 480px thumbnail when
// one exists (generated by Imagick at upload time); falls back to the
// full image otherwise so the page still loads on hosts without imagick.
function pageAnnotationServe(): void {
    if (!isLoggedIn()) { http_response_code(403); echo 'Forbidden'; return; }
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1)       { http_response_code(400); echo 'Bad request'; return; }
    $im = annImage($id);
    if (!$im)          { http_response_code(404); echo 'Not found'; return; }
    if (!annCanView((int)$im['location_id'])) { http_response_code(403); echo 'Forbidden'; return; }

    $wantThumb  = ($_GET['t'] ?? '') === 'thumb';
    $servedMime = $im['mime_type'] ?: 'application/octet-stream';
    $path       = annAttachmentPath((string)$im['uploaded_at'], (string)$im['stored_name'], (int)$im['location_id']);

    if ($wantThumb) {
        $thumbPath = annAttachmentPath((string)$im['uploaded_at'], annThumbName((string)$im['stored_name']), (int)$im['location_id']);
        if (is_file($thumbPath)) {
            $path       = $thumbPath;
            $servedMime = 'image/jpeg'; // thumbs are always JPEG
        }
        // No thumb on disk → fall through to the full file. Browser
        // still gets a valid image, just bigger.
    }

    if (!is_file($path)) { http_response_code(404); echo 'File missing'; return; }
    header('Content-Type: ' . $servedMime);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=600');
    header('X-Content-Type-Options: nosniff');
    // ?dl=1 forces a Save-As dialog regardless of MIME (used by the
    // viewer's Download button on document attachments). Otherwise the
    // browser inlines what it can (PDF, images) and prompts only for
    // unknown types.
    if (!empty($_GET['dl'])) {
        $name = preg_replace('/[\r\n"]/', '', (string)$im['original_name']);
        header('Content-Disposition: attachment; filename="' . $name . '"');
    }
    readfile($path);
}

// JSON: pin's comments. Used by the viewer's bottom sheet.
function pageAnnotationThread(): void {
    header('Content-Type: application/json');
    if (!isLoggedIn()) { http_response_code(403); echo '{"ok":false,"error":"forbidden"}'; return; }
    $aid = (int)($_GET['id'] ?? 0);
    if ($aid < 1) { http_response_code(400); echo '{"ok":false,"error":"bad request"}'; return; }
    try {
        $st = getDb()->prepare(
            'SELECT a.id, a.image_id, i.location_id
             FROM   image_annotations a JOIN annotation_images i ON i.id = a.image_id
             WHERE  a.id = ?'
        );
        $st->execute([$aid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { http_response_code(500); echo '{"ok":false,"error":"db"}'; return; }
    if (!$row) { http_response_code(404); echo '{"ok":false,"error":"not found"}'; return; }
    if (!annCanView((int)$row['location_id'])) { http_response_code(403); echo '{"ok":false,"error":"forbidden"}'; return; }
    echo json_encode(['ok' => true, 'comments' => annCommentsForPin($aid)]);
}

// ── POST handlers (all return JSON for AJAX form submission) ──

function annJsonOk(array $extra = []): void {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['ok' => true], $extra));
    exit;
}
function annJsonFail(string $msg, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// Batch upload: one Store Manager mapping at top of the form covers every
// file uploaded across every question's file input. Mirrors the audit
// edit table pattern (modules/audit_actions.php:doSaveAuditWeights, the
// $_POST['param_files'][$pid][] handling) so the UX feels familiar.
//
// $_FILES['photos'] arrives shaped as:
//   ['name'     => [qid => [filename, …]],
//    'tmp_name' => [qid => […]],
//    'error'    => [qid => […]],
//    'size'     => [qid => […]],
//    'type'     => [qid => […]]]
function doUploadAnnotationImage(): void {
    if (!annCanCreate()) { flash('error', 'Access denied.'); header('Location: index.php?page=annotations'); exit; }
    $locId = (int)($_POST['location_id'] ?? 0);
    $date  = trim($_POST['image_date']  ?? '');
    if ($locId < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        flash('error', 'Invalid location or date.'); header('Location: index.php?page=annotations'); exit;
    }
    $smCode = trim((string)($_POST['store_manager_code'] ?? ''));
    $back   = "Location: index.php?page=annotations&loc=$locId&date=$date";

    if ($smCode === '') {
        flash('error', 'Pick a Store Manager before uploading.');
        header($back); exit;
    }
    try {
        $eSt = getDb()->prepare('SELECT 1 FROM employees WHERE employee_code = ? AND is_active = 1');
        $eSt->execute([$smCode]);
        if (!$eSt->fetchColumn()) {
            flash('error', 'Selected Store Manager is not an active employee.');
            header($back); exit;
        }
    } catch (Exception $e) { /* unreachable */ }

    if (empty($_FILES['photos']['name']) || !is_array($_FILES['photos']['name'])) {
        flash('error', 'Attach at least one photo under a question.');
        header($back); exit;
    }

    // Resolve all referenced question ids in one go so we don't query in
    // the file loop. Skip files whose question id isn't active.
    $qIds = array_filter(array_map('intval', array_keys($_FILES['photos']['name'])), fn($i) => $i > 0);
    $activeQs = [];
    if ($qIds) {
        try {
            $ph = implode(',', array_fill(0, count($qIds), '?'));
            $qSt = getDb()->prepare("SELECT id FROM sh_check_questions WHERE is_active = 1 AND id IN ($ph)");
            $qSt->execute($qIds);
            foreach ($qSt->fetchAll(PDO::FETCH_COLUMN) as $id) $activeQs[(int)$id] = true;
        } catch (Exception $e) {
            flash('error', 'Questions are not yet configured. Run the 2026-05-30 migration.');
            header($back); exit;
        }
    }
    if (!$activeQs) {
        flash('error', 'No active questions accepted the uploaded files.');
        header($back); exit;
    }

    $finfo  = new finfo(FILEINFO_MIME_TYPE);
    $bucket = annMonthBucket(date('Y-m-d H:i:s'));
    $bucketDir = ANN_UPLOAD_DIR . $bucket . '/' . annLocationSlug($locId) . '/';
    if (!is_dir($bucketDir) && !mkdir($bucketDir, 0775, true) && !is_dir($bucketDir)) {
        flash('error', 'Failed to create upload bucket.');
        header($back); exit;
    }

    $ins = getDb()->prepare(
        'INSERT INTO annotation_images
            (location_id, image_date, original_name, stored_name, mime_type, size_bytes,
             caption, question_id, store_manager_code, uploaded_by)
         VALUES (?,?,?,?,?,?,NULL,?,?,?)'
    );

    $saved = 0; $skipped = 0; $errs = [];
    foreach ($_FILES['photos']['name'] as $qid => $names) {
        $qid = (int)$qid;
        if (!isset($activeQs[$qid]) || !is_array($names)) { $skipped += is_array($names) ? count($names) : 1; continue; }
        $count = count($names);
        for ($i = 0; $i < $count; $i++) {
            $err  = (int)($_FILES['photos']['error'][$qid][$i]    ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_NO_FILE) continue;                  // empty slot — normal
            if ($err !== UPLOAD_ERR_OK)      { $skipped++; continue; }   // browser-side error
            $tmp  = (string)($_FILES['photos']['tmp_name'][$qid][$i] ?? '');
            $orig = (string)($_FILES['photos']['name'][$qid][$i]    ?? '');
            $size = (int)   ($_FILES['photos']['size'][$qid][$i]    ?? 0);
            if (!is_uploaded_file($tmp))            { $skipped++; continue; }
            if ($size <= 0 || $size > ANN_MAX_BYTES){ $skipped++; $errs[] = $orig . ': too large or empty'; continue; }
            $mime = $finfo->file($tmp) ?: '';
            if (!in_array($mime, ANN_ALLOWED_MIME, true)) { $skipped++; $errs[] = $orig . ': bad type (' . $mime . ')'; continue; }
            $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!in_array($ext, ANN_ALLOWED_EXT, true)) $ext = annIsImage($mime) ? 'jpg' : 'bin';
            $stored = 'ann_' . uniqid('', true) . '.' . $ext;
            $dest   = $bucketDir . $stored;
            if (!move_uploaded_file($tmp, $dest)) { $skipped++; $errs[] = $orig . ': move failed'; continue; }

            if (annIsImage($mime)) {
                $norm   = annNormalizeUpload($bucketDir, $stored, $mime, $size);
                $stored = $norm['stored'];
                $mime   = $norm['mime'];
                $size   = $norm['size'];
                annGenerateThumb($bucketDir, $stored);
            }

            try {
                $ins->execute([
                    $locId, $date,
                    preg_replace('/[^A-Za-z0-9._\- ]/', '_', $orig),
                    $stored, $mime, $size,
                    $qid, $smCode,
                    myCode(),
                ]);
                $saved++;
            } catch (Exception $e) {
                $skipped++;
                $errs[] = $orig . ': db error';
                @unlink($dest);
            }
        }
    }

    if ($saved > 0) {
        $msg = "Saved {$saved} file" . ($saved === 1 ? '' : 's') . '.';
        if ($skipped > 0) $msg .= " ({$skipped} skipped)";
        if ($errs)        $msg .= ' ' . implode('; ', array_slice($errs, 0, 3));
        flash('success', $msg);
    } else {
        flash('error', $skipped > 0
            ? 'No files saved. ' . implode('; ', array_slice($errs, 0, 5))
            : 'No files were submitted.');
    }
    header($back); exit;
}

function doCreateAnnotation(): void {
    if (!annCanCreate()) annJsonFail('forbidden', 403);
    $imageId  = (int)($_POST['image_id'] ?? 0);
    $x        = (float)($_POST['x_percent'] ?? -1);
    $y        = (float)($_POST['y_percent'] ?? -1);
    $comment  = trim($_POST['comment_text'] ?? '');
    if ($imageId < 1 || $x < 0 || $x > 100 || $y < 0 || $y > 100) annJsonFail('bad coordinates');
    if ($comment === '') annJsonFail('comment required');

    $im = annImage($imageId);
    if (!$im) annJsonFail('image not found', 404);

    $db = getDb();
    try {
        $db->beginTransaction();
        // Compute next pin_number for this image — race protected by UNIQUE KEY.
        $maxSt = $db->prepare('SELECT COALESCE(MAX(pin_number), 0) FROM image_annotations WHERE image_id = ?');
        $maxSt->execute([$imageId]);
        $next = (int)$maxSt->fetchColumn() + 1;
        $db->prepare(
            'INSERT INTO image_annotations
                (image_id, pin_number, x_percent, y_percent, status, created_by)
             VALUES (?,?,?,?,?,?)'
        )->execute([$imageId, $next, $x, $y, 'open', myCode()]);
        $aid = (int)$db->lastInsertId();
        $db->prepare(
            'INSERT INTO annotation_comments (annotation_id, employee_code, comment_text) VALUES (?,?,?)'
        )->execute([$aid, myCode(), $comment]);
        $db->commit();

        annNotify($aid, 'pin_created');

        // Get creator name for the response
        $emp = $db->prepare('SELECT full_name FROM employees WHERE employee_code = ?');
        $emp->execute([myCode()]);
        $creator = $emp->fetchColumn() ?: myCode();

        annJsonOk([
            'annotation_id' => $aid,
            'pin_number'    => $next,
            'creator'       => $creator,
            'created_at'    => date('Y-m-d\TH:i:s'),
        ]);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        annJsonFail($e->getMessage(), 500);
    }
}

function doAddAnnotationComment(): void {
    $aid     = (int)($_POST['annotation_id'] ?? 0);
    $comment = trim($_POST['comment_text'] ?? '');
    if ($aid < 1)        annJsonFail('bad annotation');
    if ($comment === '') annJsonFail('comment required');

    try {
        $st = getDb()->prepare(
            'SELECT a.id, i.location_id
             FROM image_annotations a JOIN annotation_images i ON i.id = a.image_id
             WHERE a.id = ?'
        );
        $st->execute([$aid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { annJsonFail('db', 500); }
    if (!$row) annJsonFail('not found', 404);
    if (!annCanComment((int)$row['location_id'])) annJsonFail('forbidden', 403);

    try {
        getDb()->prepare(
            'INSERT INTO annotation_comments (annotation_id, employee_code, comment_text) VALUES (?,?,?)'
        )->execute([$aid, myCode(), $comment]);
        annNotify($aid, 'comment_added');
        annJsonOk();
    } catch (Exception $e) { annJsonFail($e->getMessage(), 500); }
}

function doResolveAnnotation(): void {
    if (!annCanResolve()) annJsonFail('forbidden', 403);
    $aid = (int)($_POST['annotation_id'] ?? 0);
    if ($aid < 1) annJsonFail('bad annotation');
    try {
        getDb()->prepare(
            "UPDATE image_annotations
             SET status='resolved', resolved_by=?, resolved_at=NOW()
             WHERE id=? AND status='open'"
        )->execute([myCode(), $aid]);
        annNotify($aid, 'resolved');
        $emp = getDb()->prepare('SELECT full_name FROM employees WHERE employee_code = ?');
        $emp->execute([myCode()]);
        $name = $emp->fetchColumn() ?: myCode();
        annJsonOk(['status' => 'resolved', 'resolver_name' => $name, 'resolved_at' => date('Y-m-d\TH:i:s')]);
    } catch (Exception $e) { annJsonFail($e->getMessage(), 500); }
}

function doReopenAnnotation(): void {
    if (!annCanResolve()) annJsonFail('forbidden', 403);
    $aid = (int)($_POST['annotation_id'] ?? 0);
    if ($aid < 1) annJsonFail('bad annotation');
    try {
        getDb()->prepare(
            "UPDATE image_annotations
             SET status='open', resolved_by=NULL, resolved_at=NULL
             WHERE id=? AND status='resolved'"
        )->execute([$aid]);
        annNotify($aid, 'reopened');
        annJsonOk(['status' => 'open', 'resolver_name' => null, 'resolved_at' => null]);
    } catch (Exception $e) { annJsonFail($e->getMessage(), 500); }
}

// Bulk hard-delete every photo with image_date in the selected month
// across every location. Cascades through image_annotations and
// annotation_comments via FK ON DELETE CASCADE; we only need to
// DELETE FROM annotation_images. Files (originals + thumbs) are
// unlinked after the DB transaction commits — so a partial failure
// can never leave DB rows pointing at missing files (only the other
// way around, which is harmless).
function doDeleteAnnotationImagesByMonth(): void {
    if (!isSuperadmin()) {
        flash('error', 'Access denied.');
        header('Location: index.php?page=annotations'); exit;
    }
    $month = (int)($_POST['month'] ?? 0);
    $year  = (int)($_POST['year']  ?? 0);
    if ($month < 1 || $month > 12 || $year < 2000 || $year > 2099) {
        flash('error', 'Invalid month or year.');
        header('Location: index.php?page=annotations'); exit;
    }
    $monthStart = sprintf('%04d-%02d-01', $year, $month);
    $monthEnd   = date('Y-m-t', strtotime($monthStart));
    $monthName  = date('F Y', strtotime($monthStart));

    $db = getDb();
    try {
        // Collect (uploaded_at, stored_name) BEFORE delete so we can unlink
        // files afterwards. Includes soft-deleted rows on purpose — this
        // is a purge.
        $st = $db->prepare(
            'SELECT id, uploaded_at, stored_name, location_id
             FROM   annotation_images
             WHERE  image_date BETWEEN ? AND ?'
        );
        $st->execute([$monthStart, $monthEnd]);
        $victims = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$victims) {
            flash('error', 'No Store Hygiene photos found for ' . $monthName . '.');
            header('Location: index.php?page=annotations'); exit;
        }

        $db->beginTransaction();
        $del = $db->prepare('DELETE FROM annotation_images WHERE image_date BETWEEN ? AND ?');
        $del->execute([$monthStart, $monthEnd]);
        $db->commit();

        // Files: original + thumbnail (if generated by Imagick at upload).
        $unlinked = 0;
        foreach ($victims as $v) {
            $orig  = annAttachmentPath((string)$v['uploaded_at'], (string)$v['stored_name'], (int)$v['location_id']);
            $thumb = annAttachmentPath((string)$v['uploaded_at'], annThumbName((string)$v['stored_name']), (int)$v['location_id']);
            if (is_file($orig)  && @unlink($orig))  $unlinked++;
            if (is_file($thumb) && @unlink($thumb)) $unlinked++;
        }
        flash('success', 'Deleted ' . count($victims) . ' photo' . (count($victims) === 1 ? '' : 's')
            . ' for ' . $monthName . ' (' . $unlinked . ' file' . ($unlinked === 1 ? '' : 's') . ' removed). '
            . 'Pins and comments cascaded.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error', 'Bulk delete failed: ' . $e->getMessage());
    }
    header('Location: index.php?page=annotations'); exit;
}

function doDeleteAnnotationImage(): void {
    if (!isSuperadmin()) { flash('error', 'Access denied.'); header('Location: index.php?page=annotations'); exit; }
    $id = (int)($_POST['image_id'] ?? 0);
    if ($id < 1) { flash('error', 'Invalid image.'); header('Location: index.php?page=annotations'); exit; }
    $im = annImage($id);
    try {
        // Soft delete (preserve audit trail of pins/comments)
        getDb()->prepare('UPDATE annotation_images SET deleted_at = NOW() WHERE id = ?')->execute([$id]);
        // Best-effort unlink the physical file
        if ($im) {
            $path = annAttachmentPath((string)$im['uploaded_at'], (string)$im['stored_name'], (int)$im['location_id']);
            if (is_file($path)) @unlink($path);
        }
        flash('success', 'Photo deleted.');
    } catch (Exception $e) {
        flash('error', 'Delete failed: ' . $e->getMessage());
    }
    if ($im) {
        header('Location: index.php?page=annotations&loc=' . (int)$im['location_id'] . '&date=' . urlencode($im['image_date']));
    } else {
        header('Location: index.php?page=annotations');
    }
    exit;
}
