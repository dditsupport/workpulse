<?php
// =========================================================
// Checklist Module — daily checklist + manage tasks
//
// Time-window rules (non-superadmin):
//   - Only one date is fillable: the "effective checklist date"
//     (= yesterday's calendar date if it's currently before 02:00,
//      else today's calendar date).
//   - Within that day, sections are editable inside these windows:
//       1.Morning   → 08:00 → 14:00 same day
//       2.Afternoon → 14:00 → 19:00 same day
//       3.Evening   → 19:00 → 02:00 next calendar day
//   - Outside the window the cells render read-only ("Opens at HH:MM"
//     before the start, "Closed at HH:MM" after the deadline).
//   - Past dates render read-only; future dates are blocked.
//   - Superadmin bypasses all restrictions.
// =========================================================

// Returns the date (Y-m-d) of the currently-fillable checklist day. The
// previous day's evening section can still be completed in the early hours,
// so before 02:00 we report yesterday.
function checklistEffectiveDate(): string {
    $now = time();
    if ((int)date('G', $now) < 2) {
        return date('Y-m-d', strtotime('-1 day', $now));
    }
    return date('Y-m-d', $now);
}

// Per-section start (Unix timestamp) for the given checklist date.
// Below this time the section is "not yet open" — no edits accepted.
// Sections not in the map open at start-of-day (00:00).
function checklistSectionStart(string $section, string $logDate): int {
    $base = strtotime($logDate);
    if ($base === false) return 0;
    return match ($section) {
        '1.Morning'   => $base + 8  * 3600, // 08:00 same day
        '2.Afternoon' => $base + 14 * 3600, // 14:00 same day
        '3.Evening'   => $base + 19 * 3600, // 19:00 same day
        default       => $base,
    };
}

// Per-section deadline (Unix timestamp) for the given checklist date.
// Sections not in the map are treated as open until 02:00 the next day.
function checklistSectionDeadline(string $section, string $logDate): int {
    $base = strtotime($logDate);
    if ($base === false) return 0;
    return match ($section) {
        '1.Morning'   => $base + 14 * 3600, // 14:00 same day
        '2.Afternoon' => $base + 19 * 3600, // 19:00 same day
        '3.Evening'   => $base + 26 * 3600, // 02:00 next day
        default       => $base + 26 * 3600,
    };
}

// True iff the current user may still write answers for the given section
// of the given checklist day. Superadmin always passes; others must be on
// the effective date AND within the section's start..deadline window.
function checklistSectionEditable(string $section, string $logDate): bool {
    if (isSuperadmin()) return true;
    if ($logDate !== checklistEffectiveDate()) return false;
    $now = time();
    return $now >= checklistSectionStart($section, $logDate)
        && $now <= checklistSectionDeadline($section, $logDate);
}

// "not_yet_open" | "open" | "closed"
// (Past dates always return "closed" via the editable check below.)
function checklistSectionState(string $section, string $logDate): string {
    if (isSuperadmin()) return 'open';
    if ($logDate !== checklistEffectiveDate()) return 'closed';
    $now = time();
    if ($now <  checklistSectionStart($section, $logDate))    return 'not_yet_open';
    if ($now >  checklistSectionDeadline($section, $logDate)) return 'closed';
    return 'open';
}

// ── Attachment storage (per response) ─────────────────────
define('CHECKLIST_UPLOAD_DIR', __DIR__ . '/../uploads/checklist/');
define('CHECKLIST_MAX_FILE_SIZE', 10 * 1024 * 1024);
define('CHECKLIST_ALLOWED_EXT', ['jpg','jpeg','png','gif','webp','pdf','heic','heif','doc','docx','xls','xlsx']);
define('CHECKLIST_ALLOWED_MIME', [
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
    'gif'  => ['image/gif'],
    'webp' => ['image/webp'],
    'heic' => ['image/heic','image/heif','application/octet-stream'],
    'heif' => ['image/heif','image/heic','application/octet-stream'],
    'pdf'  => ['application/pdf'],
    'doc'  => ['application/msword','application/octet-stream'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/octet-stream','application/zip'],
    'xls'  => ['application/vnd.ms-excel','application/octet-stream'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/octet-stream','application/zip'],
]);

// Build the storage directory for a (location, date) pair.
// Layout: uploads/checklist/{YYYY-MM}/{location_id}/ — month bucket first
// (rolls a single month's uploads under one parent for easy archival /
// trimming), location next. The log date itself isn't in the path because
// every attachment already carries its date via chk_daily_responses.
function checklistAttachmentDir(int $locationId, string $logDate): string {
    $monthBucket = preg_match('/^(\d{4}-\d{2})/', $logDate, $m) ? $m[1] : date('Y-m');
    return CHECKLIST_UPLOAD_DIR . $monthBucket . '/' . $locationId . '/';
}

// Legacy layout used briefly during the first rollout — kept as a
// read-only fallback so any file written under the old path still
// resolves on download / delete. New writes never use this.
function checklistAttachmentDirLegacy(int $locationId, string $logDate): string {
    return CHECKLIST_UPLOAD_DIR . $locationId . '/' . $logDate . '/';
}

// Resolve the on-disk path for a stored file, trying the current layout
// first and falling back to legacy. Returns null when neither exists.
function checklistAttachmentPath(int $locationId, string $logDate, string $storedName): ?string {
    $primary = checklistAttachmentDir($locationId, $logDate) . $storedName;
    if (file_exists($primary)) return $primary;
    $legacy = checklistAttachmentDirLegacy($locationId, $logDate) . $storedName;
    if (file_exists($legacy)) return $legacy;
    return null;
}

// Resolve the chk_daily_responses.id for a (location, item, date) tuple.
// Returns null if no response row exists yet (caller must save the
// answer first before attaching).
function checklistResponseId(int $locationId, int $itemId, string $logDate): ?int {
    $st = getDb()->prepare(
        'SELECT id FROM chk_daily_responses
         WHERE location_id = ? AND item_id = ? AND log_date = ?
         LIMIT 1');
    $st->execute([$locationId, $itemId, $logDate]);
    $id = $st->fetchColumn();
    return $id !== false ? (int)$id : null;
}

// Persist files uploaded for one item under name="attachments[ITEM_ID][]".
// Skips silently on size/extension/mime mismatches so a single bad file
// doesn't derail the whole submit.
function checklistSaveAttachments(int $responseId, int $locationId, string $logDate, int $itemId, string $uploaderCode): int {
    if (empty($_FILES['attachments']['name'][$itemId]) || !is_array($_FILES['attachments']['name'][$itemId])) {
        return 0;
    }
    $files = [
        'name'     => $_FILES['attachments']['name'][$itemId],
        'tmp_name' => $_FILES['attachments']['tmp_name'][$itemId],
        'error'    => $_FILES['attachments']['error'][$itemId],
        'size'     => $_FILES['attachments']['size'][$itemId],
        'type'     => $_FILES['attachments']['type'][$itemId] ?? [],
    ];
    $dir = checklistAttachmentDir($locationId, $logDate);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $db = getDb();
    $st = $db->prepare(
        'INSERT INTO chk_response_attachments
            (response_id, filename, stored_name, mime_type, file_size, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?)');
    $saved = 0;
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $n = count($files['name']);
    for ($i = 0; $i < $n; $i++) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        if ($files['size'][$i] > CHECKLIST_MAX_FILE_SIZE) continue;
        $origName = basename((string)$files['name'][$i]);
        $ext = mb_strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, CHECKLIST_ALLOWED_EXT, true)) continue;
        $mime = $finfo->file($files['tmp_name'][$i]) ?: 'application/octet-stream';
        $ok = CHECKLIST_ALLOWED_MIME[$ext] ?? [];
        if (!in_array($mime, $ok, true)) continue;
        $storedName = uniqid('chk_', true) . '.' . $ext;
        if (move_uploaded_file($files['tmp_name'][$i], $dir . $storedName)) {
            $st->execute([$responseId, $origName, $storedName, $mime, (int)$files['size'][$i], $uploaderCode]);
            $saved++;
        }
    }
    return $saved;
}

// Fetch all attachments for a given (location, date) grouped by item_id.
// Returns: [item_id => [['id','filename','mime_type','file_size','uploaded_by','uploader_name','uploaded_at','is_image'], ...]]
function checklistAttachmentsByItem(int $locationId, string $logDate): array {
    $st = getDb()->prepare(
        'SELECT a.id, a.response_id, a.filename, a.mime_type, a.file_size,
                a.uploaded_by, a.uploaded_at, r.item_id,
                e.full_name AS uploader_name
         FROM chk_response_attachments a
         JOIN chk_daily_responses r ON r.id = a.response_id
         LEFT JOIN employees e ON e.employee_code = a.uploaded_by
         WHERE r.location_id = ? AND r.log_date = ?
         ORDER BY a.uploaded_at ASC, a.id ASC');
    try {
        $st->execute([$locationId, $logDate]);
    } catch (Exception $e) {
        // Table not yet created — fail open with no attachments.
        return [];
    }
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['is_image'] = stripos((string)$r['mime_type'], 'image/') === 0;
        $out[(int)$r['item_id']][] = $r;
    }
    return $out;
}

// ── Save checklist responses ──────────────────────────────
function doSaveChecklist(): void {
    $locationId = (int)($_POST['location_id'] ?? 0);
    $logDate    = $_POST['log_date'] ?? checklistEffectiveDate();
    $answers    = $_POST['ans'] ?? [];
    $empCode    = myCode();

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $logDate)) $logDate = checklistEffectiveDate();

    // Restrict non-privileged users to their own location
    if (!isSuperadmin() && !hasTxn('checklist')) {
        $myLocId = myLocationId();
        if ($myLocId <= 0 || $locationId !== $myLocId) {
            flash('error', 'You can only fill checklist for your claimed location.');
            header("Location: index.php?page=checklist"); exit;
        }
    }

    // Only the effective checklist date is writable for non-superadmin.
    if (!isSuperadmin() && $logDate !== checklistEffectiveDate()) {
        flash('error', 'You can only fill the current checklist day (' . checklistEffectiveDate() . ').');
        header("Location: index.php?page=checklist&location_id={$locationId}&date={$logDate}"); exit;
    }

    // Attach-only submits (no fresh answers, only files) are legitimate
    // once the response row already exists — let those through.
    $hasUploads = !empty($_FILES['attachments']['name']) && is_array($_FILES['attachments']['name']);
    if ($locationId <= 0 || (empty($answers) && !$hasUploads)) {
        flash('error', 'No data submitted.');
        header("Location: index.php?page=checklist&location_id={$locationId}&date={$logDate}"); exit;
    }

    $db = getDb();

    // Look up each answered item's section so we can enforce per-section
    // deadlines server-side. (A user could submit a stale form past 14:00
    // and try to push Morning answers — those must be silently skipped.)
    $itemIds = array_values(array_filter(array_map('intval', array_keys($answers)), fn($i) => $i > 0));
    $sections = [];
    if ($itemIds) {
        $ph = implode(',', array_fill(0, count($itemIds), '?'));
        $sst = $db->prepare("SELECT id, section_name FROM chk_items WHERE id IN ($ph)");
        $sst->execute($itemIds);
        foreach ($sst->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $sections[(int)$r['id']] = (string)($r['section_name'] ?? '');
        }
    }

    $st = $db->prepare(
        "INSERT INTO chk_daily_responses (location_id, item_id, employee_code, log_date, response_value, submitted_at)
         VALUES (?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
           response_value = VALUES(response_value),
           employee_code  = VALUES(employee_code),
           submitted_at   = NOW()"
    );

    $saved = 0; $skippedClosed = 0;
    try {
        $db->beginTransaction();
        foreach ($answers as $itemId => $val) {
            if ($val === '') continue;
            $sec = $sections[(int)$itemId] ?? '';
            if (!checklistSectionEditable($sec, $logDate)) {
                $skippedClosed++;
                continue;
            }
            $st->execute([$locationId, (int)$itemId, $empCode, $logDate, $val]);
            $saved++;
        }
        $db->commit();
        if ($skippedClosed > 0) {
            flash('success', "Saved {$saved} task(s). {$skippedClosed} task(s) skipped — section is outside its allowed time window (Morning 08:00–14:00, Afternoon 14:00–19:00, Evening 19:00–02:00).");
        } elseif ($saved > 0) {
            flash('success', 'Checklist submitted successfully.');
        } elseif (!empty($answers)) {
            // Had answers in the POST but none applied → outside window.
            flash('error', 'Nothing saved — all submitted tasks are outside their allowed time window (Morning 08:00–14:00, Afternoon 14:00–19:00, Evening 19:00–02:00).');
        }
        // Attach-only submits (empty $answers) skip the flash here; the
        // file-attachment block below sets its own success/error message.
    } catch (Exception $e) {
        $db->rollBack();
        flash('error', 'Error saving checklist.');
    }

    // ── File attachments ─────────────────────────────────
    // Run *after* the answer commit so checklistResponseId() can find the
    // upserted row. Each item's files come through as
    // attachments[ITEM_ID][] — process only items with an existing
    // response row in an editable section. We also need the section name
    // for items that didn't get an answer in this submit (uploads-only
    // case), so look those up here.
    if (!empty($_FILES['attachments']['name']) && is_array($_FILES['attachments']['name'])) {
        $attachItemIds = [];
        foreach ($_FILES['attachments']['name'] as $iid => $names) {
            if (is_array($names) && count($names) > 0) $attachItemIds[] = (int)$iid;
        }
        $attachItemIds = array_values(array_filter($attachItemIds, fn($i) => $i > 0));
        if ($attachItemIds) {
            $missingSec = array_values(array_diff($attachItemIds, array_keys($sections)));
            if ($missingSec) {
                $ph = implode(',', array_fill(0, count($missingSec), '?'));
                $sst = $db->prepare("SELECT id, section_name FROM chk_items WHERE id IN ($ph)");
                $sst->execute($missingSec);
                foreach ($sst->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $sections[(int)$r['id']] = (string)($r['section_name'] ?? '');
                }
            }
            $attSaved = 0; $attSkipped = 0;
            foreach ($attachItemIds as $itemId) {
                $sec = $sections[$itemId] ?? '';
                if (!checklistSectionEditable($sec, $logDate)) { $attSkipped++; continue; }
                $respId = checklistResponseId($locationId, $itemId, $logDate);
                if ($respId === null) { $attSkipped++; continue; } // no answer yet → ignore file
                $attSaved += checklistSaveAttachments($respId, $locationId, $logDate, $itemId, $empCode);
            }
            if ($attSaved > 0) {
                $prev = $_SESSION['flash'] ?? null;
                $prevMsg = ($prev && ($prev['type'] ?? '') === 'success') ? rtrim((string)$prev['msg']) . ' ' : '';
                flash('success', $prevMsg . "{$attSaved} file(s) attached.");
            }
        }
    }
    header("Location: index.php?page=checklist&location_id={$locationId}&date={$logDate}"); exit;
}

// ── View permission for a single attachment ──────────────
// Returns the joined attachment+response row if the current user is
// allowed to view it, or null otherwise. Mirrors the page visibility
// rule: superadmin / checklist-report txn see everything; everyone else
// only sees their own location.
function checklistAttachmentForView(int $attId): ?array {
    if ($attId < 1) return null;
    $st = getDb()->prepare(
        'SELECT a.id, a.filename, a.stored_name, a.mime_type, a.file_size,
                a.uploaded_by, a.uploaded_at,
                r.location_id, r.log_date, r.item_id
         FROM chk_response_attachments a
         JOIN chk_daily_responses r ON r.id = a.response_id
         WHERE a.id = ?');
    try { $st->execute([$attId]); } catch (Exception $e) { return null; }
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    if (isSuperadmin() || hasTxn('checklist_report')) return $row;
    $myLoc = myLocationId();
    if ($myLoc > 0 && (int)$row['location_id'] === $myLoc) return $row;
    return null;
}

// ── Download endpoint ────────────────────────────────────
function downloadChecklistAttachment(): void {
    $attId = (int)($_GET['att_id'] ?? 0);
    $row   = checklistAttachmentForView($attId);
    if (!$row) { http_response_code(403); echo 'Access denied'; return; }
    $path  = checklistAttachmentPath((int)$row['location_id'], (string)$row['log_date'], (string)$row['stored_name']);
    if (!$path) { http_response_code(404); echo 'File missing'; return; }
    header('Content-Type: ' . $row['mime_type']);
    header('Content-Disposition: inline; filename="' . str_replace('"', '', $row['filename']) . '"');
    header('Content-Length: ' . (int)$row['file_size']);
    readfile($path);
    exit;
}

// ── Delete attachment (uploader-only while section is open) ─
function doDeleteChecklistAttachment(): void {
    $attId      = (int)($_POST['att_id'] ?? 0);
    $locationId = (int)($_POST['location_id'] ?? 0);
    $logDate    = $_POST['log_date'] ?? checklistEffectiveDate();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $logDate)) $logDate = checklistEffectiveDate();
    $back = "Location: index.php?page=checklist&location_id={$locationId}&date={$logDate}";

    $row = checklistAttachmentForView($attId);
    if (!$row) { flash('error', 'Attachment not found or access denied.'); header($back); exit; }

    // Only the original uploader (or superadmin) can delete, and only
    // while their section is still editable for the original day.
    $isOwner = ((string)$row['uploaded_by'] === myCode());
    if (!isSuperadmin() && !$isOwner) {
        flash('error', 'You can only delete files you uploaded.');
        header($back); exit;
    }
    if (!isSuperadmin()) {
        $sst = getDb()->prepare('SELECT section_name FROM chk_items WHERE id = ?');
        $sst->execute([(int)$row['item_id']]);
        $sec = (string)($sst->fetchColumn() ?: '');
        if (!checklistSectionEditable($sec, (string)$row['log_date'])) {
            flash('error', 'Section is closed — file can no longer be removed.');
            header($back); exit;
        }
    }

    $path = checklistAttachmentPath((int)$row['location_id'], (string)$row['log_date'], (string)$row['stored_name']);
    try {
        getDb()->prepare('DELETE FROM chk_response_attachments WHERE id = ?')->execute([$attId]);
        if ($path) @unlink($path);
        flash('success', 'Attachment deleted.');
    } catch (Exception $e) {
        flash('error', 'Could not delete attachment.');
    }
    header($back); exit;
}

// ── Manage tasks: save ────────────────────────────────────
function doSaveTask(): void {
    $id      = (int)($_POST['task_id'] ?? 0);
    $desc    = trim($_POST['description'] ?? '');
    $section = trim($_POST['section'] ?? '');
    $type    = $_POST['input_type'] ?? 'yes_no';

    if (!$desc || !$section) {
        flash('error', 'Description and section required.');
        header('Location: index.php?page=manage_tasks'); exit;
    }

    $db = getDb();
    if ($id > 0) {
        $st = $db->prepare("UPDATE chk_items SET task_description=?, section_name=?, input_type=? WHERE id=?");
        $st->execute([$desc, $section, $type, $id]);
    } else {
        $st = $db->prepare("INSERT INTO chk_items (task_description, section_name, input_type, is_active) VALUES (?,?,?,1)");
        $st->execute([$desc, $section, $type]);
    }
    flash('success', $id ? 'Task updated.' : 'Task added.');
    header('Location: index.php?page=manage_tasks'); exit;
}

// ── Manage tasks: toggle active ───────────────────────────
function doToggleTask(): void {
    $id = (int)($_POST['task_id'] ?? 0);
    $db = getDb();
    $db->prepare("UPDATE chk_items SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
    flash('success', 'Task status toggled.');
    header('Location: index.php?page=manage_tasks'); exit;
}

// ── Manage tasks: delete ──────────────────────────────────
function doDelTask(): void {
    $id = (int)($_POST['task_id'] ?? 0);
    $db = getDb();
    $chk = $db->prepare("SELECT COUNT(*) FROM chk_daily_responses WHERE item_id = ?");
    $chk->execute([$id]);
    if ($chk->fetchColumn() > 0) {
        flash('error', 'Cannot delete: task has historical data. Deactivate instead.');
    } else {
        $db->prepare("DELETE FROM chk_items WHERE id = ?")->execute([$id]);
        flash('success', 'Task deleted.');
    }
    header('Location: index.php?page=manage_tasks'); exit;
}

// ── Checklist page (daily fill) ───────────────────────────
function pageChecklist(): void {
    // Default to the effective checklist day so users land on whatever
    // is actually fillable right now (yesterday before 02:00, today after).
    $displayDate   = $_GET['date'] ?? checklistEffectiveDate();
    $locationId    = (int)($_GET['location_id'] ?? 0);
    $effectiveDate = checklistEffectiveDate();
    $isPast        = ($displayDate < $effectiveDate);
    $isFutureDate  = ($displayDate > $effectiveDate);

    // Non-superadmin/non-module users: restrict to their own claimed location
    $myLocId = myLocationId();
    $restrictLocation = !isSuperadmin();
    if ($restrictLocation && $myLocId > 0) {
        $locationId = $myLocId;
        $locations = [];
        $loc = getLocation($myLocId);
        if ($loc) $locations[] = ['location_id' => $loc['location_id'], 'location_name' => $loc['location_name']];
    } elseif ($restrictLocation && $myLocId <= 0) {
        $locations = [];
    } else {
        $locations = getActiveLocations();
    }

    $monthStart = date('Y-m-01', strtotime($displayDate));
    $monthEnd   = date('Y-m-t', strtotime($displayDate));
    $prevMonth  = date('Y-m-d', strtotime('-1 month', strtotime($monthStart)));
    $nextMonth  = date('Y-m-d', strtotime('+1 month', strtotime($monthStart)));

    $db = getDb();
    $tasks = [];
    $existingCounts = [];
    $totalQ = (int)($db->query("SELECT COUNT(*) FROM chk_items WHERE is_active = 1")->fetchColumn() ?: 1);

    if ($locationId > 0) {
        // Monthly tile counts
        $st = $db->prepare(
            "SELECT log_date, COUNT(*) AS done FROM chk_daily_responses
             WHERE location_id = ? AND log_date BETWEEN ? AND ?
             GROUP BY log_date"
        );
        $st->execute([$locationId, $monthStart, $monthEnd]);
        $existingCounts = $st->fetchAll(PDO::FETCH_KEY_PAIR);

        // Tasks with existing responses for selected date
        $st = $db->prepare(
            "SELECT q.id, q.task_description, q.input_type, q.section_name,
                    a.response_value, e.full_name AS submitted_by
             FROM chk_items q
             LEFT JOIN chk_daily_responses a
                    ON q.id = a.item_id AND a.log_date = ? AND a.location_id = ?
             LEFT JOIN employees e ON a.employee_code = e.employee_code
             WHERE q.is_active = 1
             ORDER BY q.section_name, q.id ASC"
        );
        $st->execute([$displayDate, $locationId]);
        $tasks = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    // Per-item attachments for this (location, date). Empty array when
    // nothing's been uploaded or the table doesn't exist yet.
    $itemAttachments = ($locationId > 0)
        ? checklistAttachmentsByItem($locationId, $displayDate)
        : [];
?>
<div class="page-header"><h2>✅ Daily Checklist</h2></div>

<!-- Location Selector -->
<div class="filter-bar" style="margin-bottom:14px">
<?php if ($restrictLocation && $myLocId > 0): ?>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <strong style="font-size:15px"><?= h($locations[0]['location_name'] ?? '') ?></strong>
        <span class="text-muted">Viewing: <strong><?= date('d M Y', strtotime($displayDate)) ?></strong></span>
    </div>
<?php else: ?>
    <form method="GET" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="page" value="checklist">
        <input type="hidden" name="date" value="<?= h($displayDate) ?>">
        <select name="location_id" class="form-control" style="width:260px" onchange="this.form.submit()">
            <option value="">— Select Location —</option>
            <?php foreach ($locations as $loc): ?>
            <option value="<?= $loc['location_id'] ?>" <?= $locationId == $loc['location_id'] ? 'selected' : '' ?>>
                <?= h($loc['location_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <span class="text-muted">Viewing: <strong><?= date('d M Y', strtotime($displayDate)) ?></strong></span>
    </form>
<?php endif; ?>
</div>

<?php if ($restrictLocation && $myLocId <= 0): ?>
<div class="alert alert-error">You have not claimed a location yet. Please go to <a href="?page=my_location" style="color:var(--accent)">My Location</a> to claim your location first.</div>
<?php elseif ($locationId > 0): ?>
<!-- Month Calendar Tiles -->
<div class="table-wrap" style="padding:16px;margin-bottom:14px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <a href="?page=checklist&location_id=<?= $locationId ?>&date=<?= $prevMonth ?>" class="btn btn-ghost btn-sm">&lsaquo; Prev</a>
        <strong><?= date('F Y', strtotime($displayDate)) ?></strong>
        <a href="?page=checklist&location_id=<?= $locationId ?>&date=<?= $nextMonth ?>" class="btn btn-ghost btn-sm">Next &rsaquo;</a>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(72px,1fr));gap:6px">
        <?php
        $daysInMonth = (int)date('t', strtotime($displayDate));
        for ($d = 1; $d <= $daysInMonth; $d++):
            $tileDate = date('Y-m-', strtotime($displayDate)) . str_pad($d, 2, '0', STR_PAD_LEFT);
            // Anything past the effective day is "future" for fill purposes
            // (you can't open a checklist for tomorrow). Past dates stay
            // clickable but render the form read-only.
            $tileFuture = ($tileDate > $effectiveDate);
            $tilePast   = ($tileDate < $effectiveDate);
            $done = $existingCounts[$tileDate] ?? 0;
            if ($tileFuture) { $bg = '#4b5563'; }
            elseif ($done >= $totalQ)  { $bg = 'var(--green)'; }
            elseif ($done > 0)     { $bg = 'var(--yellow)'; }
            else                   { $bg = $tilePast ? '#6b7280' : 'var(--red)'; }
            $active = ($displayDate === $tileDate && !$tileFuture) ? 'outline:3px solid var(--accent);outline-offset:2px;' : '';
            $tileOpacity = $tilePast ? '0.7' : '1';
        ?>
        <?php if ($tileFuture): ?>
        <span style="background:<?= $bg ?>;color:#9ca3af;border-radius:6px;padding:8px 4px;text-align:center;
                  font-size:11px;font-weight:700;display:block;opacity:0.5;cursor:not-allowed;">
            <?= $d ?><br><span style="font-weight:400">—</span>
        </span>
        <?php else: ?>
        <a href="?page=checklist&location_id=<?= $locationId ?>&date=<?= $tileDate ?>"
           style="background:<?= $bg ?>;color:#fff;border-radius:6px;padding:8px 4px;text-align:center;
                  font-size:11px;font-weight:700;text-decoration:none;display:block;opacity:<?= $tileOpacity ?>;<?= $active ?>">
            <?= $d ?><br><span style="font-weight:400"><?= $done ?>/<?= $totalQ ?></span>
        </a>
        <?php endif; ?>
        <?php endfor; ?>
    </div>
    <div style="display:flex;gap:14px;margin-top:10px;font-size:11px;color:var(--muted)">
        <span><span style="color:var(--green)">&#9632;</span> Complete</span>
        <span><span style="color:var(--yellow)">&#9632;</span> Partial</span>
        <span><span style="color:var(--red)">&#9632;</span> Pending</span>
    </div>
</div>

<!-- Checklist Form -->
<?php if ($isFutureDate): ?>
<div class="alert alert-error">Future dates cannot be filled. The current checklist day is <strong><?= h(date('d M Y', strtotime($effectiveDate))) ?></strong>.</div>
<?php elseif (!empty($tasks)): ?>
<?php
// Per-section editability for this displayed date — used to gate inputs.
// $sectionStatus[<section_name>] = [
//     'state'         => 'not_yet_open' | 'open' | 'closed',
//     'open'          => bool (= state === 'open'),
//     'startLabel'    => 'h:i A' for "Opens at …" badges,
//     'deadlineLabel' => 'h:i A' for "Open until …" / "Closed at …" badges,
// ]
$sectionStatus = [];
foreach ($tasks as $t) {
    $sec = (string)($t['section_name'] ?? '');
    if (!isset($sectionStatus[$sec])) {
        $start    = checklistSectionStart($sec, $displayDate);
        $deadline = checklistSectionDeadline($sec, $displayDate);
        $state    = checklistSectionState($sec, $displayDate);
        $sectionStatus[$sec] = [
            'state'         => $state,
            'open'          => $state === 'open',
            'startLabel'    => $start    ? date('h:i A', $start)    : '',
            'deadlineLabel' => $deadline ? date('h:i A', $deadline) : '',
        ];
    }
}

// Form is read-only when viewing a past day OR when no section is
// currently open (either everything's closed already OR everything's
// still scheduled for later in the day — we show different copy below).
$anyOpenSection = false;
$anyUpcoming    = false;
foreach ($sectionStatus as $info) {
    if ($info['open'])                       $anyOpenSection = true;
    if ($info['state'] === 'not_yet_open')   $anyUpcoming    = true;
}
$readOnly = $isPast || !$anyOpenSection;
?>
<?php if ($isPast): ?>
<div class="alert" style="margin-bottom:10px;background:rgba(107,114,128,.12);color:#9ca3af;border:1px solid rgba(107,114,128,.3)">Viewing a past day — read-only.</div>
<?php elseif (!$anyOpenSection && $anyUpcoming): ?>
<div class="alert" style="margin-bottom:10px;background:rgba(201,168,0,.10);color:var(--yellow);border:1px solid rgba(201,168,0,.30)">No section is open right now. The next section opens later today — check back at the time shown on each section header.</div>
<?php elseif (!$anyOpenSection): ?>
<div class="alert" style="margin-bottom:10px;background:rgba(107,114,128,.12);color:#9ca3af;border:1px solid rgba(107,114,128,.3)">All sections for today are closed. The day will roll over at 02:00.</div>
<?php endif; ?>
<form method="POST" enctype="multipart/form-data" id="chkForm"<?= $readOnly ? ' onsubmit="return false"' : '' ?>>
    <input type="hidden" name="action" value="save_checklist">
    <input type="hidden" name="location_id" value="<?= $locationId ?>">
    <input type="hidden" name="log_date" value="<?= h($displayDate) ?>">
    <div class="table-wrap">
        <table class="table chk-table">
            <thead>
                <tr><th style="width:48px;text-align:center">#</th><th>Particular</th><th style="width:260px">Status / Answer</th></tr>
            </thead>
            <tbody>
            <?php
            $currentSection = null; $sr = 1;
            foreach ($tasks as $t):
                $sec = (string)($t['section_name'] ?? '');
                $secInfo = $sectionStatus[$sec] ?? ['state' => 'closed', 'open' => false, 'startLabel' => '', 'deadlineLabel' => ''];
                $cellEditable = !$readOnly && $secInfo['open'];
                if ($sec !== $currentSection):
                    $currentSection = $sec;
            ?>
                <tr><td colspan="3" class="chk-section" style="background:var(--border);font-weight:700;font-size:12px;padding:8px 13px">
                    <?= h($currentSection ?: 'General') ?>
                    <?php if ($isPast): ?>
                        <span class="badge badge-grey" style="margin-left:8px;font-weight:600">Read-only (past)</span>
                    <?php elseif ($secInfo['state'] === 'not_yet_open'): ?>
                        <span class="badge badge-yellow" style="margin-left:8px;font-weight:600">Opens at <?= h($secInfo['startLabel']) ?></span>
                    <?php elseif ($secInfo['open']): ?>
                        <span class="badge badge-green" style="margin-left:8px;font-weight:600">Open <?= h($secInfo['startLabel']) ?> – <?= h($secInfo['deadlineLabel']) ?></span>
                    <?php else: ?>
                        <span class="badge badge-red" style="margin-left:8px;font-weight:600">Closed at <?= h($secInfo['deadlineLabel']) ?></span>
                    <?php endif; ?>
                </td></tr>
            <?php endif; ?>
                <tr>
                    <td class="chk-num" style="text-align:center;color:var(--muted);font-size:12px"><?= $sr++ ?></td>
                    <td class="chk-particular"><?= h($t['task_description']) ?></td>
                    <td class="chk-answer">
                        <?php if (!empty($t['response_value'])): ?>
                            <span class="badge badge-green">&#10003; <?= h($t['response_value']) ?></span>
                            <div class="text-muted" style="margin-top:2px">By: <?= h($t['submitted_by'] ?? 'Unknown') ?></div>
                        <?php elseif (!$cellEditable): ?>
                            <?php
                                $cellMsg = $isPast
                                    ? 'Not filled'
                                    : ($secInfo['state'] === 'not_yet_open'
                                        ? ('Opens at ' . ($secInfo['startLabel'] ?: '—'))
                                        : 'Section closed');
                            ?>
                            <span class="text-muted" style="font-size:12px">— <?= h($cellMsg) ?> —</span>
                        <?php else: ?>
                            <?php if ($t['input_type'] === 'time'): ?>
                                <?= time24Input('ans[' . $t['id'] . ']') ?>
                            <?php elseif ($t['input_type'] === 'yes_no'): ?>
                                <select name="ans[<?= $t['id'] ?>]" class="form-control" style="width:120px">
                                    <option value="">— Select —</option>
                                    <option value="Yes">Yes</option>
                                    <option value="No">No</option>
                                </select>
                            <?php elseif ($t['input_type'] === 'number'): ?>
                                <input type="number" name="ans[<?= $t['id'] ?>]" class="form-control" style="width:140px" placeholder="Enter number">
                            <?php else: ?>
                                <input type="text" name="ans[<?= $t['id'] ?>]" class="form-control" placeholder="Enter details">
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php
                        $attList    = $itemAttachments[(int)$t['id']] ?? [];
                        $hasAnswer  = !empty($t['response_value']);
                        // Allow file input alongside an unanswered editable
                        // input too — the save handler upserts the answer
                        // first then attaches, so a single submit covers both.
                        $canAttach  = $cellEditable;
                        if ($attList || $canAttach):
                        ?>
                            <div class="chk-att-wrap" style="margin-top:6px;display:flex;flex-direction:column;gap:4px">
                                <?php foreach ($attList as $att): ?>
                                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                                        <a class="chk-att-chip" target="_blank"
                                           href="?page=download_checklist_attachment&att_id=<?= (int)$att['id'] ?>"
                                           title="<?= h($att['uploader_name'] ?? $att['uploaded_by']) . ' · ' . h($att['uploaded_at']) ?>"
                                           style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border:1px solid var(--border);border-radius:999px;font-size:11px;color:var(--text);text-decoration:none;background:rgba(255,255,255,.04)">
                                            <?= $att['is_image'] ? '🖼' : '📎' ?>
                                            <span style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($att['filename']) ?></span>
                                        </a>
                                        <?php if ($cellEditable && (string)$att['uploaded_by'] === myCode()): ?>
                                            <button type="button" class="btn-ghost-x"
                                                onclick="if(confirm('Delete this file?')){document.getElementById('chkAttDelId').value='<?= (int)$att['id'] ?>';document.getElementById('chkAttDelForm').submit();}"
                                                style="border:none;background:transparent;color:var(--muted);cursor:pointer;font-size:14px;line-height:1;padding:0 2px"
                                                title="Delete">×</button>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php if ($canAttach): ?>
                                    <input type="file" class="form-control chk-files"
                                           name="attachments[<?= (int)$t['id'] ?>][]"
                                           accept="image/*,application/pdf,.doc,.docx,.xls,.xlsx"
                                           multiple capture="environment"
                                           style="font-size:11px;margin-top:2px">
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    // Show submit button while any open section is available — covers both
    // unanswered tasks AND attach-only saves on already-answered ones.
    $hasFillable = false;
    $hasAttachable = false;
    if (!$readOnly) {
        foreach ($tasks as $tk) {
            $tkSec = (string)($tk['section_name'] ?? '');
            $sectionOpen = ($sectionStatus[$tkSec]['open'] ?? false);
            if (!$sectionOpen) continue;
            if (empty($tk['response_value']))  $hasFillable   = true;
            else                               $hasAttachable = true;
        }
    }
    if ($hasFillable || $hasAttachable): ?>
    <div class="form-actions" style="position:sticky;bottom:0;margin-top:10px;padding:10px 0;background:var(--bg);border-top:1px solid var(--border);z-index:20">
        <button type="submit" class="btn btn-success">
            <?= $hasFillable ? 'Submit Daily Progress' : 'Save Attachments' ?>
        </button>
    </div>
    <?php elseif (!$readOnly): ?>
    <div class="alert alert-success" style="margin-top:10px">All open-section tasks have been completed.</div>
    <?php endif; ?>
</form>
<!-- Hidden form for attachment delete (one form, item-id-less by design — uses att_id only) -->
<form id="chkAttDelForm" method="POST" style="display:none">
    <input type="hidden" name="action" value="delete_checklist_attachment">
    <input type="hidden" name="att_id" id="chkAttDelId" value="">
    <input type="hidden" name="location_id" value="<?= $locationId ?>">
    <input type="hidden" name="log_date" value="<?= h($displayDate) ?>">
</form>
<script>
// ── Client-side image compression for checklist uploads ──
// Same rules as the audit edit page: image > 600 KB → downscale long
// edge to 1600 px and re-encode JPEG q=0.75. Other files pass through.
// Gates submit until every in-flight compression resolves.
(function () {
    var form = document.getElementById('chkForm');
    if (!form) return;
    var MAX_EDGE = 1600, SKIP_BELOW = 600 * 1024, JPEG_QUALITY = 0.75;
    var IMAGE_RE = /^image\/(jpeg|png|gif|webp|heic|heif)$/i;
    var inflight = 0;
    var submitBtns = form.querySelectorAll('button[type="submit"]');
    function fmtSize(b) {
        if (b < 1024) return b + ' B';
        if (b < 1024 * 1024) return (b / 1024).toFixed(1) + ' KB';
        return (b / 1024 / 1024).toFixed(2) + ' MB';
    }
    function setSubmitDisabled(d) { submitBtns.forEach(function (b) { b.disabled = d; }); }
    function statusNodeFor(input) {
        var node = input.nextElementSibling;
        if (!node || !node.classList || !node.classList.contains('chk-att-status')) {
            node = document.createElement('div');
            node.className = 'chk-att-status';
            node.style.cssText = 'font-size:10px;margin-top:2px;color:var(--muted);min-height:12px';
            input.parentNode.insertBefore(node, input.nextSibling);
        }
        return node;
    }
    function setFiles(input, arr) {
        try { var dt = new DataTransfer(); arr.forEach(function (f) { dt.items.add(f); }); input.files = dt.files; return true; }
        catch (e) { return false; }
    }
    function decode(file) {
        if (typeof createImageBitmap === 'function') {
            try { return createImageBitmap(file, { imageOrientation: 'from-image' }); }
            catch (e) { return createImageBitmap(file); }
        }
        return new Promise(function (res, rej) {
            var url = URL.createObjectURL(file); var img = new Image();
            img.onload = function () { URL.revokeObjectURL(url); res(img); };
            img.onerror = function () { URL.revokeObjectURL(url); rej(new Error('decode failed')); };
            img.src = url;
        });
    }
    function compressOne(file) {
        if (!IMAGE_RE.test(file.type)) return Promise.resolve(file);
        if (file.size <= SKIP_BELOW)   return Promise.resolve(file);
        return decode(file).then(function (bmp) {
            var w = bmp.width || bmp.naturalWidth, h = bmp.height || bmp.naturalHeight;
            if (!w || !h) return file;
            var scale = Math.min(1, MAX_EDGE / Math.max(w, h));
            var tw = Math.round(w * scale), th = Math.round(h * scale);
            var canvas = document.createElement('canvas');
            canvas.width = tw; canvas.height = th;
            canvas.getContext('2d').drawImage(bmp, 0, 0, tw, th);
            return new Promise(function (res) {
                canvas.toBlob(function (blob) {
                    if (!blob || blob.size >= file.size) { res(file); return; }
                    var base = (file.name || 'photo').replace(/\.(png|jpe?g|gif|webp|heic|heif)$/i, '');
                    res(new File([blob], base + '.jpg', { type: 'image/jpeg', lastModified: Date.now() }));
                }, 'image/jpeg', JPEG_QUALITY);
            });
        }).catch(function () { return file; });
    }
    document.addEventListener('change', function (e) {
        if (!e.target.matches || !e.target.matches('.chk-files')) return;
        var input = e.target, status = statusNodeFor(input);
        var files = Array.from(input.files || []);
        if (!files.length) { status.textContent = ''; return; }
        var origTotal = files.reduce(function (n, f) { return n + f.size; }, 0);
        var any = files.some(function (f) { return IMAGE_RE.test(f.type) && f.size > SKIP_BELOW; });
        if (!any) { status.style.color = 'var(--muted)'; status.textContent = files.length + ' file(s) — ' + fmtSize(origTotal); return; }
        inflight++; setSubmitDisabled(true);
        status.style.color = 'var(--muted)'; status.textContent = 'Compressing photo(s)…';
        Promise.all(files.map(compressOne)).then(function (out) {
            var newTotal = out.reduce(function (n, f) { return n + f.size; }, 0);
            if (!setFiles(input, out)) {
                status.style.color = 'var(--yellow)';
                status.textContent = 'Could not replace files — uploading originals (' + fmtSize(origTotal) + ').';
            } else if (newTotal < origTotal) {
                status.style.color = 'var(--green)';
                status.textContent = fmtSize(origTotal) + ' → ' + fmtSize(newTotal) + ' (' + Math.round((1 - newTotal / origTotal) * 100) + '% smaller).';
            } else {
                status.style.color = 'var(--muted)';
                status.textContent = out.length + ' file(s) — ' + fmtSize(newTotal);
            }
        }).catch(function () {
            status.style.color = 'var(--yellow)';
            status.textContent = 'Compression failed — uploading originals (' + fmtSize(origTotal) + ').';
        }).then(function () {
            inflight = Math.max(0, inflight - 1);
            if (inflight === 0) setSubmitDisabled(false);
        });
    }, true);
    form.addEventListener('submit', function (e) {
        if (inflight > 0) { e.preventDefault(); alert('Still compressing photo(s) — please wait a moment and try again.'); }
    }, true);
})();
</script>
<?php else: ?>
<div class="alert alert-error">No active checklist tasks found. Add tasks via Manage Tasks.</div>
<?php endif; ?>

<?php endif; // locationId ?>
<?php }

// ── Manage Tasks page (operations, superadmin) ────────────
function pageManageTasks(): void {
    $db = getDb();
    $tasks = $db->query("SELECT id, task_description, section_name, input_type, is_active FROM chk_items ORDER BY section_name, id ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="page-header"><h2>📝 Manage Checklist Tasks</h2></div>

<!-- Add / Edit Task -->
<div class="form-card" style="margin-bottom:16px">
    <h3 id="taskFormTitle" style="font-size:14px;margin-bottom:12px">Add New Task</h3>
    <form method="POST" id="taskForm">
        <input type="hidden" name="action" value="save_task">
        <input type="hidden" name="task_id" id="taskId" value="0">
        <div class="form-grid">
            <div class="form-group" style="grid-column:1/-1">
                <label>Task Description <span class="required">*</span></label>
                <input type="text" name="description" id="taskDesc" class="form-control" required placeholder="e.g. Check Fridge Temperature">
            </div>
            <div class="form-group">
                <label>Section <span class="required">*</span></label>
                <input type="text" name="section" id="taskSection" class="form-control" required placeholder="e.g. Morning">
            </div>
            <div class="form-group">
                <label>Input Type</label>
                <select name="input_type" id="taskType" class="form-control">
                    <option value="yes_no">Yes / No</option>
                    <option value="time">Time</option>
                    <option value="text">Text</option>
                    <option value="number">Number</option>
                </select>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" id="taskSubmitBtn" class="btn btn-primary">Add Task</button>
            <button type="button" id="taskCancelBtn" class="btn btn-secondary" style="display:none" onclick="cancelEdit()">Cancel</button>
        </div>
    </form>
</div>
<script>
function editTask(id, section, desc, type) {
    document.getElementById('taskId').value = id;
    document.getElementById('taskSection').value = section;
    document.getElementById('taskDesc').value = desc;
    document.getElementById('taskType').value = type;
    document.getElementById('taskFormTitle').textContent = 'Edit Task #' + id;
    document.getElementById('taskSubmitBtn').textContent = 'Update Task';
    document.getElementById('taskCancelBtn').style.display = 'inline-block';
    document.getElementById('taskForm').scrollIntoView({behavior:'smooth'});
}
function cancelEdit() {
    document.getElementById('taskId').value = 0;
    document.getElementById('taskSection').value = '';
    document.getElementById('taskDesc').value = '';
    document.getElementById('taskType').value = 'yes_no';
    document.getElementById('taskFormTitle').textContent = 'Add New Task';
    document.getElementById('taskSubmitBtn').textContent = 'Add Task';
    document.getElementById('taskCancelBtn').style.display = 'none';
}
</script>

<!-- Tasks Table -->
<div class="table-wrap" data-stack>
    <table class="table">
        <thead>
            <tr>
                <th style="width:50px">ID</th>
                <th style="width:140px">Section</th>
                <th>Description</th>
                <th style="width:100px">Type</th>
                <th style="width:90px">Status</th>
                <th style="width:180px">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($tasks)): ?>
            <tr><td colspan="6" class="empty-row">No tasks defined yet.</td></tr>
            <?php else: foreach ($tasks as $t): ?>
            <tr class="<?= $t['is_active'] ? '' : 'row-inactive' ?>">
                <td><?= $t['id'] ?></td>
                <td><?= h($t['section_name']) ?></td>
                <td><?= h($t['task_description']) ?></td>
                <td><span class="badge badge-blue"><?= h($t['input_type']) ?></span></td>
                <td><?= $t['is_active'] ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-grey">Inactive</span>' ?></td>
                <td class="actions" style="display:flex;gap:4px;flex-wrap:wrap">
                    <button type="button" class="btn btn-primary btn-sm" onclick="editTask(<?= $t['id'] ?>, <?= h(json_encode($t['section_name'])) ?>, <?= h(json_encode($t['task_description'])) ?>, '<?= h($t['input_type']) ?>')">Edit</button>
                    <form method="POST" class="inline-form">
                        <input type="hidden" name="action" value="toggle_task">
                        <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                        <button type="submit" class="btn btn-ghost btn-sm"><?= $t['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                    </form>
                    <?php if (!$t['is_active']): ?>
                    <form method="POST" class="inline-form" onsubmit="return confirm('Delete this task permanently?')">
                        <input type="hidden" name="action" value="del_task">
                        <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Del</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?php }
