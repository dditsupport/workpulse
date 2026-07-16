<?php
// =========================================================
// Audit Module — POST handlers for the auditor/approver flow,
// attachment download, and CSV export.
// Loaded by modules/audit.php.
// =========================================================

// ===========================================================
// POST: create_audit  (deferred persistence)
// ===========================================================
// Form metadata is stashed in $_SESSION until the auditor clicks Save.
// Nothing is written to the DB up front, so abandoned drafts never
// pollute audit_responses / audit_category_weights and audit_number
// sequence slots are only consumed by audits that the user actually
// commits to. The token in the URL keeps multiple parallel drafts (e.g.
// two browser tabs) isolated.
function doCreateAudit(): void {
    if (!auditCanCreate()) { header('Location: ?page=audit_list'); return; }
    $tpl    = (int)($_POST['template_id'] ?? 0);
    $loc    = (int)($_POST['location_id'] ?? 0);
    $date   = trim($_POST['audit_date'] ?? '');
    $smCode = trim($_POST['store_manager_code'] ?? '');
    $seCode = trim($_POST['store_executive_code'] ?? '');
    if (!$tpl || !$loc || !$date || !$smCode || !$seCode) {
        flash('error', 'Template, Store, Store Manager, Present Store Executive, and Date are required.');
        header('Location: ?page=audit_new'); return;
    }
    // Validate referenced rows exist + are usable. Doing this here means
    // the edit page never has to deal with stale form input.
    $db = getDb();
    $tplOk = $db->prepare('SELECT 1 FROM audit_templates WHERE id = ? AND is_active = 1 LIMIT 1');
    $tplOk->execute([$tpl]);
    if (!$tplOk->fetchColumn()) {
        flash('error', 'Audit template not found or inactive.');
        header('Location: ?page=audit_new'); return;
    }
    $locOk = $db->prepare('SELECT 1 FROM locations WHERE location_id = ? LIMIT 1');
    $locOk->execute([$loc]);
    if (!$locOk->fetchColumn()) {
        flash('error', 'Store not found.');
        header('Location: ?page=audit_new'); return;
    }
    $smOk = $db->prepare('SELECT 1 FROM employees WHERE employee_code = ? AND is_active = 1 LIMIT 1');
    $smOk->execute([$smCode]);
    if (!$smOk->fetchColumn()) {
        flash('error', 'Store Manager must be an active employee.');
        header('Location: ?page=audit_new'); return;
    }
    $seOk = $db->prepare('SELECT 1 FROM employees WHERE employee_code = ? AND is_active = 1 LIMIT 1');
    $seOk->execute([$seCode]);
    if (!$seOk->fetchColumn()) {
        flash('error', 'Present Store Executive must be an active employee.');
        header('Location: ?page=audit_new'); return;
    }

    $token = bin2hex(random_bytes(8));
    if (!isset($_SESSION['audit_drafts']) || !is_array($_SESSION['audit_drafts'])) {
        $_SESSION['audit_drafts'] = [];
    }
    $_SESSION['audit_drafts'][$token] = [
        'template_id'          => $tpl,
        'location_id'          => $loc,
        'audit_date'           => $date,
        'store_manager_code'   => $smCode,
        'store_executive_code' => $seCode,
        'auditor_code'         => myCode(),
        'created_at'           => time(),
    ];
    flash('success', 'Fill in the answers and click Save. Nothing is stored until you save.');
    header('Location: ?page=audit_edit&draft=' . $token);
}

// Take a session draft + commit it to the DB on first save. Returns the
// new audit id. Caller is responsible for transaction control.
function auditCommitDraft(PDO $db, array $d): int {
    $tpl    = (int)$d['template_id'];
    $loc    = (int)$d['location_id'];
    $date   = (string)$d['audit_date'];
    $smCode = (string)$d['store_manager_code'];
    $seCode = (string)($d['store_executive_code'] ?? '');
    $auCode = (string)($d['auditor_code'] ?? myCode());

    // audit_number left NULL — gets assigned in the same first-save flow
    // as before, so the existing UNIQUE-key retry logic still applies.
    $ins = $db->prepare(
        'INSERT INTO audits (audit_number, template_id, location_id, auditor_code, store_manager_code, store_executive_code, audit_date)
         VALUES (NULL, ?, ?, ?, ?, ?, ?)');
    $ins->execute([$tpl, $loc, $auCode, $smCode, ($seCode !== '' ? $seCode : null), $date]);
    $auditId = (int)$db->lastInsertId();

    // Seed category weights — include the snapshot columns only if the
    // migration has been applied; otherwise use the legacy shape.
    $hasCw  = auditHasCwSnapshotCols();
    $hasRsp = auditHasResponseSnapshotCols();
    $cats = $db->prepare('SELECT id, name, weightage FROM audit_categories WHERE template_id = ?');
    $cats->execute([$tpl]);
    if ($hasCw) {
        $cwIns = $db->prepare(
            'INSERT INTO audit_category_weights
              (audit_id, category_id, category_name, actual_weightage, modified_weightage)
             VALUES (?, ?, ?, ?, ?)');
    } else {
        $cwIns = $db->prepare(
            'INSERT INTO audit_category_weights
              (audit_id, category_id, modified_weightage)
             VALUES (?, ?, ?)');
    }
    $catRows = $cats->fetchAll(PDO::FETCH_ASSOC);
    foreach ($catRows as $c) {
        $w = (float)$c['weightage'];
        if ($hasCw) {
            $cwIns->execute([$auditId, (int)$c['id'], (string)$c['name'], $w, $w]);
        } else {
            $cwIns->execute([$auditId, (int)$c['id'], $w]);
        }
    }

    // Seed responses — same conditional shape.
    if ($catRows) {
        $catIds = array_column($catRows, 'id');
        $ph = implode(',', array_fill(0, count($catIds), '?'));
        $pSt = $db->prepare(
            "SELECT p.id, p.parameter_text, p.type, p.max_value, p.score_weightage,
                    p.category_id, c.name AS category_name
             FROM audit_parameters p
             JOIN audit_categories c ON c.id = p.category_id
             WHERE p.category_id IN ({$ph}) AND p.is_active = 1");
        $pSt->execute($catIds);
        if ($hasRsp) {
            $pIns = $db->prepare(
                'INSERT INTO audit_responses
                  (audit_id, parameter_id, category_id, category_name,
                   parameter_text, parameter_type, parameter_max_value,
                   actual_weightage, modified_weightage)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        } else {
            $pIns = $db->prepare(
                'INSERT INTO audit_responses
                  (audit_id, parameter_id, actual_weightage, modified_weightage)
                 VALUES (?, ?, ?, ?)');
        }
        foreach ($pSt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $w = (float)$p['score_weightage'];
            if ($hasRsp) {
                $pIns->execute([
                    $auditId,
                    (int)$p['id'],
                    (int)$p['category_id'],
                    (string)$p['category_name'],
                    (string)$p['parameter_text'],
                    (string)$p['type'],
                    $p['max_value'] !== null ? (float)$p['max_value'] : null,
                    $w,
                    $w,
                ]);
            } else {
                $pIns->execute([$auditId, (int)$p['id'], $w, $w]);
            }
        }
    }

    auditAddHistory($auditId, 'create', $auCode, 'Audit created (committed on first save)');
    return $auditId;
}

// ===========================================================
// Validate weightage sums. Returns [ok, errorMsg].
// ===========================================================
function auditValidateWeights(int $auditId): array {
    $db = getDb();
    $catSum = 0.0;
    $catSt = $db->prepare('SELECT modified_weightage FROM audit_category_weights WHERE audit_id = ?');
    $catSt->execute([$auditId]);
    foreach ($catSt->fetchAll(PDO::FETCH_COLUMN) as $w) $catSum += (float)$w;
    if (abs($catSum - 100) > AUDIT_WEIGHT_TOLERANCE) {
        return [false, 'Category Modified Weightages must sum to 100 (currently ' . number_format($catSum, 2) . ').'];
    }
    // per-cat param sums
    $st = $db->prepare(
        'SELECT p.category_id, SUM(r.modified_weightage) s
         FROM audit_responses r
         JOIN audit_parameters p ON p.id = r.parameter_id
         WHERE r.audit_id = ?
         GROUP BY p.category_id');
    $st->execute([$auditId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (abs((float)$r['s'] - 100) > AUDIT_WEIGHT_TOLERANCE) {
            return [false, 'Parameter Modified Weightages must sum to 100 within each category (category #' . (int)$r['category_id'] . ' = ' . number_format((float)$r['s'], 2) . ').'];
        }
    }
    return [true, ''];
}

// ===========================================================
// POST: save_audit_weights (values + weights + remarks + attachments)
// ===========================================================
function doSaveAuditWeights(): void {
    $auditId    = (int)($_POST['audit_id'] ?? 0);
    $draftToken = trim((string)($_POST['draft_token'] ?? ''));
    $db = getDb();
    $wasDraft = false;

    // First save of a deferred (in-session) draft → commit the audit row
    // and seed responses now, before falling through to the normal save
    // path. The session entry is consumed in the same transaction so the
    // user never gets two committed audits from one draft.
    if ($auditId === 0 && $draftToken !== '' && isset($_SESSION['audit_drafts'][$draftToken])) {
        $d = $_SESSION['audit_drafts'][$draftToken];
        if (($d['auditor_code'] ?? '') !== myCode() && !isSuperadmin()) {
            flash('error', 'Cannot save someone else\'s draft.');
            header('Location: ?page=audit_list'); return;
        }
        try {
            $db->beginTransaction();
            $auditId = auditCommitDraft($db, $d);
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            flash('error', 'Could not start audit: ' . $e->getMessage());
            header('Location: ?page=audit_list'); return;
        }
        unset($_SESSION['audit_drafts'][$draftToken]);
        $wasDraft = true;
    }

    $a = auditGetById($auditId);
    if (!$a || !auditCanEditRow($a)) { flash('error', 'Cannot edit this audit.'); header('Location: ?page=audit_list'); return; }
    // Was the audit unnumbered going in? We'll assign a number on first save.
    $assignedNumber = null;
    try {
        $db->beginTransaction();

        // 0) First-save hook: assign audit_number if it's still NULL.
        //    Done inside the transaction so two concurrent first-saves can't
        //    collide — worst case the UNIQUE index kicks in and we retry.
        if (empty($a['audit_number'])) {
            for ($attempt = 0; $attempt < 3; $attempt++) {
                try {
                    $next = auditNextNumber($db);
                    $db->prepare('UPDATE audits SET audit_number = ? WHERE id = ? AND audit_number IS NULL')
                       ->execute([$next, $auditId]);
                    $assignedNumber = $next;
                    break;
                } catch (Exception $e) {
                    if ($attempt === 2) throw $e;
                    // sequence collision — loop and try again
                }
            }
        }

        // 1) Category modified weightages — integers only, decimals are rounded.
        if (!empty($_POST['cat_mod']) && is_array($_POST['cat_mod'])) {
            $up = $db->prepare('UPDATE audit_category_weights SET modified_weightage = ? WHERE audit_id = ? AND category_id = ?');
            foreach ($_POST['cat_mod'] as $cid => $w) {
                $up->execute([(int)round((float)$w), $auditId, (int)$cid]);
            }
        }

        // 2) Per-parameter: value, modified weight, remark, attachments
        $paramVals   = $_POST['param_value']  ?? [];
        $paramMods   = $_POST['param_mod']    ?? [];
        $paramRemark = $_POST['param_remark'] ?? [];

        // Load each response's effective type + max. When the snapshot
        // columns exist (post-2026-04-27 migration) we prefer the
        // response's own snapshot so master edits after creation can't
        // re-interpret saved answers. When they don't exist we use the
        // master only — this keeps Save working on databases that
        // haven't run the migration yet (otherwise the SELECT would
        // throw and silently roll back the entire save).
        // Pre-fetch response.id and modified_weightage alongside type/max so the
        // per-parameter loop below doesn't issue per-row SELECTs (used to fire
        // 1-2 extra queries per audit parameter — easily 60+ on a full audit).
        $allParams = [];
        if (auditHasResponseSnapshotCols()) {
            $pSt = $db->prepare(
                'SELECT r.parameter_id AS id,
                        r.id                                          AS response_id,
                        r.modified_weightage                          AS existing_mod_w,
                        COALESCE(r.parameter_type, p.type)            AS type,
                        COALESCE(r.parameter_max_value, p.max_value)  AS max_value
                 FROM audit_responses r
                 LEFT JOIN audit_parameters p ON p.id = r.parameter_id
                 WHERE r.audit_id = ?');
        } else {
            $pSt = $db->prepare(
                'SELECT p.id,
                        r.id                  AS response_id,
                        r.modified_weightage  AS existing_mod_w,
                        p.type, p.max_value
                 FROM audit_responses r
                 JOIN audit_parameters p ON p.id = r.parameter_id
                 WHERE r.audit_id = ?');
        }
        $pSt->execute([$auditId]);
        foreach ($pSt->fetchAll(PDO::FETCH_ASSOC) as $p) $allParams[(int)$p['id']] = $p;

        $up = $db->prepare(
            'UPDATE audit_responses SET value_entered = ?, obtain_score = ?, modified_weightage = ?, auditor_remark = ?
             WHERE audit_id = ? AND parameter_id = ?');
        foreach ($allParams as $pid => $p) {
            $rawVal = array_key_exists($pid, $paramVals) ? trim((string)$paramVals[$pid]) : '';
            $val = $rawVal === '' ? null : (float)$rawVal;
            // Ratings are restricted to half-steps (0, 0.5, 1, 1.5 … 5) — match
            // the existing mobile app and the input's step="0.5". Snap server-side
            // so any value bypassing the input lands on a legal half-step.
            if ($val !== null && $p['type'] === 'rating') {
                $val = round($val * 2) / 2;
                if ($val < 0) $val = 0;
                if ($val > 5) $val = 5;
            }
            $obtain = auditComputeObtainScore($p['type'], $p['max_value'] !== null ? (float)$p['max_value'] : null, $val);
            // Modified Wt. is integer-only — defensive round in case a decimal
            // sneaks through (browser inputmode, pasted value, etc.).
            $modW = array_key_exists($pid, $paramMods) ? (int)round((float)$paramMods[$pid]) : null;
            if ($modW === null) {
                // leave existing modified weightage (pre-fetched above)
                $modW = (int)round((float)($p['existing_mod_w'] ?? 0));
            }
            $remark = array_key_exists($pid, $paramRemark) ? (string)$paramRemark[$pid] : '';
            $up->execute([$val, $obtain, $modW, $remark !== '' ? $remark : null, $auditId, $pid]);

            // Attachments: response id was pre-fetched above
            if (!empty($_FILES['param_files']['name'][$pid][0] ?? null)) {
                $rid = (int)($p['response_id'] ?? 0);
                if ($rid) {
                    // re-shape $_FILES for a single pid
                    $orig = $_FILES['param_files'];
                    $_FILES['attachments'] = [
                        'name'     => $orig['name'][$pid],
                        'type'     => $orig['type'][$pid],
                        'tmp_name' => $orig['tmp_name'][$pid],
                        'error'    => $orig['error'][$pid],
                        'size'     => $orig['size'][$pid],
                    ];
                    auditSaveAttachments($auditId, $rid, myCode());
                    unset($_FILES['attachments']);
                }
            }
        }

        $db->commit();

        if (!empty($_POST['submit_after_save'])) {
            doSubmitAudit($auditId);
            return;
        }
        auditRecalcTotalScore($auditId);
        flash('success', $assignedNumber
            ? ('Saved. Audit number assigned: ' . $assignedNumber)
            : 'Saved.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error', 'Save failed: ' . $e->getMessage());
    }
    header('Location: ?page=audit_edit&id=' . $auditId);
}

// ===========================================================
// POST: submit_audit
// ===========================================================
function doSubmitAudit(?int $auditId = null): void {
    $auditId = $auditId ?? (int)($_POST['audit_id'] ?? 0);
    $a = auditGetById($auditId);
    if (!$a || !auditCanEditRow($a)) { flash('error', 'Cannot submit this audit.'); header('Location: ?page=audit_list'); return; }
    $from = $a['status']; $to = 'submitted';
    if (!auditValidateTransition($from, $to)) { flash('error', 'Invalid status transition.'); header('Location: ?page=audit_edit&id=' . $auditId); return; }

    // Weight validation
    [$ok, $msg] = auditValidateWeights($auditId);
    if (!$ok) { flash('error', $msg); header('Location: ?page=audit_edit&id=' . $auditId); return; }

    // Ensure every response has a value_entered
    $missing = (int)getDb()->query('SELECT COUNT(*) FROM audit_responses WHERE audit_id = ' . (int)$auditId . ' AND value_entered IS NULL')->fetchColumn();
    if ($missing > 0) { flash('error', "Cannot submit — {$missing} parameter(s) are unanswered."); header('Location: ?page=audit_edit&id=' . $auditId); return; }

    // Safety: if someone hits submit_audit directly without ever saving,
    // the draft may still be unnumbered. Assign one before we flip status.
    if (empty($a['audit_number'])) {
        $db = getDb();
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $next = auditNextNumber($db);
                $db->prepare('UPDATE audits SET audit_number = ? WHERE id = ? AND audit_number IS NULL')
                   ->execute([$next, $auditId]);
                break;
            } catch (Exception $e) {
                if ($attempt === 2) throw $e;
            }
        }
    }

    auditRecalcTotalScore($auditId);
    // Send-back agenda gate — on a resubmit cycle the auditor must have
    // replied to any open pins from the prior reviewer. On a first submit
    // there can't be any pins yet (annotations are post-submit only), so
    // the check is a no-op.
    if ($from === 'sent_back' && auditEnforceOpenPinsGate($auditId, 'audit_edit')) return;
    getDb()->prepare("UPDATE audits SET status='submitted', submitted_at=NOW() WHERE id=?")->execute([$auditId]);
    auditAddHistory($auditId, $from === 'sent_back' ? 'resubmit' : 'submit', myCode());
    flash('success', 'Audit submitted.');
    header('Location: ?page=audit_list');
}

// ===========================================================
// POST: manager_review_audit (Store Manager justifies + forwards)
// ===========================================================
// Saves the SM's per-question justification text, then optionally
// forwards the audit to the Operation Team (5-stage flow).
function doManagerReviewAudit(): void {
    $auditId = (int)($_POST['audit_id'] ?? 0);
    $a = auditGetById($auditId);
    if (!$a) { flash('error', 'Audit not found.'); header('Location: ?page=audit_list'); return; }
    if (!auditCanManagerReview($a)) {
        flash('error', 'Only the assigned Store Manager can justify this audit.');
        header('Location: ?page=audit_list'); return;
    }
    if (!auditHasManagerReviewCols()) {
        flash('error', 'Manager review is not enabled — run migration_2026_05_06_audit_manager_review.sql.');
        header('Location: ?page=audit_list'); return;
    }
    $smAction = $_POST['sm_action'] ?? 'save';
    $remarks  = $_POST['store_manager_remark'] ?? [];
    $db = getDb();

    $up = $db->prepare('UPDATE audit_responses SET store_manager_remark = ? WHERE audit_id = ? AND parameter_id = ?');
    $filled = 0;
    $summary = [];
    foreach ($remarks as $pid => $txt) {
        $clean = trim((string)$txt);
        $up->execute([$clean !== '' ? $clean : null, $auditId, (int)$pid]);
        if ($clean !== '') { $filled++; $summary[] = 'P#' . (int)$pid . ': ' . mb_substr($clean, 0, 80); }
    }

    if ($smAction === 'forward') {
        if (!auditValidateTransition('submitted', 'operation_review')) { header('Location: ?page=audit_list'); return; }
        // Send-back agenda gate: if Ops (or anyone in a prior cycle) left
        // pins open on this audit's images, the SM must reply / resolve
        // before forwarding.
        if (auditEnforceOpenPinsGate($auditId, 'audit_manager_review')) return;
        $db->prepare("UPDATE audits SET status='operation_review', manager_reviewed_at=NOW() WHERE id = ?")->execute([$auditId]);
        auditAddHistory($auditId, 'sm_forward', myCode(), $filled > 0 ? implode(' | ', $summary) : 'No comments — forwarded as-is');
        flash('success', 'Audit forwarded to Operation Team' . ($filled > 0 ? ' with ' . $filled . ' justification(s).' : '.'));
        header('Location: ?page=audit_list');
        return;
    }
    auditAddHistory($auditId, 'manager_remark', myCode(), $filled > 0 ? implode(' | ', $summary) : 'Cleared comments');
    flash('success', 'Justification saved. Forward when you are done.');
    header('Location: ?page=audit_manager_review&id=' . $auditId);
}

// Helper used by every forward handler: returns true (and emits a flash +
// redirect) when the actor still has open pins to address; the caller
// returns immediately on a true result so the status change is skipped.
function auditEnforceOpenPinsGate(int $auditId, string $backPage): bool {
    $blocking = auditOpenPinsBlockingFor($auditId, myCode());
    if (!$blocking) return false;
    $n = count($blocking);
    flash('error', $n . ' open pin' . ($n === 1 ? '' : 's')
        . ' on attached images still need your reply or resolution before you can forward this audit.');
    header('Location: ?page=' . $backPage . '&id=' . $auditId);
    return true;
}

// ===========================================================
// POST: operation_review_audit (Operation Team comments + forwards / sends back)
// ===========================================================
// Saves operation_remark per parameter, then either forwards the audit
// to the Approver or sends it back to the Auditor / SM. Send-back
// requires at least one parameter remark so the recipient has a
// concrete agenda to act on (mirrors the old approver send-back rule).
function doOperationReviewAudit(): void {
    if (!auditCanOperationReview()) { header('Location: ?page=audit_list'); return; }
    $auditId = (int)($_POST['audit_id'] ?? 0);
    $a = auditGetById($auditId);
    if (!$a || $a['status'] !== 'operation_review') {
        flash('error', 'Audit not ready for Operation Team review.');
        header('Location: ?page=audit_list'); return;
    }
    $action  = $_POST['op_action'] ?? 'save';
    $remarks = $_POST['operation_remark'] ?? [];
    $db = getDb();

    $up = $db->prepare('UPDATE audit_responses SET operation_remark = ? WHERE audit_id = ? AND parameter_id = ?');
    $filled = 0; $summary = [];
    foreach ($remarks as $pid => $txt) {
        $clean = trim((string)$txt);
        $up->execute([$clean !== '' ? $clean : null, $auditId, (int)$pid]);
        if ($clean !== '') { $filled++; $summary[] = 'P#' . (int)$pid . ': ' . mb_substr($clean, 0, 80); }
    }

    if ($action === 'forward') {
        if (!auditValidateTransition('operation_review', 'approver_review')) { header('Location: ?page=audit_list'); return; }
        if (auditEnforceOpenPinsGate($auditId, 'audit_operation_review')) return;
        $db->prepare("UPDATE audits SET status='approver_review', operation_code=?, operation_reviewed_at=NOW() WHERE id=?")
           ->execute([myCode(), $auditId]);
        auditAddHistory($auditId, 'ops_forward', myCode(), $filled > 0 ? implode(' | ', $summary) : 'No comments — forwarded as-is');
        flash('success', 'Audit forwarded to Approver' . ($filled > 0 ? ' with ' . $filled . ' comment(s).' : '.'));
        header('Location: ?page=audit_list');
        return;
    }
    if ($action === 'send_back_auditor') {
        if ($filled === 0) { flash('error', 'Add a comment on at least one parameter before sending back.'); header('Location: ?page=audit_operation_review&id=' . $auditId); return; }
        if (!auditValidateTransition('operation_review', 'sent_back')) { header('Location: ?page=audit_list'); return; }
        $db->prepare("UPDATE audits SET status='sent_back' WHERE id=?")->execute([$auditId]);
        auditAddHistory($auditId, 'ops_sendback_auditor', myCode(), 'To Auditor — ' . implode(' | ', $summary));
        flash('success', 'Audit sent back to the Auditor with comments.');
        header('Location: ?page=audit_list');
        return;
    }
    if ($action === 'send_back_sm') {
        if ($filled === 0) { flash('error', 'Add a comment on at least one parameter before sending back.'); header('Location: ?page=audit_operation_review&id=' . $auditId); return; }
        if (!auditValidateTransition('operation_review', 'submitted')) { header('Location: ?page=audit_list'); return; }
        $db->prepare("UPDATE audits SET status='submitted' WHERE id=?")->execute([$auditId]);
        auditAddHistory($auditId, 'ops_sendback_sm', myCode(), 'To Store Manager — ' . implode(' | ', $summary));
        flash('success', 'Audit sent back to the Store Manager.');
        header('Location: ?page=audit_list');
        return;
    }
    auditAddHistory($auditId, 'ops_remark', myCode(), $filled > 0 ? implode(' | ', $summary) : 'Cleared comments');
    flash('success', 'Comments saved. Forward when you are done.');
    header('Location: ?page=audit_operation_review&id=' . $auditId);
}

// ===========================================================
// POST: approve_audit (Approver forwards to Management or sends back to Ops)
// ===========================================================
function doApproveAudit(): void {
    if (!auditCanApprove()) { header('Location: ?page=audit_list'); return; }
    $auditId = (int)($_POST['audit_id'] ?? 0);
    $a = auditGetById($auditId);
    if (!$a || $a['status'] !== 'approver_review') {
        flash('error', 'Audit not ready for approval.');
        header('Location: ?page=audit_list');
        return;
    }
    $decision = $_POST['decision'] ?? 'approve';
    $remarks  = $_POST['approver_remark'] ?? [];
    $db = getDb();

    $up = $db->prepare('UPDATE audit_responses SET approver_remark = ? WHERE audit_id = ? AND parameter_id = ?');
    $flagged = 0; $flaggedSummary = [];
    foreach ($remarks as $pid => $txt) {
        $clean = trim((string)$txt);
        $up->execute([$clean !== '' ? $clean : null, $auditId, (int)$pid]);
        if ($clean !== '') { $flagged++; $flaggedSummary[] = 'P#' . (int)$pid . ': ' . mb_substr($clean, 0, 80); }
    }

    // ── Approver-edited Modified Weightages ──────────────────────────
    // The Modified Wt. columns on the approver page (category + per-
    // parameter) post back as cat_mod[$cat_id] and param_mod[$param_id].
    // The auditor no longer sees these — only the approver can change
    // them. Validate the per-cat sums and the overall cat sum, then
    // save and recompute total_score before transitioning.
    $catMods   = is_array($_POST['cat_mod']   ?? null) ? $_POST['cat_mod']   : [];
    $paramMods = is_array($_POST['param_mod'] ?? null) ? $_POST['param_mod'] : [];
    if ($catMods || $paramMods) {
        // Persist first, then validate via the existing helper which
        // reads from the now-updated rows.
        if ($catMods) {
            $cUp = $db->prepare('UPDATE audit_category_weights SET modified_weightage = ? WHERE audit_id = ? AND category_id = ?');
            foreach ($catMods as $cid => $w) { $cUp->execute([(int)round((float)$w), $auditId, (int)$cid]); }
        }
        if ($paramMods) {
            $pUp = $db->prepare('UPDATE audit_responses SET modified_weightage = ? WHERE audit_id = ? AND parameter_id = ?');
            foreach ($paramMods as $pid => $w) { $pUp->execute([(int)round((float)$w), $auditId, (int)$pid]); }
        }
        [$wOk, $wMsg] = auditValidateWeights($auditId);
        if (!$wOk) {
            flash('error', $wMsg);
            header('Location: ?page=audit_approve&id=' . $auditId); return;
        }
        // Recompute the obtain_score-based total_score so the new weights
        // are reflected immediately on the audit list / summary.
        auditRecalcTotalScore($auditId);
    }

    if ($decision === 'send_back_ops') {
        if ($flagged === 0) { flash('error', 'Add a remark on at least one parameter before sending back.'); header('Location: ?page=audit_approve&id=' . $auditId); return; }
        if (!auditValidateTransition('approver_review', 'operation_review')) { header('Location: ?page=audit_list'); return; }
        $db->prepare("UPDATE audits SET status='operation_review' WHERE id = ?")->execute([$auditId]);
        auditAddHistory($auditId, 'approver_sendback_ops', myCode(), 'To Operation Team — ' . implode(' | ', $flaggedSummary));
        flash('success', 'Audit sent back to the Operation Team.');
        header('Location: ?page=audit_list');
        return;
    }
    // Default: approve & forward to Management. We stamp approver_code +
    // approved_at + approver_reviewed_at here; management_approved_at is
    // stamped at the final approve step in doManagementApproveAudit.
    if (!auditValidateTransition('approver_review', 'management_review')) { header('Location: ?page=audit_list'); return; }
    if (auditEnforceOpenPinsGate($auditId, 'audit_approve')) return;
    $db->prepare("UPDATE audits SET status='management_review', approver_code=?, approved_at=NOW(), approver_reviewed_at=NOW() WHERE id = ?")
       ->execute([myCode(), $auditId]);
    auditAddHistory($auditId, 'approver_forward', myCode(), $flagged > 0 ? implode(' | ', $flaggedSummary) : null);
    flash('success', 'Audit forwarded to Management for final approval.');
    header('Location: ?page=audit_list');
}

// ===========================================================
// POST: management_approve_audit (Management final approve / send back to Approver)
// ===========================================================
function doManagementApproveAudit(): void {
    if (!auditCanManagementReview()) { header('Location: ?page=audit_list'); return; }
    $auditId = (int)($_POST['audit_id'] ?? 0);
    $a = auditGetById($auditId);
    if (!$a || $a['status'] !== 'management_review') {
        flash('error', 'Audit not ready for Management review.');
        header('Location: ?page=audit_list'); return;
    }
    $decision = $_POST['decision'] ?? 'approve';
    $remarks  = $_POST['management_remark'] ?? [];
    $db = getDb();

    $up = $db->prepare('UPDATE audit_responses SET management_remark = ? WHERE audit_id = ? AND parameter_id = ?');
    $flagged = 0; $flaggedSummary = [];
    foreach ($remarks as $pid => $txt) {
        $clean = trim((string)$txt);
        $up->execute([$clean !== '' ? $clean : null, $auditId, (int)$pid]);
        if ($clean !== '') { $flagged++; $flaggedSummary[] = 'P#' . (int)$pid . ': ' . mb_substr($clean, 0, 80); }
    }

    if ($decision === 'send_back_approver') {
        if ($flagged === 0) { flash('error', 'Add a remark on at least one parameter before sending back.'); header('Location: ?page=audit_management_review&id=' . $auditId); return; }
        if (!auditValidateTransition('management_review', 'approver_review')) { header('Location: ?page=audit_list'); return; }
        $db->prepare("UPDATE audits SET status='approver_review' WHERE id = ?")->execute([$auditId]);
        auditAddHistory($auditId, 'mgmt_sendback_approver', myCode(), 'To Approver — ' . implode(' | ', $flaggedSummary));
        flash('success', 'Audit sent back to the Approver.');
        header('Location: ?page=audit_list');
        return;
    }
    // Final approve.
    if (!auditValidateTransition('management_review', 'approved')) { header('Location: ?page=audit_list'); return; }
    if (auditEnforceOpenPinsGate($auditId, 'audit_management_review')) return;
    $db->prepare("UPDATE audits SET status='approved', management_code=?, management_approved_at=NOW() WHERE id = ?")
       ->execute([myCode(), $auditId]);
    auditAddHistory($auditId, 'management_approve', myCode(), $flagged > 0 ? implode(' | ', $flaggedSummary) : null);
    flash('success', 'Audit approved by Management. Workflow complete.');
    header('Location: ?page=audit_list');
}

// ===========================================================
// POST: delete_audit_attachment
// ===========================================================
function doDeleteAuditAttachment(): void {
    $auditId = (int)($_POST['audit_id'] ?? 0);
    $attId   = (int)($_POST['att_id']   ?? 0);
    $a = auditGetById($auditId);
    if (!$a || !auditCanEditRow($a)) { flash('error', 'Cannot modify this audit.'); header('Location: ?page=audit_list'); return; }
    $db = getDb();
    $st = $db->prepare(
        'SELECT aa.stored_name
         FROM audit_response_attachments aa
         JOIN audit_responses r ON r.id = aa.response_id
         WHERE aa.id = ? AND r.audit_id = ?');
    $st->execute([$attId, $auditId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $path = auditAttachmentPath($auditId, null, (string)$row['stored_name']);
        if ($path) @unlink($path);
        // Drop any annotation pins on this attachment first (the FK on
        // audit_image_pin_comments.pin_id cascades comments automatically).
        // Wrapped in try/catch so a pre-migration DB (table missing) still
        // lets the attachment delete go through.
        try {
            $db->prepare('DELETE FROM audit_image_pins WHERE attachment_id = ?')->execute([$attId]);
        } catch (Exception $e) { /* pre-migration DB — table missing, ignore */ }
        $db->prepare('DELETE FROM audit_response_attachments WHERE id = ?')->execute([$attId]);
    }
    header('Location: ?page=audit_edit&id=' . $auditId);
}

// ===========================================================
// POST: delete_audit (superadmin only)
// ===========================================================
function doDeleteAudit(): void {
    if (!isSuperadmin()) { header('Location: ?page=audit_list'); return; }
    $id = (int)($_POST['audit_id'] ?? 0);
    if (!$id) { header('Location: ?page=audit_list'); return; }
    $db = getDb();
    // Resolve both possible directories BEFORE the row goes away — once
    // the audits row is deleted we can no longer derive the new-layout
    // bucket (no template_id / audit_date to read).
    $row     = auditGetById($id);
    $newDir  = $row ? auditAttachmentDir($row) : null;
    $legDir  = auditAttachmentDirLegacy($id);
    try {
        $db->prepare('DELETE FROM audits WHERE id = ?')->execute([$id]);
        foreach (array_filter([$newDir, $legDir]) as $dir) {
            if (is_dir($dir)) {
                foreach (glob($dir . '*') ?: [] as $f) @unlink($f);
                @rmdir($dir);
            }
        }
        flash('success', 'Audit deleted.');
    } catch (Exception $e) {
        flash('error', 'Delete failed: ' . $e->getMessage());
    }
    header('Location: ?page=audit_list');
}

// ===========================================================
// DOWNLOAD: Attachment
// ===========================================================
function downloadAuditAttachment(): void {
    $auditId = (int)($_GET['audit_id'] ?? 0);
    $attId   = (int)($_GET['att_id']   ?? 0);
    $a = auditGetById($auditId);
    if (!$a || !auditCanViewRow($a)) { http_response_code(403); echo 'Access denied'; return; }
    $db = getDb();
    $st = $db->prepare(
        'SELECT aa.filename, aa.stored_name, aa.mime_type, aa.file_size
         FROM audit_response_attachments aa
         JOIN audit_responses r ON r.id = aa.response_id
         WHERE aa.id = ? AND r.audit_id = ?');
    $st->execute([$attId, $auditId]);
    $att = $st->fetch(PDO::FETCH_ASSOC);
    if (!$att) { http_response_code(404); echo 'Not found'; return; }
    $path = auditAttachmentPath($auditId, $a, (string)$att['stored_name']);
    if (!$path) { http_response_code(404); echo 'File missing'; return; }
    // Track the open before streaming the bytes — the file leaves the
    // server regardless of whether the browser inlines it or saves it.
    auditLogView($auditId, 'attachment', $attId);
    header('Content-Type: ' . $att['mime_type']);
    header('Content-Disposition: inline; filename="' . $att['filename'] . '"');
    header('Content-Length: ' . $att['file_size']);
    readfile($path);
    exit;
}

// ===========================================================
// CSV EXPORT
// ===========================================================
function exportAuditRegister(): void {
    $fromDate = trim($_GET['from_date'] ?? '');
    $toDate   = trim($_GET['to_date']   ?? '');
    // Default to the current calendar month when either bound is missing,
    // so the Export button is always usable from the audit list.
    if (!$fromDate) $fromDate = date('Y-m-01');
    if (!$toDate)   $toDate   = date('Y-m-t');
    $from = strtotime($fromDate); $to = strtotime($toDate);
    if (!$from || !$to || $from > $to) {
        http_response_code(400);
        flash('error', 'Invalid date range.');
        header('Location: ?page=audit_list'); return;
    }

    $db = getDb();
    $where  = ['a.audit_date >= ?', 'a.audit_date <= ?'];
    $params = [$fromDate, $toDate];

    if (!empty($_GET['status']))      { $where[] = 'a.status = ?'; $params[] = $_GET['status']; }
    if (!empty($_GET['template_id'])) { $where[] = 'a.template_id = ?'; $params[] = (int)$_GET['template_id']; }

    // Location scope
    $locIdParam = (int)($_GET['location_id'] ?? 0);
    if (!isSuperadmin() && !auditCanViewAll() && !auditCanAdmin() && !auditCanApprove() && !auditCanCreate()) {
        // Pure location-owner: force their own location
        $locIdParam = myLocationId();
    }
    if ($locIdParam > 0) { $where[] = 'a.location_id = ?'; $params[] = $locIdParam; }

    // Additional visibility scope for mixed roles
    auditApplyScope($where, $params);

    // Drive the export from audit_responses outward. When the snapshot
    // columns exist (post-2026-04-27 migration) every text field comes
    // from the snapshot via COALESCE so later template edits never
    // rewrite history. Without the migration we fall back to the live
    // master values (the export still works, just won't be edit-proof).
    $hasRsp = auditHasResponseSnapshotCols();
    $hasCw  = auditHasCwSnapshotCols();
    $catNameExpr = $hasRsp ? "COALESCE(NULLIF(r.category_name, ''), c.name)"            : 'c.name';
    $catWtExpr   = $hasCw  ? "COALESCE(NULLIF(cw.actual_weightage, 0), c.weightage)"    : 'c.weightage';
    $pTextExpr   = $hasRsp ? "COALESCE(NULLIF(r.parameter_text, ''), p.parameter_text)" : 'p.parameter_text';
    $pTypeExpr   = $hasRsp ? "COALESCE(r.parameter_type, p.type)"                       : 'p.type';
    $pMaxExpr    = $hasRsp ? "COALESCE(r.parameter_max_value, p.max_value)"             : 'p.max_value';
    $catLinkExpr = $hasRsp ? "COALESCE(r.category_id, p.category_id)"                   : 'p.category_id';

    $sql = 'SELECT a.audit_date, a.audit_number, a.status, a.submitted_at, a.approved_at,
                   a.location_id, l.location_name, t.name AS template_name,
                   ae.full_name AS auditor_name, ap.full_name AS approver_name,
                   sm.full_name AS store_manager_name,
                   se.full_name AS store_executive_name,
                   ' . $catNameExpr . ' AS category_name,
                   ' . $catWtExpr   . ' AS cat_weightage,
                   cw.modified_weightage AS cat_mod_weightage,
                   ' . $pTextExpr   . ' AS parameter_text,
                   ' . $pTypeExpr   . ' AS parameter_type,
                   ' . $pMaxExpr    . ' AS parameter_max_value,
                   p.score_weightage     AS param_current_weightage,
                   r.actual_weightage, r.modified_weightage, r.value_entered, r.obtain_score, r.auditor_remark,
                   (SELECT COUNT(*) FROM audit_response_attachments aa WHERE aa.response_id = r.id) AS doc_count,
                   a.total_score
            FROM audits a
            JOIN audit_templates t ON t.id = a.template_id
            LEFT JOIN locations l ON l.location_id = a.location_id
            LEFT JOIN employees ae ON ae.employee_code = a.auditor_code
            LEFT JOIN employees ap ON ap.employee_code = a.approver_code
            LEFT JOIN employees sm ON sm.employee_code = a.store_manager_code
            LEFT JOIN employees se ON se.employee_code = a.store_executive_code
            JOIN audit_responses r              ON r.audit_id = a.id
            LEFT JOIN audit_parameters p        ON p.id = r.parameter_id
            LEFT JOIN audit_categories c        ON c.id = ' . $catLinkExpr . '
            LEFT JOIN audit_category_weights cw ON cw.audit_id = a.id AND cw.category_id = ' . $catLinkExpr;
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY a.audit_date, a.id,
                       ' . $catLinkExpr . ',
                       COALESCE(c.sort_order, 0),
                       COALESCE(p.sort_order, 0),
                       r.id';

    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $filename = 'AuditRegisterReport_' . $fromDate . '_' . $toDate . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    // UTF-8 BOM for Excel
    fwrite($out, "\xEF\xBB\xBF");
    // Both category and parameter weightages get the explicit "Actual"/"Modified"
    // pair so consumers can never confuse one for the other. Parameter Type
    // and Max Value also surface from the snapshot — they're what the auditor
    // saw at the time, not the current master values.
    fputcsv($out, [
        'Audit Date','Audit Number','Status','Auditor','Store Manager','Present Store Executive','Approval Date','Approver',
        'Zone','Region','Cluster','Store','Audit Template',
        'Audit Category','Category Actual Weightage','Category Modified Weightage',
        'Audit Parameter','Parameter Type','Parameter Max Value',
        'Parameter Actual Weightage','Parameter Modified Weightage',
        'Value','Obtain Score','Weighted Obtain (Modified Wt × Obtain%)',
        'Total Audit Score','Remarks','Documents'
    ], escape: '');
    foreach ($rows as $r) {
        $obtPct = ($r['obtain_score'] !== null && $r['modified_weightage'] !== null)
                ? round((float)$r['modified_weightage'] * (float)$r['obtain_score'] / 100, 2) : '';
        fputcsv($out, [
            $r['audit_date'], $r['audit_number'], $r['status'], $r['auditor_name'] ?? '',
            $r['store_manager_name'] ?? '',
            $r['store_executive_name'] ?? '',
            $r['approved_at'] ?? '', $r['approver_name'] ?? '',
            '', '', '', // Zone / Region / Cluster — parity blanks
            $r['location_name'] ?? '', $r['template_name'] ?? '',
            $r['category_name'] ?? '',
            $r['cat_weightage'] ?? '',
            $r['cat_mod_weightage'] ?? '',
            $r['parameter_text'] ?? '',
            $r['parameter_type'] ?? '',
            $r['parameter_max_value'] ?? '',
            $r['actual_weightage'] ?? '',
            $r['modified_weightage'] ?? '',
            $r['value_entered'] ?? '', $r['obtain_score'] ?? '', $obtPct,
            $r['total_score'] ?? '', $r['auditor_remark'] ?? '', $r['doc_count'] ?? 0
        ], escape: '');
    }
    fclose($out);
    exit;
}
