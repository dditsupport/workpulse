<?php
// =========================================================
// Audit Module — templated store audits with approval workflow
// Roles: txn_audit_create (auditor), txn_audit_approve (approver),
//        txn_audit_admin (template masters), txn_audit_view (read-only across all stores)
// Location owners (employees.location_id === audit.location_id) see read-only for their store.
//
// This file holds constants, role/permission helpers, shared utilities
// (DB fetch, scoring math, scope filter, attachments, history). Pages and
// POST handlers live in three sibling files, pulled in at the bottom:
//   modules/audit_pages.php   — list/new/edit/view/approve + render helpers + JS
//   modules/audit_admin.php   — template/category/parameter admin pages + their handlers
//   modules/audit_actions.php — audit POST handlers, attachment download, CSV export
// =========================================================

define('AUDIT_UPLOAD_DIR', __DIR__ . '/../uploads/audit/');
define('AUDIT_MAX_FILE_SIZE', 5 * 1024 * 1024);
define('AUDIT_ALLOWED_EXT', ['jpg','jpeg','png','gif','webp','pdf']);
define('AUDIT_ALLOWED_MIME', [
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
    'gif'  => ['image/gif'],
    'webp' => ['image/webp'],
    'pdf'  => ['application/pdf'],
]);
const AUDIT_WEIGHT_TOLERANCE = 0.05; // rounding tolerance for weightage sums

// ── Role helpers ────────────────────────────────────────
function auditCanCreate(): bool             { return isSuperadmin() || hasTxn('audit_create'); }
function auditCanApprove(): bool            { return isSuperadmin() || hasTxn('audit_approve'); }
function auditCanOperationReview(): bool    { return isSuperadmin() || hasTxn('audit_operation'); }
function auditCanManagementReview(): bool   { return isSuperadmin() || hasTxn('audit_management'); }
function auditCanAdmin(): bool              { return isSuperadmin() || hasTxn('audit_admin'); }
function auditCanViewAll(): bool            { return isSuperadmin() || hasTxn('audit_view'); }

// Is the current user the assigned Store Manager on this audit row?
// Used to gate the new "Manager Review" step that sits between submit
// and approve — the SM adds justification comments before the approver
// gets the audit.
function auditIsStoreManager(array $a): bool {
    $code = myCode();
    return $code !== '' && (string)($a['store_manager_code'] ?? '') === $code;
}

// Can the current user act on the manager-review step for this audit?
//
// Strict path: the audit's named store_manager_code matches the user.
// Fallback: the user has a self-claim on the audit's location_id —
// covers the case where the named SM transferred/left and the
// store_manager_code on old audits is stale. Tightly scoped to the
// "submitted" status and the location, so a store's current
// self-claim manager can take over without an admin re-assigning
// every audit by hand.
function auditCanManagerReview(array $a): bool {
    if ($a['status'] !== 'submitted') return false;
    if (isSuperadmin()) return true;
    if (auditIsStoreManager($a)) return true;
    if (myLocationId() > 0 && (int)$a['location_id'] === myLocationId()) return true;
    return false;
}

// Can the current user see this particular audit row?
function auditCanViewRow(array $a): bool {
    if (isSuperadmin()) return true;
    if (auditCanViewAll() && $a['status'] !== 'draft') return true;
    if (auditCanApprove())          return true;
    if (auditCanOperationReview())  return true;
    if (auditCanManagementReview()) return true;
    if (auditCanAdmin())   return true;
    $code = myCode();
    if ($a['auditor_code'] === $code) return true;
    if (auditIsStoreManager($a) && $a['status'] !== 'draft') return true;
    if (myLocationId() > 0 && (int)$a['location_id'] === myLocationId() && $a['status'] !== 'draft') return true;
    return false;
}

function auditCanEditRow(array $a): bool {
    if (!auditCanCreate()) return false;
    if ($a['auditor_code'] !== myCode() && !isSuperadmin()) return false;
    return in_array($a['status'], ['draft','sent_back'], true);
}

// Can the current user pin / comment on attached images for this audit?
// Used by the audit attachment-annotations module (modules/audit_annotations.php)
// to gate the viewer. The default policy is permissive: anyone with any
// workflow role on the audit (auditor, approver, ops, management,
// assigned SM) can drop pins and comment — this matches the product brief
// ("annotation enabled for every post-submit reviewer").
function auditCanAnnotate(array $a): bool {
    if (isSuperadmin()) return true;
    if (auditCanCreate() || auditCanApprove()
        || auditCanOperationReview() || auditCanManagementReview()) return true;
    if (auditIsStoreManager($a)) return true;
    return false;
}

// Can the current user resolve / reopen pins on audit annotation images?
// Distinct from auditCanAnnotate above: resolving is a moderation action
// that closes the issue thread, so it sits on its own dedicated txn rather
// than being implicitly granted to every workflow actor. Recipients who
// don't hold this role can still address pins by COMMENTING (which clears
// the open-pin send-back gate); only marking a pin officially resolved
// requires this role.
function auditCanResolveAnnotation(): bool {
    return isSuperadmin() || hasTxn('audit_annotation_resolve');
}

// ── Status transition guard ─────────────────────────────
// Flow:
//   draft → submitted
//   submitted        → operation_review (SM forwards) | sent_back
//   operation_review → approver_review  (Ops forwards) | submitted (back to SM) | sent_back (back to auditor)
//   approver_review  → management_review (Approver forwards) | operation_review (back to Ops)
//   management_review → approved (Management final) | approver_review (back to Approver)
//   sent_back        → submitted
//   approved         → terminal
//   manager_review   → operation_review (legacy alias; in-flight rows are
//                      auto-migrated by the 2026-05-29 migration, but the
//                      transition is kept so any straggler can still move)
// On pre-2026-05-06 schemas (no manager-review columns at all) we still
// fall back to the original two-step path so the page keeps working.
function auditValidateTransition(string $from, string $to): bool {
    $strict = auditHasManagerReviewCols();
    $map = $strict ? [
        'draft'             => ['submitted'],
        'submitted'         => ['operation_review','sent_back'],
        'operation_review'  => ['approver_review','submitted','sent_back'],
        'approver_review'   => ['management_review','operation_review'],
        'management_review' => ['approved','approver_review'],
        'sent_back'         => ['submitted'],
        'approved'          => [],
        'manager_review'    => ['operation_review'],
    ] : [
        // Legacy fallback (un-migrated DB) — direct submit→approve path.
        'draft'          => ['submitted'],
        'submitted'      => ['approved','sent_back'],
        'sent_back'      => ['submitted'],
        'approved'       => [],
    ];
    return in_array($to, $map[$from] ?? [], true);
}

// ── Status label / badge ────────────────────────────────
function auditStatusBadge(string $s): string {
    $map = [
        'draft'             => ['Draft',                       'badge-grey'],
        'submitted'         => ['Pending SM Justify',          'badge-red'],
        // manager_review is a legacy alias for operation_review (the 2026-05-29
        // migration moves rows over; the badge keeps a sensible label in case
        // a stale row still appears anywhere).
        'manager_review'    => ['Pending Operation Review',    'badge-amber'],
        'operation_review'  => ['Pending Operation Review',    'badge-amber'],
        'approver_review'   => ['Pending Approval',            'badge-amber'],
        'management_review' => ['Pending Management Approval', 'badge-yellow'],
        'approved'          => ['Approved',                    'badge-green'],
        'sent_back'         => ['Sent Back',                   'badge-red'],
    ];
    [$lbl, $cls] = $map[$s] ?? [$s, 'badge-grey'];
    return '<span class="badge ' . $cls . '">' . h($lbl) . '</span>';
}

// ── Score colour bucket for the report webview ─────────
// Auditors (and viewers) want to see at a glance which questions are
// failing. Bucket the obtain score into red/orange/green so the same
// thresholds apply everywhere obtain_score is rendered.
//   < 50%      → red    (poor)
//   50%–<75%   → orange (needs attention)
//   ≥ 75%      → green  (good)
function auditScoreColor(?float $obtain): string {
    if ($obtain === null) return '';
    if ($obtain < 50)  return 'audit-score-red';
    if ($obtain < 75)  return 'audit-score-orange';
    return 'audit-score-green';
}

// ── Next audit number (8-digit zero-padded) ─────────────
function auditNextNumber(PDO $db): string {
    $n = (int)$db->query('SELECT COALESCE(MAX(CAST(audit_number AS UNSIGNED)), 0) + 1 FROM audits')->fetchColumn();
    if ($n < 1) $n = 1;
    return str_pad((string)$n, 8, '0', STR_PAD_LEFT);
}

// ── Obtain score conversion ─────────────────────────────
// Returns 0..100, or null when value is null/blank.
function auditComputeObtainScore(string $type, ?float $maxValue, ?float $value): ?float {
    if ($value === null) return null;
    switch ($type) {
        case 'rating':
            $v = max(0, min(5, $value));
            return round($v / 5 * 100, 2);
        case 'value':
            if ($maxValue === null || $maxValue <= 0) return null;
            $v = max(0, $value) / $maxValue;
            if ($v > 1) $v = 1;
            return round($v * 100, 2);
        case 'boolean':
            return $value > 0 ? 100.0 : 0.0;
    }
    return null;
}

// ── Recompute & cache total_score for an audit ──────────
function auditRecalcTotalScore(int $auditId): float {
    $db = getDb();
    // category modified weightages
    $cw = [];
    $st = $db->prepare('SELECT category_id, modified_weightage FROM audit_category_weights WHERE audit_id = ?');
    $st->execute([$auditId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cw[(int)$r['category_id']] = (float)$r['modified_weightage'];
    }
    // responses — when the snapshot column exists category_id is on the
    // response row directly, so we can survive a deleted master
    // parameter. When it doesn't (pre-migration DB) we use the master
    // join. Either way the calculation works.
    if (auditHasResponseSnapshotCols()) {
        $st = $db->prepare(
            'SELECT r.modified_weightage, r.obtain_score,
                    COALESCE(r.category_id, p.category_id) AS category_id
             FROM audit_responses r
             LEFT JOIN audit_parameters p ON p.id = r.parameter_id
             WHERE r.audit_id = ?');
    } else {
        $st = $db->prepare(
            'SELECT r.modified_weightage, r.obtain_score,
                    p.category_id
             FROM audit_responses r
             JOIN audit_parameters p ON p.id = r.parameter_id
             WHERE r.audit_id = ?');
    }
    $st->execute([$auditId]);
    $byCat = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cid = (int)$r['category_id'];
        if (!isset($byCat[$cid])) $byCat[$cid] = 0.0;
        $w = (float)$r['modified_weightage'];
        $o = $r['obtain_score'] === null ? 0.0 : (float)$r['obtain_score'];
        $byCat[$cid] += $w * $o / 100.0; // weighted_pct within category (0..100 if sums=100)
    }
    $total = 0.0;
    foreach ($byCat as $cid => $sum) {
        $catW = $cw[$cid] ?? 0.0;
        // sum is 0..100 (since within-cat param weights sum to 100) → multiply by catW / 100
        $total += $catW * $sum / 100.0;
    }
    $total = round($total, 2);
    $db->prepare('UPDATE audits SET total_score = ? WHERE id = ?')->execute([$total, $auditId]);
    return $total;
}

// ── Visibility scope builder for list/export queries ────
// Appends WHERE conditions + params. Returns void.
// All grants are OR'd together so a user with both txn_audit_view AND
// txn_audit_create still sees their own drafts (the view flag alone
// hides drafts across all stores, but a creator must always see theirs).
function auditApplyScope(array &$where, array &$params): void {
    if (isSuperadmin())  return; // sees all
    if (auditCanAdmin()) return; // admin sees all

    $conds = [];
    // View-all flag: every non-draft audit across all stores
    if (auditCanViewAll()) {
        $conds[] = "a.status <> 'draft'";
    }
    // Approver: every non-draft audit (submitted/approved/sent_back)
    if (auditCanApprove()) {
        $conds[] = "a.status <> 'draft'";
    }
    // Operation Team + Management see every non-draft audit so the cross-
    // store queue is visible on the audit list. The page-level handlers
    // gate the actual review action by status.
    if (auditCanOperationReview() || auditCanManagementReview()) {
        $conds[] = "a.status <> 'draft'";
    }
    // Creator: their own audits in any state — drafts, submitted, etc.
    if (auditCanCreate()) {
        $conds[] = "a.auditor_code = ?";
        $params[] = myCode();
    }
    // Store Manager: every non-draft audit assigned to them — they need
    // to see "my reviews" even if they don't carry any audit_* txn.
    $myCode = myCode();
    if ($myCode !== '') {
        $conds[] = "(a.store_manager_code = ? AND a.status <> 'draft')";
        $params[] = $myCode;
    }
    // Location owner: non-draft audits for their own store
    $locId = myLocationId();
    if ($locId > 0) {
        $conds[] = "(a.location_id = ? AND a.status <> 'draft')";
        $params[] = $locId;
    }
    if (!$conds) {
        // No grant applies — block everything
        $where[] = "1=0";
    } else {
        // Dedupe identical expressions (e.g. view+approve both produce status<>draft)
        $conds = array_values(array_unique($conds));
        $where[] = '(' . implode(' OR ', $conds) . ')';
    }
}

// ── Fetch helpers ────────────────────────────────────────
function auditGetById(int $id): ?array {
    $st = getDb()->prepare(
        'SELECT a.*, t.name AS template_name, l.location_name,
                ae.full_name AS auditor_name, sm.full_name AS store_manager_name,
                se.full_name AS store_executive_name,
                ap.full_name AS approver_name
         FROM audits a
         LEFT JOIN audit_templates t ON t.id = a.template_id
         LEFT JOIN locations l ON l.location_id = a.location_id
         LEFT JOIN employees ae ON ae.employee_code = a.auditor_code
         LEFT JOIN employees sm ON sm.employee_code = a.store_manager_code
         LEFT JOIN employees se ON se.employee_code = a.store_executive_code
         LEFT JOIN employees ap ON ap.employee_code = a.approver_code
         WHERE a.id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function auditGetTemplates(bool $onlyActive = true): array {
    $sql = 'SELECT id, name, is_active FROM audit_templates';
    if ($onlyActive) $sql .= ' WHERE is_active = 1';
    $sql .= ' ORDER BY name';
    return getDb()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function auditGetCategories(int $templateId = 0): array {
    if ($templateId > 0) {
        $st = getDb()->prepare(
            'SELECT c.*, t.name AS template_name
             FROM audit_categories c
             JOIN audit_templates t ON t.id = c.template_id
             WHERE c.template_id = ? ORDER BY c.sort_order, c.name');
        $st->execute([$templateId]);
    } else {
        $st = getDb()->query(
            'SELECT c.*, t.name AS template_name
             FROM audit_categories c
             JOIN audit_templates t ON t.id = c.template_id
             ORDER BY t.name, c.sort_order, c.name');
    }
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function auditGetParameters(int $categoryId = 0): array {
    if ($categoryId > 0) {
        $st = getDb()->prepare(
            'SELECT p.*, c.name AS category_name, c.template_id, t.name AS template_name
             FROM audit_parameters p
             JOIN audit_categories c ON c.id = p.category_id
             JOIN audit_templates  t ON t.id = c.template_id
             WHERE p.category_id = ? ORDER BY p.sort_order, p.id');
        $st->execute([$categoryId]);
    } else {
        $st = getDb()->query(
            'SELECT p.*, c.name AS category_name, c.template_id, t.name AS template_name
             FROM audit_parameters p
             JOIN audit_categories c ON c.id = p.category_id
             JOIN audit_templates  t ON t.id = c.template_id
             ORDER BY t.name, c.sort_order, p.sort_order');
    }
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// Full tree for audit edit/view: categories with their parameters + response row.
//
// Historical fidelity: every audit_response and audit_category_weight row
// now carries a snapshot of the parameter/category text + numeric fields
// at audit-creation time. We prefer those snapshots over the live master
// values whenever they're populated, so the page (and any downstream
// report) shows what the auditor actually answered, not what the template
// looks like today. Live master rows are only used as a fallback for
// pre-snapshot legacy data and to drive ordering/structure.
function auditGetTree(int $auditId, int $templateId): array {
    $db = getDb();
    $cats = auditGetCategories($templateId);
    $catIds = array_column($cats, 'id');

    // category weight rows (full snapshot)
    $cw = [];
    $st = $db->prepare(
        'SELECT audit_id, category_id, actual_weightage, category_name, modified_weightage
         FROM audit_category_weights WHERE audit_id = ?'
    );
    $st->execute([$auditId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cw[(int)$r['category_id']] = $r;
    }

    // parameters (live master — provides ordering and the universe of
    // questions to render; snapshot text from the response overrides the
    // wording when present)
    $params = [];
    if ($catIds) {
        $ph = implode(',', array_fill(0, count($catIds), '?'));
        $st = $db->prepare(
            "SELECT id, category_id, parameter_text, type, max_value, score_weightage, sort_order, is_active
             FROM audit_parameters WHERE category_id IN ({$ph}) ORDER BY category_id, sort_order, id"
        );
        $st->execute($catIds);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) $params[] = $p;
    }
    $paramIds = array_column($params, 'id');

    // responses (carry the snapshot fields used during render)
    $respCols = 'id, audit_id, parameter_id, category_id, category_name,
                 parameter_text, parameter_type, parameter_max_value,
                 value_entered, obtain_score, actual_weightage, modified_weightage,
                 auditor_remark, approver_remark, store_manager_remark';
    if (auditHasFiveStageCols()) {
        $respCols .= ', operation_remark, management_remark';
    }
    $resp = [];
    if ($paramIds) {
        $ph = implode(',', array_fill(0, count($paramIds), '?'));
        $st = $db->prepare("SELECT {$respCols} FROM audit_responses WHERE audit_id = ? AND parameter_id IN ({$ph})");
        $st->execute(array_merge([$auditId], $paramIds));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $resp[(int)$r['parameter_id']] = $r;
    }

    // Surface any orphan responses — rows whose parameter has been deleted
    // from the master since the audit was filed. Without this they'd
    // silently disappear from the page; with it the snapshot still
    // renders so the historical report stays complete.
    if ($paramIds) {
        $ph = implode(',', array_fill(0, count($paramIds), '?'));
        $orph = $db->prepare("SELECT {$respCols} FROM audit_responses WHERE audit_id = ? AND parameter_id NOT IN ({$ph})");
        $orph->execute(array_merge([$auditId], $paramIds));
    } else {
        $orph = $db->prepare("SELECT {$respCols} FROM audit_responses WHERE audit_id = ?");
        $orph->execute([$auditId]);
    }
    $orphans = []; // category_id => [response, …]
    foreach ($orph->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $orphans[(int)($r['category_id'] ?? 0)][] = $r;
        $resp[(int)$r['parameter_id']] = $r;
    }

    // attachments per response
    $atts = [];
    if ($resp) {
        $respIds = array_column($resp, 'id');
        $ph = implode(',', array_fill(0, count($respIds), '?'));
        $st = $db->prepare(
            "SELECT id, response_id, filename, stored_name, mime_type, file_size, uploaded_by, uploaded_at
             FROM audit_response_attachments WHERE response_id IN ({$ph}) ORDER BY uploaded_at"
        );
        $st->execute($respIds);
        $allAtts = $st->fetchAll(PDO::FETCH_ASSOC);

        // Decorate each attachment with its pin counts so the UI can flag
        // annotated images at a glance. One batch query keyed by
        // attachment_id; fails OPEN (zero counts) on pre-2026-05-29 DBs
        // where the audit_image_pins table doesn't exist yet.
        $pinCounts = [];
        if ($allAtts) {
            $attIds = array_column($allAtts, 'id');
            $phA = implode(',', array_fill(0, count($attIds), '?'));
            try {
                $pst = $db->prepare(
                    "SELECT attachment_id,
                            SUM(CASE WHEN status='open' THEN 1 ELSE 0 END) AS pins_open,
                            COUNT(*) AS pins_total
                     FROM   audit_image_pins
                     WHERE  attachment_id IN ({$phA})
                     GROUP  BY attachment_id"
                );
                $pst->execute($attIds);
                foreach ($pst->fetchAll(PDO::FETCH_ASSOC) as $pcRow) {
                    $pinCounts[(int)$pcRow['attachment_id']] = $pcRow;
                }
            } catch (Exception $e) { /* pre-migration DB — leave empty */ }
        }

        foreach ($allAtts as $a) {
            $pc = $pinCounts[(int)$a['id']] ?? null;
            $a['pins_open']  = $pc ? (int)$pc['pins_open']  : 0;
            $a['pins_total'] = $pc ? (int)$pc['pins_total'] : 0;
            $rid = (int)$a['response_id'];
            if (!isset($atts[$rid])) $atts[$rid] = [];
            $atts[$rid][] = $a;
        }
    }

    $tree = [];
    foreach ($cats as $c) {
        $cwRow = $cw[(int)$c['id']] ?? null;
        // Prefer snapshot name/weightage when present
        if ($cwRow) {
            if (!empty($cwRow['category_name'])) $c['name'] = $cwRow['category_name'];
            if (isset($cwRow['actual_weightage']) && (float)$cwRow['actual_weightage'] > 0) {
                $c['weightage'] = (float)$cwRow['actual_weightage'];
            }
            $c['modified_weightage'] = (float)$cwRow['modified_weightage'];
        } else {
            $c['modified_weightage'] = (float)$c['weightage'];
        }
        $c['parameters'] = [];
        foreach ($params as $p) {
            if ((int)$p['category_id'] !== (int)$c['id']) continue;
            $r = $resp[(int)$p['id']] ?? null;
            // Snapshot fields from the response take priority — they're
            // what the auditor saw and answered.
            if ($r) {
                if (!empty($r['parameter_text']))                 $p['parameter_text']  = $r['parameter_text'];
                if (!empty($r['parameter_type']))                 $p['type']            = $r['parameter_type'];
                if (array_key_exists('parameter_max_value', $r))  $p['max_value']       = $r['parameter_max_value'];
                if (isset($r['actual_weightage']) && (float)$r['actual_weightage'] > 0) {
                    $p['score_weightage'] = (float)$r['actual_weightage'];
                }
            }
            $p['response']    = $r;
            $p['attachments'] = $r ? ($atts[(int)$r['id']] ?? []) : [];
            $c['parameters'][] = $p;
        }
        // Append orphans that belong to this category (parameter deleted
        // but response retained for the historical record).
        foreach (($orphans[(int)$c['id']] ?? []) as $r) {
            $c['parameters'][] = [
                'id'              => (int)$r['parameter_id'],
                'category_id'     => (int)$c['id'],
                'parameter_text'  => (string)($r['parameter_text'] ?? '(deleted parameter)'),
                'type'            => (string)($r['parameter_type'] ?? 'rating'),
                'max_value'       => $r['parameter_max_value'] ?? null,
                'score_weightage' => (float)($r['actual_weightage'] ?? 0),
                'sort_order'      => 9999,
                'is_active'       => 0,
                'response'        => $r,
                'attachments'     => $atts[(int)$r['id']] ?? [],
                'is_orphan'       => true,
            ];
        }
        $tree[] = $c;
    }
    return $tree;
}

// ── Attachment storage layout ──────────────────────────
// New layout: uploads/audit/{template_id}/{YYYY-MM}/{audit_id}/
//   - template_id (numeric, never changes) → top-level bucket so each
//     audit template owns its own subtree (browseable per template).
//   - YYYY-MM derived from audits.audit_date → second-level bucket so a
//     month's worth of work groups together for archival / trimming.
//   - audit_id keeps individual audits' files isolated inside the bucket.
// Legacy layout was uploads/audit/{audit_id}/ — kept as a read-only
// fallback so files written before this change still resolve on
// download / annotation / delete. New writes always use the new layout.
function auditAttachmentDir(array $auditRow): string {
    $templateId = (int)($auditRow['template_id'] ?? 0);
    $auditId    = (int)($auditRow['id'] ?? 0);
    $month      = preg_match('/^(\d{4}-\d{2})/', (string)($auditRow['audit_date'] ?? ''), $m)
        ? $m[1]
        : date('Y-m');
    return AUDIT_UPLOAD_DIR . $templateId . '/' . $month . '/' . $auditId . '/';
}

function auditAttachmentDirLegacy(int $auditId): string {
    return AUDIT_UPLOAD_DIR . $auditId . '/';
}

// Resolve an attachment's on-disk path, trying the new bucketed layout
// first and falling back to legacy. Returns null when neither exists.
// $auditRow may be null — we'll fetch it ourselves so the legacy fallback
// still works even for pre-migration files where the new path can't be
// computed without a DB row.
function auditAttachmentPath(int $auditId, ?array $auditRow, string $storedName): ?string {
    if ($auditRow === null) $auditRow = auditGetById($auditId);
    if ($auditRow) {
        $primary = auditAttachmentDir($auditRow) . $storedName;
        if (file_exists($primary)) return $primary;
    }
    $legacy = auditAttachmentDirLegacy($auditId) . $storedName;
    if (file_exists($legacy)) return $legacy;
    return null;
}

// ── File upload helper ─────────────────────────────────
function auditSaveAttachments(int $auditId, int $responseId, string $uploaderCode): void {
    if (empty($_FILES['attachments']['name'][0])) return;
    $auditRow = auditGetById($auditId);
    if (!$auditRow) return; // can't bucket without template + date
    $dir = auditAttachmentDir($auditRow);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $db = getDb();
    $st = $db->prepare(
        'INSERT INTO audit_response_attachments
          (response_id, filename, stored_name, mime_type, file_size, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?)');
    $files = $_FILES['attachments'];
    for ($i = 0; $i < count($files['name']); $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
        if ($files['size'][$i] > AUDIT_MAX_FILE_SIZE) continue;
        $origName = basename($files['name'][$i]);
        $ext = mb_strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, AUDIT_ALLOWED_EXT, true)) continue;
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($files['tmp_name'][$i]);
        $ok = AUDIT_ALLOWED_MIME[$ext] ?? [];
        if (!in_array($mime, $ok, true)) continue;
        $storedName = uniqid('aud_', true) . '.' . $ext;
        if (move_uploaded_file($files['tmp_name'][$i], $dir . $storedName)) {
            $st->execute([$responseId, $origName, $storedName, $mime, (int)$files['size'][$i], $uploaderCode]);
        }
    }
}

// ── History row ────────────────────────────────────────
function auditAddHistory(int $auditId, string $action, string $byCode, ?string $remark = null): void {
    $st = getDb()->prepare('INSERT INTO audit_history (audit_id, action, by_code, remark) VALUES (?, ?, ?, ?)');
    $st->execute([$auditId, $action, $byCode, $remark]);
}

// ── View tracking ──────────────────────────────────────
// Records every open of the Audit View page and every attachment
// download. Schema lives in migration_2026_06_06_audit_view_logs.sql;
// fail-silent on missing-table so the page keeps working before the
// migration is applied.
function auditLogView(int $auditId, string $viewType = 'page', ?int $attachmentId = null): void {
    if ($auditId < 1) return;
    $code = myCode();
    if ($code === '') return;
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    if ($ip !== null) $ip = substr(trim(explode(',', (string)$ip)[0]), 0, 45);
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 500) : null;
    try {
        $st = getDb()->prepare(
            'INSERT INTO audit_view_logs (audit_id, employee_code, view_type, attachment_id, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?)');
        $st->execute([$auditId, $code, $viewType, $attachmentId, $ip, $ua]);
    } catch (Exception $e) {
        // Migration not yet run — swallow so the page still renders.
    }
}

// Most-recent N view+download events for an audit, joined to employees
// and (when present) audit_response_attachments to label which file was
// opened. Returns an empty array if the table doesn't exist yet.
function auditGetViewLog(int $auditId, int $limit = 100): array {
    if ($auditId < 1) return [];
    try {
        $st = getDb()->prepare(
            'SELECT v.id, v.employee_code, v.view_type, v.attachment_id,
                    v.ip_address, v.user_agent, v.viewed_at,
                    e.full_name AS viewer_name,
                    aa.filename AS attachment_name
             FROM audit_view_logs v
             LEFT JOIN employees e ON e.employee_code = v.employee_code
             LEFT JOIN audit_response_attachments aa ON aa.id = v.attachment_id
             WHERE v.audit_id = ?
             ORDER BY v.viewed_at DESC, v.id DESC
             LIMIT ' . (int)$limit);
        $st->execute([$auditId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

// ── Snapshot-column detection ──────────────────────────
// migration_2026_04_27_audit_response_snapshots.sql adds parameter/category
// snapshot columns to audit_responses + audit_category_weights. We detect
// once per request whether the migration has been applied so the audit
// flow keeps working on databases that still need to run it. Without
// this, every reference to the new columns would throw and silently roll
// back saves (taking the user's typed values + uploaded images with it).
function auditHasResponseSnapshotCols(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        getDb()->query('SELECT parameter_type FROM audit_responses LIMIT 0')->fetch();
        $cached = true;
    } catch (Exception $e) {
        $cached = false;
    }
    return $cached;
}
function auditHasCwSnapshotCols(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        getDb()->query('SELECT actual_weightage FROM audit_category_weights LIMIT 0')->fetch();
        $cached = true;
    } catch (Exception $e) {
        $cached = false;
    }
    return $cached;
}

// Did the 2026-05-06 manager-review migration run? Gates the SM review
// page and the store_manager_remark column on responses. Without it we
// fall back to the original two-step approve flow.
function auditHasManagerReviewCols(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        getDb()->query('SELECT store_manager_remark FROM audit_responses LIMIT 0')->fetch();
        $cached = true;
    } catch (Exception $e) {
        $cached = false;
    }
    return $cached;
}

// Did the 2026-05-29 five-stage migration run? Gates operation_remark +
// management_remark on audit_responses and the matching role stamps on
// audits. Without it auditGetTree silently omits the new remark columns
// from its SELECT so the page keeps loading on un-migrated installs.
function auditHasFiveStageCols(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        getDb()->query('SELECT operation_remark FROM audit_responses LIMIT 0')->fetch();
        $cached = true;
    } catch (Exception $e) {
        $cached = false;
    }
    return $cached;
}

// ── Per-parameter response history (for the "history" icon next
// to each question in the audit edit/view page). Returns saved
// audit responses for the same parameter across past audits,
// newest first. Excludes the current audit so the popup shows
// "what was said before" rather than the row being edited now.
// Pass $locationId > 0 to scope history to one store — auditors care
// about how the same store has been trending on a question, not how
// other stores have answered it.
function auditGetParameterHistory(int $paramId, int $excludeAuditId = 0, int $locationId = 0, int $limit = 50): array {
    // Return snapshot fields too when available — they let the modal
    // surface the wording asked at the time of each historical audit
    // even if the master template has been edited since.
    $snapCols = auditHasResponseSnapshotCols()
        ? ', r.parameter_text AS hist_parameter_text,
             r.parameter_type AS hist_parameter_type,
             r.category_name  AS hist_category_name'
        : '';
    $sql = 'SELECT a.id AS audit_id, a.audit_number, a.audit_date, a.status,
                   a.location_id,
                   l.location_name,
                   sm.full_name AS store_manager_name,
                   ae.full_name AS auditor_name,
                   r.actual_weightage, r.modified_weightage,
                   r.value_entered, r.obtain_score,
                   r.auditor_remark, r.approver_remark
                   ' . $snapCols . '
            FROM audit_responses r
            JOIN audits a       ON a.id = r.audit_id
            LEFT JOIN locations l ON l.location_id = a.location_id
            LEFT JOIN employees sm ON sm.employee_code = a.store_manager_code
            LEFT JOIN employees ae ON ae.employee_code = a.auditor_code
            WHERE r.parameter_id = ?
              AND a.audit_number IS NOT NULL';
    $params = [$paramId];
    if ($excludeAuditId > 0) { $sql .= ' AND a.id <> ?';          $params[] = $excludeAuditId; }
    if ($locationId    > 0) { $sql .= ' AND a.location_id = ?';   $params[] = $locationId; }
    $sql .= ' ORDER BY a.audit_date DESC, a.id DESC LIMIT ' . max(1, (int)$limit);
    $st = getDb()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// ── Send-back agenda gate ─────────────────────────────
// Returns the list of open pins on this audit's attached images that the
// given actor still has to address before the audit can move forward.
// Definition: pin status='open' AND the pin was created by someone else
// AND the actor has not posted any comment on the pin yet. The pin row's
// own author column is excluded from the agenda — reviewers don't have to
// "reply to themselves" before forwarding. A reply counts as addressing
// the pin; resolving it works too (the row drops out of the 'open' set).
// Wraps the query in a try/catch so the gate fails OPEN on a pre-2026-05-29
// database (tables missing) — those installs can't have annotations on
// audit images anyway, so the result is naturally empty.
function auditOpenPinsBlockingFor(int $auditId, string $actorCode): array {
    if ($auditId < 1 || $actorCode === '') return [];
    try {
        $st = getDb()->prepare(
            "SELECT p.id            AS pin_id,
                    p.pin_number,
                    p.created_by,
                    ec.full_name    AS creator_name,
                    aa.id           AS audit_attachment_id,
                    aa.filename     AS original_name
             FROM   audit_image_pins p
             JOIN   audit_response_attachments aa ON aa.id = p.attachment_id
             JOIN   audit_responses             r ON r.id  = aa.response_id
             LEFT JOIN employees ec               ON ec.employee_code = p.created_by
             WHERE  r.audit_id    = ?
               AND  p.status      = 'open'
               AND  p.created_by <> ?
               AND  NOT EXISTS (
                       SELECT 1 FROM audit_image_pin_comments c
                       WHERE  c.pin_id        = p.id
                         AND  c.employee_code = ?)
             ORDER BY aa.filename, p.pin_number"
        );
        $st->execute([$auditId, $actorCode, $actorCode]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

// ── Load the rest of the module ────────────────────────
require_once __DIR__ . '/audit_pages.php';
require_once __DIR__ . '/audit_admin.php';
require_once __DIR__ . '/audit_actions.php';
require_once __DIR__ . '/audit_annotations.php';
