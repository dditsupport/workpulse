<?php
// =========================================================
// Dashboard — landing page with stat cards + "Pending For You"
// widget that aggregates actionable items from every module.
//
// Each module's "what's pending for this user" query lives in a
// pendingForMe_* function below. pageDashboard() collects them all
// and renders the unified widget; pageMyPending() shows the full
// paginated list.
// =========================================================

// ── Inline SVG icons (cross-cutting design rule: no emojis) ──
function dbIcon(string $name): string {
    $svgs = [
        'inbox'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>',
        'issue'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8"  x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
        'audit'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
        'punch'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        'rupee'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12"/><path d="M6 8h12"/><path d="M6 13l8.5 8"/><path d="M6 13h3a4.5 4.5 0 1 0 0-9"/></svg>',
        'check'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
    ];
    return $svgs[$name] ?? '';
}

// ── Providers — one per module ───────────────────────────
// Each returns rows of:
//   ['source'=>'Issue', 'icon'=>'<svg>', 'title'=>'...', 'url'=>'?page=...', 'created_at'=>'YYYY-MM-DD HH:MM:SS']

function pendingForMe_issues(string $empCode, int $deptId): array {
    if (!function_exists('getDb')) return [];
    $rows = [];
    try {
        // 1. Issues I reported, now waiting for my reply.
        $st = getDb()->prepare(
            "SELECT id, summary, created_at
             FROM   issues
             WHERE  reporter_code = ? AND status = 'waiting_for_customer'
             ORDER  BY created_at DESC
             LIMIT  20"
        );
        $st->execute([$empCode]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                'source'     => 'Issue · awaiting your reply',
                'icon'       => dbIcon('issue'),
                'title'      => $r['summary'],
                'url'        => '?page=view_issue&id=' . (int)$r['id'],
                'created_at' => $r['created_at'],
            ];
        }
        // 2. Issues assigned to my department, still open.
        if ($deptId > 0) {
            $st = getDb()->prepare(
                "SELECT i.id, i.summary, i.created_at, i.status
                 FROM   issues i
                 JOIN   issue_participants p ON p.issue_id = i.id
                 WHERE  p.department_id = ?
                   AND  i.status IN ('assigned_to_concerned','in_progress')
                 GROUP  BY i.id
                 ORDER  BY i.created_at DESC
                 LIMIT  20"
            );
            $st->execute([$deptId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $label = $r['status'] === 'in_progress' ? 'in progress' : 'assigned';
                $rows[] = [
                    'source'     => 'Issue · ' . $label,
                    'icon'       => dbIcon('issue'),
                    'title'      => $r['summary'],
                    'url'        => '?page=view_issue&id=' . (int)$r['id'],
                    'created_at' => $r['created_at'],
                ];
            }
        }
    } catch (Exception $e) { /* table may be missing on legacy installs */ }
    return $rows;
}

function pendingForMe_audits(string $empCode): array {
    $rows = [];
    try {
        // 1. Audits I created that were sent back.
        $st = getDb()->prepare(
            "SELECT a.id, a.audit_number, a.created_at, l.location_name
             FROM   audits a
             LEFT JOIN locations l ON l.location_id = a.location_id
             WHERE  a.auditor_code = ? AND a.status = 'sent_back'
             ORDER  BY a.created_at DESC LIMIT 20"
        );
        $st->execute([$empCode]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                'source'     => 'Audit · sent back',
                'icon'       => dbIcon('audit'),
                'title'      => ($r['audit_number'] ?: ('#' . (int)$r['id'])) . ' — ' . ($r['location_name'] ?: ''),
                'url'        => '?page=audit_edit&id=' . (int)$r['id'],
                'created_at' => $r['created_at'],
            ];
        }
        // 2. Audits awaiting my manager review (I'm the store manager).
        $st = getDb()->prepare(
            "SELECT a.id, a.audit_number, a.submitted_at, l.location_name
             FROM   audits a
             LEFT JOIN locations l ON l.location_id = a.location_id
             WHERE  a.store_manager_code = ? AND a.status = 'manager_review'
             ORDER  BY a.submitted_at DESC LIMIT 20"
        );
        $st->execute([$empCode]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                'source'     => 'Audit · manager review',
                'icon'       => dbIcon('audit'),
                'title'      => ($r['audit_number'] ?: ('#' . (int)$r['id'])) . ' — ' . ($r['location_name'] ?: ''),
                'url'        => '?page=audit_manager_review&id=' . (int)$r['id'],
                'created_at' => $r['submitted_at'],
            ];
        }
        // 3. Audits awaiting my approval.
        $st = getDb()->prepare(
            "SELECT a.id, a.audit_number, a.submitted_at, l.location_name
             FROM   audits a
             LEFT JOIN locations l ON l.location_id = a.location_id
             WHERE  a.approver_code = ? AND a.status IN ('submitted','manager_review')
             ORDER  BY a.submitted_at DESC LIMIT 20"
        );
        $st->execute([$empCode]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                'source'     => 'Audit · awaiting approval',
                'icon'       => dbIcon('audit'),
                'title'      => ($r['audit_number'] ?: ('#' . (int)$r['id'])) . ' — ' . ($r['location_name'] ?: ''),
                'url'        => '?page=audit_approve&id=' . (int)$r['id'],
                'created_at' => $r['submitted_at'],
            ];
        }
    } catch (Exception $e) { /* table may be missing */ }
    return $rows;
}

function pendingForMe_punchRequests(): array {
    if (!isSuperadmin() && !hasTxn('approve_punches')) return [];
    $rows = [];
    try {
        $st = getDb()->query(
            "SELECT pr.id, pr.employee_code, pr.punch_date, pr.created_at,
                    e.full_name
             FROM   punch_requests pr
             LEFT JOIN employees e ON e.employee_code = pr.employee_code
             WHERE  pr.status = 'pending'
             ORDER  BY pr.created_at DESC LIMIT 30"
        );
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                'source'     => 'Punch request',
                'icon'       => dbIcon('punch'),
                'title'      => ($r['full_name'] ?: $r['employee_code']) . ' · ' . $r['punch_date'],
                'url'        => '?page=approve_punches',
                'created_at' => $r['created_at'],
            ];
        }
    } catch (Exception $e) { }
    return $rows;
}

function pendingForMe_priceVariations(): array {
    $canConfirm = isSuperadmin() || hasTxn('price_variation_confirm') || hasTxn('price_variation_admin');
    $canAdmin   = isSuperadmin() || hasTxn('price_variation_admin');
    if (!$canConfirm && !$canAdmin) return [];

    $rows = [];
    try {
        // Awaiting confirmation
        if ($canConfirm) {
            $st = getDb()->query(
                "SELECT id, location_name, partner, order_id, submitted_at
                 FROM   price_variations
                 WHERE  status = 'pending'
                 ORDER  BY submitted_at DESC LIMIT 20"
            );
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[] = [
                    'source'     => 'Price variation · awaiting confirmation',
                    'icon'       => dbIcon('rupee'),
                    'title'      => $r['location_name'] . ' · ' . ucfirst($r['partner']) . ' · ' . $r['order_id'],
                    'url'        => '?page=price_variation_detail&id=' . (int)$r['id'],
                    'created_at' => $r['submitted_at'],
                ];
            }
        }
        // Confirmed → awaiting admin approval
        if ($canAdmin) {
            $st = getDb()->query(
                "SELECT id, location_name, partner, order_id, submitted_at
                 FROM   price_variations
                 WHERE  status = 'confirmed'
                 ORDER  BY submitted_at DESC LIMIT 20"
            );
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[] = [
                    'source'     => 'Price variation · awaiting approval',
                    'icon'       => dbIcon('rupee'),
                    'title'      => $r['location_name'] . ' · ' . ucfirst($r['partner']) . ' · ' . $r['order_id'],
                    'url'        => '?page=price_variation_detail&id=' . (int)$r['id'],
                    'created_at' => $r['submitted_at'],
                ];
            }
        }
    } catch (Exception $e) { }
    return $rows;
}

function pendingForMe_checklist(int $locId): array {
    if ($locId <= 0) return [];
    // Only surface the checklist reminder to users who actually hold the
    // "Store Checklist" role (txn_checklist) — same gate the sidebar uses
    // to show the Store Checklist page. Superadmin passes via hasTxn().
    if (!hasTxn('checklist')) return [];
    $rows = [];
    try {
        $today = date('Y-m-d');
        $totalQ = (int)getDb()->query("SELECT COUNT(*) FROM chk_items WHERE is_active = 1")->fetchColumn();
        if ($totalQ === 0) return [];
        $st = getDb()->prepare(
            "SELECT COUNT(*) FROM chk_daily_responses WHERE location_id = ? AND log_date = ?"
        );
        $st->execute([$locId, $today]);
        $done = (int)$st->fetchColumn();
        if ($done < $totalQ) {
            $rows[] = [
                'source'     => 'Checklist · today',
                'icon'       => dbIcon('check'),
                'title'      => "Today's checklist incomplete ({$done}/{$totalQ})",
                'url'        => '?page=checklist',
                // Anchor to start of today so age sorts naturally
                'created_at' => $today . ' 00:00:00',
            ];
        }
    } catch (Exception $e) { }
    return $rows;
}

// ── Aggregator ───────────────────────────────────────────
function collectPendingForMe(): array {
    $empCode = myCode();
    $deptId  = myDeptId();
    $locId   = myLocationId();
    if ($empCode === '') return [];

    $items = array_merge(
        pendingForMe_issues($empCode, $deptId),
        pendingForMe_audits($empCode),
        pendingForMe_punchRequests(),
        pendingForMe_priceVariations(),
        pendingForMe_checklist($locId)
    );

    // Sort newest-first by created_at
    usort($items, function ($a, $b) {
        return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
    });
    return $items;
}

// ── Render helper for the table body ─────────────────────
function renderPendingTable(array $items, int $cap = 0): void {
    $rows = $cap > 0 ? array_slice($items, 0, $cap) : $items;
?>
<div class="table-wrap" data-stack>
    <table class="table">
        <thead>
            <tr>
                <th style="width:220px">Source</th>
                <th>Item</th>
                <th style="width:70px">Age</th>
                <th style="width:80px"></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $it): ?>
            <tr>
                <td style="white-space:nowrap;color:var(--muted);font-size:12px">
                    <span style="display:inline-flex;align-items:center;gap:6px"><?= $it['icon'] ?> <?= h($it['source']) ?></span>
                </td>
                <td><?= h($it['title']) ?></td>
                <td class="text-muted" style="font-size:11px"><?= h(relAge($it['created_at'])) ?></td>
                <td><a href="<?= h($it['url']) ?>" class="btn btn-sm btn-secondary">Open</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php
}

// ── Page: Dashboard ──────────────────────────────────────
function pageDashboard(): void {
    $items = collectPendingForMe();
    $hasItems = !empty($items);
    $cap = 10;
?>
<div class="page-header"><h2>Dashboard</h2></div>

<div class="form-card" style="max-width:none;margin-bottom:18px;<?= $hasItems ? 'border-left:3px solid var(--accent)' : '' ?>">
    <div class="form-section-title" style="margin-top:0;display:flex;align-items:center;gap:8px">
        <?= dbIcon('inbox') ?>
        Pending For You
        <span class="badge <?= $hasItems ? 'badge-blue' : 'badge-grey' ?>" style="margin-left:4px"><?= count($items) ?></span>
        <?php if (count($items) > $cap): ?>
            <a href="?page=my_pending" style="margin-left:auto;font-size:12px;color:var(--accent);text-decoration:none">Show all →</a>
        <?php endif; ?>
    </div>
    <?php if (!$hasItems): ?>
        <p class="text-muted" style="font-size:13px;margin:6px 0 0">All caught up — nothing requires your action.</p>
    <?php else: ?>
        <?php renderPendingTable($items, $cap); ?>
    <?php endif; ?>
</div>

<?php
}

// ── Page: My Pending (full list) ─────────────────────────
function pageMyPending(): void {
    $items = collectPendingForMe();
?>
<div class="page-header" style="display:flex;align-items:center;gap:10px">
    <?= dbIcon('inbox') ?>
    <h2 style="margin:0">Pending For You</h2>
    <span class="badge badge-blue"><?= count($items) ?></span>
    <a href="?page=dashboard" class="btn btn-sm btn-ghost" style="margin-left:auto">← Dashboard</a>
</div>

<?php if (empty($items)): ?>
    <div class="rpt-prompt">All caught up — nothing requires your action.</div>
<?php else: ?>
    <?php renderPendingTable($items); ?>
<?php endif; ?>
<?php
}
