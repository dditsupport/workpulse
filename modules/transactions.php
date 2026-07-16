<?php
// =========================================================
// Retail Transactions — bank deposit slip uploads
//
// Store managers (any logged-in employee with a self-claim
// location) upload a bank deposit receipt with date + amount
// + optional remark + one image/PDF. Default location is
// the user's claimed store but the dropdown shows all
// active locations so a manager can record a deposit made
// for a sister store if needed.
//
// Permissions:
//   - upload + browse history : myLocationId() > 0 OR superadmin
//   - report / CSV export     : txn_transactions_report (separate file)
//   - bulk delete by date     : superadmin only
// =========================================================

define('TXN_UPLOAD_DIR',  __DIR__ . '/../uploads/transactions/');
define('TXN_MAX_BYTES',   10 * 1024 * 1024); // 10 MB
const TXN_ALLOWED_EXT  = ['jpg','jpeg','png','pdf'];
const TXN_ALLOWED_MIME = ['image/jpeg','image/png','application/pdf'];

// ── Permission helpers ──────────────────────────────────
function canUploadTransaction(): bool {
    return isSuperadmin() || myLocationId() > 0;
}
function canViewTransactionReport(): bool {
    return isSuperadmin() || hasTxn('transactions_report');
}
// Validation is the same role gate as viewing the report — anyone who
// can audit deposits can mark them validated. Bulk-delete stays
// superadmin-only by design (handled separately in the report module).
function canValidateTransaction(): bool {
    return canViewTransactionReport();
}

// migration_2026_05_06_txn_validate.sql adds validated_at / validated_by
// to the transactions table. We detect once per request so the report
// keeps rendering on databases that haven't run the migration — the
// validate button just won't appear there.
function txnHasValidationCols(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        getDb()->query('SELECT validated_at FROM transactions LIMIT 0')->fetch();
        $cached = true;
    } catch (Exception $e) {
        $cached = false;
    }
    return $cached;
}

// migration_2026_05_07_txn_invalidate.sql adds invalidated_at /
// invalidated_by / invalidate_reason. Same detect-once pattern so the
// page survives an un-migrated DB — the Invalidate button just won't
// appear and CSV totals work the way they did before the feature.
function txnHasInvalidateCols(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        getDb()->query('SELECT invalidated_at FROM transactions LIMIT 0')->fetch();
        $cached = true;
    } catch (Exception $e) {
        $cached = false;
    }
    return $cached;
}

// Path-on-disk for a given txn_date + stored filename.
function txnAttachmentPath(string $txnDate, string $storedName): string {
    $d = date('Y/m/d', strtotime($txnDate));
    return TXN_UPLOAD_DIR . $d . '/' . $storedName;
}

// ── Upload handler ──────────────────────────────────────
function doSaveTransaction(): void {
    if (!canUploadTransaction()) {
        flash('error', 'You do not have permission to upload cash deposits.');
        header('Location: index.php?page=transactions'); exit;
    }

    $locationId = (int)($_POST['location_id'] ?? 0);
    $txnDate    = trim($_POST['txn_date'] ?? '');
    $amount     = (float)($_POST['amount'] ?? 0);
    $remark     = trim($_POST['remark'] ?? '');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $txnDate)) {
        flash('error', 'Invalid date.');
        header('Location: index.php?page=transactions'); exit;
    }
    if ($txnDate > date('Y-m-d')) {
        flash('error', 'Cannot record a cash deposit for a future date.');
        header('Location: index.php?page=transactions'); exit;
    }
    if ($locationId <= 0) {
        flash('error', 'Please choose a location.');
        header('Location: index.php?page=transactions'); exit;
    }
    if ($amount <= 0) {
        flash('error', 'Amount must be greater than zero.');
        header('Location: index.php?page=transactions'); exit;
    }
    if (mb_strlen($remark) > 500) {
        flash('error', 'Remark too long (max 500 chars).');
        header('Location: index.php?page=transactions'); exit;
    }

    // File required.
    if (empty($_FILES['receipt']) || ($_FILES['receipt']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        flash('error', 'Receipt file is required.');
        header('Location: index.php?page=transactions'); exit;
    }
    $f = $_FILES['receipt'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        flash('error', 'Upload failed (error code ' . (int)$f['error'] . ').');
        header('Location: index.php?page=transactions'); exit;
    }
    if ((int)$f['size'] > TXN_MAX_BYTES) {
        flash('error', 'File exceeds ' . (TXN_MAX_BYTES / 1024 / 1024) . ' MB.');
        header('Location: index.php?page=transactions'); exit;
    }
    $orig = basename((string)$f['name']);
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, TXN_ALLOWED_EXT, true)) {
        flash('error', 'Unsupported file type "' . $ext . '". Allowed: ' . implode(', ', TXN_ALLOWED_EXT) . '.');
        header('Location: index.php?page=transactions'); exit;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file((string)$f['tmp_name']) ?: '';
    if (!in_array($mime, TXN_ALLOWED_MIME, true)) {
        flash('error', 'File "' . $orig . '" is not a recognized image or PDF (mime: ' . $mime . ').');
        header('Location: index.php?page=transactions'); exit;
    }

    // Date-organised target dir.
    $targetDir = TXN_UPLOAD_DIR . date('Y/m/d', strtotime($txnDate)) . '/';
    if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);
    if (!is_dir($targetDir) || !is_writable($targetDir)) {
        flash('error', 'Upload directory not writable.');
        header('Location: index.php?page=transactions'); exit;
    }

    $stored = uniqid('txn_', true) . '.' . $ext;
    if (!move_uploaded_file((string)$f['tmp_name'], $targetDir . $stored)) {
        flash('error', 'Could not save uploaded file.');
        header('Location: index.php?page=transactions'); exit;
    }

    try {
        $st = getDb()->prepare(
            'INSERT INTO transactions
                (location_id, txn_date, amount, remark,
                 original_name, stored_name, mime_type, size_bytes,
                 uploaded_by, uploaded_at)
             VALUES (?,?,?,?,?,?,?,?,?, NOW())'
        );
        $st->execute([
            $locationId, $txnDate, $amount, ($remark === '' ? null : $remark),
            $orig, $stored, $mime, (int)$f['size'], myCode(),
        ]);
        flash('success', 'Cash deposit recorded.');
    } catch (Exception $e) {
        // Roll back the file we just saved so we don't leave an orphan.
        @unlink($targetDir . $stored);
        flash('error', 'Could not save cash deposit: ' . $e->getMessage());
    }

    $qs = http_build_query(['page' => 'transactions', 'location_id' => $locationId]);
    header('Location: index.php?' . $qs); exit;
}

// ── Validate a single deposit (mark as reviewed) ────────
// Called from the report popup after the reviewer eyeballs the receipt
// image. Idempotent — re-validating just refreshes who/when. Returns
// JSON when the request asks for it (XHR from the popup) and otherwise
// redirects back to the report.
function doValidateTransaction(): void {
    $wantsJson = isset($_POST['xhr']) || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
    // For XHR responses, kill any stray output buffered earlier in the
    // request (PHP notices, accidental whitespace before <?php in an
    // included file, etc). Without this the JSON gets prefixed with
    // garbage and the popup's r.json() fails with "Bad response".
    if ($wantsJson) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        // Suppress notices/warnings from polluting the JSON body. They'd
        // still surface to the PHP error log via error_log() defaults.
        @ini_set('display_errors', '0');
    }
    if (!canValidateTransaction()) {
        if ($wantsJson) { http_response_code(403); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'Access denied.']); exit; }
        flash('error', 'Access denied.');
        header('Location: index.php?page=transactions_report'); exit;
    }
    if (!txnHasValidationCols()) {
        $msg = 'Validation is not enabled — run migration_2026_05_06_txn_validate.sql.';
        if ($wantsJson) { http_response_code(409); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => $msg]); exit; }
        flash('error', $msg);
        header('Location: index.php?page=transactions_report'); exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        if ($wantsJson) { http_response_code(400); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'Bad request.']); exit; }
        flash('error', 'Bad request.');
        header('Location: index.php?page=transactions_report'); exit;
    }
    try {
        // When the invalidate columns are present, re-validating also
        // clears the invalidate state so the row goes back into the totals
        // and the export. Without that the row would stay excluded.
        if (txnHasInvalidateCols()) {
            $st = getDb()->prepare(
                'UPDATE transactions
                 SET validated_at = NOW(), validated_by = ?,
                     invalidated_at = NULL, invalidated_by = NULL, invalidate_reason = NULL
                 WHERE id = ?'
            );
        } else {
            $st = getDb()->prepare('UPDATE transactions SET validated_at = NOW(), validated_by = ? WHERE id = ?');
        }
        $st->execute([myCode(), $id]);
        if ($st->rowCount() === 0) {
            if ($wantsJson) { http_response_code(404); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'Deposit not found.']); exit; }
            flash('error', 'Deposit not found.');
            header('Location: index.php?page=transactions_report'); exit;
        }
    } catch (Exception $e) {
        if ($wantsJson) { http_response_code(500); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => $e->getMessage()]); exit; }
        flash('error', 'Validate failed: ' . $e->getMessage());
        header('Location: index.php?page=transactions_report'); exit;
    }
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode([
            'ok'           => true,
            'id'           => $id,
            'validated_by' => myCode(),
            'validator'    => myName(),
            'validated_at' => date('Y-m-d H:i:s'),
        ]);
        exit;
    }
    flash('success', 'Cash deposit validated.');
    $back = $_POST['return_date'] ?? '';
    $qs = http_build_query(array_filter(['page' => 'transactions_report', 'date' => $back]));
    header('Location: index.php?' . $qs); exit;
}

// ── Mark a deposit invalid (drop it from totals + CSV export) ────
// Mutually exclusive with validation: invalidating clears any prior
// validated_* stamp, and the corresponding doValidateTransaction()
// clears the invalidate state when the same deposit is re-validated.
// Optional reason (max 500 chars) goes alongside who/when.
function doInvalidateTransaction(): void {
    $wantsJson = isset($_POST['xhr']) || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
    if ($wantsJson) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        @ini_set('display_errors', '0');
    }
    if (!canValidateTransaction()) {
        if ($wantsJson) { http_response_code(403); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'Access denied.']); exit; }
        flash('error', 'Access denied.');
        header('Location: index.php?page=transactions_report'); exit;
    }
    if (!txnHasInvalidateCols()) {
        $msg = 'Invalidate is not enabled — run migration_2026_05_07_txn_invalidate.sql.';
        if ($wantsJson) { http_response_code(409); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => $msg]); exit; }
        flash('error', $msg);
        header('Location: index.php?page=transactions_report'); exit;
    }
    $id     = (int)($_POST['id'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? ''));
    if (mb_strlen($reason) > 500) $reason = mb_substr($reason, 0, 500);
    if ($id <= 0) {
        if ($wantsJson) { http_response_code(400); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'Bad request.']); exit; }
        flash('error', 'Bad request.');
        header('Location: index.php?page=transactions_report'); exit;
    }
    try {
        $st = getDb()->prepare(
            'UPDATE transactions
             SET invalidated_at = NOW(), invalidated_by = ?, invalidate_reason = ?,
                 validated_at = NULL, validated_by = NULL
             WHERE id = ?'
        );
        $st->execute([myCode(), ($reason === '' ? null : $reason), $id]);
        if ($st->rowCount() === 0) {
            if ($wantsJson) { http_response_code(404); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'Deposit not found.']); exit; }
            flash('error', 'Deposit not found.');
            header('Location: index.php?page=transactions_report'); exit;
        }
    } catch (Exception $e) {
        if ($wantsJson) { http_response_code(500); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => $e->getMessage()]); exit; }
        flash('error', 'Invalidate failed: ' . $e->getMessage());
        header('Location: index.php?page=transactions_report'); exit;
    }
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode([
            'ok'              => true,
            'id'              => $id,
            'invalidated_by'  => myCode(),
            'invalidator'     => myName(),
            'invalidated_at'  => date('Y-m-d H:i:s'),
            'reason'          => $reason,
        ]);
        exit;
    }
    flash('success', 'Cash deposit marked invalid.');
    $back = $_POST['return_date'] ?? '';
    $qs = http_build_query(array_filter(['page' => 'transactions_report', 'date' => $back]));
    header('Location: index.php?' . $qs); exit;
}

// ── Attachment download ─────────────────────────────────
function doDownloadTxnAttachment(): void {
    if (!canUploadTransaction() && !canViewTransactionReport()) {
        http_response_code(403); echo 'Access denied.'; return;
    }
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(404); echo 'Not found.'; return; }

    try {
        $st = getDb()->prepare(
            'SELECT id, txn_date, original_name, stored_name, mime_type, location_id
             FROM transactions WHERE id = ?'
        );
        $st->execute([$id]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $t = null; }
    if (!$t) { http_response_code(404); echo 'Not found.'; return; }

    // Scope check: superadmin and report-viewers (txn_transactions_report)
    // can fetch any receipt; everyone else may only fetch receipts whose
    // location matches their self-claim. Without this, exposing receipt
    // IDs in the new "all deposits at my location" history would let a
    // store manager guess IDs for receipts at other stores.
    if (!isSuperadmin() && !canViewTransactionReport()
        && (int)$t['location_id'] !== myLocationId()) {
        http_response_code(403); echo 'Access denied.'; return;
    }

    $path = txnAttachmentPath((string)$t['txn_date'], (string)$t['stored_name']);
    if (!is_file($path)) { http_response_code(404); echo 'File missing.'; return; }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($path) ?: ($t['mime_type'] ?: 'application/octet-stream');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . str_replace('"', '', (string)$t['original_name']) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ── Page: upload form + history list ────────────────────
function pageTransactions(): void {
    if (!canUploadTransaction()) {
        echo '<div class="page-header"><h2>Banking Cash Deposit</h2></div>';
        echo '<div class="rpt-prompt">You don\'t have access to record cash deposits. Set your store under "My Location" first.</div>';
        return;
    }

    $db        = getDb();
    $locations = getActiveLocations();
    $myLoc     = myLocationId();
    $myCode    = myCode();

    // Filter — for the history list shown on this page.
    // Default range = first of current month → today.
    $today       = date('Y-m-d');
    $monthStart  = date('Y-m-01');
    $dateFrom    = $_GET['date_from'] ?? $monthStart;
    $dateTo      = $_GET['date_to']   ?? $today;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = $monthStart;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = $today;

    // History is scoped to the user's self-claim location — every deposit
    // at that location is visible regardless of who uploaded it. The store
    // manager (whoever has the self-claim) sees their team's full activity.
    $rows = [];
    if ($myLoc > 0) {
        $st = $db->prepare(
            'SELECT t.id, t.txn_date, t.amount, t.remark, t.original_name,
                    t.uploaded_by, t.uploaded_at, l.location_name,
                    e.full_name AS uploader_name
             FROM transactions t
             LEFT JOIN locations l ON l.location_id = t.location_id
             LEFT JOIN employees e ON e.employee_code = t.uploaded_by
             WHERE t.location_id = ?
               AND t.txn_date BETWEEN ? AND ?
             ORDER BY t.txn_date DESC, t.id DESC'
        );
        $st->execute([$myLoc, $dateFrom, $dateTo]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    $totalAmount = 0.0;
    foreach ($rows as $r) $totalAmount += (float)$r['amount'];
?>
<div class="page-header"><h2>💵 Banking Cash Deposit</h2></div>

<!-- Upload form -->
<form method="POST" enctype="multipart/form-data" class="form-card" style="max-width:none;margin-bottom:18px"
      id="txnUploadForm">
    <input type="hidden" name="action" value="save_transaction">
    <div class="form-section-title">Record Bank Deposit</div>
    <div class="form-grid" style="grid-template-columns:repeat(2,1fr);max-width:840px">
        <div class="form-group">
            <label>Location <span class="required">*</span></label>
            <select name="location_id" class="form-control" required>
                <?php if ($myLoc <= 0): ?><option value="">— pick a location —</option><?php endif; ?>
                <?php foreach ($locations as $l): ?>
                    <option value="<?= (int)$l['location_id'] ?>"
                        <?= (int)$l['location_id'] === $myLoc ? 'selected' : '' ?>>
                        <?= h($l['location_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Deposit Date <span class="required">*</span></label>
            <input type="date" name="txn_date" class="form-control"
                   value="<?= h(date('Y-m-d')) ?>" max="<?= h(date('Y-m-d')) ?>" required>
        </div>
        <div class="form-group">
            <label>Amount (₹) <span class="required">*</span></label>
            <input type="number" name="amount" class="form-control"
                   step="0.01" min="0.01" placeholder="0.00" required>
        </div>
        <div class="form-group">
            <label>Receipt (image or PDF) <span class="required">*</span></label>
            <input type="file" name="receipt" id="txn-receipt" class="form-control"
                   accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" required>
            <small style="color:var(--muted);font-size:11px">Max <?= (int)(TXN_MAX_BYTES / 1024 / 1024) ?> MB. Allowed: <?= h(implode(', ', TXN_ALLOWED_EXT)) ?>. Photos are auto-compressed in your browser before upload.</small>
            <div id="txn-receipt-status" style="font-size:11px;margin-top:4px;color:var(--muted);min-height:14px"></div>
        </div>
        <div class="form-group" style="grid-column:1 / -1">
            <label>Remark</label>
            <input type="text" name="remark" class="form-control" maxlength="500"
                   placeholder="Optional — bank/branch, slip ref, etc.">
        </div>
    </div>
    <div style="margin-top:14px">
        <button type="submit" class="btn btn-primary" id="txn-submit-btn">Submit</button>
    </div>
</form>

<script>
// Client-side image compression for the bank-deposit receipt.
//
// Why: store managers in low-signal areas were hitting UPLOAD_ERR_PARTIAL
// (error code 3) on full-resolution phone photos that were 4–8 MB. We
// downscale to 1600px max edge and re-encode as JPEG q=0.75 in the
// browser before the upload starts. Net effect: a 6 MB photo becomes
// ~250–500 KB, which uploads in seconds even on weak LTE.
//
// PDFs and already-small images (<600 KB) bypass compression untouched.
// Submit is gated while compression is in flight so the form can't post
// the original heavy file by accident.
(function () {
    var input    = document.getElementById('txn-receipt');
    var status   = document.getElementById('txn-receipt-status');
    var form     = document.getElementById('txnUploadForm');
    var submit   = document.getElementById('txn-submit-btn');
    if (!input || !form || !submit) return;

    var MAX_EDGE     = 1600;       // px — long-edge cap; bank slips remain legible
    var SKIP_BELOW   = 600 * 1024; // 600 KB — not worth re-encoding
    var JPEG_QUALITY = 0.75;
    var compressing  = false;

    function fmtSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1024 / 1024).toFixed(2) + ' MB';
    }

    function setBusy(msg) {
        compressing = true;
        submit.disabled = true;
        status.style.color = 'var(--muted)';
        status.textContent = msg;
    }
    function setDone(msg, color) {
        compressing = false;
        submit.disabled = false;
        status.style.color = color || 'var(--muted)';
        status.textContent = msg;
    }

    // Replace the file in the file input by spinning up a DataTransfer
    // (the only API that lets you write to input.files). Falls back to
    // leaving the original file in place if the browser doesn't allow it.
    function setFile(file) {
        try {
            var dt = new DataTransfer();
            dt.items.add(file);
            input.files = dt.files;
            return true;
        } catch (e) { return false; }
    }

    function decode(file) {
        // createImageBitmap honours EXIF orientation when asked, which
        // matters for portrait phone photos. Fall back to <img> when the
        // option (or createImageBitmap) is missing.
        if (typeof createImageBitmap === 'function') {
            try {
                return createImageBitmap(file, { imageOrientation: 'from-image' });
            } catch (e) {
                return createImageBitmap(file);
            }
        }
        return new Promise(function (resolve, reject) {
            var url = URL.createObjectURL(file);
            var img = new Image();
            img.onload  = function () { URL.revokeObjectURL(url); resolve(img); };
            img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('image decode failed')); };
            img.src = url;
        });
    }

    input.addEventListener('change', function () {
        var f = input.files && input.files[0];
        if (!f) { setDone(''); return; }

        // PDFs pass through.
        if (f.type === 'application/pdf' || /\.pdf$/i.test(f.name)) {
            setDone('PDF selected — ' + fmtSize(f.size) + ' (no compression).');
            return;
        }
        // Non-image, unknown type — let the server validator handle it.
        if (!/^image\//.test(f.type)) {
            setDone('');
            return;
        }
        // Already small — skip the round trip.
        if (f.size <= SKIP_BELOW) {
            setDone('Selected — ' + fmtSize(f.size) + ' (already small, no compression).');
            return;
        }

        setBusy('Compressing photo… please wait.');
        var origSize = f.size;

        decode(f).then(function (bmp) {
            var w = bmp.width || bmp.naturalWidth;
            var h = bmp.height || bmp.naturalHeight;
            if (!w || !h) throw new Error('cannot read image dimensions');
            var scale = Math.min(1, MAX_EDGE / Math.max(w, h));
            var tw = Math.round(w * scale);
            var th = Math.round(h * scale);
            var canvas = document.createElement('canvas');
            canvas.width = tw; canvas.height = th;
            var ctx = canvas.getContext('2d');
            ctx.drawImage(bmp, 0, 0, tw, th);
            return new Promise(function (resolve, reject) {
                canvas.toBlob(function (blob) {
                    if (!blob) reject(new Error('canvas toBlob returned null'));
                    else       resolve(blob);
                }, 'image/jpeg', JPEG_QUALITY);
            });
        }).then(function (blob) {
            // If somehow the re-encode came out larger (unlikely), keep
            // the original — re-encoding a tiny JPEG can sometimes do
            // that.
            if (blob.size >= origSize) {
                setDone('Selected — ' + fmtSize(origSize) + ' (compression skipped, original was already smaller).');
                return;
            }
            var nameBase = (f.name || 'receipt').replace(/\.(png|jpe?g|gif|webp|heic|heif)$/i, '');
            var newFile = new File([blob], nameBase + '.jpg', { type: 'image/jpeg', lastModified: Date.now() });
            if (!setFile(newFile)) {
                setDone('Could not replace selected file in this browser — uploading original (' + fmtSize(origSize) + ').', 'var(--yellow)');
                return;
            }
            setDone('Compressed: ' + fmtSize(origSize) + ' → ' + fmtSize(newFile.size)
                + ' (' + Math.round((1 - newFile.size / origSize) * 100) + '% smaller).', 'var(--green)');
        }).catch(function (err) {
            // If anything blew up, leave the original file alone — the
            // server will accept it (or reject for size, same as before).
            setDone('Could not compress — uploading original (' + fmtSize(origSize) + '). ' + (err && err.message ? err.message : ''), 'var(--yellow)');
        });
    });

    // Block submit while compression is in flight; otherwise disable the
    // button so a double-tap can't fire two POSTs.
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

<!-- History — every cash deposit at the user's self-claim location, by anyone -->
<div class="form-card" style="max-width:none;margin-bottom:14px">
    <div class="form-section-title">Cash Deposits at Your Location</div>
    <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="page" value="transactions">
        <div class="form-group">
            <label>From</label>
            <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>">
        </div>
        <div class="form-group">
            <label>To</label>
            <input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>">
        </div>
        <button type="submit" class="btn btn-primary">View</button>
    </form>
</div>

<?php if ($myLoc <= 0): ?>
<div class="rpt-prompt">Set your self-claim location under "My Location" to see deposits at your store.</div>
<?php else: ?>
<div class="table-wrap" data-stack style="overflow-x:auto">
    <table class="table">
        <thead>
            <tr>
                <th style="width:110px">Date</th>
                <th class="num" style="width:130px;text-align:right">Amount (₹)</th>
                <th>Remark</th>
                <th style="width:160px">Uploaded By</th>
                <th style="width:140px">Uploaded At</th>
                <th style="width:90px;text-align:center">Receipt</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:18px">No cash deposits at your location in this range.</td></tr>
        <?php else: foreach ($rows as $r): ?>
            <tr>
                <td><?= h(date('d-M-Y', strtotime((string)$r['txn_date']))) ?></td>
                <td class="num" style="text-align:right;font-family:Consolas,monospace">
                    <?= h(number_format((float)$r['amount'], 2)) ?>
                </td>
                <td><?= h((string)($r['remark'] ?? '')) ?></td>
                <td>
                    <?= h((string)($r['uploader_name'] ?? $r['uploaded_by'])) ?>
                    <?php if (!empty($r['uploader_name']) && (string)$r['uploaded_by'] !== ''): ?>
                        <span class="text-muted" style="font-size:11px">(<?= h((string)$r['uploaded_by']) ?>)</span>
                    <?php endif; ?>
                    <?php if ((string)$r['uploaded_by'] === $myCode): ?>
                        <span class="badge badge-blue" style="margin-left:4px;font-size:10px">you</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:11px;color:var(--muted)"><?= h(date('d-M-Y H:i', strtotime((string)$r['uploaded_at']))) ?></td>
                <td style="text-align:center">
                    <?php
                        $attName = (string)($r['original_name'] ?? '');
                        $isImg   = (bool)preg_match('/\.(jpg|jpeg|png|gif|webp|heic|heif)$/i', $attName);
                    ?>
                    <button type="button" class="btn btn-ghost"
                            style="padding:4px 10px;font-size:12px"
                            onclick="txnUploadOpen(<?= (int)$r['id'] ?>)"
                            data-location="<?= h($r['location_name'] ?? '') ?>"
                            data-amount="<?= h(number_format((float)$r['amount'], 2)) ?>"
                            data-date="<?= h(date('d-M-Y', strtotime((string)$r['txn_date']))) ?>"
                            data-uploader="<?= h((string)($r['uploader_name'] ?? $r['uploaded_by'])) ?>"
                            data-uploader-code="<?= h((string)$r['uploaded_by']) ?>"
                            data-uploaded-at="<?= h(date('d-M-Y H:i', strtotime((string)$r['uploaded_at']))) ?>"
                            data-remark="<?= h((string)($r['remark'] ?? '')) ?>"
                            data-att-name="<?= h($attName) ?>"
                            data-is-img="<?= $isImg ? '1' : '0' ?>">View</button>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
        <?php if ($rows): ?>
        <tfoot>
            <tr style="background:var(--border);font-weight:700">
                <td>Total (<?= count($rows) ?>)</td>
                <td class="num" style="text-align:right;font-family:Consolas,monospace">
                    <?= h(number_format($totalAmount, 2)) ?>
                </td>
                <td colspan="4"></td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
</div>

<!-- ── Receipt-image modal — read-only viewer ──────────── -->
<style>
.txu-overlay{position:fixed;inset:0;background:rgba(0,0,0,.78);display:none;z-index:9100;align-items:flex-start;justify-content:center;padding:14px;overflow:auto}
.txu-overlay.open{display:flex}
.txu-modal{background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:10px;width:100%;max-width:min(1280px, 96vw);max-height:calc(100vh - 28px);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 16px 48px rgba(0,0,0,.6)}
.txu-modal-head{display:flex;align-items:center;justify-content:space-between;padding:12px 18px;border-bottom:1px solid var(--border)}
.txu-modal-head h3{margin:0;font-size:15px;font-weight:600}
.txu-modal-close{background:transparent;border:none;color:var(--muted);font-size:24px;cursor:pointer;line-height:1;padding:0 4px}
.txu-modal-close:hover{color:var(--text)}
.txu-modal-body{padding:14px 18px;overflow:auto;flex:1}
.txu-img-wrap{background:#000;border:1px solid var(--border);border-radius:6px;display:flex;align-items:center;justify-content:center;min-height:480px;max-height:78vh;overflow:auto}
.txu-img-wrap img{max-width:100%;max-height:78vh;display:block;cursor:zoom-in}
.txu-img-wrap img.txu-img-zoomed{max-height:none;max-width:none;cursor:zoom-out}
.txu-meta-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:14px}
.txu-meta-grid .lbl{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px}
.txu-meta-grid .val{font-size:14px;font-weight:600}
.txu-modal-foot{display:flex;align-items:center;justify-content:flex-end;gap:8px;padding:12px 18px;border-top:1px solid var(--border);background:rgba(0,0,0,.15);flex-wrap:wrap}
@media(max-width:900px){.txu-img-wrap{min-height:320px}}
@media(max-width:560px){.txu-meta-grid{grid-template-columns:1fr}.txu-img-wrap{min-height:240px}}
</style>

<div class="txu-overlay" id="txuOverlay" role="dialog" aria-modal="true" aria-labelledby="txuModalTitle">
    <div class="txu-modal">
        <div class="txu-modal-head">
            <h3 id="txuModalTitle">Cash Deposit Receipt</h3>
            <button type="button" class="txu-modal-close" aria-label="Close" onclick="txnUploadClose()">×</button>
        </div>
        <div class="txu-modal-body">
            <div class="txu-img-wrap" id="txuImgWrap">
                <span style="color:var(--muted)">Loading…</span>
            </div>
            <div class="txu-meta-grid">
                <div>
                    <div class="lbl">Location</div>
                    <div class="val" id="txuMetaLocation">—</div>
                </div>
                <div>
                    <div class="lbl">Amount (₹)</div>
                    <div class="val" id="txuMetaAmount">—</div>
                </div>
                <div>
                    <div class="lbl">Date</div>
                    <div class="val" id="txuMetaDate">—</div>
                </div>
                <div>
                    <div class="lbl">Uploaded By</div>
                    <div class="val" id="txuMetaUploader">—</div>
                </div>
                <div>
                    <div class="lbl">Uploaded At</div>
                    <div class="val" id="txuMetaUploadedAt">—</div>
                </div>
                <div>
                    <div class="lbl">File</div>
                    <div class="val" id="txuMetaFile" style="font-weight:400;font-size:12px;word-break:break-all">—</div>
                </div>
                <div style="grid-column:1 / -1">
                    <div class="lbl">Remark</div>
                    <div class="val" style="font-weight:400" id="txuMetaRemark">—</div>
                </div>
            </div>
        </div>
        <div class="txu-modal-foot">
            <a id="txuDownloadLink" class="btn btn-secondary" href="#" target="_blank" style="padding:4px 12px">Open Original</a>
            <button type="button" class="btn btn-ghost" onclick="txnUploadClose()">Close</button>
        </div>
    </div>
</div>

<script>
(function () {
    var overlay     = document.getElementById('txuOverlay');
    var imgWrap     = document.getElementById('txuImgWrap');
    var locEl       = document.getElementById('txuMetaLocation');
    var amtEl       = document.getElementById('txuMetaAmount');
    var dtEl        = document.getElementById('txuMetaDate');
    var uploaderEl  = document.getElementById('txuMetaUploader');
    var uploadedEl  = document.getElementById('txuMetaUploadedAt');
    var fileEl      = document.getElementById('txuMetaFile');
    var remarkEl    = document.getElementById('txuMetaRemark');
    var dlEl        = document.getElementById('txuDownloadLink');

    function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    }); }

    window.txnUploadOpen = function (id) {
        var btn = document.querySelector('button[onclick="txnUploadOpen(' + id + ')"]');
        if (!btn) return;

        var src    = 'index.php?page=download_txn_attachment&id=' + id;
        var isImg  = btn.getAttribute('data-is-img') === '1';
        var attNm  = btn.getAttribute('data-att-name') || '';

        if (isImg) {
            imgWrap.innerHTML = '<img src="' + esc(src) + '" alt="' + esc(attNm) + '" title="Click to zoom">';
            var img = imgWrap.querySelector('img');
            if (img) img.addEventListener('click', function () { img.classList.toggle('txu-img-zoomed'); });
        } else {
            imgWrap.innerHTML = '<div style="padding:30px;text-align:center;color:var(--muted)">Receipt is a document — use <strong>Open Original</strong> to view.</div>';
        }

        locEl.textContent      = btn.getAttribute('data-location')     || '—';
        amtEl.textContent      = '₹ ' + (btn.getAttribute('data-amount') || '0.00');
        dtEl.textContent       = btn.getAttribute('data-date')         || '—';
        uploaderEl.innerHTML   = esc(btn.getAttribute('data-uploader') || '—') +
                                 ' <span style="color:var(--muted);font-weight:400;font-size:12px">(' + esc(btn.getAttribute('data-uploader-code') || '') + ')</span>';
        uploadedEl.textContent = btn.getAttribute('data-uploaded-at')  || '—';
        fileEl.textContent     = attNm || '—';
        remarkEl.textContent   = btn.getAttribute('data-remark')       || '—';
        dlEl.setAttribute('href', src);

        overlay.classList.add('open');
        document.body.style.overflow = 'hidden';
    };

    window.txnUploadClose = function () {
        overlay.classList.remove('open');
        document.body.style.overflow = '';
    };

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('open')) txnUploadClose();
    });
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) txnUploadClose();
    });
})();
</script>
<?php endif; ?>
<?php
}
