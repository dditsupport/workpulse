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

// ── Checklist + section data access ───────────────────────
// One registry row per checklist (the merged Store checklist + the factory
// department work-lists). Cached per request.
function chkGetChecklist(int $id): ?array {
    static $cache = [];
    if ($id <= 0) return null;
    if (array_key_exists($id, $cache)) return $cache[$id];
    $st = getDb()->prepare("SELECT * FROM chk_checklists WHERE id = ?");
    $st->execute([$id]);
    return $cache[$id] = ($st->fetch(PDO::FETCH_ASSOC) ?: null);
}

function chkActiveChecklists(): array {
    try {
        return getDb()->query(
            "SELECT * FROM chk_checklists WHERE is_active = 1 ORDER BY sort_order, id"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

// Sections of a checklist, ordered, keyed by id.
function chkGetSections(int $checklistId): array {
    $st = getDb()->prepare(
        "SELECT * FROM chk_sections WHERE checklist_id = ? ORDER BY sort_order, id");
    $st->execute([$checklistId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = $r;
    return $out;
}

function chkIsAssignee(int $checklistId, string $code): bool {
    if ($code === '') return false;
    $st = getDb()->prepare("SELECT 1 FROM chk_assignees WHERE checklist_id = ? AND employee_code = ? LIMIT 1");
    $st->execute([$checklistId, $code]);
    return (bool)$st->fetchColumn();
}

function chkIsValidator(int $checklistId, string $code): bool {
    if ($code === '') return false;
    $st = getDb()->prepare("SELECT 1 FROM chk_validators WHERE checklist_id = ? AND employee_code = ? LIMIT 1");
    $st->execute([$checklistId, $code]);
    return (bool)$st->fetchColumn();
}

// Admin over all checklists (manage every checklist + view-all reports).
// Management is split by assign_type: txn_manage_tasks governs location-assigned
// (Store) checklists, txn_manage_dept_tasks the employee-assigned (department)
// ones. Superadmin governs both. Pass null to ask "may manage at least one
// kind?" — the coarse gate for the Manage Checklists page and its routes.
function chkCanManageChecklist(?array $cl): bool {
    if (isSuperadmin()) return true;
    if ($cl === null) return hasTxn('manage_tasks') || hasTxn('manage_dept_tasks');
    return chkScopeIsLocation($cl) ? hasTxn('manage_tasks') : hasTxn('manage_dept_tasks');
}
function chkCanManage(): bool { return chkCanManageChecklist(null); }

// Guard for every manage handler: resolve the target checklist and bail unless
// the caller holds the role for that kind. Returns the checklist row.
function chkRequireManage(int $checklistId): array {
    $cl = chkGetChecklist($checklistId);
    if (!$cl || !chkCanManageChecklist($cl)) {
        flash('error', 'You cannot manage this checklist.');
        header('Location: ' . chkManageBack(0)); exit;
    }
    return $cl;
}

// The checklist an item actually belongs to. Task handlers resolve ownership
// from the item rather than trusting the POSTed checklist_id, so a manager of
// one kind can't reach another kind's task by forging it.
function chkChecklistOfItem(int $itemId): int {
    $st = getDb()->prepare("SELECT checklist_id FROM chk_items WHERE id = ?");
    $st->execute([$itemId]);
    return (int)($st->fetchColumn() ?: 0);
}

// May the current user validate this checklist? Only a designated validator
// (chk_validators) — the txn_checklist_validate role merely reveals the page,
// it does NOT grant validation rights. Superadmin is the global override.
function chkCanValidateChecklist(int $checklistId, string $code): bool {
    return isSuperadmin() || chkIsValidator($checklistId, $code);
}

// Is the user a designated filler / validator of *any* checklist? Used by the
// sidebar to surface the hub / validate pages to designated factory staff who
// don't hold the location-based checklist txns. Cached per request.
function chkUserHasAssignment(string $code): bool {
    static $c = [];
    if ($code === '') return false;
    if (isset($c[$code])) return $c[$code];
    try { $st = getDb()->prepare("SELECT 1 FROM chk_assignees WHERE employee_code = ? LIMIT 1"); $st->execute([$code]); return $c[$code] = (bool)$st->fetchColumn(); }
    catch (Exception $e) { return $c[$code] = false; }
}
function chkUserHasValidation(string $code): bool {
    static $c = [];
    if ($code === '') return false;
    if (isset($c[$code])) return $c[$code];
    try { $st = getDb()->prepare("SELECT 1 FROM chk_validators WHERE employee_code = ? LIMIT 1"); $st->execute([$code]); return $c[$code] = (bool)$st->fetchColumn(); }
    catch (Exception $e) { return $c[$code] = false; }
}

// Scope (location_id) a checklist's responses live under: 0 for the single
// shared factory copy of an employee-assigned checklist; the outlet id for
// a location-assigned one.
function chkScopeIsLocation(array $cl): bool { return ($cl['assign_type'] ?? 'location') === 'location'; }

// May the current user fill this checklist? Employee-mode → designated
// assignee (or admin); location-mode → has the checklist txn or a claimed
// location (or admin).
function chkCanFill(array $cl, string $code): bool {
    if (chkCanManageChecklist($cl)) return true;
    if (!chkScopeIsLocation($cl)) return chkIsAssignee((int)$cl['id'], $code);
    return hasTxn('checklist') || myLocationId() > 0;
}

// ── Time-window engine (per-checklist, DB-driven) ─────────
// The fillable "day" for a checklist. rollover_min shifts the boundary:
// while now's minute-of-day is below rollover_min we still report yesterday
// (Store rollover_min=120 → before 02:00 counts as the prior day, matching
// the old hardcoded behavior). Un-gated checklists still roll at midnight.
function checklistEffectiveDate(array $cl): string {
    $now = time();
    $minOfDay = (int)date('G', $now) * 60 + (int)date('i', $now);
    if ($minOfDay < (int)($cl['rollover_min'] ?? 0)) {
        return date('Y-m-d', strtotime('-1 day', $now));
    }
    return date('Y-m-d', $now);
}

// Per-section start / deadline (Unix ts) on the given checklist date, from
// the section's minutes-from-midnight window (end_min may exceed 1440 for
// cross-midnight bands, e.g. Store Evening → 02:00 next day).
function checklistSectionStartTs(array $section, string $logDate): int {
    $base = strtotime($logDate);
    return $base === false ? 0 : $base + (int)$section['start_min'] * 60;
}
function checklistSectionDeadlineTs(array $section, string $logDate): int {
    $base = strtotime($logDate);
    return $base === false ? 0 : $base + (int)$section['end_min'] * 60;
}

// "not_yet_open" | "open" | "closed" for a section on a given date.
// $section may be null (an item with no section → open all day on the
// effective date). Superadmin bypasses; an un-gated checklist is always
// open on its effective date.
function checklistSectionState(?array $section, string $logDate, array $cl): string {
    if (isSuperadmin()) return 'open';
    if (empty($cl['time_gated'])) {
        return ($logDate === checklistEffectiveDate($cl)) ? 'open' : 'closed';
    }
    if ($logDate !== checklistEffectiveDate($cl)) return 'closed';
    if ($section === null) return 'open';
    $now = time();
    if ($now < checklistSectionStartTs($section, $logDate))    return 'not_yet_open';
    if ($now > checklistSectionDeadlineTs($section, $logDate)) return 'closed';
    return 'open';
}

// True iff the current user may still write answers for the given section
// on the given checklist day.
function checklistSectionEditable(?array $section, string $logDate, array $cl): bool {
    return checklistSectionState($section, $logDate, $cl) === 'open';
}

// ── Attachment storage (per response) ─────────────────────
define('CHECKLIST_UPLOAD_DIR', __DIR__ . '/../uploads/checklist/');
define('DEPT_CHECKLIST_UPLOAD_DIR', __DIR__ . '/../uploads/department_checklist/');
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

// Month bucket ("YYYY-MM") a log date's uploads roll up under.
function checklistAttachmentMonth(string $logDate): string {
    return preg_match('/^(\d{4}-\d{2})/', $logDate, $m) ? $m[1] : date('Y-m');
}

// Build the storage directory for a (location, date) pair.
// Layout: uploads/checklist/{YYYY-MM}/{location_id}/ — month bucket first
// (rolls a single month's uploads under one parent for easy archival /
// trimming), location next. The log date itself isn't in the path because
// every attachment already carries its date via chk_daily_responses.
//
// Employee-assigned (department) checklists have no outlet — they scope to
// location_id = 0, which would otherwise pile all six departments into a
// bogus "0" location folder. They get their own root instead, bucketed by
// month only. location_id = 0 means department here by construction:
// doSaveChecklist() forces a real outlet id on every location-mode save.
function checklistAttachmentDir(int $locationId, string $logDate): string {
    $monthBucket = checklistAttachmentMonth($logDate);
    if ($locationId === 0) return DEPT_CHECKLIST_UPLOAD_DIR . $monthBucket . '/';
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
    // Department files written before department_checklist/ existed landed in
    // the location tree under a "0" folder. Read-only fallback; never written.
    if ($locationId === 0) {
        $pre = CHECKLIST_UPLOAD_DIR . checklistAttachmentMonth($logDate) . '/0/' . $storedName;
        if (file_exists($pre)) return $pre;
    }
    return null;
}

// Resolve the chk_daily_responses.id for this uploader's own answer on a
// (checklist, location, item, date) tuple — attachments hang off the row the
// current user just upserted. Returns null if no such row exists yet.
function checklistResponseId(int $checklistId, int $locationId, int $itemId, string $logDate, string $empCode): ?int {
    $st = getDb()->prepare(
        'SELECT id FROM chk_daily_responses
         WHERE checklist_id = ? AND location_id = ? AND item_id = ? AND log_date = ? AND employee_code = ?
         ORDER BY id DESC LIMIT 1');
    $st->execute([$checklistId, $locationId, $itemId, $logDate, $empCode]);
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

// Fetch all attachments for a given (checklist, location, date) grouped by item_id.
// Returns: [item_id => [['id','filename','mime_type','file_size','uploaded_by','uploader_name','uploaded_at','is_image'], ...]]
function checklistAttachmentsByItem(int $checklistId, int $locationId, string $logDate): array {
    $st = getDb()->prepare(
        'SELECT a.id, a.response_id, a.filename, a.mime_type, a.file_size,
                a.uploaded_by, a.uploaded_at, r.item_id,
                e.full_name AS uploader_name
         FROM chk_response_attachments a
         JOIN chk_daily_responses r ON r.id = a.response_id
         LEFT JOIN employees e ON e.employee_code = a.uploaded_by
         WHERE r.checklist_id = ? AND r.location_id = ? AND r.log_date = ?
         ORDER BY a.uploaded_at ASC, a.id ASC');
    try {
        $st->execute([$checklistId, $locationId, $logDate]);
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
    $checklistId = (int)($_POST['checklist_id'] ?? 0);
    $cl          = chkGetChecklist($checklistId);
    $empCode     = myCode();
    if (!$cl) {
        flash('error', 'Unknown checklist.');
        header("Location: index.php?page=checklist"); exit;
    }
    $isLocMode = chkScopeIsLocation($cl);
    $logDate   = $_POST['log_date'] ?? checklistEffectiveDate($cl);
    $answers   = $_POST['ans'] ?? [];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $logDate)) $logDate = checklistEffectiveDate($cl);

    // Resolve scope (location_id). Employee-mode checklists share one factory
    // copy under location_id = 0; location-mode keeps the per-outlet scope.
    if ($isLocMode) {
        // Everyone fills against their own employees.location_id — a POSTed
        // location_id is never trusted, so no one can write another outlet.
        $myLocId = myLocationId();
        if ($myLocId <= 0) {
            flash('error', 'You can only fill checklist for your claimed location.');
            header("Location: index.php?page=checklist&id={$checklistId}"); exit;
        }
        $locationId = $myLocId;
    } else {
        $locationId = 0;
        if (!chkCanManageChecklist($cl) && !chkIsAssignee($checklistId, $empCode)) {
            flash('error', 'You are not assigned to this checklist.');
            header("Location: index.php?page=checklist"); exit;
        }
    }

    $back = "index.php?page=checklist&id={$checklistId}"
          . ($isLocMode ? "&location_id={$locationId}" : '')
          . "&date={$logDate}";

    // Only the effective checklist date is writable for non-superadmin.
    if (!isSuperadmin() && $logDate !== checklistEffectiveDate($cl)) {
        flash('error', 'You can only fill the current checklist day (' . checklistEffectiveDate($cl) . ').');
        header("Location: {$back}"); exit;
    }

    // Attach-only submits (no fresh answers, only files) are legitimate
    // once the response row already exists — let those through.
    $hasUploads = !empty($_FILES['attachments']['name']) && is_array($_FILES['attachments']['name']);
    if (($isLocMode && $locationId <= 0) || (empty($answers) && !$hasUploads)) {
        flash('error', 'No data submitted.');
        header("Location: {$back}"); exit;
    }

    $db = getDb();
    $allSections = chkGetSections($checklistId);

    // Look up each answered item's section so we can enforce per-section
    // deadlines server-side, and confirm the item belongs to this checklist.
    $itemIds = array_values(array_filter(array_map('intval', array_keys($answers)), fn($i) => $i > 0));
    $secByItem = [];   // itemId => section row (or null)
    $ownItems  = [];   // itemIds that belong to this checklist
    if ($itemIds) {
        $ph = implode(',', array_fill(0, count($itemIds), '?'));
        $sst = $db->prepare("SELECT id, section_id FROM chk_items WHERE checklist_id = ? AND id IN ($ph)");
        $sst->execute(array_merge([$checklistId], $itemIds));
        foreach ($sst->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $iid = (int)$r['id'];
            $ownItems[$iid] = true;
            $secByItem[$iid] = $r['section_id'] ? ($allSections[(int)$r['section_id']] ?? null) : null;
        }
    }

    $st = $db->prepare(
        "INSERT INTO chk_daily_responses (checklist_id, location_id, item_id, employee_code, log_date, response_value, submitted_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
           response_value = VALUES(response_value),
           checklist_id   = VALUES(checklist_id),
           employee_code  = VALUES(employee_code),
           submitted_at   = NOW()"
    );

    $saved = 0; $skippedClosed = 0;
    try {
        $db->beginTransaction();
        foreach ($answers as $itemId => $val) {
            $itemId = (int)$itemId;
            if ($val === '' || empty($ownItems[$itemId])) continue;
            if (!checklistSectionEditable($secByItem[$itemId] ?? null, $logDate, $cl)) {
                $skippedClosed++;
                continue;
            }
            $st->execute([$checklistId, $locationId, $itemId, $empCode, $logDate, $val]);
            $saved++;
        }
        $db->commit();
        if ($skippedClosed > 0) {
            flash('success', "Saved {$saved} task(s). {$skippedClosed} task(s) skipped — section is outside its allowed time window.");
        } elseif ($saved > 0) {
            flash('success', 'Checklist submitted successfully.');
        } elseif (!empty($answers)) {
            // Had answers in the POST but none applied → outside window.
            flash('error', 'Nothing saved — all submitted tasks are outside their allowed time window.');
        }
        // Attach-only submits (empty $answers) skip the flash here; the
        // file-attachment block below sets its own success/error message.
    } catch (Exception $e) {
        $db->rollBack();
        flash('error', 'Error saving checklist.');
    }

    // ── File attachments ─────────────────────────────────
    // Run *after* the answer commit so checklistResponseId() can find the
    // upserted row. Each item's files come through as attachments[ITEM_ID][]
    // — process only items belonging to this checklist with an existing
    // response row in an editable section.
    if (!empty($_FILES['attachments']['name']) && is_array($_FILES['attachments']['name'])) {
        $attachItemIds = [];
        foreach ($_FILES['attachments']['name'] as $iid => $names) {
            if (is_array($names) && count($names) > 0) $attachItemIds[] = (int)$iid;
        }
        $attachItemIds = array_values(array_filter($attachItemIds, fn($i) => $i > 0));
        $missingSec = array_values(array_filter($attachItemIds, fn($i) => !isset($ownItems[$i])));
        if ($missingSec) {
            $ph = implode(',', array_fill(0, count($missingSec), '?'));
            $sst = $db->prepare("SELECT id, section_id FROM chk_items WHERE checklist_id = ? AND id IN ($ph)");
            $sst->execute(array_merge([$checklistId], $missingSec));
            foreach ($sst->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $iid = (int)$r['id'];
                $ownItems[$iid] = true;
                $secByItem[$iid] = $r['section_id'] ? ($allSections[(int)$r['section_id']] ?? null) : null;
            }
        }
        $attSaved = 0;
        foreach ($attachItemIds as $itemId) {
            if (empty($ownItems[$itemId])) continue;
            if (!checklistSectionEditable($secByItem[$itemId] ?? null, $logDate, $cl)) continue;
            $respId = checklistResponseId($checklistId, $locationId, $itemId, $logDate, $empCode);
            if ($respId === null) continue; // no answer yet → ignore file
            $attSaved += checklistSaveAttachments($respId, $locationId, $logDate, $itemId, $empCode);
        }
        if ($attSaved > 0) {
            $prev = $_SESSION['flash'] ?? null;
            $prevMsg = ($prev && ($prev['type'] ?? '') === 'success') ? rtrim((string)$prev['msg']) . ' ' : '';
            flash('success', $prevMsg . "{$attSaved} file(s) attached.");
        }
    }

    // Mirror this user's completed-task time into page=my_time.
    chkSyncTimeEntry($checklistId, $empCode, $logDate);

    header("Location: {$back}"); exit;
}

// ── Map completed checklist time into the timesheet ───────
// Sums est_minutes of the items this employee has completed for one
// (checklist, date) and keeps a single auto-logged time_entries row in sync
// (delete-then-insert, keyed by employee+checklist+date). Fails open so a
// missing time_entries.checklist_id column never blocks a checklist save.
function chkSyncTimeEntry(int $checklistId, string $empCode, string $logDate): void {
    if ($empCode === '') return;
    $db = getDb();
    try {
        $sumSt = $db->prepare(
            "SELECT COALESCE(SUM(i.est_minutes),0)
             FROM chk_daily_responses r
             JOIN chk_items i ON i.id = r.item_id
             WHERE r.checklist_id = ? AND r.employee_code = ? AND r.log_date = ?
               AND r.response_value IS NOT NULL AND r.response_value <> '' AND i.est_minutes > 0");
        $sumSt->execute([$checklistId, $empCode, $logDate]);
        $mins = (int)$sumSt->fetchColumn();

        $db->prepare("DELETE FROM time_entries WHERE employee_code = ? AND checklist_id = ? AND entry_date = ?")
           ->execute([$empCode, $checklistId, $logDate]);
        if ($mins > 0) {
            $db->prepare(
                "INSERT INTO time_entries (employee_code, checklist_id, entry_date, minutes, notes)
                 VALUES (?, ?, ?, ?, 'Checklist auto-logged')")
               ->execute([$empCode, $checklistId, $logDate, $mins]);
        }
    } catch (Exception $e) {
        // time_entries.checklist_id not present yet — ignore.
    }
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
                r.checklist_id, r.location_id, r.log_date, r.item_id
         FROM chk_response_attachments a
         JOIN chk_daily_responses r ON r.id = a.response_id
         WHERE a.id = ?');
    try { $st->execute([$attId]); } catch (Exception $e) { return null; }
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    if (isSuperadmin() || hasTxn('checklist_report')) return $row;
    $cid = (int)$row['checklist_id'];
    $cl  = chkGetChecklist($cid);
    // A manager sees attachments only for the kind of checklist they govern.
    if ($cl && chkCanManageChecklist($cl)) return $row;
    // Location-assigned checklist → own outlet; employee-assigned → assignee
    // or validator of that checklist.
    if ($cl && chkScopeIsLocation($cl)) {
        $myLoc = myLocationId();
        if ($myLoc > 0 && (int)$row['location_id'] === $myLoc) return $row;
    }
    $me = myCode();
    if ($cid > 0 && ($me === (string)$row['uploaded_by'] || chkIsAssignee($cid, $me) || chkIsValidator($cid, $me))) {
        return $row;
    }
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
    $attId = (int)($_POST['att_id'] ?? 0);
    $row   = checklistAttachmentForView($attId);

    $checklistId = (int)($row['checklist_id'] ?? ($_POST['checklist_id'] ?? 0));
    $cl          = chkGetChecklist($checklistId);
    $locationId  = (int)($row['location_id'] ?? ($_POST['location_id'] ?? 0));
    $logDate     = (string)($row['log_date'] ?? ($_POST['log_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $logDate)) $logDate = $cl ? checklistEffectiveDate($cl) : date('Y-m-d');
    $back = "Location: index.php?page=checklist&id={$checklistId}"
          . (($cl && chkScopeIsLocation($cl)) ? "&location_id={$locationId}" : '')
          . "&date={$logDate}";

    if (!$row) { flash('error', 'Attachment not found or access denied.'); header($back); exit; }

    // Only the original uploader (or superadmin) can delete, and only
    // while their section is still editable for the original day.
    $isOwner = ((string)$row['uploaded_by'] === myCode());
    if (!isSuperadmin() && !$isOwner) {
        flash('error', 'You can only delete files you uploaded.');
        header($back); exit;
    }
    if (!isSuperadmin() && $cl) {
        $sst = getDb()->prepare('SELECT section_id FROM chk_items WHERE id = ?');
        $sst->execute([(int)$row['item_id']]);
        $secId = (int)($sst->fetchColumn() ?: 0);
        $secRow = $secId ? (chkGetSections($checklistId)[$secId] ?? null) : null;
        if (!checklistSectionEditable($secRow, (string)$row['log_date'], $cl)) {
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

// Redirect target for Manage Tasks, keeping the selected checklist.
function chkManageBack(int $checklistId): string {
    return 'index.php?page=manage_tasks' . ($checklistId > 0 ? '&id=' . $checklistId : '');
}
// Parse "HH:MM" → minutes from midnight, or null.
function chkHhmmToMin(string $s): ?int {
    if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($s), $m)) {
        $h = (int)$m[1]; $mm = (int)$m[2];
        if ($h <= 24 && $mm < 60) return $h * 60 + $mm;
    }
    return null;
}

// ── Manage: save the checklist registry row ───────────────
function doSaveChecklistMeta(): void {
    $id       = (int)($_POST['checklist_id'] ?? 0);
    $name     = trim($_POST['name'] ?? '');
    $assign   = ($_POST['assign_type'] ?? 'location') === 'employee' ? 'employee' : 'location';
    $gated    = isset($_POST['time_gated']) ? 1 : 0;
    $rollover = max(0, (int)($_POST['rollover_min'] ?? 0));
    $sort     = (int)($_POST['sort_order'] ?? 0);
    if ($name === '') {
        flash('error', 'Checklist name required.');
        header('Location: ' . chkManageBack($id)); exit;
    }
    // Creating a checklist, and changing assign_type on an existing one, are
    // superadmin-only: assign_type decides which manage role owns the
    // checklist, so letting a manager set it would let them grant themselves
    // control of the other kind.
    if ($id <= 0 && !isSuperadmin()) {
        flash('error', 'Only a superadmin can create a checklist.');
        header('Location: ' . chkManageBack(0)); exit;
    }
    $db = getDb();
    if ($id > 0) {
        $cl = chkRequireManage($id);
        if (!isSuperadmin()) $assign = (string)$cl['assign_type'];
        $db->prepare("UPDATE chk_checklists SET name=?, assign_type=?, time_gated=?, rollover_min=?, sort_order=? WHERE id=?")
           ->execute([$name, $assign, $gated, $rollover, $sort, $id]);
    } else {
        $db->prepare("INSERT INTO chk_checklists (name, assign_type, time_gated, rollover_min, sort_order, is_active) VALUES (?,?,?,?,?,1)")
           ->execute([$name, $assign, $gated, $rollover, $sort]);
        $id = (int)$db->lastInsertId();
    }
    flash('success', 'Checklist saved.');
    header('Location: ' . chkManageBack($id)); exit;
}

// ── Manage: toggle a checklist active ─────────────────────
function doToggleChecklist(): void {
    $id = (int)($_POST['checklist_id'] ?? 0);
    chkRequireManage($id);
    getDb()->prepare("UPDATE chk_checklists SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
    flash('success', 'Checklist status toggled.');
    header('Location: ' . chkManageBack($id)); exit;
}

// ── Manage: save a section (time band) ────────────────────
function doSaveSection(): void {
    $checklistId = (int)($_POST['checklist_id'] ?? 0);
    $sectionId   = (int)($_POST['section_id'] ?? 0);
    $name        = trim($_POST['name'] ?? '');
    $startMin    = chkHhmmToMin($_POST['start_time'] ?? '') ?? 0;
    $endMin      = chkHhmmToMin($_POST['end_time'] ?? '') ?? 1440;
    if (!empty($_POST['end_next_day'])) $endMin += 1440;
    $sort        = (int)($_POST['sort_order'] ?? 0);
    $back        = chkManageBack($checklistId);
    if ($checklistId <= 0 || $name === '') {
        flash('error', 'Section name required.');
        header("Location: {$back}"); exit;
    }
    chkRequireManage($checklistId);
    $db = getDb();
    if ($sectionId > 0) {
        $db->prepare("UPDATE chk_sections SET name=?, start_min=?, end_min=?, sort_order=? WHERE id=? AND checklist_id=?")
           ->execute([$name, $startMin, $endMin, $sort, $sectionId, $checklistId]);
        // Keep the denormalized copy on chk_items in sync so reports that group
        // by section_name (audit, overview filters, exports) stay aligned with
        // the section's new name instead of drifting to the old label.
        $db->prepare("UPDATE chk_items SET section_name=? WHERE section_id=? AND checklist_id=?")
           ->execute([$name, $sectionId, $checklistId]);
    } else {
        $db->prepare("INSERT INTO chk_sections (checklist_id, name, start_min, end_min, sort_order) VALUES (?,?,?,?,?)")
           ->execute([$checklistId, $name, $startMin, $endMin, $sort]);
    }
    flash('success', 'Section saved.');
    header("Location: {$back}"); exit;
}

// ── Manage: delete a section (orphans its items to "no section") ─
function doDelSection(): void {
    $checklistId = (int)($_POST['checklist_id'] ?? 0);
    $sectionId   = (int)($_POST['section_id'] ?? 0);
    chkRequireManage($checklistId);
    $db = getDb();
    $db->prepare("UPDATE chk_items SET section_id = NULL WHERE section_id = ? AND checklist_id = ?")
       ->execute([$sectionId, $checklistId]);
    $db->prepare("DELETE FROM chk_sections WHERE id = ? AND checklist_id = ?")->execute([$sectionId, $checklistId]);
    flash('success', 'Section deleted.');
    header('Location: ' . chkManageBack($checklistId)); exit;
}

// ── Manage: add / remove an assignee or validator ─────────
function doSaveChkPerson(string $table, string $label): void {
    $checklistId = (int)($_POST['checklist_id'] ?? 0);
    $code        = trim($_POST['employee_code'] ?? '');
    $back        = chkManageBack($checklistId);
    if ($checklistId <= 0 || $code === '') {
        flash('error', "Pick an employee to add as {$label}.");
        header("Location: {$back}"); exit;
    }
    chkRequireManage($checklistId);
    try {
        getDb()->prepare("INSERT IGNORE INTO {$table} (checklist_id, employee_code) VALUES (?,?)")
               ->execute([$checklistId, $code]);
        flash('success', ucfirst($label) . ' added.');
    } catch (Exception $e) {
        flash('error', "Could not add {$label}.");
    }
    header("Location: {$back}"); exit;
}
function doDelChkPerson(string $table, string $label): void {
    $checklistId = (int)($_POST['checklist_id'] ?? 0);
    $rowId       = (int)($_POST['row_id'] ?? 0);
    chkRequireManage($checklistId);
    getDb()->prepare("DELETE FROM {$table} WHERE id = ? AND checklist_id = ?")->execute([$rowId, $checklistId]);
    flash('success', ucfirst($label) . ' removed.');
    header('Location: ' . chkManageBack($checklistId)); exit;
}
function doSaveAssignee():  void { doSaveChkPerson('chk_assignees',  'assignee');  }
function doDelAssignee():   void { doDelChkPerson('chk_assignees',   'assignee');  }
function doSaveValidator(): void { doSaveChkPerson('chk_validators', 'validator'); }
function doDelValidator():  void { doDelChkPerson('chk_validators',  'validator'); }

// ── Manage tasks: save ────────────────────────────────────
function doSaveTask(): void {
    $id          = (int)($_POST['task_id'] ?? 0);
    $checklistId = (int)($_POST['checklist_id'] ?? 0);
    $sectionId   = (int)($_POST['section_id'] ?? 0);
    $desc        = trim($_POST['description'] ?? '');
    $type        = $_POST['input_type'] ?? 'yes_no';
    $est         = max(0, (int)($_POST['est_minutes'] ?? 0));
    if (!in_array($type, ['yes_no', 'time', 'text', 'number'], true)) $type = 'yes_no';
    $back = chkManageBack($checklistId);

    if (!$desc || $checklistId <= 0) {
        flash('error', 'Description and checklist required.');
        header("Location: {$back}"); exit;
    }
    chkRequireManage($checklistId);
    // Editing an existing task: its CURRENT checklist must be manageable too,
    // otherwise a manager could move another kind's task into their own.
    if ($id > 0) chkRequireManage(chkChecklistOfItem($id));

    $db = getDb();
    // Resolve the section name (kept on chk_items for legacy display) and
    // confirm the section belongs to this checklist.
    $secName = null;
    if ($sectionId > 0) {
        $sx = $db->prepare("SELECT name FROM chk_sections WHERE id = ? AND checklist_id = ?");
        $sx->execute([$sectionId, $checklistId]);
        $secName = $sx->fetchColumn();
        if ($secName === false) { $sectionId = 0; $secName = null; }
    }
    if ($id > 0) {
        $st = $db->prepare("UPDATE chk_items SET checklist_id=?, section_id=?, section_name=?, task_description=?, input_type=?, est_minutes=? WHERE id=?");
        $st->execute([$checklistId, ($sectionId ?: null), $secName, $desc, $type, $est, $id]);
    } else {
        $st = $db->prepare("INSERT INTO chk_items (checklist_id, section_id, section_name, task_description, input_type, est_minutes, is_active) VALUES (?,?,?,?,?,?,1)");
        $st->execute([$checklistId, ($sectionId ?: null), $secName, $desc, $type, $est]);
    }
    flash('success', $id ? 'Task updated.' : 'Task added.');
    header("Location: {$back}"); exit;
}

// ── Manage tasks: toggle active ───────────────────────────
function doToggleTask(): void {
    $id = (int)($_POST['task_id'] ?? 0);
    $checklistId = (int)($_POST['checklist_id'] ?? 0);
    chkRequireManage(chkChecklistOfItem($id));
    getDb()->prepare("UPDATE chk_items SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
    flash('success', 'Task status toggled.');
    header('Location: ' . chkManageBack($checklistId)); exit;
}

// ── Manage tasks: delete ──────────────────────────────────
function doDelTask(): void {
    $id = (int)($_POST['task_id'] ?? 0);
    $checklistId = (int)($_POST['checklist_id'] ?? 0);
    chkRequireManage(chkChecklistOfItem($id));
    $db = getDb();
    $chk = $db->prepare("SELECT COUNT(*) FROM chk_daily_responses WHERE item_id = ?");
    $chk->execute([$id]);
    if ($chk->fetchColumn() > 0) {
        flash('error', 'Cannot delete: task has historical data. Deactivate instead.');
    } else {
        $db->prepare("DELETE FROM chk_items WHERE id = ?")->execute([$id]);
        flash('success', 'Task deleted.');
    }
    header('Location: ' . chkManageBack($checklistId)); exit;
}

// ── Checklist landing: hub (no id) or fill view (with id) ─
function pageChecklist(): void {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) { pageChecklistFill($id); return; }
    pageChecklistHub();
}

// Count of active items in a checklist (its "out of N").
function chkItemTotal(int $checklistId): int {
    $st = getDb()->prepare("SELECT COUNT(*) FROM chk_items WHERE checklist_id = ? AND is_active = 1");
    $st->execute([$checklistId]);
    return (int)$st->fetchColumn();
}
// Distinct answered items for one (checklist, scope, date).
function chkDoneCount(int $checklistId, int $locationId, string $logDate): int {
    $st = getDb()->prepare(
        "SELECT COUNT(DISTINCT item_id) FROM chk_daily_responses
         WHERE checklist_id = ? AND location_id = ? AND log_date = ?
           AND response_value IS NOT NULL AND response_value <> ''");
    $st->execute([$checklistId, $locationId, $logDate]);
    return (int)$st->fetchColumn();
}

// ── Hub: list every checklist assigned to the user ────────
function pageChecklistHub(): void {
    $me   = myCode();
    $cards = [];
    foreach (chkActiveChecklists() as $cl) {
        if (!chkCanFill($cl, $me)) continue;
        $cid    = (int)$cl['id'];
        $isLoc  = chkScopeIsLocation($cl);
        // Progress is scoped to the user's own location; null = none claimed,
        // which the card reports instead of a misleading 0/N.
        $scopeLoc   = $isLoc ? myLocationId() : 0;
        $eff        = checklistEffectiveDate($cl);
        $total      = chkItemTotal($cid);
        $done       = ($isLoc && $scopeLoc <= 0) ? null : chkDoneCount($cid, $scopeLoc, $eff);
        $link = "?page=checklist&id={$cid}";
        $cards[] = ['cl' => $cl, 'isLoc' => $isLoc, 'total' => $total, 'done' => $done, 'link' => $link];
    }
?>
<div class="page-header"><h2>✅ Checklists</h2></div>
<?php if (empty($cards)): ?>
<div class="alert alert-error">No checklists are assigned to you yet.
    <?php if (!isSuperadmin() && myLocationId() <= 0): ?>
    If you fill a store checklist, claim your location under <a href="?page=my_location" style="color:var(--accent)">My Location</a> first.
    <?php endif; ?>
</div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px">
    <?php foreach ($cards as $c): $cl = $c['cl']; $done = $c['done']; $total = $c['total'];
        $pct = ($total > 0 && $done !== null) ? (int)round($done * 100 / $total) : 0;
        $bg  = ($done === null) ? 'var(--border)' : ($done >= $total && $total > 0 ? 'var(--green)' : ($done > 0 ? 'var(--yellow)' : 'var(--red)'));
    ?>
    <a href="<?= h($c['link']) ?>" class="table-wrap" style="display:block;padding:16px;text-decoration:none;color:var(--text)">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px">
            <strong style="font-size:15px"><?= h($cl['name']) ?></strong>
            <span class="badge <?= $c['isLoc'] ? 'badge-blue' : 'badge-grey' ?>" style="font-weight:600"><?= $c['isLoc'] ? 'By location' : 'Department' ?></span>
        </div>
        <div style="height:8px;border-radius:999px;background:var(--bg);overflow:hidden;margin-bottom:6px">
            <span style="display:block;height:100%;width:<?= $pct ?>%;background:<?= $bg ?>"></span>
        </div>
        <div class="text-muted" style="font-size:12px">
            <?php if ($done === null): ?>
                No location claimed
            <?php else: ?>
                Today: <?= $done ?>/<?= $total ?> done
            <?php endif; ?>
        </div>
    </a>
    <?php endforeach; ?>
</div>
<?php endif;
}

// ── Fill view for one checklist ───────────────────────────
function pageChecklistFill(int $checklistId): void {
    $cl = chkGetChecklist($checklistId);
    $me = myCode();
    if (!$cl || (int)($cl['is_active'] ?? 0) !== 1) {
        flash('error', 'Checklist not found.');
        header('Location: index.php?page=checklist'); exit;
    }
    if (!chkCanFill($cl, $me)) {
        flash('error', 'You are not assigned to this checklist.');
        header('Location: index.php?page=checklist'); exit;
    }
    $isLoc         = chkScopeIsLocation($cl);
    $effectiveDate = checklistEffectiveDate($cl);
    $displayDate   = $_GET['date'] ?? $effectiveDate;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $displayDate)) $displayDate = $effectiveDate;
    $isPast        = ($displayDate < $effectiveDate);
    $isFutureDate  = ($displayDate > $effectiveDate);

    // Resolve scope (location_id) for location-mode. Everyone — admins
    // included — fills against their own employees.location_id; there is no
    // picker. location_id in the query string is ignored.
    $myLocId       = myLocationId();
    $noLocation    = false;
    $locationName  = '';
    if (!$isLoc) {
        $locationId = 0;
    } elseif ($myLocId > 0) {
        $locationId = $myLocId;
        $loc = getLocation($myLocId);
        $locationName = (string)($loc['location_name'] ?? '');
    } else {
        $locationId = 0;
        $noLocation = true;
    }
    $haveScope = !$isLoc || $locationId > 0;

    // Per-checklist day rollover wording (e.g. Store → 02:00).
    $rolloverLabel = '';
    if ((int)($cl['rollover_min'] ?? 0) > 0) {
        $rolloverLabel = date('h:i A', strtotime('00:00') + (int)$cl['rollover_min'] * 60);
    }

    $monthStart = date('Y-m-01', strtotime($displayDate));
    $monthEnd   = date('Y-m-t', strtotime($displayDate));
    $prevMonth  = date('Y-m-d', strtotime('-1 month', strtotime($monthStart)));
    $nextMonth  = date('Y-m-d', strtotime('+1 month', strtotime($monthStart)));
    $locQS      = $isLoc && $locationId > 0 ? "&location_id={$locationId}" : '';

    $db = getDb();
    $sections = chkGetSections($checklistId);
    $tasks = [];
    $existingCounts = [];
    $totalQ = max(1, chkItemTotal($checklistId));

    if ($haveScope) {
        // Monthly tile counts (distinct answered items per day).
        $st = $db->prepare(
            "SELECT log_date, COUNT(DISTINCT item_id) AS done FROM chk_daily_responses
             WHERE checklist_id = ? AND location_id = ? AND log_date BETWEEN ? AND ?
               AND response_value IS NOT NULL AND response_value <> ''
             GROUP BY log_date"
        );
        $st->execute([$checklistId, $locationId, $monthStart, $monthEnd]);
        $existingCounts = $st->fetchAll(PDO::FETCH_KEY_PAIR);

        // Tasks + this scope's latest answer per item (one row per item).
        $st = $db->prepare(
            "SELECT q.id, q.task_description, q.input_type, q.section_id, q.section_name, q.est_minutes,
                    a.response_value, e.full_name AS submitted_by
             FROM chk_items q
             LEFT JOIN chk_sections sec ON sec.id = q.section_id
             LEFT JOIN (
                 SELECT item_id, MAX(id) AS rid FROM chk_daily_responses
                 WHERE checklist_id = ? AND location_id = ? AND log_date = ?
                 GROUP BY item_id
             ) latest ON latest.item_id = q.id
             LEFT JOIN chk_daily_responses a ON a.id = latest.rid
             LEFT JOIN employees e ON a.employee_code = e.employee_code
             WHERE q.checklist_id = ? AND q.is_active = 1
             ORDER BY COALESCE(sec.sort_order, 9999), q.id ASC"
        );
        $st->execute([$checklistId, $locationId, $displayDate, $checklistId]);
        $tasks = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $itemAttachments = $haveScope
        ? checklistAttachmentsByItem($checklistId, $locationId, $displayDate)
        : [];
?>
<div class="page-header" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <a href="?page=checklist" class="btn btn-ghost btn-sm">&lsaquo; All checklists</a>
    <h2 style="margin:0">✅ <?= h($cl['name']) ?></h2>
</div>

<!-- Scope -->
<div class="filter-bar" style="margin-bottom:14px">
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <?php if (!$isLoc): ?>
        <span class="badge badge-grey" style="font-weight:600">Department checklist</span>
    <?php elseif ($myLocId > 0): ?>
        <strong style="font-size:15px"><?= h($locationName) ?></strong>
    <?php endif; ?>
        <span class="text-muted">Viewing: <strong><?= date('d M Y', strtotime($displayDate)) ?></strong></span>
    </div>
</div>

<?php if ($noLocation): ?>
<div class="alert alert-error">You have not claimed a location yet. Please go to <a href="?page=my_location" style="color:var(--accent)">My Location</a> to claim your location first.</div>
<?php elseif ($haveScope): ?>
<!-- Month Calendar Tiles -->
<div class="table-wrap" style="padding:16px;margin-bottom:14px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <a href="?page=checklist&id=<?= $checklistId ?><?= $locQS ?>&date=<?= $prevMonth ?>" class="btn btn-ghost btn-sm">&lsaquo; Prev</a>
        <strong><?= date('F Y', strtotime($displayDate)) ?></strong>
        <a href="?page=checklist&id=<?= $checklistId ?><?= $locQS ?>&date=<?= $nextMonth ?>" class="btn btn-ghost btn-sm">Next &rsaquo;</a>
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
        <a href="?page=checklist&id=<?= $checklistId ?><?= $locQS ?>&date=<?= $tileDate ?>"
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
// Keyed by section_id (0 = item with no section). Driven by the checklist's
// editable chk_sections windows via the time-window engine.
// $sectionStatus[<section_id>] = ['state','open','name','startLabel','deadlineLabel']
$sectionStatus = [];
foreach ($tasks as $t) {
    $sid = (int)($t['section_id'] ?? 0);
    if (!isset($sectionStatus[$sid])) {
        $secRow = $sid ? ($sections[$sid] ?? null) : null;
        $state  = checklistSectionState($secRow, $displayDate, $cl);
        $sectionStatus[$sid] = [
            'state'         => $state,
            'open'          => $state === 'open',
            'name'          => $secRow['name'] ?? ($t['section_name'] ?: 'General'),
            'startLabel'    => $secRow ? date('h:i A', checklistSectionStartTs($secRow, $displayDate))    : '',
            'deadlineLabel' => $secRow ? date('h:i A', checklistSectionDeadlineTs($secRow, $displayDate)) : '',
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
<div class="alert" style="margin-bottom:10px;background:rgba(107,114,128,.12);color:#9ca3af;border:1px solid rgba(107,114,128,.3)">All sections for today are closed. The day will roll over<?= $rolloverLabel ? ' at ' . h($rolloverLabel) : '' ?>.</div>
<?php endif; ?>
<form method="POST" enctype="multipart/form-data" id="chkForm"<?= $readOnly ? ' onsubmit="return false"' : '' ?>>
    <input type="hidden" name="action" value="save_checklist">
    <input type="hidden" name="checklist_id" value="<?= $checklistId ?>">
    <?php if ($isLoc): ?><input type="hidden" name="location_id" value="<?= $locationId ?>"><?php endif; ?>
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
                $sid = (int)($t['section_id'] ?? 0);
                $secInfo = $sectionStatus[$sid] ?? ['state' => 'closed', 'open' => false, 'name' => 'General', 'startLabel' => '', 'deadlineLabel' => ''];
                $cellEditable = !$readOnly && $secInfo['open'];
                if ($sid !== $currentSection):
                    $currentSection = $sid;
            ?>
                <tr><td colspan="3" class="chk-section" style="background:var(--border);font-weight:700;font-size:12px;padding:8px 13px">
                    <?= h($secInfo['name'] ?: 'General') ?>
                    <?php if ($isPast): ?>
                        <span class="badge badge-grey" style="margin-left:8px;font-weight:600">Read-only (past)</span>
                    <?php elseif ($secInfo['state'] === 'not_yet_open'): ?>
                        <span class="badge badge-yellow" style="margin-left:8px;font-weight:600">Opens at <?= h($secInfo['startLabel']) ?></span>
                    <?php elseif ($secInfo['open']): ?>
                        <span class="badge badge-green" style="margin-left:8px;font-weight:600"><?= ($secInfo['startLabel'] && $secInfo['deadlineLabel']) ? 'Open ' . h($secInfo['startLabel']) . ' – ' . h($secInfo['deadlineLabel']) : 'Open' ?></span>
                    <?php else: ?>
                        <span class="badge badge-red" style="margin-left:8px;font-weight:600"><?= $secInfo['deadlineLabel'] ? 'Closed at ' . h($secInfo['deadlineLabel']) : 'Closed' ?></span>
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
            $sectionOpen = ($sectionStatus[(int)($tk['section_id'] ?? 0)]['open'] ?? false);
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
    <input type="hidden" name="checklist_id" value="<?= $checklistId ?>">
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

// ── Manage Tasks page (per-checklist admin) ───────────────
function pageManageTasks(): void {
    $db = getDb();
    $checklists = $db->query("SELECT * FROM chk_checklists ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    // Only the kinds this manager governs (superadmin sees both).
    $checklists = array_values(array_filter($checklists, 'chkCanManageChecklist'));

    // Selected checklist (?id), defaulting to the first one.
    $selId = (int)($_GET['id'] ?? 0);
    $cl = null;
    foreach ($checklists as $c) { if ((int)$c['id'] === $selId) { $cl = $c; break; } }
    if (!$cl && $checklists) { $cl = $checklists[0]; $selId = (int)$cl['id']; }
    if (!$cl) $selId = 0;

    $sections = $selId ? chkGetSections($selId) : [];
    $tasks = [];
    if ($selId) {
        $st = $db->prepare(
            "SELECT q.id, q.task_description, q.section_id, q.section_name, q.input_type, q.est_minutes, q.is_active,
                    COALESCE(sec.sort_order, 9999) AS sec_sort
             FROM chk_items q LEFT JOIN chk_sections sec ON sec.id = q.section_id
             WHERE q.checklist_id = ? ORDER BY sec_sort, q.id ASC");
        $st->execute([$selId]);
        $tasks = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $isEmp = $cl && ($cl['assign_type'] ?? '') === 'employee';

    // Assignees / validators for this checklist (with names).
    $people = function (string $table) use ($db, $selId): array {
        if (!$selId) return [];
        $st = $db->prepare("SELECT p.id, p.employee_code, e.full_name FROM {$table} p
                            LEFT JOIN employees e ON e.employee_code = p.employee_code
                            WHERE p.checklist_id = ? ORDER BY e.full_name");
        $st->execute([$selId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };
    $assignees  = $isEmp ? $people('chk_assignees') : [];
    $validators = $selId ? $people('chk_validators') : [];
    $employees  = getEmployeesLite();
    $totalEst   = 0; foreach ($tasks as $t) { if ($t['is_active']) $totalEst += (int)$t['est_minutes']; }
?>
<div class="page-header"><h2>📝 Manage Checklists</h2></div>

<!-- Checklist selector -->
<div class="filter-bar" style="margin-bottom:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <form method="GET" style="display:flex;gap:8px;align-items:center">
        <input type="hidden" name="page" value="manage_tasks">
        <label class="text-muted" style="font-size:13px">Checklist</label>
        <select name="id" class="form-control" style="width:240px" onchange="this.form.submit()">
            <?php foreach ($checklists as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $selId ? 'selected' : '' ?>>
                <?= h($c['name']) ?><?= (int)$c['is_active'] ? '' : ' (inactive)' ?>
            </option>
            <?php endforeach; ?>
        </select>
    </form>
    <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('clEdit').style.display='';clMeta(<?= $cl ? (int)$cl['id'] : 0 ?>,<?= h(json_encode($cl['name'] ?? '')) ?>,'<?= h($cl['assign_type'] ?? 'location') ?>',<?= (int)($cl['time_gated'] ?? 1) ?>,<?= (int)($cl['rollover_min'] ?? 0) ?>,<?= (int)($cl['sort_order'] ?? 0) ?>)">Edit checklist</button>
    <?php if (isSuperadmin()): ?>
    <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('clEdit').style.display='';clMetaNew()">+ New checklist</button>
    <?php endif; ?>
</div>

<!-- Checklist registry add/edit -->
<div class="form-card" id="clEdit" style="margin-bottom:16px;display:none">
    <h3 id="clTitle" style="font-size:14px;margin-bottom:12px">Edit Checklist</h3>
    <form method="POST">
        <input type="hidden" name="action" value="save_checklist_meta">
        <input type="hidden" name="checklist_id" id="clId" value="0">
        <div class="form-grid">
            <div class="form-group" style="grid-column:1/-1"><label>Name <span class="required">*</span></label>
                <input type="text" name="name" id="clName" class="form-control" required></div>
            <div class="form-group"><label>Assigned by</label>
                <select name="assign_type" id="clAssign" class="form-control">
                    <option value="location">Location (all outlets)</option>
                    <option value="employee">Designated employees</option>
                </select></div>
            <div class="form-group"><label>Day rollover (min after midnight)</label>
                <input type="number" name="rollover_min" id="clRollover" class="form-control" min="0" max="1440" value="0">
                <span class="text-muted" style="font-size:11px">e.g. 120 = day rolls at 02:00</span></div>
            <div class="form-group"><label>Sort order</label>
                <input type="number" name="sort_order" id="clSort" class="form-control" value="0"></div>
            <div class="form-group"><label>&nbsp;</label>
                <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="time_gated" id="clGated" value="1" checked> Enforce section time windows</label></div>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn-primary">Save Checklist</button>
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('clEdit').style.display='none'">Cancel</button></div>
    </form>
</div>

<?php if (!$cl): ?>
<div class="alert alert-error">No checklists yet. Click <strong>+ New checklist</strong> to create one.</div>
<?php else: ?>

<!-- Sections -->
<div class="form-card" style="margin-bottom:16px">
    <h3 style="font-size:14px;margin-bottom:10px">Sections &amp; time windows — <?= h($cl['name']) ?></h3>
    <div class="table-wrap" data-stack style="margin-bottom:10px">
        <table class="table" style="font-size:13px">
            <thead><tr><th style="width:50px">Sort</th><th>Name</th><th style="width:120px">Window</th><th style="width:160px">Actions</th></tr></thead>
            <tbody>
            <?php if (empty($sections)): ?>
            <tr><td colspan="4" class="empty-row">No sections — tasks will be open all day.</td></tr>
            <?php else: foreach ($sections as $s):
                $sm = (int)$s['start_min']; $em = (int)$s['end_min'];
                $startTxt = sprintf('%02d:%02d', intdiv($sm,60)%24, $sm%60);
                $endTxt   = sprintf('%02d:%02d', intdiv($em,60)%24, $em%60);
                $nextDay  = $em >= 1440;
            ?>
            <tr>
                <td><?= (int)$s['sort_order'] ?></td>
                <td><?= h($s['name']) ?></td>
                <td><?= h($startTxt) ?>–<?= h($endTxt) ?><?= $nextDay ? ' <span class="badge badge-grey">+1d</span>' : '' ?></td>
                <td class="actions" style="display:flex;gap:4px;flex-wrap:wrap">
                    <button type="button" class="btn btn-primary btn-sm" onclick="editSec(<?= (int)$s['id'] ?>,<?= h(json_encode($s['name'])) ?>,'<?= h($startTxt) ?>','<?= h(sprintf('%02d:%02d', intdiv($em,60)%24, $em%60)) ?>',<?= $nextDay ? 1 : 0 ?>,<?= (int)$s['sort_order'] ?>)">Edit</button>
                    <form method="POST" class="inline-form" onsubmit="return confirm('Delete this section? Its tasks become un-sectioned.')">
                        <input type="hidden" name="action" value="del_section">
                        <input type="hidden" name="checklist_id" value="<?= $selId ?>">
                        <input type="hidden" name="section_id" value="<?= (int)$s['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Del</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <form method="POST" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="action" value="save_section">
        <input type="hidden" name="checklist_id" value="<?= $selId ?>">
        <input type="hidden" name="section_id" id="secId" value="0">
        <div class="form-group" style="margin:0"><label>Section name</label><input type="text" name="name" id="secName" class="form-control" style="width:160px" required></div>
        <div class="form-group" style="margin:0"><label>Start</label><input type="time" name="start_time" id="secStart" class="form-control" value="00:00"></div>
        <div class="form-group" style="margin:0"><label>End</label><input type="time" name="end_time" id="secEnd" class="form-control" value="00:00"></div>
        <div class="form-group" style="margin:0"><label>&nbsp;</label><label style="display:flex;gap:6px;align-items:center;font-size:12px"><input type="checkbox" name="end_next_day" id="secNext" value="1"> End next day</label></div>
        <div class="form-group" style="margin:0"><label>Sort</label><input type="number" name="sort_order" id="secSort" class="form-control" style="width:80px" value="0"></div>
        <button type="submit" class="btn btn-primary" id="secSubmit">Add Section</button>
        <button type="button" class="btn btn-secondary" onclick="secReset()">Clear</button>
    </form>
</div>

<!-- Add / Edit Task -->
<div class="form-card" style="margin-bottom:16px">
    <h3 id="taskFormTitle" style="font-size:14px;margin-bottom:12px">Add New Task — <?= h($cl['name']) ?></h3>
    <form method="POST" id="taskForm">
        <input type="hidden" name="action" value="save_task">
        <input type="hidden" name="checklist_id" value="<?= $selId ?>">
        <input type="hidden" name="task_id" id="taskId" value="0">
        <div class="form-grid">
            <div class="form-group" style="grid-column:1/-1">
                <label>Task Description <span class="required">*</span></label>
                <input type="text" name="description" id="taskDesc" class="form-control" required placeholder="e.g. Check Fridge Temperature">
            </div>
            <div class="form-group">
                <label>Section</label>
                <select name="section_id" id="taskSection" class="form-control">
                    <option value="0">— None —</option>
                    <?php foreach ($sections as $s): ?>
                    <option value="<?= (int)$s['id'] ?>"><?= h($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
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
            <div class="form-group">
                <label>Std time (min)</label>
                <input type="number" name="est_minutes" id="taskEst" class="form-control" min="0" value="0">
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" id="taskSubmitBtn" class="btn btn-primary">Add Task</button>
            <button type="button" id="taskCancelBtn" class="btn btn-secondary" style="display:none" onclick="cancelEdit()">Cancel</button>
        </div>
    </form>
</div>
<script>
function editTask(id, sectionId, desc, type, est) {
    document.getElementById('taskId').value = id;
    document.getElementById('taskSection').value = sectionId || 0;
    document.getElementById('taskDesc').value = desc;
    document.getElementById('taskType').value = type;
    document.getElementById('taskEst').value = est || 0;
    document.getElementById('taskFormTitle').textContent = 'Edit Task #' + id;
    document.getElementById('taskSubmitBtn').textContent = 'Update Task';
    document.getElementById('taskCancelBtn').style.display = 'inline-block';
    document.getElementById('taskForm').scrollIntoView({behavior:'smooth'});
}
function cancelEdit() {
    document.getElementById('taskId').value = 0;
    document.getElementById('taskSection').value = 0;
    document.getElementById('taskDesc').value = '';
    document.getElementById('taskType').value = 'yes_no';
    document.getElementById('taskEst').value = 0;
    document.getElementById('taskFormTitle').textContent = 'Add New Task';
    document.getElementById('taskSubmitBtn').textContent = 'Add Task';
    document.getElementById('taskCancelBtn').style.display = 'none';
}
function editSec(id, name, start, end, nextDay, sort) {
    document.getElementById('secId').value = id;
    document.getElementById('secName').value = name;
    document.getElementById('secStart').value = start;
    document.getElementById('secEnd').value = end;
    document.getElementById('secNext').checked = !!nextDay;
    document.getElementById('secSort').value = sort;
    document.getElementById('secSubmit').textContent = 'Update Section';
}
function secReset() {
    document.getElementById('secId').value = 0;
    document.getElementById('secName').value = '';
    document.getElementById('secStart').value = '00:00';
    document.getElementById('secEnd').value = '00:00';
    document.getElementById('secNext').checked = false;
    document.getElementById('secSort').value = 0;
    document.getElementById('secSubmit').textContent = 'Add Section';
}
function clMeta(id, name, assign, gated, rollover, sort) {
    document.getElementById('clTitle').textContent = id ? 'Edit Checklist #' + id : 'New Checklist';
    document.getElementById('clId').value = id;
    document.getElementById('clName').value = name;
    document.getElementById('clAssign').value = assign;
    document.getElementById('clGated').checked = !!gated;
    document.getElementById('clRollover').value = rollover;
    document.getElementById('clSort').value = sort;
}
function clMetaNew() { clMeta(0, '', 'location', 1, 0, 0); }
</script>

<!-- Tasks Table -->
<div class="table-wrap" data-stack style="margin-bottom:16px">
    <div style="display:flex;justify-content:space-between;align-items:center;padding:4px 2px 8px">
        <strong style="font-size:13px">Tasks</strong>
        <span class="text-muted" style="font-size:12px">Total std time: <?= (int)$totalEst ?> min</span>
    </div>
    <table class="table">
        <thead>
            <tr>
                <th style="width:50px">ID</th>
                <th style="width:140px">Section</th>
                <th>Description</th>
                <th style="width:90px">Type</th>
                <th style="width:70px">Min</th>
                <th style="width:80px">Status</th>
                <th style="width:180px">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($tasks)): ?>
            <tr><td colspan="7" class="empty-row">No tasks defined yet.</td></tr>
            <?php else: foreach ($tasks as $t): ?>
            <tr class="<?= $t['is_active'] ? '' : 'row-inactive' ?>">
                <td><?= $t['id'] ?></td>
                <td><?= h($t['section_name']) ?></td>
                <td><?= h($t['task_description']) ?></td>
                <td><span class="badge badge-blue"><?= h($t['input_type']) ?></span></td>
                <td><?= (int)$t['est_minutes'] ?></td>
                <td><?= $t['is_active'] ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-grey">Inactive</span>' ?></td>
                <td class="actions" style="display:flex;gap:4px;flex-wrap:wrap">
                    <button type="button" class="btn btn-primary btn-sm" onclick="editTask(<?= $t['id'] ?>, <?= (int)$t['section_id'] ?>, <?= h(json_encode($t['task_description'])) ?>, '<?= h($t['input_type']) ?>', <?= (int)$t['est_minutes'] ?>)">Edit</button>
                    <form method="POST" class="inline-form">
                        <input type="hidden" name="action" value="toggle_task">
                        <input type="hidden" name="checklist_id" value="<?= $selId ?>">
                        <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                        <button type="submit" class="btn btn-ghost btn-sm"><?= $t['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                    </form>
                    <?php if (!$t['is_active']): ?>
                    <form method="POST" class="inline-form" onsubmit="return confirm('Delete this task permanently?')">
                        <input type="hidden" name="action" value="del_task">
                        <input type="hidden" name="checklist_id" value="<?= $selId ?>">
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

<!-- Assignees (employee-mode) + Validators -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px">
    <?php
    $personBox = function (string $title, string $addAction, string $delAction, array $rows, string $empty) use ($selId, $employees) {
        ?>
        <div class="form-card">
            <h3 style="font-size:14px;margin-bottom:10px"><?= h($title) ?></h3>
            <?php if (empty($rows)): ?><div class="text-muted" style="font-size:12px;margin-bottom:8px"><?= h($empty) ?></div>
            <?php else: ?>
            <div class="table-wrap" style="margin-bottom:10px"><table class="table" style="font-size:13px"><tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= h($r['full_name'] ?? $r['employee_code']) ?> <span class="text-muted" style="font-size:11px">(<?= h($r['employee_code']) ?>)</span></td>
                    <td style="width:60px;text-align:right">
                        <form method="POST" class="inline-form">
                            <input type="hidden" name="action" value="<?= h($delAction) ?>">
                            <input type="hidden" name="checklist_id" value="<?= $selId ?>">
                            <input type="hidden" name="row_id" value="<?= (int)$r['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">×</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
            <form method="POST" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
                <input type="hidden" name="action" value="<?= h($addAction) ?>">
                <input type="hidden" name="checklist_id" value="<?= $selId ?>">
                <div class="form-group" style="margin:0;flex:1"><label>Add employee</label>
                    <select name="employee_code" class="form-control" required>
                        <option value="">— Select —</option>
                        <?php foreach ($employees as $e): if ((int)$e['is_active'] !== 1) continue; ?>
                        <option value="<?= h($e['employee_code']) ?>"><?= h($e['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Add</button>
            </form>
        </div>
        <?php
    };
    if ($isEmp) $personBox('Designated fillers (assignees)', 'save_assignee', 'del_assignee', $assignees, 'No assignees — only admins can fill this checklist.');
    $personBox('Validators', 'save_validator', 'del_validator', $validators, 'No validators designated yet.');
    ?>
</div>
<?php endif; // $cl ?>
<?php }
