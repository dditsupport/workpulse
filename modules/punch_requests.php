<?php
// =========================================================
// Punch Request Module — manual punch request + HR approval
// =========================================================

define('PR_UPLOAD_DIR', __DIR__ . '/../uploads/punch_requests/');
define('PR_MAX_FILE', 5 * 1024 * 1024);
define('PR_ALLOWED_EXT', ['jpg','jpeg','png','gif','pdf']);

// Month bucket (e.g. "2026-05") for a punch_date string. Stops the
// punch_requests/ folder from sprawling — listings stay quick and the
// directory is human-skimmable. Pure derivation from punch_date, so we
// don't need an extra column to know where a file lives.
function prMonthBucket(string $punchDate): string {
    $ts = strtotime($punchDate);
    return date('Y-m', $ts ?: time());
}

// Resolve the on-disk path of a punch-request attachment. New uploads
// always go into the month-bucketed path; reads prefer the bucketed
// path but fall back to the legacy flat layout (PR_UPLOAD_DIR/<file>)
// for files saved before the bucketing change.
function prAttachmentPath(string $punchDate, string $stored, bool $forWrite = false): string {
    $bucketed = PR_UPLOAD_DIR . prMonthBucket($punchDate) . '/' . $stored;
    if ($forWrite) return $bucketed;
    if (is_file($bucketed)) return $bucketed;
    $flat = PR_UPLOAD_DIR . $stored;
    return is_file($flat) ? $flat : $bucketed;
}

// punch_requests.attendance_log_id arrives with
// 2026-09-18_punch_request_reversal.sql: the attendance row an approval
// created, so reversing that approval deletes exactly that punch. Until
// the migration runs, approvals simply record nothing and a reversal
// falls back to matching the punch the INSERT would have written — same
// deploy-PHP-before-SQL guard the rest of the app uses.
function prHasLogIdColumn(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $st = getDb()->query("SHOW COLUMNS FROM punch_requests LIKE 'attendance_log_id'");
        $cached = $st !== false && $st->fetch() !== false;
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

// ── Submit punch request (any user) ──────────────────────
function doSubmitPunchRequest(): void {
    $punchDate  = trim($_POST['punch_date'] ?? '');
    $punchTime  = trim($_POST['punch_time'] ?? '');
    $punchType  = trim($_POST['punch_type'] ?? '');
    $locationId = (int)($_POST['location_id'] ?? 0);
    $reason     = trim($_POST['reason'] ?? '');

    if (!$punchDate || !$punchTime || !in_array($punchType, ['IN','OUT']) || !$locationId || !$reason) {
        flash('error', 'All fields are required.');
        header('Location: index.php?page=punch_request'); exit;
    }
    if ($punchDate > date('Y-m-d')) {
        flash('error', 'Cannot request punch for future dates.');
        header('Location: index.php?page=punch_request'); exit;
    }
    // Block night-hour IN punches — no manual IN allowed for 01:00–06:59.
    // OUT punches (e.g. late shift close) are still allowed at any time.
    $punchHour = (int)substr($punchTime, 0, 2);
    if ($punchType === 'IN' && $punchHour >= 1 && $punchHour <= 6) {
        flash('error', 'IN punch not allowed in night hours (01:00–06:59). OUT punches are allowed.');
        header('Location: index.php?page=punch_request'); exit;
    }

    // Handle CCTV screenshot attachment
    $attName = null; $attStored = null;
    if (!empty($_FILES['attachment']['name']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $origName = basename($_FILES['attachment']['name']);
        $ext = mb_strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, PR_ALLOWED_EXT)) {
            flash('error', 'Invalid file type. Allowed: jpg, png, gif, pdf.');
            header('Location: index.php?page=punch_request'); exit;
        }
        if ($_FILES['attachment']['size'] > PR_MAX_FILE) {
            flash('error', 'File too large. Max 5MB.');
            header('Location: index.php?page=punch_request'); exit;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES['attachment']['tmp_name']);
        $allowedMimes = ['image/jpeg','image/png','image/gif','application/pdf'];
        if (!in_array($mime, $allowedMimes)) {
            flash('error', 'Invalid file content.');
            header('Location: index.php?page=punch_request'); exit;
        }
        $attStored = uniqid('pr_', true) . '.' . $ext;
        $attName   = $origName;
        // Always write to the month-bucketed layout (e.g. uploads/punch_requests/2026-05/).
        $destPath  = prAttachmentPath($punchDate, $attStored, true);
        $destDir   = dirname($destPath);
        if (!is_dir($destDir)) mkdir($destDir, 0755, true);
        move_uploaded_file($_FILES['attachment']['tmp_name'], $destPath);
    }

    $db = getDb();
    $st = $db->prepare(
        "INSERT INTO punch_requests (employee_code, location_id, punch_date, punch_time, punch_type, reason, attachment_name, attachment_stored)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $st->execute([myCode(), $locationId, $punchDate, $punchTime, $punchType, $reason, $attName, $attStored]);

    // Send email notifications to HR and Operations
    $hrEmail  = getSetting('PunchRequestNotifyHR');
    $opsEmail = getSetting('PunchRequestNotifyOps');
    $empName  = myName() ?: myCode();

    if ($hrEmail || $opsEmail) {
        $body = "
        <div style='font-family:Arial,sans-serif;max-width:500px;margin:0 auto'>
            <div style='background:#1a1d2e;color:#fff;padding:16px 20px;border-radius:8px 8px 0 0'>
                <h2 style='margin:0;font-size:16px'>Punch Request Submitted</h2>
            </div>
            <div style='background:#f8f9fa;padding:20px;border:1px solid #dee2e6;border-top:0;border-radius:0 0 8px 8px'>
                <p><strong>Employee:</strong> " . htmlspecialchars($empName) . " (" . htmlspecialchars(myCode()) . ")</p>
                <p><strong>Date:</strong> " . htmlspecialchars($punchDate) . "</p>
                <p><strong>Time:</strong> " . htmlspecialchars($punchTime) . "</p>
                <p><strong>Type:</strong> " . htmlspecialchars($punchType) . "</p>
                <p><strong>Reason:</strong> " . htmlspecialchars($reason) . "</p>
                <p style='font-size:12px;color:#999;margin-top:16px'>Please review this request in Work Pulse &rarr; Punch Requests.</p>
            </div>
        </div>";

        $subject = 'Work Pulse — Punch Request from ' . $empName;
        if ($hrEmail)  sendSmtpEmailQuiet($hrEmail, $subject, $body);
        if ($opsEmail) sendSmtpEmailQuiet($opsEmail, $subject, $body);
    }

    flash('success', 'Punch request submitted. Awaiting HR approval.');
    header('Location: index.php?page=punch_request'); exit;
}

// Force one shift day's punches to alternate IN/OUT/IN/OUT in time order.
//
// A device has no idea which punch is which: it alternates from the last one
// it saw, so a forgotten IN silently mislabels every punch after it. Time
// order is the only thing that is never wrong — within a shift day the
// earliest punch IS the arrival, so alternating from it rebuilds the truth.
// Shift-day grouping is what makes this safe: a punch at 00:43 belongs to the
// PREVIOUS day's shift, so it is that day's last punch, not the next day's
// first.
//
// Called after an approval, and again after an approval is reversed, for that
// employee and that one shift day. auto_close placeholders are left alone —
// they are not punches and must not take part in the alternation. Returns how
// many rows were corrected.
//
// $afterRemoval says the manual punch has just been taken back out, and
// changes two things. A lone punch is then worth sequencing: an approval that
// flipped the day's only other punch to OUT leaves that flip behind once its
// own punch is gone, and the rule (earliest punch of the shift day is the
// arrival) puts it back to IN. On the approval side the same lone punch is
// HR's own explicit IN/OUT decision, so it is left exactly as approved.
function prResequenceShiftDay(PDO $db, string $empCode, string $punchDatetime, bool $afterRemoval = false): int {
    $cut   = function_exists('shiftCutoffHour') ? shiftCutoffHour() : 6;
    $day   = function_exists('shiftDay') ? shiftDay($punchDatetime) : substr($punchDatetime, 0, 10);
    $from  = $day . ' ' . sprintf('%02d:00:00', $cut);
    $to    = date('Y-m-d', strtotime($day . ' +1 day')) . ' ' . sprintf('%02d:59:59', $cut - 1);

    $st = $db->prepare(
        "SELECT id, punch_type, punch_time
           FROM attendance_logs
          WHERE employee_code = ? AND punch_time >= ? AND punch_time <= ?
            AND punch_method <> 'auto_close'
          ORDER BY punch_time ASC, id ASC"
    );
    $st->execute([$empCode, $from, $to]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) < ($afterRemoval ? 1 : 2)) return 0;   // nothing left to alternate

    $fix     = $db->prepare('UPDATE attendance_logs SET punch_type = ? WHERE id = ?');
    $changed = 0;
    foreach ($rows as $i => $r) {
        $want = ($i % 2 === 0) ? 'IN' : 'OUT';
        if ($r['punch_type'] === $want) continue;
        $fix->execute([$want, (int)$r['id']]);
        $changed++;
        // The device's original reading is evidence, so never silently
        // overwrite it — leave a trail of exactly what was changed and why.
        if (function_exists('attOddPunchLog')) {
            attOddPunchLog(sprintf(
                '[%s] re-sequenced %s punch #%d at %s: %s -> %s (%s by %s)',
                $day, $empCode, (int)$r['id'], $r['punch_time'], $r['punch_type'], $want,
                $afterRemoval ? 'approval reversed' : 'missing punch approved', myCode()
            ));
        }
    }
    return $changed;
}

// ── Delete own punch request (owner, while not approved) ──
// An approved request has already been written into attendance_logs, so
// it stays put — only HR can undo that. Pending/rejected rows are the
// employee's to withdraw or clear.
function doDeletePunchRequest(): void {
    $back = 'index.php?page=punch_request';
    $id   = (int)($_POST['request_id'] ?? 0);

    $db = getDb();
    $st = $db->prepare('SELECT employee_code, status, punch_date, attachment_stored FROM punch_requests WHERE id = ?');
    $st->execute([$id]);
    $req = $st->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        flash('error', 'Request not found.');
        header("Location: {$back}"); exit;
    }
    if ($req['employee_code'] !== myCode()) {
        flash('error', 'You can only delete your own requests.');
        header("Location: {$back}"); exit;
    }
    if ($req['status'] === 'approved') {
        flash('error', 'Approved requests cannot be deleted.');
        header("Location: {$back}"); exit;
    }

    // Only drop the file once the row is gone — and only if the DELETE
    // actually matched a still-unapproved row (guards a concurrent approval).
    $del = $db->prepare("DELETE FROM punch_requests WHERE id = ? AND employee_code = ? AND status <> 'approved'");
    $del->execute([$id, myCode()]);
    if ($del->rowCount() !== 1) {
        flash('error', 'Request could not be deleted — it may have just been approved.');
        header("Location: {$back}"); exit;
    }

    if (!empty($req['attachment_stored'])) {
        $path = prAttachmentPath((string)$req['punch_date'], (string)$req['attachment_stored'], false);
        if (is_file($path)) @unlink($path);
    }

    flash('success', 'Punch request deleted.');
    header("Location: {$back}"); exit;
}

// ── Approve/reject punch request (HR, superadmin) ────────
function doReviewPunchRequest(): void {
    // Detect AJAX call (modal review on approve_punches). Non-AJAX
    // submits keep the legacy redirect/flash path so any external
    // bookmarks / scripts still work.
    $isXhr = !empty($_POST['xhr']) || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
    $jsonFail = function (string $msg, int $http = 400) use ($isXhr) {
        if ($isXhr) {
            http_response_code($http);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $msg]);
            exit;
        }
        flash('error', $msg);
        header('Location: index.php?page=approve_punches'); exit;
    };

    if (!canManageEmployees()) { $jsonFail('Access denied.', 403); }

    $id     = (int)($_POST['request_id'] ?? 0);
    $action = $_POST['review_action'] ?? '';
    $note   = trim($_POST['review_note'] ?? '');

    if (!in_array($action, ['approved','rejected'])) { $jsonFail('Invalid action.'); }

    $db = getDb();
    $st = $db->prepare("SELECT employee_code, location_id, punch_date, punch_time, punch_type FROM punch_requests WHERE id = ? AND status = 'pending'");
    $st->execute([$id]);
    $req = $st->fetch(PDO::FETCH_ASSOC);
    if (!$req) { $jsonFail('Request not found or already reviewed.', 404); }

    $db->beginTransaction();
    try {
        // Guard against concurrent reviewers: only the reviewer whose UPDATE
        // flips status from 'pending' wins. The runner-up sees rowCount() === 0
        // and bails out before inserting a duplicate attendance row.
        $upd = $db->prepare(
            "UPDATE punch_requests SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ?
             WHERE id = ? AND status = 'pending'"
        );
        $upd->execute([$action, myCode(), $note, $id]);
        if ($upd->rowCount() !== 1) {
            $db->rollBack();
            $jsonFail('Request not found or already reviewed.', 409);
        }

        // If approved, insert into attendance_logs
        $resequenced = 0;
        if ($action === 'approved') {
            $punchDatetime = $req['punch_date'] . ' ' . $req['punch_time'];
            $ins = $db->prepare(
                "INSERT INTO attendance_logs (employee_code, device_serial, device_type, location_id, punch_type, punch_method, match_score, punch_time)
                 VALUES (?, 'MANUAL', 'MFS500', ?, ?, 'manual', 0, ?)"
            );
            $ins->execute([$req['employee_code'], $req['location_id'], $req['punch_type'], $punchDatetime]);

            // Tie the request to the punch it just created. Without this a
            // reversal has to guess which attendance row was ours, and a
            // re-sequenced punch is no longer recognisable by its type.
            if (prHasLogIdColumn()) {
                $db->prepare('UPDATE punch_requests SET attendance_log_id = ? WHERE id = ?')
                   ->execute([(int)$db->lastInsertId(), $id]);
            }

            // Adding the missing punch can leave the day's types wrong, because
            // the device decides IN/OUT by alternating from whatever it saw
            // first. Someone who forgot the evening IN and punched out at
            // 00:43 gets that punch stored as an IN — it was the first of the
            // shift day. Insert the real 18:00 IN and the day now reads
            // IN 18:00, IN 00:43: even, so it drops off the odd-punch report,
            // but the in→out trace is still nonsense and the ERP still can't
            // use it. Re-sequencing fixes the type that the missing punch
            // invalidated.
            $resequenced = prResequenceShiftDay($db, (string)$req['employee_code'], $punchDatetime);
        }

        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $jsonFail('Error processing request: ' . $e->getMessage(), 500);
    }

    if ($isXhr) {
        // Return reviewer name for the row's audit-trail label.
        $rev = $db->prepare('SELECT full_name FROM employees WHERE employee_code = ?');
        $rev->execute([myCode()]);
        $reviewerName = $rev->fetchColumn() ?: myCode();
        header('Content-Type: application/json');
        echo json_encode([
            'ok'             => true,
            'request_id'     => $id,
            'action'         => $action,
            'review_note'    => $note,
            'reviewer_name'  => $reviewerName,
            'reviewed_at'    => date('d M Y H:i'),
            'message'        => $action === 'approved'
                ? 'Punch request approved and added to attendance.'
                    . ($resequenced ? ' ' . $resequenced . ' existing punch(es) on that shift day re-sequenced to keep the in/out trace correct.' : '')
                : 'Punch request rejected.',
        ]);
        exit;
    }

    flash('success', $action === 'approved'
        ? 'Punch request approved and added to attendance.'
            . ($resequenced ? ' ' . $resequenced . ' existing punch(es) on that shift day re-sequenced to keep the in/out trace correct.' : '')
        : 'Punch request rejected.');
    header('Location: index.php?page=approve_punches'); exit;
}

// ── Reverse an approved punch request (HR, superadmin) ───
// Approving writes a real punch into attendance_logs, so undoing an
// approval is not a status change on its own: leave the punch behind and
// attendance, the odd-punch report and the ERP export all keep counting a
// punch HR has just disowned. This flips the request back to rejected AND
// removes the punch it created, then re-sequences that shift day — taking
// a punch out invalidates the day's IN/OUT alternation exactly as adding
// one does, because the device only ever alternates from the punch it saw
// first.
//
// A mandatory note is the record of why: the employee sees it on Missing
// Punch, where the request is now rejected and deletable again.
function doReversePunchRequest(): void {
    $isXhr = !empty($_POST['xhr']) || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
    $jsonFail = function (string $msg, int $http = 400) use ($isXhr) {
        if ($isXhr) {
            http_response_code($http);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $msg]);
            exit;
        }
        flash('error', $msg);
        header('Location: index.php?page=approve_punches&status=approved'); exit;
    };

    if (!canManageEmployees()) { $jsonFail('Access denied.', 403); }

    $id   = (int)($_POST['request_id'] ?? 0);
    $note = trim($_POST['review_note'] ?? '');
    if ($note === '') { $jsonFail('A reason is required to reverse an approval.'); }

    $db       = getDb();
    $hasLogId = prHasLogIdColumn();

    $cols = 'employee_code, punch_date, punch_time, punch_type'
          . ($hasLogId ? ', attendance_log_id' : '');
    $st = $db->prepare("SELECT {$cols} FROM punch_requests WHERE id = ? AND status = 'approved'");
    $st->execute([$id]);
    $req = $st->fetch(PDO::FETCH_ASSOC);
    if (!$req) { $jsonFail('Request not found or not in an approved state.', 404); }

    $empCode       = (string)$req['employee_code'];
    $punchDatetime = $req['punch_date'] . ' ' . $req['punch_time'];

    $db->beginTransaction();
    try {
        // Same concurrency guard as the review path: only the reverser whose
        // UPDATE moves the row off 'approved' goes on to delete the punch, so
        // two HR users clicking at once cannot delete twice.
        $sql = $hasLogId
            ? "UPDATE punch_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), review_note = ?, attendance_log_id = NULL WHERE id = ? AND status = 'approved'"
            : "UPDATE punch_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), review_note = ? WHERE id = ? AND status = 'approved'";
        $upd = $db->prepare($sql);
        $upd->execute([myCode(), $note, $id]);
        if ($upd->rowCount() !== 1) {
            $db->rollBack();
            $jsonFail('Request not found or already reviewed.', 409);
        }

        // Which attendance row belongs to this approval? The recorded id is
        // authoritative. Requests approved before the reversal migration
        // carry none, so fall back to the key the approval INSERT itself
        // used — employee + exact punch datetime + punch_method 'manual'.
        $logId = $hasLogId ? (int)($req['attendance_log_id'] ?? 0) : 0;
        if (!$logId) {
            $find = $db->prepare(
                "SELECT id FROM attendance_logs
                  WHERE employee_code = ? AND punch_method = 'manual' AND punch_time = ?
                  ORDER BY id ASC LIMIT 1"
            );
            $find->execute([$empCode, $punchDatetime]);
            $logId = (int)$find->fetchColumn();

            // Two approvals for the same employee and second would both point
            // at that one punch. If another request already claims it, this
            // reversal leaves attendance alone rather than deleting a punch
            // that is still backed by a live approval.
            if ($logId && $hasLogId) {
                $claim = $db->prepare('SELECT COUNT(*) FROM punch_requests WHERE attendance_log_id = ? AND id <> ?');
                $claim->execute([$logId, $id]);
                if ((int)$claim->fetchColumn() > 0) $logId = 0;
            }
        }

        $deleted = 0;
        if ($logId) {
            // punch_method 'manual' is part of the WHERE on purpose: whatever
            // the id says, a reversal must never remove a biometric punch.
            $del = $db->prepare("DELETE FROM attendance_logs WHERE id = ? AND employee_code = ? AND punch_method = 'manual'");
            $del->execute([$logId, $empCode]);
            $deleted = $del->rowCount();
        }

        // Only worth re-sequencing if a punch actually left the day.
        $resequenced = $deleted ? prResequenceShiftDay($db, $empCode, $punchDatetime, true) : 0;

        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $jsonFail('Error reversing request: ' . $e->getMessage(), 500);
    }

    // Deleting attendance is the one thing here that cannot be undone from
    // the UI, so it leaves the same trail the re-sequencing does.
    if (function_exists('attOddPunchLog')) {
        $day = function_exists('shiftDay') ? shiftDay($punchDatetime) : substr($punchDatetime, 0, 10);
        attOddPunchLog(sprintf(
            '[%s] approval reversed on punch request #%d for %s (%s %s): %s; by %s — %s',
            $day, $id, $empCode, $punchDatetime, (string)$req['punch_type'],
            $deleted ? 'attendance punch #' . $logId . ' deleted' : 'no matching attendance punch found',
            myCode(), $note
        ));
    }

    $message = 'Approval reversed — request is now rejected'
             . ($deleted
                 ? ' and the punch was removed from attendance.'
                 : '. No matching attendance punch was found — it may already have been removed.')
             . ($resequenced ? ' ' . $resequenced . ' remaining punch(es) on that shift day re-sequenced to keep the in/out trace correct.' : '');

    if ($isXhr) {
        $rev = $db->prepare('SELECT full_name FROM employees WHERE employee_code = ?');
        $rev->execute([myCode()]);
        $reviewerName = $rev->fetchColumn() ?: myCode();
        header('Content-Type: application/json');
        echo json_encode([
            'ok'            => true,
            'request_id'    => $id,
            'action'        => 'rejected',
            'review_note'   => $note,
            'reviewer_name' => $reviewerName,
            'reviewed_at'   => date('d M Y H:i'),
            'punch_deleted' => $deleted ? 1 : 0,
            'message'       => $message,
        ]);
        exit;
    }

    flash('success', $message);
    header('Location: index.php?page=approve_punches&status=approved'); exit;
}

// ── Punch request form page (any user) ───────────────────
function pagePunchRequest(): void {
    $locations = getActiveLocations();
    $db = getDb();

    // Show user's own requests
    $st = $db->prepare(
        "SELECT pr.*, l.location_name FROM punch_requests pr
         LEFT JOIN locations l ON pr.location_id = l.location_id
         WHERE pr.employee_code = ?
         ORDER BY pr.created_at DESC LIMIT 20"
    );
    $st->execute([myCode()]);
    $myRequests = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="page-header"><h2>Missing Punch</h2></div>

<div style="margin: -4px 0 16px; padding: 12px 14px; background: #f8f9fa; border-left: 3px solid #6c757d; border-radius: 4px; font-size: 13px; color: #495057; line-height: 1.6">
    <div>• If an employee forgot to punch or faced server/internet issues during biometric punching, submit a request here.</div>
    <div>• Raise an issue only if the biometric device is not working properly.</div>
</div>

<div class="form-card" style="margin-bottom:16px">
    <h3 style="font-size:14px;margin-bottom:12px">Request Manual Punch</h3>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="submit_punch_request">
        <div class="form-grid">
            <div class="form-group">
                <label>Date <span class="required">*</span></label>
                <input type="date" name="punch_date" class="form-control" required max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-group">
                <label>Location <span class="required">*</span></label>
                <select name="location_id" class="form-control" required>
                    <option value="">— Select Location —</option>
                    <?php foreach ($locations as $loc): ?>
                    <option value="<?= $loc['location_id'] ?>"><?= h($loc['location_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Punch Type <span class="required">*</span></label>
                <select name="punch_type" class="form-control" required>
                    <option value="">— Select —</option>
                    <option value="IN">IN</option>
                    <option value="OUT">OUT</option>
                </select>
            </div>
            <div class="form-group">
                <label>Time <span class="required">*</span></label>
                <?= time24Input('punch_time', '', true, true) ?>
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <label>Reason <span class="required">*</span></label>
                <input type="text" name="reason" class="form-control" required maxlength="500" placeholder="e.g. Forgot to punch, biometric not working">
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <label>CCTV Screenshot (optional)</label>
                <input type="file" name="attachment" class="form-control" accept=".jpg,.jpeg,.png,.gif,.pdf">
                <span class="hint">Max 5MB. Allowed: jpg, png, gif, pdf</span>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Submit Request</button>
        </div>
    </form>
</div>

<!-- My requests history -->
<?php if ($myRequests): ?>
<h3 style="font-size:14px;margin-bottom:8px">My Requests</h3>
<div class="table-wrap" data-stack>
    <table class="table">
        <thead>
            <tr>
                <th style="width:50px">#</th>
                <th>Date</th>
                <th>Time</th>
                <th>Type</th>
                <th>Location</th>
                <th>Reason</th>
                <th>Status</th>
                <th>HR Note</th>
                <th>Reviewed</th>
                <th style="width:80px;text-align:center">Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($myRequests as $r): ?>
            <tr>
                <td><?= $r['id'] ?></td>
                <td><?= date('d M Y', strtotime($r['punch_date'])) ?></td>
                <td><?= $r['punch_time'] ?></td>
                <td><span class="badge <?= $r['punch_type'] === 'IN' ? 'badge-green' : 'badge-red' ?>"><?= $r['punch_type'] ?></span></td>
                <td><?= h($r['location_name'] ?? '') ?></td>
                <td><?= h($r['reason']) ?></td>
                <td><span class="badge <?= $r['status'] === 'approved' ? 'badge-green' : ($r['status'] === 'rejected' ? 'badge-red' : 'badge-yellow') ?>"><?= ucfirst($r['status']) ?></span></td>
                <td class="text-muted"><?= !empty($r['review_note']) ? h($r['review_note']) : '—' ?></td>
                <td class="text-muted"><?= $r['reviewed_at'] ? date('d M H:i', strtotime($r['reviewed_at'])) : '—' ?></td>
                <td style="text-align:center">
                    <?php if ($r['status'] !== 'approved'): ?>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this punch request?')">
                        <input type="hidden" name="action" value="delete_punch_request">
                        <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                    <?php else: ?>
                    <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php }

// ── Approve punches page (HR, superadmin) ────────────────
function pageApprovePunches(): void {
    if (!canManageEmployees()) { flash('error', 'Access denied.'); header('Location: index.php'); exit; }

    $db = getDb();
    $statusFilter = $_GET['status'] ?? 'pending';

    $st = $db->prepare(
        "SELECT pr.*, l.location_name, e.full_name AS employee_name
         FROM punch_requests pr
         LEFT JOIN locations l ON pr.location_id = l.location_id
         LEFT JOIN employees e ON pr.employee_code = e.employee_code
         WHERE pr.status = ?
         ORDER BY pr.created_at DESC LIMIT 100"
    );
    $st->execute([$statusFilter]);
    $requests = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="page-header"><h2>Approve Punch Requests</h2></div>

<form method="GET" class="filter-bar" style="margin-bottom:14px">
    <input type="hidden" name="page" value="approve_punches">
    <select name="status" class="form-control" style="width:150px" onchange="this.form.submit()">
        <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
        <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
        <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
    </select>
</form>

<?php if ($statusFilter === 'approved'): ?>
<div style="margin:-4px 0 14px;padding:12px 14px;background:#f8f9fa;border-left:3px solid #6c757d;border-radius:4px;font-size:13px;color:#495057;line-height:1.6">
    Approved by mistake? Open the request and use <strong>Reverse to Rejected</strong>. The request goes back to
    rejected and the punch it added is deleted from attendance, so reports and the odd-punch list stop counting it.
    A reason is required and is shown to the employee.
</div>
<?php endif; ?>

<?php if (empty($requests)): ?>
<div class="rpt-prompt">No <?= h($statusFilter) ?> punch requests.</div>
<?php else: ?>
<div class="table-wrap" data-stack>
    <table class="table">
        <thead>
            <tr>
                <th style="width:50px">#</th>
                <th>Created</th>
                <th>Employee</th>
                <th>Date</th>
                <th>Time</th>
                <th>Type</th>
                <th>Location</th>
                <th>Reason</th>
                <th style="width:110px;text-align:center">Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($requests as $r):
            $hasAttach = !empty($r['attachment_stored']);
            $attName   = (string)($r['attachment_name'] ?? '');
            $isImg     = $hasAttach && (bool)preg_match('/\.(jpg|jpeg|png|gif|webp|heic|heif)$/i', $attName);
            // An approved row is not read-only any more — its button opens the
            // modal HR reverses from, so it says so.
            $btnLabel  = $statusFilter === 'pending' ? 'Review' : ($r['status'] === 'approved' ? 'View / Reverse' : 'View');
            $btnClass  = $statusFilter === 'pending' ? 'btn-primary' : 'btn-secondary';
        ?>
            <tr id="prRow-<?= (int)$r['id'] ?>">
                <td><?= (int)$r['id'] ?></td>
                <td class="text-muted"><?= h(date('d M Y H:i', strtotime($r['created_at']))) ?></td>
                <td><strong><?= h($r['employee_name'] ?? $r['employee_code']) ?></strong><br>
                    <span class="text-muted"><?= h($r['employee_code']) ?></span></td>
                <td><?= h(date('d M Y', strtotime($r['punch_date']))) ?></td>
                <td><?= h($r['punch_time']) ?></td>
                <td><span class="badge <?= $r['punch_type'] === 'IN' ? 'badge-green' : 'badge-red' ?>"><?= h($r['punch_type']) ?></span></td>
                <td><?= h($r['location_name'] ?? '') ?></td>
                <td><?= h($r['reason']) ?></td>
                <td style="text-align:center" class="pr-action-cell">
                    <button type="button" class="btn btn-sm <?= $btnClass ?>"
                            onclick="prOpenReview(<?= (int)$r['id'] ?>)"
                            data-status="<?= h($r['status']) ?>"
                            data-employee="<?= h($r['employee_name'] ?? $r['employee_code']) ?>"
                            data-employee-code="<?= h($r['employee_code']) ?>"
                            data-location="<?= h($r['location_name'] ?? '') ?>"
                            data-punch-date="<?= h(date('d M Y', strtotime($r['punch_date']))) ?>"
                            data-punch-time="<?= h($r['punch_time']) ?>"
                            data-punch-type="<?= h($r['punch_type']) ?>"
                            data-reason="<?= h($r['reason']) ?>"
                            data-created-at="<?= h(date('d M Y H:i', strtotime($r['created_at']))) ?>"
                            data-has-attach="<?= $hasAttach ? '1' : '0' ?>"
                            data-is-img="<?= $isImg ? '1' : '0' ?>"
                            data-att-name="<?= h($attName) ?>"
                            data-review-note="<?= h($r['review_note'] ?? '') ?>"
                            data-reviewed-at="<?= $r['reviewed_at'] ? h(date('d M Y H:i', strtotime($r['reviewed_at']))) : '' ?>"
                            data-reviewer="<?= h($r['reviewed_by'] ?? '') ?>"><?= $btnLabel ?></button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- ── Review modal — shared by every row on the page ───── -->
<style>
.pr-overlay{position:fixed;inset:0;background:rgba(0,0,0,.78);display:none;z-index:9100;align-items:flex-start;justify-content:center;padding:14px;overflow:auto}
.pr-overlay.open{display:flex}
/* Wide so the receipt image gets real estate; capped so ultra-wide
   monitors don't stretch the meta-grid into uncomfortable lines. */
.pr-modal{background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:10px;width:100%;max-width:min(1280px, 96vw);max-height:calc(100vh - 28px);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 16px 48px rgba(0,0,0,.6)}
.pr-modal-head{display:flex;align-items:center;justify-content:space-between;padding:12px 18px;border-bottom:1px solid var(--border)}
.pr-modal-head h3{margin:0;font-size:15px;font-weight:600}
.pr-modal-close{background:transparent;border:none;color:var(--muted);font-size:24px;cursor:pointer;line-height:1;padding:0 4px}
.pr-modal-close:hover{color:var(--text)}
.pr-modal-body{padding:14px 18px;overflow:auto;flex:1}
.pr-img-wrap{background:#000;border:1px solid var(--border);border-radius:6px;display:flex;align-items:center;justify-content:center;min-height:480px;max-height:78vh;overflow:auto}
.pr-img-wrap img{max-width:100%;max-height:78vh;display:block;cursor:zoom-in}
.pr-img-wrap img.pr-img-zoomed{max-height:none;max-width:none;cursor:zoom-out}
.pr-meta-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:14px}
.pr-meta-grid .lbl{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px}
.pr-meta-grid .val{font-size:14px;font-weight:600}
.pr-modal-foot{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 18px;border-top:1px solid var(--border);background:rgba(0,0,0,.15);flex-wrap:wrap}
.pr-stamp{font-size:12px;font-weight:600}
@media(max-width:900px){.pr-img-wrap{min-height:320px}}
@media(max-width:560px){.pr-meta-grid{grid-template-columns:1fr}.pr-img-wrap{min-height:240px}}
</style>

<div class="pr-overlay" id="prOverlay" role="dialog" aria-modal="true" aria-labelledby="prModalTitle">
    <div class="pr-modal">
        <div class="pr-modal-head">
            <h3 id="prModalTitle">Review Punch Request</h3>
            <button type="button" class="pr-modal-close" aria-label="Close" onclick="prCloseReview()">×</button>
        </div>
        <div class="pr-modal-body">
            <div class="pr-img-wrap" id="prImgWrap">
                <span style="color:var(--muted)">No attachment</span>
            </div>
            <div class="pr-meta-grid">
                <div>
                    <div class="lbl">Employee</div>
                    <div class="val" id="prMetaEmployee">—</div>
                </div>
                <div>
                    <div class="lbl">Date / Time</div>
                    <div class="val" id="prMetaDateTime">—</div>
                </div>
                <div>
                    <div class="lbl">Type / Location</div>
                    <div class="val" id="prMetaTypeLocation">—</div>
                </div>
                <div style="grid-column:1 / -1">
                    <div class="lbl">Reason (from employee)</div>
                    <div class="val" style="font-weight:400" id="prMetaReason">—</div>
                </div>
                <div style="grid-column:1 / -1">
                    <div class="lbl">Reviewer Note <span style="text-transform:none;font-weight:400">(optional)</span></div>
                    <input type="text" id="prReviewNote" maxlength="500"
                           placeholder="e.g. confirmed with manager, biometric down, …"
                           style="width:100%;background:var(--bg);color:var(--text);border:1px solid var(--border);border-radius:6px;padding:7px 11px;font-size:13px;box-sizing:border-box">
                </div>
            </div>
        </div>
        <div class="pr-modal-foot">
            <div id="prStamp" class="pr-stamp"></div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <button type="button" class="btn btn-ghost" onclick="prCloseReview()">Close</button>
                <a id="prDownloadLink" class="btn btn-secondary" href="#" target="_blank" style="display:none;padding:4px 12px">Open Original</a>
                <button type="button" class="btn btn-danger"  id="prReverseBtn" onclick="prReverseConfirm()" style="display:none">Reverse to Rejected</button>
                <button type="button" class="btn btn-danger"  id="prRejectBtn"  onclick="prReviewConfirm('rejected')">Reject</button>
                <button type="button" class="btn btn-success" id="prApproveBtn" onclick="prReviewConfirm('approved')">Approve</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var overlay   = document.getElementById('prOverlay');
    var imgWrap   = document.getElementById('prImgWrap');
    var empEl     = document.getElementById('prMetaEmployee');
    var dtEl      = document.getElementById('prMetaDateTime');
    var typeLocEl = document.getElementById('prMetaTypeLocation');
    var reasonEl  = document.getElementById('prMetaReason');
    var noteEl    = document.getElementById('prReviewNote');
    var dlEl      = document.getElementById('prDownloadLink');
    var stampEl   = document.getElementById('prStamp');
    var apBtn     = document.getElementById('prApproveBtn');
    var rjBtn     = document.getElementById('prRejectBtn');
    var rvBtn     = document.getElementById('prReverseBtn');
    var currentId = 0;
    var notePlaceholder = noteEl.getAttribute('placeholder') || '';

    function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    }); }

    window.prOpenReview = function (id) {
        var btn = document.querySelector('button[onclick="prOpenReview(' + id + ')"]');
        if (!btn) return;
        currentId = id;

        var status   = btn.getAttribute('data-status') || '';
        var pending  = status === 'pending';
        var hasAtt   = btn.getAttribute('data-has-attach') === '1';
        var isImg    = btn.getAttribute('data-is-img') === '1';
        var attName  = btn.getAttribute('data-att-name') || '';
        var src      = 'index.php?page=download_pr_attachment&id=' + id;

        if (!hasAtt) {
            imgWrap.innerHTML = '<span style="color:var(--muted)">No attachment uploaded</span>';
            dlEl.style.display = 'none';
        } else if (isImg) {
            imgWrap.innerHTML = '<img src="' + esc(src) + '" alt="' + esc(attName) + '" title="Click to zoom">';
            // Click to toggle full-resolution view inside the modal scroll area.
            var img = imgWrap.querySelector('img');
            if (img) img.addEventListener('click', function () {
                img.classList.toggle('pr-img-zoomed');
            });
            dlEl.setAttribute('href', src);
            dlEl.style.display = '';
        } else {
            imgWrap.innerHTML = '<div style="padding:30px;text-align:center;color:var(--muted)">Attachment is a document — use <strong>Open Original</strong> to view.</div>';
            dlEl.setAttribute('href', src);
            dlEl.style.display = '';
        }

        empEl.innerHTML     = esc(btn.getAttribute('data-employee') || '—') +
                              ' <span style="color:var(--muted);font-weight:400;font-size:12px">(' + esc(btn.getAttribute('data-employee-code') || '') + ')</span>';
        dtEl.textContent    = (btn.getAttribute('data-punch-date') || '—') + ' · ' + (btn.getAttribute('data-punch-time') || '—');
        typeLocEl.innerHTML = '<span class="badge ' + (btn.getAttribute('data-punch-type') === 'IN' ? 'badge-green' : 'badge-red') + '">' + esc(btn.getAttribute('data-punch-type') || '') + '</span> · ' + esc(btn.getAttribute('data-location') || '');
        reasonEl.textContent = btn.getAttribute('data-reason') || '—';
        noteEl.value         = btn.getAttribute('data-review-note') || '';

        if (pending) {
            apBtn.style.display = '';
            rjBtn.style.display = '';
            rvBtn.style.display = 'none';
            apBtn.disabled = false; apBtn.textContent = 'Approve';
            rjBtn.disabled = false; rjBtn.textContent = 'Reject';
            noteEl.disabled = false;
            noteEl.setAttribute('placeholder', notePlaceholder);
            stampEl.textContent = '';
            stampEl.style.color = '';
        } else {
            apBtn.style.display = 'none';
            rjBtn.style.display = 'none';
            // An approval can still be taken back: the note stays editable so
            // the reversal carries its reason, which is what the employee
            // reads on Missing Punch once the request flips to rejected.
            var approved = status === 'approved';
            rvBtn.style.display = approved ? '' : 'none';
            rvBtn.disabled = false; rvBtn.textContent = 'Reverse to Rejected';
            noteEl.disabled = !approved;
            noteEl.setAttribute('placeholder', approved
                ? 'Reason for reversing this approval (required)'
                : notePlaceholder);
            // The note box is where the reversal reason goes, so it starts
            // empty on an approved row — the note the approver left is kept
            // in view on the stamp line instead of being typed over blindly.
            var pastNote = btn.getAttribute('data-review-note') || '';
            if (approved) noteEl.value = '';
            stampEl.style.color = approved ? 'var(--green)' : 'var(--red)';
            stampEl.textContent = (approved ? '✓ Approved' : '✗ Rejected')
                + ' by ' + (btn.getAttribute('data-reviewer') || '—')
                + ' on '  + (btn.getAttribute('data-reviewed-at') || '—')
                + (pastNote ? ' · “' + pastNote + '”' : '');
        }

        overlay.classList.add('open');
        document.body.style.overflow = 'hidden';
    };

    window.prCloseReview = function () {
        overlay.classList.remove('open');
        document.body.style.overflow = '';
        currentId = 0;
    };

    // Close on Escape + click-outside-modal
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('open')) prCloseReview();
    });
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) prCloseReview();
    });

    // One submit path for review and reversal alike: both POST to index.php,
    // both disable the footer buttons while in flight, and both end with the
    // row leaving this filtered view. Only the payload differs.
    function prSend(fd, workingBtn, workingLabel) {
        var footBtns = [apBtn, rjBtn, rvBtn];
        footBtns.forEach(function (b) { b.disabled = true; });
        workingBtn.textContent = 'Saving…';

        function restore() {
            footBtns.forEach(function (b) { b.disabled = false; });
            workingBtn.textContent = workingLabel;
        }

        fetch('index.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.text().then(function (t) { return { status: r.status, text: t }; }); })
            .then(function (resp) {
                var data = null;
                try { data = JSON.parse(resp.text); } catch (e) {}
                if (!data || !data.ok) {
                    var msg = (data && data.error) ? data.error : ('Server returned HTTP ' + resp.status);
                    stampEl.style.color = 'var(--red)';
                    stampEl.textContent = msg;
                    restore();
                    return;
                }
                // Success — drop the row from this view (status no longer matches the filter).
                var row = document.getElementById('prRow-' + data.request_id);
                if (row) row.remove();
                prCloseReview();
                restore();
                // Tiny one-shot success banner at the top of the page.
                var banner = document.createElement('div');
                banner.className = 'alert alert-success';
                banner.textContent = data.message || 'Saved.';
                banner.style.position = 'fixed';
                banner.style.top      = '14px';
                banner.style.right    = '14px';
                banner.style.zIndex   = '9200';
                banner.style.boxShadow = '0 4px 14px rgba(0,0,0,.35)';
                document.body.appendChild(banner);
                setTimeout(function () { banner.remove(); }, 6000);
            })
            .catch(function (err) {
                stampEl.style.color = 'var(--red)';
                stampEl.textContent = 'Network error: ' + (err && err.message ? err.message : 'try again');
                restore();
            });
    }

    window.prReviewConfirm = function (action) {
        if (!currentId) return;
        if (action === 'rejected' && !confirm('Reject this punch request?')) return;

        var fd = new FormData();
        fd.append('action',        'review_punch_request');
        fd.append('request_id',    String(currentId));
        fd.append('review_action', action);
        fd.append('review_note',   noteEl.value.trim());
        fd.append('xhr',           '1');

        prSend(fd, action === 'approved' ? apBtn : rjBtn, action === 'approved' ? 'Approve' : 'Reject');
    };

    // Reversing deletes the punch this approval put into attendance, so the
    // reason is not optional and the confirm spells out what goes.
    window.prReverseConfirm = function () {
        if (!currentId) return;
        var note = noteEl.value.trim();
        if (!note) {
            stampEl.style.color = 'var(--red)';
            stampEl.textContent = 'Enter a reason before reversing this approval.';
            noteEl.focus();
            return;
        }
        if (!confirm('Reverse this approval?\n\nThe request goes back to rejected and the punch it added is removed from attendance.')) return;

        var fd = new FormData();
        fd.append('action',      'reverse_punch_request');
        fd.append('request_id',  String(currentId));
        fd.append('review_note', note);
        fd.append('xhr',         '1');

        prSend(fd, rvBtn, 'Reverse to Rejected');
    };
})();
</script>
<?php }

// ── Download punch request attachment ────────────────────
function downloadPrAttachment(): void {
    $id = (int)($_GET['id'] ?? 0);
    $db = getDb();
    $st = $db->prepare("SELECT employee_code, punch_date, attachment_name, attachment_stored FROM punch_requests WHERE id = ?");
    $st->execute([$id]);
    $req = $st->fetch(PDO::FETCH_ASSOC);

    if (!$req || !$req['attachment_stored']) return;

    // Only the requester, HR, or superadmin can view
    if ($req['employee_code'] !== myCode() && !canManageEmployees()) return;

    // Bucketed layout for new uploads, flat legacy path as fallback.
    $path = prAttachmentPath((string)$req['punch_date'], (string)$req['attachment_stored'], false);
    if (!file_exists($path)) return;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($path);
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . $req['attachment_name'] . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}
