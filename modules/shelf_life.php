<?php
// =========================================================
// Product Shelf Life — View for all roles, image upload for superadmin
// =========================================================

define('SL_UPLOAD_DIR', __DIR__ . '/../uploads/shelf_life/');
define('SL_MAX_SIZE', 5 * 1024 * 1024); // 5MB
define('SL_MAX_W', 1024); // max width in pixels
define('SL_MAX_H', 768);  // max height in pixels
define('SL_ALLOWED_EXT', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('SL_ALLOWED_MIMES', [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'gif' => 'image/gif', 'webp' => 'image/webp',
]);

// ── Upload image handler (superadmin) ────────────────────
function doShelfLifeUpload(): void {
    if (!isSuperadmin() && !hasTxn('shelf_life_upload')) { flash('error', 'Access denied.'); header('Location: index.php?page=shelf_life'); exit; }

    $id = (int)($_POST['product_id'] ?? 0);
    $product = getShelfLifeProduct($id);
    if (!$product) { flash('error', 'Product not found.'); header('Location: index.php?page=shelf_life'); exit; }

    if (empty($_FILES['image']['name']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        flash('error', 'No file uploaded or upload error.');
        header('Location: index.php?page=shelf_life'); exit;
    }

    $file = $_FILES['image'];
    if ($file['size'] > SL_MAX_SIZE) {
        flash('error', 'File too large. Max 5MB.');
        header('Location: index.php?page=shelf_life'); exit;
    }

    $origName = basename($file['name']);
    $ext = mb_strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, SL_ALLOWED_EXT)) {
        flash('error', 'Invalid file type. Allowed: jpg, png, gif, webp.');
        header('Location: index.php?page=shelf_life'); exit;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = $finfo->file($file['tmp_name']);
    $expectedMime = SL_ALLOWED_MIMES[$ext] ?? null;
    if (!$expectedMime || $detectedMime !== $expectedMime) {
        flash('error', 'MIME type mismatch.');
        header('Location: index.php?page=shelf_life'); exit;
    }

    // Validate and resize image
    $imgInfo = getimagesize($file['tmp_name']);
    if (!$imgInfo) {
        flash('error', 'Invalid image file.');
        header('Location: index.php?page=shelf_life'); exit;
    }

    [$origW, $origH] = $imgInfo;
    $resized = resizeImage($file['tmp_name'], $ext, $origW, $origH, SL_MAX_W, SL_MAX_H);
    if (!$resized) {
        flash('error', 'Failed to process image.');
        header('Location: index.php?page=shelf_life'); exit;
    }

    // Save file
    if (!is_dir(SL_UPLOAD_DIR)) mkdir(SL_UPLOAD_DIR, 0755, true);

    // Delete old image if exists
    if (!empty($product['image'])) {
        $oldPath = SL_UPLOAD_DIR . $product['image'];
        if (file_exists($oldPath)) unlink($oldPath);
    }

    $storedName = 'sl_' . $id . '_' . time() . '.' . $ext;
    if (!file_put_contents(SL_UPLOAD_DIR . $storedName, $resized)) {
        flash('error', 'Failed to save image.');
        header('Location: index.php?page=shelf_life'); exit;
    }

    // Update DB
    $st = getDb()->prepare('UPDATE product_shelf_life SET image = ? WHERE id = ?');
    $st->execute([$storedName, $id]);

    flash('success', 'Image uploaded for ' . $product['item_name'] . '.');
    header('Location: index.php?page=shelf_life');
    exit;
}

// ── Update product details handler ──────────────────────
function doShelfLifeUpdate(): void {
    if (!isSuperadmin() && !hasTxn('shelf_life_upload')) { flash('error', 'Access denied.'); header('Location: index.php?page=shelf_life'); exit; }

    $id = (int)($_POST['product_id'] ?? 0);
    $product = getShelfLifeProduct($id);
    if (!$product) { flash('error', 'Product not found.'); header('Location: index.php?page=shelf_life'); exit; }

    $itemGroup    = trim($_POST['item_group'] ?? '');
    $itemCode     = trim($_POST['item_code'] ?? '');
    $itemName     = trim($_POST['item_name'] ?? '');
    $shelfLife    = (int)($_POST['shelf_life_days'] ?? 0);
    $basic        = trim($_POST['basic'] ?? '');
    $tax          = trim($_POST['tax'] ?? '');
    $mrp          = trim($_POST['mrp'] ?? '');
    $description  = trim($_POST['description'] ?? '');

    if ($itemGroup === '' || $itemCode === '' || $itemName === '' || $shelfLife < 0) {
        flash('error', 'Item Group, Code, Name are required and Shelf Life must be >= 0.');
        header('Location: index.php?page=shelf_life'); exit;
    }

    // Check duplicate item_code (excluding current)
    $dup = getDb()->prepare('SELECT id FROM product_shelf_life WHERE item_code = ? AND id != ?');
    $dup->execute([$itemCode, $id]);
    if ($dup->fetch()) {
        flash('error', 'Another product with code "' . $itemCode . '" already exists.');
        header('Location: index.php?page=shelf_life'); exit;
    }

    $basicVal = ($basic !== '' && is_numeric($basic)) ? (float)$basic : null;
    $taxVal   = ($tax   !== '' && is_numeric($tax))   ? (float)$tax   : null;
    $mrpVal   = ($mrp   !== '' && is_numeric($mrp))   ? (float)$mrp   : null;

    $st = getDb()->prepare('UPDATE product_shelf_life SET item_group=?, item_code=?, item_name=?, shelf_life_days=?, basic=?, tax=?, mrp=?, description=? WHERE id=?');
    $st->execute([$itemGroup, $itemCode, $itemName, $shelfLife, $basicVal, $taxVal, $mrpVal, $description ?: null, $id]);

    flash('success', 'Product "' . $itemName . '" updated.');
    header('Location: index.php?page=shelf_life');
    exit;
}

// ── Bulk CSV import (replace via TRUNCATE, or upsert via update) ─
function doShelfLifeImport(): void {
    if (!isSuperadmin() && !hasTxn('shelf_life_upload')) {
        flash('error', 'Access denied.'); header('Location: index.php?page=shelf_life'); exit;
    }
    if (empty($_FILES['csv']['name']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        flash('error', 'No file uploaded or upload error.');
        header('Location: index.php?page=shelf_life'); exit;
    }
    // Import mode — 'replace' (default) truncates the table then inserts
    // every row and wipes the image dir; 'update' upserts on item_code so
    // existing products keep their image and unmentioned codes survive.
    $mode = strtolower(trim((string)($_POST['mode'] ?? 'replace')));
    if (!in_array($mode, ['replace', 'update'], true)) $mode = 'replace';
    if ($mode === 'replace' && empty($_POST['confirm'])) {
        flash('error', 'Please tick the confirmation before replacing the list.');
        header('Location: index.php?page=shelf_life'); exit;
    }

    $tmp = $_FILES['csv']['tmp_name'];
    $fh = @fopen($tmp, 'r');
    if (!$fh) {
        flash('error', 'Could not read uploaded file.');
        header('Location: index.php?page=shelf_life'); exit;
    }

    // Header row — normalise to lowercase keys and strip UTF-8 BOM.
    $header = fgetcsv($fh, 0, ',', '"', '');
    if (!$header) {
        fclose($fh);
        flash('error', 'File is empty or unreadable.');
        header('Location: index.php?page=shelf_life'); exit;
    }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
    $idx = [];
    foreach ($header as $i => $col) {
        $key = strtolower(trim((string)$col));
        if ($key !== '') $idx[$key] = $i;
    }

    $required = ['item_group', 'item_code', 'item_name', 'shelf_life_days'];
    foreach ($required as $req) {
        if (!isset($idx[$req])) {
            fclose($fh);
            flash('error', 'Missing required column: ' . $req . '. Download the template for the expected format.');
            header('Location: index.php?page=shelf_life'); exit;
        }
    }

    // Parse all rows up-front so we can abort cleanly before mutating.
    $rows = [];
    $seenCodes = [];
    $line = 1;
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        $line++;
        // Skip blank lines
        if (count($r) === 1 && trim((string)$r[0]) === '') continue;

        $get = fn(string $k) => isset($idx[$k]) && isset($r[$idx[$k]]) ? trim((string)$r[$idx[$k]]) : '';
        $itemGroup = $get('item_group');
        $itemCode  = $get('item_code');
        $itemName  = $get('item_name');
        $days      = $get('shelf_life_days');
        $basic     = $get('basic');
        $tax       = $get('tax');
        $mrp       = $get('mrp');
        $description = $get('description');

        if ($itemCode === '' || $itemName === '' || $itemGroup === '') {
            fclose($fh);
            flash('error', "Line {$line}: item_group, item_code, and item_name are required.");
            header('Location: index.php?page=shelf_life'); exit;
        }
        if ($days === '' || !is_numeric($days) || (int)$days < 0) {
            fclose($fh);
            flash('error', "Line {$line}: shelf_life_days must be a non-negative integer.");
            header('Location: index.php?page=shelf_life'); exit;
        }
        if (isset($seenCodes[$itemCode])) {
            fclose($fh);
            flash('error', "Line {$line}: duplicate item_code \"{$itemCode}\" in file.");
            header('Location: index.php?page=shelf_life'); exit;
        }
        $seenCodes[$itemCode] = true;

        $rows[] = [
            'item_group' => $itemGroup,
            'item_code'  => $itemCode,
            'item_name'  => $itemName,
            'days'       => (int)$days,
            'basic'      => ($basic !== '' && is_numeric($basic)) ? (float)$basic : null,
            'tax'        => ($tax   !== '' && is_numeric($tax))   ? (float)$tax   : null,
            'mrp'        => ($mrp   !== '' && is_numeric($mrp))   ? (float)$mrp   : null,
            'description' => $description !== '' ? $description : null,
        ];
    }
    fclose($fh);

    if (!$rows) {
        flash('error', 'No data rows found in file.');
        header('Location: index.php?page=shelf_life'); exit;
    }

    $db = getDb();
    $inserted = 0; $updated = 0;
    try {
        // Replace mode uses TRUNCATE so AUTO_INCREMENT resets to 1. The
        // statement issues an implicit commit in MySQL, so it must run
        // *before* the transaction is opened.
        if ($mode === 'replace') {
            $db->exec('TRUNCATE TABLE product_shelf_life');
        }
        $db->beginTransaction();

        $sql = 'INSERT INTO product_shelf_life
            (item_group, item_code, item_name, shelf_life_days, basic, tax, mrp, description)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
        if ($mode === 'update') {
            // UPSERT keyed on uq_item_code — every column except item_code
            // (and image, which we never touch from CSV) tracks the new
            // values. Existing rows keep their image and updated_at flips.
            $sql .= ' ON DUPLICATE KEY UPDATE
                item_group     = VALUES(item_group),
                item_name      = VALUES(item_name),
                shelf_life_days= VALUES(shelf_life_days),
                basic          = VALUES(basic),
                tax            = VALUES(tax),
                mrp            = VALUES(mrp),
                description    = VALUES(description)';
        }
        $ins = $db->prepare($sql);

        foreach ($rows as $row) {
            $ins->execute([
                $row['item_group'], $row['item_code'], $row['item_name'],
                $row['days'], $row['basic'], $row['tax'], $row['mrp'], $row['description'],
            ]);
            if ($mode === 'update') {
                // PDO rowCount on a MySQL UPSERT: 1 = inserted, 2 = updated,
                // 0 = matched-but-identical. Anything other than 1 is an
                // update for reporting purposes.
                $rc = $ins->rowCount();
                if ($rc === 1) $inserted++; else $updated++;
            } else {
                $inserted++;
            }
        }
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error', 'Import failed: ' . $e->getMessage());
        header('Location: index.php?page=shelf_life'); exit;
    }

    if ($mode === 'replace') {
        // Every existing product was wiped — its image is now orphaned.
        if (is_dir(SL_UPLOAD_DIR)) {
            foreach (glob(SL_UPLOAD_DIR . '*') ?: [] as $f) {
                if (is_file($f)) @unlink($f);
            }
        }
        flash('success', 'Imported ' . count($rows) . ' product(s). Previous data replaced.');
    } else {
        flash('success', "Update import — {$inserted} new, {$updated} updated.");
    }
    header('Location: index.php?page=shelf_life'); exit;
}

// ── POST: Add a single new shelf-life product (manual form) ──
// Mirrors doShelfLifeUpdate's validation but for an INSERT path. Rejects
// duplicate item_code; image is added separately via the existing
// per-row Upload control.
function doShelfLifeAdd(): void {
    if (!isSuperadmin() && !hasTxn('shelf_life_upload')) {
        flash('error', 'Access denied.'); header('Location: index.php?page=shelf_life'); exit;
    }

    $itemGroup   = trim($_POST['item_group']      ?? '');
    $itemCode    = trim($_POST['item_code']       ?? '');
    $itemName    = trim($_POST['item_name']       ?? '');
    $shelfLife   = (int)($_POST['shelf_life_days'] ?? 0);
    $basic       = trim($_POST['basic']           ?? '');
    $tax         = trim($_POST['tax']             ?? '');
    $mrp         = trim($_POST['mrp']             ?? '');
    $description = trim($_POST['description']     ?? '');

    if ($itemGroup === '' || $itemCode === '' || $itemName === '' || $shelfLife < 0) {
        flash('error', 'Item Group, Code, Name are required and Shelf Life must be ≥ 0.');
        header('Location: index.php?page=shelf_life'); exit;
    }

    $dup = getDb()->prepare('SELECT id FROM product_shelf_life WHERE item_code = ? LIMIT 1');
    $dup->execute([$itemCode]);
    if ($dup->fetchColumn()) {
        flash('error', 'A product with code "' . $itemCode . '" already exists. Edit it from the list, or switch import to Update mode.');
        header('Location: index.php?page=shelf_life'); exit;
    }

    $basicVal = ($basic !== '' && is_numeric($basic)) ? (float)$basic : null;
    $taxVal   = ($tax   !== '' && is_numeric($tax))   ? (float)$tax   : null;
    $mrpVal   = ($mrp   !== '' && is_numeric($mrp))   ? (float)$mrp   : null;

    try {
        $st = getDb()->prepare(
            'INSERT INTO product_shelf_life
                (item_group, item_code, item_name, shelf_life_days, basic, tax, mrp, description)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([$itemGroup, $itemCode, $itemName, $shelfLife,
                      $basicVal, $taxVal, $mrpVal, $description !== '' ? $description : null]);
        flash('success', 'Added "' . $itemName . '".');
    } catch (Exception $e) {
        flash('error', 'Add failed: ' . $e->getMessage());
    }
    header('Location: index.php?page=shelf_life'); exit;
}

// ── CSV export of all products (round-trippable with import) ────
function exportShelfLifeCsv(): void {
    if (!isSuperadmin() && !hasTxn('shelf_life_upload')) {
        http_response_code(403); echo 'Access denied.'; return;
    }
    $filename = 'shelf_life_products_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Excel opens non-ASCII item names correctly
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['item_group','item_code','item_name','shelf_life_days','basic','tax','mrp','description'], escape: '');

    $rows = getDb()
        ->query('SELECT item_group, item_code, item_name, shelf_life_days, basic, tax, mrp, description
                 FROM product_shelf_life
                 ORDER BY item_group, item_name')
        ->fetchAll();

    if (!$rows) {
        // Empty DB → emit two example rows so the file is still usable as a template.
        fputcsv($out, ['Cake','CK001','Example Chocolate Cake 500gm','3','523.81','5.00','549.00','Chocolate sponge with cream layers.'], escape: '');
        fputcsv($out, ['Pastry','PS001','Example Pastry','2','104.76','','110.00','Layered vanilla pastry.'], escape: ''); // description column
    } else {
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['item_group'],
                $r['item_code'],
                $r['item_name'],
                (int)$r['shelf_life_days'],
                $r['basic'] !== null ? number_format((float)$r['basic'], 2, '.', '') : '',
                $r['tax']   !== null ? number_format((float)$r['tax'],   2, '.', '') : '',
                $r['mrp']   !== null ? number_format((float)$r['mrp'],   2, '.', '') : '',
                $r['description'] ?? '',
            ], escape: '');
        }
    }
    fclose($out);
    exit;
}

// ── Resize image to max dimensions ───────────────────────
function resizeImage(string $tmpPath, string $ext, int $origW, int $origH, int $maxW, int $maxH): ?string {
    // No resize needed if within limits
    if ($origW <= $maxW && $origH <= $maxH) {
        return file_get_contents($tmpPath);
    }

    $src = match ($ext) {
        'jpg', 'jpeg' => imagecreatefromjpeg($tmpPath),
        'png'         => imagecreatefrompng($tmpPath),
        'gif'         => imagecreatefromgif($tmpPath),
        'webp'        => imagecreatefromwebp($tmpPath),
        default       => null,
    };
    if (!$src) return null;

    // Calculate new dimensions keeping aspect ratio
    $ratio = min($maxW / $origW, $maxH / $origH);
    $newW = (int)round($origW * $ratio);
    $newH = (int)round($origH * $ratio);

    $dst = imagecreatetruecolor($newW, $newH);

    // Preserve transparency for PNG/GIF/WebP
    if (in_array($ext, ['png', 'gif', 'webp'])) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $newW, $newH, $transparent);
    }

    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

    ob_start();
    match ($ext) {
        'jpg', 'jpeg' => imagejpeg($dst, null, 85),
        'png'         => imagepng($dst, null, 8),
        'gif'         => imagegif($dst),
        'webp'        => imagewebp($dst, null, 85),
    };
    $data = ob_get_clean();

    imagedestroy($src);
    imagedestroy($dst);

    return $data ?: null;
}

// ── Serve shelf life image ───────────────────────────────
function serveShelfLifeImage(): void {
    $id = (int)($_GET['id'] ?? 0);
    $product = getShelfLifeProduct($id);
    if (!$product || empty($product['image'])) {
        http_response_code(404);
        echo 'Image not found';
        return;
    }

    $path = SL_UPLOAD_DIR . $product['image'];
    if (!file_exists($path)) {
        http_response_code(404);
        echo 'File not found';
        return;
    }

    $ext = mb_strtolower(pathinfo($product['image'], PATHINFO_EXTENSION));
    $mime = SL_ALLOWED_MIMES[$ext] ?? 'application/octet-stream';

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: public, max-age=86400');
    readfile($path);
    exit;
}

// ── Public (no-login) image server ───────────────────────
// Used by product.php?img=ID — safe because it only serves files
// referenced in the product_shelf_life.image column.
function servePublicShelfLifeImage(int $id): void {
    $product = getShelfLifeProduct($id);
    if (!$product || empty($product['image'])) {
        http_response_code(404); echo 'Image not found'; return;
    }
    $path = SL_UPLOAD_DIR . $product['image'];
    if (!file_exists($path)) { http_response_code(404); echo 'File not found'; return; }
    $ext  = mb_strtolower(pathinfo($product['image'], PATHINFO_EXTENSION));
    $mime = SL_ALLOWED_MIMES[$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: public, max-age=86400');
    readfile($path);
    exit;
}

// ── Public (no-login) read-only shelf life page ──────────
// Renders a full standalone HTML page so it can be shared with customers.
function pagePublicShelfLife(): void {
    $search = trim($_GET['search'] ?? '');
    $group  = trim($_GET['group']  ?? '');
    $groups = getShelfLifeGroups();
    $products = getShelfLifeProducts($search, $group);
    $title = getSetting('CompanyName', 'Product Shelf Life');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Product Shelf Life</title>
<style>
:root{--bg:#f6f8fb;--surface:#fff;--text:#1a2332;--muted:#6b7a8f;--border:#e2e8f0;--accent:#1a8fe3;--red:#e53e3e;--yellow:#d69e2e;--green:#38a169;--purple:#805ad5}
*{box-sizing:border-box}
html,body{margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:var(--bg);color:var(--text);font-size:14px}
.wrap{max-width:1100px;margin:0 auto;padding:16px}
.hdr{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:16px 20px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
.hdr h1{margin:0;font-size:20px;font-weight:700}
.hdr .sub{color:var(--muted);font-size:12px;margin-top:2px}
.filter{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:12px;margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap}
.filter input,.filter select{padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:14px;background:#fff}
.filter input{min-width:220px;flex:1}
.filter select{min-width:160px}
.btn{padding:8px 16px;border:none;border-radius:6px;cursor:pointer;font-size:14px;text-decoration:none;display:inline-block;font-weight:500}
.btn-p{background:var(--accent);color:#fff}
.btn-s{background:#edf2f7;color:var(--text)}
.tbl-wrap{background:var(--surface);border:1px solid var(--border);border-radius:10px;overflow:hidden}
table{width:100%;border-collapse:collapse}
th,td{padding:10px 12px;text-align:left;border-bottom:1px solid var(--border)}
th{background:#f7fafc;font-weight:600;font-size:12px;text-transform:uppercase;color:var(--muted);letter-spacing:.03em}
tr:last-child td{border-bottom:none}
.badge{display:inline-block;padding:3px 9px;border-radius:10px;font-size:12px;font-weight:600}
.badge-purple{background:#ede7f6;color:var(--purple)}
.badge-red{background:#fed7d7;color:var(--red)}
.badge-yellow{background:#faf089;color:var(--yellow)}
.badge-green{background:#c6f6d5;color:var(--green)}
.row-img{cursor:pointer}
.row-img:hover{background:rgba(26,143,227,.06)}
.empty{text-align:center;color:var(--muted);padding:30px}
.count{color:var(--muted);font-size:12px;margin-top:10px;text-align:right}
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:9999;align-items:center;justify-content:center}
.modal.on{display:flex}
.modal-box{background:#fff;border-radius:10px;padding:14px;max-width:90vw;max-height:90vh;position:relative}
.modal-x{position:absolute;top:6px;right:12px;font-size:26px;color:#888;cursor:pointer;line-height:1}
.modal-t{font-size:14px;margin:0 24px 8px 0;color:var(--text)}
.modal-i{display:block;max-width:min(1024px,80vw);max-height:min(768px,75vh);border-radius:6px}
.ico{vertical-align:middle;margin-left:4px}
@media (max-width:600px){th,td{padding:8px 6px;font-size:12px}.hdr h1{font-size:16px}}
</style>
</head>
<body>
<div class="wrap">
    <div class="hdr">
        <div>
            <h1>Product Shelf Life</h1>
            <div class="sub">Reference list · Updated daily</div>
        </div>
    </div>
    <form class="filter" method="GET" action="product.php">
        <select name="group">
            <option value="">All Groups</option>
            <?php foreach ($groups as $g): ?>
                <option value="<?= h($g) ?>" <?= $group === $g ? 'selected' : '' ?>><?= h($g) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="search" placeholder="Search name, code, description..." value="<?= h($search) ?>">
        <button class="btn btn-p" type="submit">Filter</button>
        <?php if ($search !== '' || $group !== ''): ?>
            <a href="product.php" class="btn btn-s">Clear</a>
        <?php endif; ?>
    </form>
    <div class="tbl-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:40px">#</th>
                    <th>Item Group</th>
                    <th>Item Code</th>
                    <th>Item Name</th>
                    <th style="text-align:center">Shelf Life (Days)</th>
                    <th style="text-align:right">MRP</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($products)): ?>
                <tr><td colspan="7" class="empty">No products found.</td></tr>
            <?php else: $i = 0; foreach ($products as $p): $i++;
                $hasImg = !empty($p['image']);
                $d = (int)$p['shelf_life_days'];
                $cls = $d <= 2 ? 'badge-red' : ($d <= 3 ? 'badge-yellow' : 'badge-green');
            ?>
                <tr class="<?= $hasImg ? 'row-img' : '' ?>"
                    <?php if ($hasImg): ?>onclick="showImg('<?= h(addslashes($p['item_name'])) ?>','product.php?img=<?= (int)$p['id'] ?>')"<?php endif; ?>>
                    <td><?= $i ?></td>
                    <td><span class="badge badge-purple"><?= h($p['item_group']) ?></span></td>
                    <td><?= h($p['item_code']) ?></td>
                    <td>
                        <?= h($p['item_name']) ?>
                        <?php if ($hasImg): ?>
                        <svg class="ico" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#1a8fe3" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center"><span class="badge <?= $cls ?>"><?= $d ?></span></td>
                    <td style="text-align:right;font-weight:600"><?= $p['mrp'] !== null ? number_format((float)$p['mrp'], 2) : '-' ?></td>
                    <td style="font-size:12px;color:var(--muted)"><?= h($p['description'] ?? '') ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="count"><?= count($products) ?> product(s)</div>
</div>

<div id="slMod" class="modal" onclick="closeImg(event)">
    <div class="modal-box">
        <span class="modal-x" onclick="closeImg()">&times;</span>
        <h4 id="slModT" class="modal-t"></h4>
        <img id="slModI" class="modal-i" src="" alt="">
    </div>
</div>
<script>
function showImg(n,u){document.getElementById('slModT').textContent=n;document.getElementById('slModI').src=u;document.getElementById('slMod').classList.add('on');}
function closeImg(e){if(!e||e.target===document.getElementById('slMod')||e.target.classList.contains('modal-x'))document.getElementById('slMod').classList.remove('on');}
document.addEventListener('keydown',function(e){if(e.key==='Escape')document.getElementById('slMod').classList.remove('on');});
</script>
</body>
</html><?php
}

// ── Page: Product Shelf Life ─────────────────────────────
function pageShelfLife(): void {
    $search = trim($_GET['search'] ?? '');
    $group  = trim($_GET['group']  ?? '');
    $groups = getShelfLifeGroups();
    $products = getShelfLifeProducts($search, $group);
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <h2 style="margin:0">Product Details</h2>
    <?php if (isSuperadmin() || hasTxn('shelf_life_upload')): ?>
    <div style="display:flex;gap:8px;align-items:center">
        <a href="?page=sl_export" class="btn btn-secondary btn-sm">Export CSV</a>
        <button type="button" class="btn btn-primary btn-sm" onclick="slOpenImport()">Import CSV</button>
        <button type="button" class="btn btn-success btn-sm" onclick="slOpenAdd()">+ Add Product</button>
    </div>
    <?php endif; ?>
</div>

<form class="rpt-filter" method="GET">
    <input type="hidden" name="page" value="shelf_life">
    <select name="group" class="form-control" style="width:180px">
        <option value="">All Groups</option>
        <?php foreach ($groups as $g): ?>
            <option value="<?= h($g) ?>" <?= $group === $g ? 'selected' : '' ?>><?= h($g) ?></option>
        <?php endforeach; ?>
    </select>
    <span class="input-clear-wrap" style="flex:1 1 auto;min-width:200px">
        <input type="text" name="search" class="form-control" placeholder="Search name, code, description..."
               value="<?= h($search) ?>">
        <button type="button" class="input-clear-btn" aria-label="Clear search" tabindex="-1">&times;</button>
    </span>
    <button class="btn btn-primary" type="submit">Filter</button>
    <?php if ($search !== '' || $group !== ''): ?>
        <a href="?page=shelf_life" class="btn btn-secondary">Clear</a>
    <?php endif; ?>
</form>

<div class="table-wrap" data-stack>
    <table class="table">
        <thead>
            <tr>
                <th>#</th>
                <th>Item Group</th>
                <th>Item Code</th>
                <th>Item Name</th>
                <th>Shelf Life (Days)</th>
                <th style="text-align:right">Basic</th>
                <th style="text-align:right">Tax (%)</th>
                <th style="text-align:right">MRP</th>
                <th>Description</th>
                <?php if ((isSuperadmin() || hasTxn('shelf_life_upload'))): ?><th style="width:130px">Actions</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($products)): ?>
            <tr><td colspan="<?= (isSuperadmin() || hasTxn('shelf_life_upload')) ? 10 : 9 ?>" class="empty-row">No products found.</td></tr>
        <?php else: $i = 0; foreach ($products as $p): $i++; ?>
            <tr class="<?= !empty($p['image']) ? 'sl-has-image' : '' ?>"
                <?php if (!empty($p['image'])): ?>
                onclick="slShowImage('<?= h($p['item_name']) ?>','?page=sl_image&id=<?= $p['id'] ?>')"
                style="cursor:pointer"
                <?php endif; ?>>
                <td><?= $i ?></td>
                <td><span class="badge badge-purple"><?= h($p['item_group']) ?></span></td>
                <td><?= h($p['item_code']) ?></td>
                <td>
                    <?= h($p['item_name']) ?>
                    <?php if (!empty($p['image'])): ?>
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2" style="vertical-align:middle;margin-left:4px"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                    <?php endif; ?>
                </td>
                <td style="text-align:center;font-weight:600">
                    <?php
                        $d = (int)$p['shelf_life_days'];
                        $cls = $d <= 2 ? 'badge-red' : ($d <= 3 ? 'badge-yellow' : 'badge-green');
                    ?>
                    <span class="badge <?= $cls ?>"><?= $d ?></span>
                </td>
                <td style="text-align:right;font-weight:600"><?= $p['basic'] !== null ? number_format((float)$p['basic'], 2) : '-' ?></td>
                <td style="text-align:right"><?= $p['tax'] !== null ? number_format((float)$p['tax'], 2) : '-' ?></td>
                <td style="text-align:right;font-weight:600"><?= $p['mrp'] !== null ? number_format((float)$p['mrp'], 2) : '-' ?></td>
                <td style="font-size:12px;color:var(--muted)"><?= h($p['description'] ?? '') ?></td>
                <?php if ((isSuperadmin() || hasTxn('shelf_life_upload'))): ?>
                <td onclick="event.stopPropagation()" style="white-space:nowrap">
                    <button type="button" class="btn btn-ghost btn-sm" style="padding:4px 8px;margin:0"
                            onclick="slEdit(<?= (int)$p['id'] ?>,<?= h(json_encode($p['item_group'])) ?>,<?= h(json_encode($p['item_code'])) ?>,<?= h(json_encode($p['item_name'])) ?>,<?= (int)$p['shelf_life_days'] ?>,<?= h(json_encode($p['basic'] ?? '')) ?>,<?= h(json_encode($p['tax'] ?? '')) ?>,<?= h(json_encode($p['mrp'] ?? '')) ?>,<?= h(json_encode($p['description'] ?? '')) ?>)">Edit</button>
                    <form method="POST" enctype="multipart/form-data" style="display:inline">
                        <input type="hidden" name="action" value="sl_upload">
                        <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                        <label class="btn btn-ghost btn-sm" style="cursor:pointer;margin:0;padding:4px 8px">
                            <?= !empty($p['image']) ? 'Replace' : 'Upload' ?>
                            <input type="file" name="image" accept="image/*" style="display:none"
                                   onchange="this.form.submit()">
                        </label>
                    </form>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<div class="table-count"><?= count($products) ?> product(s) · Max image: 1024x768, 5MB (jpg, png, gif, webp)</div>

<!-- Image popup modal -->
<div id="slModal" class="sl-modal" onclick="slCloseModal(event)">
    <div class="sl-modal-content">
        <span class="sl-modal-close" onclick="slCloseModal()">&times;</span>
        <h4 id="slModalTitle" class="sl-modal-title"></h4>
        <img id="slModalImg" class="sl-modal-img" src="" alt="">
    </div>
</div>

<style>
.sl-has-image:hover{background:rgba(26,143,227,.06)}
.sl-modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.7);z-index:9999;align-items:center;justify-content:center}
.sl-modal.active{display:flex}
.sl-modal-content{background:var(--surface);border-radius:10px;padding:16px;max-width:90vw;max-height:90vh;position:relative;border:1px solid var(--border)}
.sl-modal-close{position:absolute;top:8px;right:14px;font-size:24px;color:var(--muted);cursor:pointer;z-index:1}
.sl-modal-close:hover{color:var(--text)}
.sl-modal-title{font-size:14px;margin-bottom:10px;padding-right:24px;color:var(--text)}
.sl-modal-img{display:block;max-width:min(1024px,80vw);max-height:min(768px,75vh);border-radius:6px;object-fit:contain}
</style>
<script>
function slShowImage(name,url){
    document.getElementById('slModalTitle').textContent=name;
    document.getElementById('slModalImg').src=url;
    document.getElementById('slModal').classList.add('active');
}
function slCloseModal(e){
    if(!e||e.target===document.getElementById('slModal')||e.target.classList.contains('sl-modal-close'))
        document.getElementById('slModal').classList.remove('active');
}
document.addEventListener('keydown',function(e){if(e.key==='Escape'){document.getElementById('slModal')?.classList.remove('active');document.getElementById('slEditModal')?.classList.remove('active');document.getElementById('slImportModal')?.classList.remove('active');document.getElementById('slAddModal')?.classList.remove('active');}});
</script>

<?php if (isSuperadmin() || hasTxn('shelf_life_upload')): ?>
<!-- Import CSV modal -->
<div id="slImportModal" class="sl-modal" onclick="slCloseImport(event)">
    <div class="sl-modal-content" style="max-width:560px;width:90vw">
        <span class="sl-modal-close" onclick="slCloseImport()">&times;</span>
        <h4 class="sl-modal-title">Import Products (CSV)</h4>
        <form method="POST" enctype="multipart/form-data" id="slImportForm" onsubmit="return slConfirmImport()">
            <input type="hidden" name="action" value="sl_import">
            <div style="display:flex;flex-direction:column;gap:12px">
                <div>
                    <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:6px">Mode</label>
                    <div style="display:flex;flex-direction:column;gap:6px;font-size:13px">
                        <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer">
                            <input type="radio" name="mode" value="replace" checked style="margin-top:3px" onchange="slSyncImportMode()">
                            <span><strong>Replace</strong>
                                <span style="display:block;font-size:11px;color:var(--muted)">Truncates the list (auto_increment resets to 1) and re-inserts every CSV row. Product images are wiped.</span>
                            </span>
                        </label>
                        <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer">
                            <input type="radio" name="mode" value="update" style="margin-top:3px" onchange="slSyncImportMode()">
                            <span><strong>Update</strong>
                                <span style="display:block;font-size:11px;color:var(--muted)">Upsert by <code>item_code</code> — existing rows updated (images kept), new items added.</span>
                            </span>
                        </label>
                    </div>
                </div>
                <div id="slImportReplaceWarn" style="background:#fff4e5;border:1px solid #f4c37d;border-radius:6px;padding:10px 12px;font-size:12px;color:#7a4a00;line-height:1.5">
                    <b>Warning:</b> Replace will <b>delete all existing products</b> and remove their images.
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">CSV file</label>
                    <input type="file" name="csv" accept=".csv,text/csv" required class="form-control" style="width:100%">
                    <div style="font-size:11px;color:var(--muted);margin-top:4px">
                        Required columns: <code>item_group, item_code, item_name, shelf_life_days</code>.
                        Optional: <code>basic, tax, mrp, description</code>.
                        Tip: <a href="?page=sl_export">Export CSV</a> first, edit it, then import.
                    </div>
                </div>
                <label id="slImportConfirmRow" style="font-size:13px;display:flex;align-items:flex-start;gap:8px;cursor:pointer;padding:8px 0">
                    <input type="checkbox" name="confirm" value="1" required style="margin-top:3px">
                    <span>I understand that all current shelf-life products will be replaced by the contents of this file.</span>
                </label>
                <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:4px">
                    <button type="button" class="btn btn-secondary" onclick="slCloseImport()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="slImportSubmit">Replace &amp; Import</button>
                </div>
            </div>
        </form>
    </div>
</div>
<script>
function slOpenImport(){
    document.getElementById('slImportModal').classList.add('active');
    slSyncImportMode();
}
function slCloseImport(e){
    if(!e||e.target===document.getElementById('slImportModal')||e.target.classList.contains('sl-modal-close'))
        document.getElementById('slImportModal').classList.remove('active');
}
function slCurrentImportMode(){
    var form = document.getElementById('slImportForm');
    if (!form) return 'replace';
    var sel = form.querySelector('input[name="mode"]:checked');
    return sel ? sel.value : 'replace';
}
// Replace = destructive: show the warning + require the confirm checkbox.
// Update = non-destructive: hide the warning, drop the checkbox requirement
// (and uncheck it so it doesn't submit), and relabel the submit button.
function slSyncImportMode(){
    var mode = slCurrentImportMode();
    var warn = document.getElementById('slImportReplaceWarn');
    var row  = document.getElementById('slImportConfirmRow');
    var cb   = row ? row.querySelector('input[name="confirm"]') : null;
    var btn  = document.getElementById('slImportSubmit');
    if (mode === 'update') {
        if (warn) warn.style.display = 'none';
        if (row)  row.style.display  = 'none';
        if (cb)   { cb.required = false; cb.checked = false; }
        if (btn)  btn.textContent = 'Update from CSV';
    } else {
        if (warn) warn.style.display = '';
        if (row)  row.style.display  = '';
        if (cb)   cb.required = true;
        if (btn)  btn.textContent = 'Replace & Import';
    }
}
function slConfirmImport(){
    if (slCurrentImportMode() === 'update') return true;
    return confirm('This will DELETE all existing shelf-life products and replace them. Continue?');
}
</script>

<!-- Add Product modal -->
<div id="slAddModal" class="sl-modal" onclick="slCloseAdd(event)">
    <div class="sl-modal-content" style="max-width:480px;width:90vw">
        <span class="sl-modal-close" onclick="slCloseAdd()">&times;</span>
        <h4 class="sl-modal-title">Add Product</h4>
        <form method="POST">
            <input type="hidden" name="action" value="sl_add">
            <div style="display:flex;flex-direction:column;gap:12px">
                <div>
                    <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Item Group <span class="required">*</span></label>
                    <input type="text" name="item_group" class="form-control" required maxlength="100" style="width:100%" list="slGroupList">
                    <datalist id="slGroupList">
                        <?php foreach ($groups as $g): ?>
                        <option value="<?= h($g) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Item Code <span class="required">*</span></label>
                    <input type="text" name="item_code" class="form-control" required maxlength="50" style="width:100%;font-family:Consolas,monospace;font-size:12.5px">
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Item Name <span class="required">*</span></label>
                    <input type="text" name="item_name" class="form-control" required maxlength="255" style="width:100%">
                </div>
                <div style="display:flex;gap:12px">
                    <div style="flex:1">
                        <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Shelf Life (Days) <span class="required">*</span></label>
                        <input type="number" name="shelf_life_days" class="form-control" min="0" required style="width:100%" value="0">
                    </div>
                    <div style="flex:1">
                        <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Basic</label>
                        <input type="number" name="basic" class="form-control" step="0.01" min="0" style="width:100%" placeholder="Optional">
                    </div>
                    <div style="flex:1">
                        <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Tax (%)</label>
                        <input type="number" name="tax" class="form-control" step="0.01" min="0" max="100" style="width:100%" placeholder="Optional">
                    </div>
                    <div style="flex:1">
                        <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">MRP</label>
                        <input type="number" name="mrp" class="form-control" step="0.01" min="0" style="width:100%" placeholder="Optional">
                    </div>
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Description</label>
                    <textarea name="description" class="form-control" rows="2" style="width:100%" placeholder="Optional"></textarea>
                </div>
                <div style="font-size:11px;color:var(--muted)">
                    Item code must be unique. Add the product image afterwards via the <strong>Upload</strong> button on its row.
                </div>
                <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:4px">
                    <button type="button" class="btn btn-secondary" onclick="slCloseAdd()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Product</button>
                </div>
            </div>
        </form>
    </div>
</div>
<script>
function slOpenAdd(){document.getElementById('slAddModal').classList.add('active');}
function slCloseAdd(e){
    if(!e||e.target===document.getElementById('slAddModal')||e.target.classList.contains('sl-modal-close'))
        document.getElementById('slAddModal').classList.remove('active');
}
</script>

<!-- Edit product modal -->
<div id="slEditModal" class="sl-modal" onclick="slCloseEdit(event)">
    <div class="sl-modal-content" style="max-width:480px;width:90vw">
        <span class="sl-modal-close" onclick="slCloseEdit()">&times;</span>
        <h4 class="sl-modal-title">Edit Product</h4>
        <form method="POST" id="slEditForm">
            <input type="hidden" name="action" value="sl_update">
            <input type="hidden" name="product_id" id="slEditId">
            <div style="display:flex;flex-direction:column;gap:12px">
                <div>
                    <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Item Group</label>
                    <input type="text" name="item_group" id="slEditGroup" class="form-control" required style="width:100%">
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Item Code</label>
                    <input type="text" name="item_code" id="slEditCode" class="form-control" required style="width:100%">
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Item Name</label>
                    <input type="text" name="item_name" id="slEditName" class="form-control" required style="width:100%">
                </div>
                <div style="display:flex;gap:12px">
                    <div style="flex:1">
                        <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Shelf Life (Days)</label>
                        <input type="number" name="shelf_life_days" id="slEditDays" class="form-control" min="0" required style="width:100%">
                    </div>
                    <div style="flex:1">
                        <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Basic</label>
                        <input type="number" name="basic" id="slEditBasic" class="form-control" step="0.01" min="0" style="width:100%" placeholder="Optional">
                    </div>
                    <div style="flex:1">
                        <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Tax (%)</label>
                        <input type="number" name="tax" id="slEditTax" class="form-control" step="0.01" min="0" max="100" style="width:100%" placeholder="Optional">
                    </div>
                    <div style="flex:1">
                        <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">MRP</label>
                        <input type="number" name="mrp" id="slEditMrp" class="form-control" step="0.01" min="0" style="width:100%" placeholder="Optional">
                    </div>
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Description</label>
                    <textarea name="description" id="slEditDescription" class="form-control" rows="2" style="width:100%"></textarea>
                </div>
                <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:4px">
                    <button type="button" class="btn btn-secondary" onclick="slCloseEdit()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </div>
        </form>
    </div>
</div>
<script>
function slEdit(id,group,code,name,days,basic,tax,mrp,description){
    document.getElementById('slEditId').value=id;
    document.getElementById('slEditGroup').value=group;
    document.getElementById('slEditCode').value=code;
    document.getElementById('slEditName').value=name;
    document.getElementById('slEditDays').value=days;
    document.getElementById('slEditBasic').value=basic||'';
    document.getElementById('slEditTax').value=tax||'';
    document.getElementById('slEditMrp').value=mrp||'';
    document.getElementById('slEditDescription').value=description||'';
    document.getElementById('slEditModal').classList.add('active');
}
function slCloseEdit(e){
    if(!e||e.target===document.getElementById('slEditModal')||e.target.classList.contains('sl-modal-close'))
        document.getElementById('slEditModal').classList.remove('active');
}
</script>
<?php endif; ?>
<?php
}
