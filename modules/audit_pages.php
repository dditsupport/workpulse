<?php
// =========================================================
// Audit Module — auditor-facing pages & render helpers
// Loaded by modules/audit.php.
// =========================================================

// ===========================================================
// PAGE: Audit list
// ===========================================================
function pageAuditList(): void {
    $db = getDb();
    $viewClicked = !empty($_GET['view']);
    // Default range: 1st of current month → today. Empty inputs (e.g., user
    // cleared and clicked View) fall back to the same defaults so the KPI
    // cards and the table always describe the same window.
    $fromDate = trim($_GET['from_date'] ?? '');
    $toDate   = trim($_GET['to_date']   ?? '');
    if ($fromDate === '') $fromDate = date('Y-m-01');
    if ($toDate   === '') $toDate   = date('Y-m-d');
    $rows = [];
    $pendingApprove = 0;
    $mySentBack = 0;
    $myJustifyPending = 0;
    $myCount = 0;
    $myAvg = 0.0;

    if ($viewClicked) {
        $where  = [];
        $params = [];
        auditApplyScope($where, $params);

        if (!empty($_GET['status']))      { $where[] = 'a.status = ?'; $params[] = $_GET['status']; }
        if (!empty($_GET['template_id'])) { $where[] = 'a.template_id = ?'; $params[] = (int)$_GET['template_id']; }
        if (!empty($_GET['location_id'])) { $where[] = 'a.location_id = ?'; $params[] = (int)$_GET['location_id']; }
        $where[] = 'a.audit_date >= ?'; $params[] = $fromDate;
        $where[] = 'a.audit_date <= ?'; $params[] = $toDate;
        // Hide unsaved drafts (rows the user created but never clicked Save on).
        // audit_number stays NULL until the first successful save, so we filter
        // those out everywhere — the list now only shows audits the user has
        // committed to. Abandoned shells remain in the DB but are invisible
        // here, and superadmins can still clean them up via direct SQL.
        $where[] = "a.audit_number IS NOT NULL AND a.audit_number <> ''";

        $sql = 'SELECT a.id, a.audit_number, a.audit_date, a.status, a.total_score, a.location_id,
                       a.auditor_code, a.approver_code, a.store_manager_code,
                       t.name AS template_name, l.location_name,
                       ae.full_name AS auditor_name, sm.full_name AS store_manager_name,
                       (SELECT COUNT(*) FROM audit_response_attachments aa
                          JOIN audit_responses r ON r.id = aa.response_id
                          WHERE r.audit_id = a.id) AS attachment_count,
                       (SELECT COUNT(*) FROM audit_image_pins p
                          JOIN audit_response_attachments aa ON aa.id = p.attachment_id
                          JOIN audit_responses r ON r.id = aa.response_id
                          WHERE r.audit_id = a.id AND p.status = \'open\') AS open_pin_count
                FROM audits a
                LEFT JOIN audit_templates t ON t.id = a.template_id
                LEFT JOIN locations l ON l.location_id = a.location_id
                LEFT JOIN employees ae ON ae.employee_code = a.auditor_code
                LEFT JOIN employees sm ON sm.employee_code = a.store_manager_code';
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY a.audit_date DESC, a.id DESC LIMIT 500';
        $st = $db->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        // KPI cards
        $myCode = myCode();
        try {
            // Approver KPI now counts the new approver_review queue (after
            // Ops has commented). The Approver no longer sees raw 'submitted'
            // audits — those go to SM, then Ops, before reaching them.
            if (auditCanApprove() || isSuperadmin()) {
                $pendingApprove = (int)$db->query("SELECT COUNT(*) FROM audits WHERE status='approver_review'")->fetchColumn();
            }
            if (auditCanCreate()) {
                $s = $db->prepare("SELECT COUNT(*) FROM audits WHERE status='sent_back' AND auditor_code = ?");
                $s->execute([$myCode]);
                $mySentBack = (int)$s->fetchColumn();
            }
            // SM justification queue — count audits awaiting this user's
            // comment. Anyone can be a Store Manager, so we don't gate on
            // a txn flag; the count is naturally zero for users who
            // aren't on any audit.
            if ($myCode !== '') {
                $s = $db->prepare("SELECT COUNT(*) FROM audits WHERE status='submitted' AND store_manager_code = ?");
                $s->execute([$myCode]);
                $myJustifyPending = (int)$s->fetchColumn();
            }
            $scopeW  = []; $scopeP = [];
            auditApplyScope($scopeW, $scopeP);
            $rangeSql = 'SELECT COUNT(*) c, AVG(total_score) avg FROM audits a WHERE a.audit_date >= ? AND a.audit_date <= ?';
            if ($scopeW) $rangeSql .= ' AND ' . implode(' AND ', $scopeW);
            $s = $db->prepare($rangeSql);
            $s->execute(array_merge([$fromDate, $toDate], $scopeP));
            $r = $s->fetch(PDO::FETCH_ASSOC);
            $myCount = (int)($r['c'] ?? 0);
            $myAvg   = $r && $r['avg'] !== null ? round((float)$r['avg'], 2) : 0.0;
        } catch (Exception $e) {}
    }

    $templates = auditGetTemplates(false);
    $locations = getActiveLocations();
    ?>
    <div class="page-header">
        <h2>Audits</h2>
        <div class="actions">
            <a href="?page=audit_manual" class="btn btn-ghost">📖 Manual</a>
            <?php if (auditCanCreate()): ?>
                <a href="?page=audit_new" class="btn btn-primary">+ New Audit</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($viewClicked): ?>
    <div class="stats-grid">
        <?php if (auditCanApprove() || isSuperadmin()): ?>
        <div class="stat-card stat-yellow">
            <div class="stat-val"><?= $pendingApprove ?></div>
            <div class="stat-lbl">Awaiting Approval</div>
        </div>
        <?php endif; ?>
        <?php if ($myJustifyPending > 0): ?>
        <div class="stat-card stat-blue">
            <div class="stat-val"><?= $myJustifyPending ?></div>
            <div class="stat-lbl">Awaiting My Justification</div>
        </div>
        <?php endif; ?>
        <?php if (auditCanCreate()): ?>
        <div class="stat-card stat-red">
            <div class="stat-val"><?= $mySentBack ?></div>
            <div class="stat-lbl">My Sent-Back</div>
        </div>
        <?php endif; ?>
        <div class="stat-card stat-blue">
            <div class="stat-val"><?= $myCount ?></div>
            <div class="stat-lbl">Audits in Range</div>
        </div>
        <div class="stat-card stat-green">
            <div class="stat-val"><?= number_format($myAvg, 2) ?></div>
            <div class="stat-lbl">Avg Score in Range</div>
        </div>
    </div>
    <?php endif; ?>

    <form method="GET" class="filter-bar">
        <input type="hidden" name="page" value="audit_list">
        <input type="hidden" name="view" value="1">
        <select name="status" class="form-control" style="max-width:180px">
            <option value="">All Status</option>
            <?php foreach (['draft','submitted','operation_review','approver_review','management_review','approved','sent_back'] as $s): ?>
                <option value="<?= $s ?>" <?= ($_GET['status'] ?? '') === $s ? 'selected' : '' ?>><?= h(ucfirst(str_replace('_',' ', $s))) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="template_id" class="form-control" style="max-width:200px">
            <option value="">All Templates</option>
            <?php foreach ($templates as $t): ?>
                <option value="<?= $t['id'] ?>" <?= (int)($_GET['template_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php
        // Location-bound users (e.g. Retail Sales managers with no audit_view/
        // approve/admin/create txn) only ever see their own store — the dropdown
        // would be misleading. Restrict it to users who can actually browse
        // across stores.
        $showLocFilter = isSuperadmin() || auditCanViewAll() || auditCanApprove()
                         || auditCanAdmin() || auditCanCreate();
        if ($showLocFilter):
        ?>
        <select name="location_id" class="form-control" style="max-width:220px">
            <option value="">All Stores</option>
            <?php foreach ($locations as $l): ?>
                <option value="<?= $l['location_id'] ?>" <?= (int)($_GET['location_id'] ?? 0) === (int)$l['location_id'] ? 'selected' : '' ?>><?= h($l['location_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <input type="date" id="audit-from-date" name="from_date" class="form-control" style="max-width:150px" value="<?= h($fromDate) ?>">
        <input type="date" id="audit-to-date"   name="to_date"   class="form-control" style="max-width:150px" value="<?= h($toDate) ?>">
        <button class="btn btn-secondary">View</button>
        <a class="btn btn-ghost" href="?page=export_audit_register&from_date=<?= h($fromDate) ?>&to_date=<?= h($toDate) ?>&status=<?= h($_GET['status'] ?? '') ?>&template_id=<?= (int)($_GET['template_id'] ?? 0) ?>&location_id=<?= (int)($_GET['location_id'] ?? 0) ?>">Export CSV</a>
    </form>
    <script>
    (function () {
        var fromEl = document.getElementById('audit-from-date');
        var toEl   = document.getElementById('audit-to-date');
        if (!fromEl || !toEl) return;
        // When the user picks a from-date, snap to-date to the last day of
        // that same calendar month. Keeps KPI/table windows on one month.
        fromEl.addEventListener('change', function () {
            if (!fromEl.value) return;
            var parts = fromEl.value.split('-');
            if (parts.length !== 3) return;
            var y = parseInt(parts[0], 10), m = parseInt(parts[1], 10);
            if (!y || !m) return;
            // Day 0 of next month = last day of current month.
            var last = new Date(y, m, 0);
            var yyyy = last.getFullYear();
            var mm   = ('0' + (last.getMonth() + 1)).slice(-2);
            var dd   = ('0' + last.getDate()).slice(-2);
            toEl.value = yyyy + '-' + mm + '-' + dd;
        });
    })();
    </script>

    <?php if (!$viewClicked): ?>
    <div class="rpt-prompt">Choose filters above and click <strong>View</strong> to load results.</div>
    <?php else: ?>
    <div class="table-wrap" data-stack>
        <table class="table">
            <thead>
                <tr>
                    <th>Audit #</th><th>Date</th><th>Store</th><th>Template</th>
                    <th>Auditor</th><th>Store Manager</th>
                    <th>Score</th><th>Attachments</th><th>Open Pins</th><th>Status</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="11" class="empty-row">No audits found.</td></tr>
            <?php else: foreach ($rows as $a): ?>
                <tr>
                    <td data-label="Audit #"><code><?= h($a['audit_number']) ?></code></td>
                    <td data-label="Date"><?= h($a['audit_date']) ?></td>
                    <td data-label="Store"><?= h($a['location_name'] ?? '—') ?></td>
                    <td data-label="Template"><?= h($a['template_name'] ?? '—') ?></td>
                    <td data-label="Auditor"><?= h($a['auditor_name'] ?? $a['auditor_code']) ?></td>
                    <td data-label="Store Manager"><?= h($a['store_manager_name'] ?? '—') ?></td>
                    <?php $tsCls = auditScoreColor($a['total_score'] !== null ? (float)$a['total_score'] : null); ?>
                    <td data-label="Score" class="<?= h($tsCls) ?>"><?= $a['total_score'] !== null ? number_format((float)$a['total_score'], 2) : '—' ?></td>
                    <td data-label="Attachments"><?= (int)($a['attachment_count'] ?? 0) ?></td>
                    <td data-label="Open Pins"><?= (int)($a['open_pin_count'] ?? 0) ?></td>
                    <td data-label="Status"><?= auditStatusBadge($a['status']) ?></td>
                    <td data-label="Actions" class="actions">
                        <?php
                        $canEdit    = auditCanEditRow($a);
                        $canSmRev   = auditCanManagerReview($a) && auditHasManagerReviewCols();
                        $canOps     = auditCanOperationReview() && $a['status'] === 'operation_review';
                        $canApprove = auditCanApprove()         && $a['status'] === 'approver_review';
                        $canMgmt    = auditCanManagementReview() && $a['status'] === 'management_review';
                        ?>
                        <?php if ($canEdit): ?>
                            <?php
                            $editLabel = $a['status'] === 'draft'     ? 'Resume Draft'
                                       : ($a['status'] === 'sent_back' ? 'Fix & Resubmit'
                                                                       : 'Edit');
                            $editCls   = $a['status'] === 'sent_back' ? 'btn-danger' : 'btn-primary';
                            ?>
                            <a class="btn btn-sm <?= $editCls ?>" href="?page=audit_edit&id=<?= (int)$a['id'] ?>"><?= $editLabel ?></a>
                        <?php elseif ($canSmRev): ?>
                            <a class="btn btn-sm btn-primary" href="?page=audit_manager_review&id=<?= (int)$a['id'] ?>">Justify</a>
                        <?php elseif ($canOps): ?>
                            <a class="btn btn-sm btn-primary" href="?page=audit_operation_review&id=<?= (int)$a['id'] ?>">Operation Review</a>
                        <?php elseif ($canApprove): ?>
                            <a class="btn btn-sm btn-success" href="?page=audit_approve&id=<?= (int)$a['id'] ?>">Approve</a>
                        <?php elseif ($canMgmt): ?>
                            <a class="btn btn-sm btn-success" href="?page=audit_management_review&id=<?= (int)$a['id'] ?>">Management Review</a>
                        <?php else: ?>
                            <a class="btn btn-sm btn-secondary" href="?page=audit_view&id=<?= (int)$a['id'] ?>">View</a>
                        <?php endif; ?>
                        <?php if (isSuperadmin()): ?>
                            <form method="POST" class="inline-form" onsubmit="return confirm('Delete audit <?= h($a['audit_number'] ?: ('#' . (int)$a['id'] . ' (unsaved)')) ?>? This removes responses, attachments, and history.')">
                                <input type="hidden" name="action" value="delete_audit">
                                <input type="hidden" name="audit_id" value="<?= (int)$a['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="table-count"><?= count($rows) ?> audit(s)</div>
    <?php endif; ?>
    <?php
}

// ===========================================================
// Audit Justification user manual (Hindi) — open to everyone.
// Static help page reached from the "Manual" button on the Audit
// List. Screenshots live in assets/audit_manual/step1..5.jpeg.
// ===========================================================
function pageAuditManual(): void {
    $img = function (string $file, string $alt): string {
        $src = 'assets/audit_manual/' . $file;
        return '<a href="' . h($src) . '" target="_blank" rel="noopener" style="display:block;margin:10px 0">'
             . '<img src="' . h($src) . '" alt="' . h($alt) . '" loading="lazy"'
             . ' style="max-width:100%;height:auto;border:1px solid var(--border);border-radius:8px;box-shadow:0 4px 14px rgba(0,0,0,.25)"></a>';
    };
?>
<div class="page-header">
    <h2>📖 Audit Justification — Store Manager Manual</h2>
    <div class="actions">
        <a href="?page=audit_list" class="btn btn-ghost btn-sm">← Back to Audits</a>
    </div>
</div>

<div class="form-card" style="max-width:900px;line-height:1.7">
    <h3 style="margin:0 0 6px;font-size:18px">ऑडिट जस्टिफिकेशन प्रक्रिया (Hindi Guide)</h3>
    <p style="color:var(--muted);margin:0 0 16px">
        यह गाइड Retail Store Manager के लिए बनाई गई है ताकि वे Auditor द्वारा सबमिट किए गए
        Audit में Low Score या Remarks का सही तरीके से जवाब (Justification) दे सकें।
    </p>

    <!-- Step 1 -->
    <div class="form-section-title">Step 1: Auditor द्वारा Submit किए गए Audit को खोलें</div>
    <p>जब Auditor आपके Store का Audit Submit करेगा, तब Audit List में उस Audit का Status दिखाई देगा।</p>
    <ul>
        <li><strong>Status:</strong> Submitted / Pending SM Justify</li>
        <li>Action में <strong>Justify</strong> बटन दिखाई देगा।</li>
    </ul>
    <?= $img('step1.jpeg', 'Audit List with Justify button') ?>
    <p style="margin-bottom:4px"><strong>क्या करना है:</strong></p>
    <ul>
        <li>Audit List स्क्रीन खोलें</li>
        <li>अपने Store का Audit खोजें</li>
        <li><strong>Justify</strong> बटन पर क्लिक करें</li>
    </ul>

    <!-- Step 2 -->
    <div class="form-section-title" style="margin-top:18px">Step 2: Low Score या Audit Remark का जवाब लिखें</div>
    <p>Justify बटन पर क्लिक करने के बाद Audit Details स्क्रीन खुलेगी। जहाँ Low Score, Red Mark या
       Auditor Remark दिखाई देगा, वहाँ आपको अपना जवाब लिखना होगा।</p>
    <?= $img('step2.jpeg', 'Audit details with justification text boxes') ?>
    <p style="margin-bottom:4px"><strong>उदाहरण:</strong></p>
    <ul>
        <li>Product expire होने का कारण</li>
        <li>Short item का कारण</li>
        <li>Stock issue का explanation</li>
        <li>Store level action taken</li>
    </ul>
    <p style="margin-bottom:4px"><strong>क्या करना है:</strong></p>
    <ul>
        <li>संबंधित Question के सामने Text Box में क्लिक करें</li>
        <li>अपना जवाब / Explanation लिखें</li>
        <li>सभी जरूरी justification भरें</li>
    </ul>

    <!-- Step 3 -->
    <div class="form-section-title" style="margin-top:18px">Step 3: Save &amp; Forward to Approver</div>
    <p>सभी जवाब भरने के बाद नीचे <strong>Save</strong> और <strong>Save &amp; Forward to Approver</strong> बटन दिखाई देंगे।</p>
    <?= $img('step3.jpeg', 'Save and Save & Forward to Approver buttons') ?>
    <div class="alert alert-info" style="background:rgba(26,143,227,.10);color:#9ed1f6;border:1px solid rgba(26,143,227,.25);padding:10px 14px;border-radius:6px">
        <p style="margin:0 0 6px">✅ सभी जवाब पूरा होने के बाद <strong>Save &amp; Forward to Approver</strong> पर क्लिक करें — इससे आपका जवाब Approver को भेज दिया जाएगा।</p>
        <p style="margin:0">❌ <strong>Save</strong> बटन केवल Draft Save करने के लिए है, इससे Process Complete नहीं होगा। Process Complete करने के लिए <strong>Save &amp; Forward to Approver</strong> पर क्लिक करना आवश्यक है।</p>
    </div>

    <!-- Step 4 -->
    <div class="form-section-title" style="margin-top:18px">Step 4: आपका Submitted Answer कहाँ दिखाई देगा</div>
    <p>जब आपका जवाब Submit हो जाएगा, तब Audit View स्क्रीन में Question के नीचे Left Side में आपका उत्तर
       दिखाई देगा। इससे Auditor और Approver दोनों आपका जवाब देख सकते हैं।</p>
    <?= $img('step4.jpeg', 'Submitted answer shown under the question') ?>

    <!-- Step 5 -->
    <div class="form-section-title" style="margin-top:18px">Step 5: पुरानी Submitted History कैसे देखें</div>
    <p>यदि आप पहले Submit किए गए जवाब या पुराने Audit देखना चाहते हैं:</p>
    <ul>
        <li>Audit List स्क्रीन खोलें</li>
        <li>पुराने Audit को चुनें</li>
        <li><strong>View</strong> बटन पर क्लिक करें</li>
    </ul>
    <?= $img('step5.jpeg', 'Viewing past audit history') ?>
    <p style="margin-bottom:4px">यहाँ आपको दिखाई देगा:</p>
    <ul>
        <li>पुराना Audit Score</li>
        <li>Submitted Status</li>
        <li>आपका दिया हुआ जवाब</li>
        <li>Auditor Remarks</li>
    </ul>

    <!-- Important notes -->
    <div class="form-section-title" style="margin-top:18px">Important Notes</div>
    <ul>
        <li>सभी justification स्पष्ट और सही लिखें</li>
        <li>गलत जानकारी Submit न करें</li>
        <li>जरूरी हो तो corrective action भी लिखें</li>
        <li>Submit करने से पहले सभी जवाब check करें</li>
    </ul>

    <!-- Summary -->
    <div class="form-section-title" style="margin-top:18px">Summary</div>
    <div class="table-wrap" data-stack>
        <table class="table">
            <thead><tr><th style="width:60px">Step</th><th>Action</th></tr></thead>
            <tbody>
                <tr><td>1</td><td><strong>Justify</strong> बटन पर क्लिक करें</td></tr>
                <tr><td>2</td><td>Low Score का जवाब लिखें</td></tr>
                <tr><td>3</td><td><strong>Save &amp; Forward to Approver</strong> करें</td></tr>
                <tr><td>4</td><td>आपका जवाब Question के नीचे दिखाई देगा</td></tr>
                <tr><td>5</td><td>पुराने जवाब <strong>View</strong> स्क्रीन में देख सकते हैं</td></tr>
            </tbody>
        </table>
    </div>
</div>
<?php
}

// ===========================================================
// JSON endpoint: per-parameter response history
// Used by the small "history" icon next to each question on the
// audit edit/view pages. Returns previous responses for the same
// parameter so an auditor can compare across past audits.
// ===========================================================
function pageAuditParamHistory(): void {
    header('Content-Type: application/json; charset=utf-8');
    if (!isLoggedIn()) { http_response_code(403); echo json_encode(['error' => 'access denied']); return; }
    if (!isSuperadmin() && !auditCanCreate() && !auditCanApprove() && !auditCanAdmin() && !auditCanViewAll()) {
        http_response_code(403); echo json_encode(['error' => 'access denied']); return;
    }
    $paramId    = (int)($_GET['param_id'] ?? 0);
    $exclude    = (int)($_GET['exclude_audit_id'] ?? 0);
    $locationId = (int)($_GET['location_id'] ?? 0);
    if ($paramId <= 0) { echo json_encode(['error' => 'bad request']); return; }

    // Parameter caption — confirms which question the user is viewing
    $st = getDb()->prepare('SELECT parameter_text, type FROM audit_parameters WHERE id = ?');
    $st->execute([$paramId]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) { echo json_encode(['error' => 'parameter not found']); return; }

    $locationName = '';
    if ($locationId > 0) {
        $ls = getDb()->prepare('SELECT location_name FROM locations WHERE location_id = ?');
        $ls->execute([$locationId]);
        $locationName = (string)($ls->fetchColumn() ?: '');
    }

    $rows = auditGetParameterHistory($paramId, $exclude, $locationId);
    echo json_encode([
        'parameter_text' => $p['parameter_text'],
        'parameter_type' => $p['type'],
        'location_id'    => $locationId,
        'location_name'  => $locationName,
        'rows'           => $rows,
    ]);
}

// ===========================================================
// PAGE: New audit form
// ===========================================================
function pageAuditNew(): void {
    if (!auditCanCreate()) { echo '<p>Access denied.</p>'; return; }
    $templates = auditGetTemplates(true);
    $locations = getActiveLocations();
    $departments = getDepartments();
    // All active employees with dept info — JS filters client-side when dept dropdown changes.
    $allEmpLite   = getEmployeesLite();
    $allEmployees = array_values(array_filter($allEmpLite, fn($e) => !empty($e['is_active'])));
    // Default the dept filter to Retail Sales when it exists (most common case for audits).
    $defaultDeptId = 0;
    foreach ($departments as $d) {
        if (strcasecmp($d['department_name'] ?? '', 'Retail Sales') === 0) {
            $defaultDeptId = (int)$d['id'];
            break;
        }
    }
    // Build a JS-friendly map: dept_id -> [{code, name}]; dept 0 = "All".
    $empMap = ['0' => []];
    foreach ($allEmployees as $e) {
        $entry = ['code' => (string)$e['employee_code'], 'name' => (string)$e['full_name']];
        $did = (string)((int)($e['department_id'] ?? 0));
        $empMap['0'][] = $entry;
        if (!isset($empMap[$did])) $empMap[$did] = [];
        $empMap[$did][] = $entry;
    }
    $empMapJson = json_encode($empMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Location → mapped store manager (for the auto-fill on store pick).
    $empNameByCode = [];
    foreach ($allEmpLite as $e) $empNameByCode[(string)$e['employee_code']] = (string)$e['full_name'];
    $locManagerMap = [];
    if (function_exists('getLocationManagerMap')) {
        foreach (getLocationManagerMap() as $lid => $code) {
            $locManagerMap[(string)$lid] = ['code' => (string)$code, 'name' => ($empNameByCode[(string)$code] ?? (string)$code)];
        }
    }
    $locManagerMapJson = json_encode($locManagerMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>
    <div class="page-header"><h2>Create Audit</h2></div>
    <form method="POST" class="form-card" id="auditNewForm" onsubmit="return auditNewValidate()">
        <input type="hidden" name="action" value="create_audit">
        <div class="form-grid">
            <div class="form-group">
                <label>Date <span class="required">*</span></label>
                <input type="date" name="audit_date" class="form-control" required value="<?= h(date('Y-m-d')) ?>">
            </div>
            <div class="form-group">
                <label>Auditor</label>
                <input type="text" class="form-control" value="<?= h(myName()) ?> (<?= h(myCode()) ?>)" readonly>
            </div>
            <div class="form-group">
                <label>Store <span class="required">*</span></label>
                <div class="combo-wrap" id="storeWrap">
                    <input type="text" id="storePicker" class="form-control combo-input" autocomplete="off"
                           placeholder="Type to search stores…" required>
                    <button type="button" class="combo-clear" id="storeClear" aria-label="Clear store" tabindex="-1">&times;</button>
                    <div class="combo-dropdown" id="storeDropdown" role="listbox"></div>
                </div>
                <input type="hidden" name="location_id" id="storeId">
            </div>
            <div class="form-group">
                <label>Audit Template <span class="required">*</span></label>
                <select name="template_id" class="form-control" required>
                    <option value="">— Select —</option>
                    <?php foreach ($templates as $t): ?>
                        <option value="<?= $t['id'] ?>"><?= h($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Department</label>
                <select id="deptFilter" class="form-control">
                    <option value="0">All departments</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= (int)$d['id'] ?>" <?= ($defaultDeptId === (int)$d['id']) ? 'selected' : '' ?>><?= h($d['department_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Store Manager <span class="required">*</span></label>
                <div class="combo-wrap" id="smWrap">
                    <input type="text" id="smPicker" class="form-control combo-input" autocomplete="off"
                           placeholder="Type to search employees…" required>
                    <button type="button" class="combo-clear" id="smClear" aria-label="Clear store manager" tabindex="-1">&times;</button>
                    <div class="combo-dropdown" id="smDropdown" role="listbox"></div>
                </div>
                <input type="hidden" name="store_manager_code" id="smCode">
            </div>
            <div class="form-group">
                <label>Present Store Executive <span class="required">*</span></label>
                <div class="combo-wrap" id="execWrap">
                    <input type="text" id="execPicker" class="form-control combo-input" autocomplete="off"
                           placeholder="Type to search employees…" required>
                    <button type="button" class="combo-clear" id="execClear" aria-label="Clear store executive" tabindex="-1">&times;</button>
                    <div class="combo-dropdown" id="execDropdown" role="listbox"></div>
                </div>
                <input type="hidden" name="store_executive_code" id="execCode">
            </div>
        </div>
        <div class="form-actions">
            <button class="btn btn-primary">Continue</button>
            <a class="btn btn-ghost" href="?page=audit_list">Cancel</a>
        </div>
        <p class="hint" style="margin-top:10px">Nothing is stored until you click <strong>Save</strong> on the next screen — abandoning this draft leaves no record behind.</p>
    </form>
    <script>
    (function () {
        var empMap = <?= $empMapJson ?>;
        var locManagerMap = <?= $locManagerMapJson ?>;
        var storeData = <?= json_encode(array_map(fn($l) => ['id' => (int)$l['location_id'], 'label' => (string)$l['location_name']], $locations), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

        // Reusable combobox: a text input + an absolute-positioned dropdown below it.
        // dataFn() returns the current options array [{value, label}]. hiddenEl gets 'value' on pick.
        function makeCombo(opts) {
            var input = opts.input, dropdown = opts.dropdown, hidden = opts.hidden, hint = opts.hint, dataFn = opts.dataFn;
            var clearBtn = opts.clearBtn || null;
            var wrap = input.closest('.combo-wrap');
            var activeIdx = -1, items = [];

            function syncClear() {
                if (!wrap) return;
                if (input.value && !input.disabled && !input.readOnly) wrap.classList.add('has-value');
                else wrap.classList.remove('has-value');
            }
            function clearAll() {
                input.value = '';
                hidden.value = '';
                syncClear();
                input.focus();
                render('');
                dropdown.classList.add('open');
            }
            if (clearBtn) {
                // mousedown fires before the input's blur — without preventDefault
                // the blur would close the dropdown and the click would be ignored.
                clearBtn.addEventListener('mousedown', function (e) { e.preventDefault(); clearAll(); });
                clearBtn.addEventListener('click', function (e) { e.preventDefault(); });
            }

            function render(q) {
                var src = dataFn();
                q = (q || '').toLowerCase().trim();
                items = q ? src.filter(function (x) { return x.label.toLowerCase().indexOf(q) !== -1; }) : src.slice();
                dropdown.innerHTML = '';
                if (!items.length) {
                    var empty = document.createElement('div');
                    empty.className = 'combo-option empty';
                    empty.textContent = src.length ? 'No matches' : 'No options available';
                    dropdown.appendChild(empty);
                } else {
                    items.forEach(function (it, i) {
                        var el = document.createElement('div');
                        el.className = 'combo-option';
                        el.setAttribute('role', 'option');
                        el.setAttribute('data-idx', i);
                        el.textContent = it.label;
                        el.addEventListener('mousedown', function (e) {
                            e.preventDefault();
                            pick(i);
                        });
                        dropdown.appendChild(el);
                    });
                }
                activeIdx = -1;
            }
            function open() { render(input.value); dropdown.classList.add('open'); }
            function close() { dropdown.classList.remove('open'); activeIdx = -1; }
            function pick(i) {
                if (i < 0 || i >= items.length) return;
                input.value = items[i].label;
                hidden.value = items[i].value;
                syncClear();
                close();
                input.dispatchEvent(new Event('combo:picked'));
            }
            function setActive(i) {
                var nodes = dropdown.querySelectorAll('.combo-option');
                nodes.forEach(function (n) { n.classList.remove('active'); });
                if (i >= 0 && i < nodes.length) {
                    nodes[i].classList.add('active');
                    nodes[i].scrollIntoView({ block: 'nearest' });
                    activeIdx = i;
                }
            }

            input.addEventListener('focus', open);
            input.addEventListener('click', open);
            input.addEventListener('input', function () {
                hidden.value = ''; // invalidate until matched
                open();
                // If user's current text matches an item exactly, commit it.
                var v = input.value.trim().toLowerCase();
                for (var i = 0; i < items.length; i++) {
                    if (items[i].label.toLowerCase() === v) { hidden.value = items[i].value; break; }
                }
                syncClear();
            });
            input.addEventListener('keydown', function (e) {
                if (e.key === 'ArrowDown') { e.preventDefault(); if (!dropdown.classList.contains('open')) open(); setActive(Math.min(items.length - 1, activeIdx + 1)); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(Math.max(0, activeIdx - 1)); }
                else if (e.key === 'Enter') { if (activeIdx >= 0) { e.preventDefault(); pick(activeIdx); } }
                else if (e.key === 'Escape') { close(); }
            });
            input.addEventListener('blur', function () { setTimeout(close, 120); });

            return {
                rebuild: function () {
                    // Clear invalid picks when the data source changes.
                    var src = dataFn(), val = input.value.trim().toLowerCase();
                    var still = src.some(function (x) { return x.label.toLowerCase() === val; });
                    if (!still) { input.value = ''; hidden.value = ''; }
                    if (hint) hint.textContent = src.length
                        ? src.length + ' option(s). Click or start typing to search.'
                        : 'No options available.';
                    if (dropdown.classList.contains('open')) render(input.value);
                    syncClear();
                },
                resolve: function () {
                    var src = dataFn(), val = input.value.trim().toLowerCase();
                    for (var i = 0; i < src.length; i++) {
                        if (src[i].label.toLowerCase() === val) { hidden.value = src[i].value; return; }
                    }
                    hidden.value = '';
                }
            };
        }

        var deptSel = document.getElementById('deptFilter');

        // Store combo: list is constant.
        var storeCombo = makeCombo({
            input: document.getElementById('storePicker'),
            dropdown: document.getElementById('storeDropdown'),
            hidden: document.getElementById('storeId'),
            clearBtn: document.getElementById('storeClear'),
            dataFn: function () { return storeData.map(function (s) { return { value: String(s.id), label: s.label }; }); }
        });

        // Store Manager combo: list depends on dept dropdown.
        var smCombo = makeCombo({
            input: document.getElementById('smPicker'),
            dropdown: document.getElementById('smDropdown'),
            hidden: document.getElementById('smCode'),
            clearBtn: document.getElementById('smClear'),
            dataFn: function () {
                var key = String(parseInt(deptSel.value, 10) || 0);
                return (empMap[key] || []).map(function (e) { return { value: e.code, label: e.name + ' (' + e.code + ')' }; });
            }
        });

        // Present Store Executive combo — same dept-filtered employee list.
        var execCombo = makeCombo({
            input: document.getElementById('execPicker'),
            dropdown: document.getElementById('execDropdown'),
            hidden: document.getElementById('execCode'),
            clearBtn: document.getElementById('execClear'),
            dataFn: function () {
                var key = String(parseInt(deptSel.value, 10) || 0);
                return (empMap[key] || []).map(function (e) { return { value: e.code, label: e.name + ' (' + e.code + ')' }; });
            }
        });

        deptSel.addEventListener('change', function () { smCombo.rebuild(); execCombo.rebuild(); });
        smCombo.rebuild();  // set initial hint
        execCombo.rebuild();
        storeCombo.rebuild();

        // Auto-fill the Store Manager from the location → manager mapping when a
        // store is picked. Switch the dept filter to "All" so the mapped manager
        // is always present in the list (keeps resolve()/validation happy). Stays
        // editable — the auditor can change it afterwards.
        document.getElementById('storePicker').addEventListener('combo:picked', function () {
            var lid = document.getElementById('storeId').value;
            var m = locManagerMap[lid];
            if (m && m.code) {
                // Keep the current department (defaults to Retail Sales). Only
                // widen to "All" if the mapped manager isn't in that department,
                // so the value still resolves on submit.
                var curKey = String(parseInt(deptSel.value, 10) || 0);
                var inDept = (empMap[curKey] || []).some(function (e) { return e.code === m.code; });
                if (!inDept) deptSel.value = '0';
                smCombo.rebuild();
                execCombo.rebuild();
                var smPicker = document.getElementById('smPicker');
                smPicker.value = m.name + ' (' + m.code + ')';
                document.getElementById('smCode').value = m.code;
                var w = smPicker.closest('.combo-wrap'); if (w) w.classList.add('has-value');
            }
        });

        window.auditNewValidate = function () {
            storeCombo.resolve();
            smCombo.resolve();
            execCombo.resolve();
            if (!document.getElementById('storeId').value) {
                alert('Please pick a Store from the suggestions.'); document.getElementById('storePicker').focus(); return false;
            }
            if (!document.getElementById('smCode').value) {
                alert('Please pick a Store Manager from the suggestions.'); document.getElementById('smPicker').focus(); return false;
            }
            if (!document.getElementById('execCode').value) {
                alert('Please pick a Present Store Executive from the suggestions.'); document.getElementById('execPicker').focus(); return false;
            }
            return true;
        };
    })();
    </script>
    <?php
}

// ===========================================================
// PAGE: Audit edit (main data-entry)
// ===========================================================
function pageAuditEdit(): void {
    $id         = (int)($_GET['id'] ?? 0);
    $draftToken = trim((string)($_GET['draft'] ?? ''));

    $a    = null;
    $tree = [];

    if ($id > 0) {
        $a = auditGetById($id);
        if (!$a) { echo '<p>Audit not found.</p>'; return; }
        if (!auditCanEditRow($a)) {
            header('Location: ?page=audit_view&id=' . $id);
            exit;
        }
        $tree = auditGetTree($id, (int)$a['template_id']);
    } elseif ($draftToken !== '' && isset($_SESSION['audit_drafts'][$draftToken])) {
        // In-session draft — no DB row yet. Render against the master
        // template and commit on first Save.
        if (!auditCanCreate()) { echo '<p>Access denied.</p>'; return; }
        $d = $_SESSION['audit_drafts'][$draftToken];
        if (($d['auditor_code'] ?? '') !== myCode() && !isSuperadmin()) {
            echo '<p>Access denied.</p>'; return;
        }
        $a    = auditDraftToHeaderArray($d);
        $tree = auditDraftTree((int)$d['template_id']);
    } else {
        // No id, no usable draft → bounce back to the list.
        header('Location: ?page=audit_list'); exit;
    }

    renderAuditHeader($a);
    ?>
    <form method="POST" enctype="multipart/form-data" id="auditForm">
        <input type="hidden" name="action" value="save_audit_weights">
        <input type="hidden" name="audit_id" value="<?= (int)$id ?>">
        <?php if ($id === 0 && $draftToken !== ''): ?>
            <input type="hidden" name="draft_token" value="<?= h($draftToken) ?>">
        <?php endif; ?>
        <?php renderAuditEditTable($tree, $id, false, (int)($a['location_id'] ?? 0)); ?>
        <div class="form-actions" style="position:sticky;bottom:0;z-index:50;margin-top:20px;padding:12px 14px;background:var(--surface);border:1px solid var(--border);border-radius:8px;box-shadow:0 -4px 12px rgba(0,0,0,.25)">
            <button class="btn btn-primary" type="submit">Save</button>
            <button class="btn btn-success" type="submit" name="submit_after_save" value="1">Save & Submit</button>
            <a class="btn btn-ghost" href="?page=audit_list">Back</a>
        </div>
    </form>
    <form method="POST" id="auditAttDelForm">
        <input type="hidden" name="action" value="delete_audit_attachment">
        <input type="hidden" name="audit_id" id="auditAttDelAuditId" value="">
        <input type="hidden" name="att_id" id="auditAttDelAttId" value="">
    </form>
    <?php renderAuditEditJs(); ?>
    <?php
}

// Helpers for the deferred-draft flow ─────────────────────────
// Build a $a-compatible header array straight from the session draft so
// renderAuditHeader doesn't need a special branch for unsaved audits.
function auditDraftToHeaderArray(array $d): array {
    $db = getDb();
    $tplName = ''; $locName = ''; $smName = '';
    $st = $db->prepare('SELECT name FROM audit_templates WHERE id = ?');
    $st->execute([(int)$d['template_id']]); $tplName = (string)($st->fetchColumn() ?: '');
    $st = $db->prepare('SELECT location_name FROM locations WHERE location_id = ?');
    $st->execute([(int)$d['location_id']]); $locName = (string)($st->fetchColumn() ?: '');
    $st = $db->prepare('SELECT full_name FROM employees WHERE employee_code = ?');
    $st->execute([(string)$d['store_manager_code']]); $smName = (string)($st->fetchColumn() ?: '');
    $seName = '';
    if (!empty($d['store_executive_code'])) {
        $st = $db->prepare('SELECT full_name FROM employees WHERE employee_code = ?');
        $st->execute([(string)$d['store_executive_code']]); $seName = (string)($st->fetchColumn() ?: '');
    }
    return [
        'id'                  => 0,
        'audit_number'        => null,
        'template_id'         => (int)$d['template_id'],
        'template_name'       => $tplName,
        'location_id'         => (int)$d['location_id'],
        'location_name'       => $locName,
        'auditor_code'        => (string)($d['auditor_code'] ?? myCode()),
        'auditor_name'        => myName(),
        'store_manager_code'  => (string)$d['store_manager_code'],
        'store_manager_name'  => $smName,
        'store_executive_code' => (string)($d['store_executive_code'] ?? ''),
        'store_executive_name' => $seName,
        'approver_code'       => null,
        'approver_name'       => null,
        'status'              => 'draft',
        'total_score'         => null,
        'audit_date'          => (string)$d['audit_date'],
        'submitted_at'        => null,
        'approved_at'         => null,
    ];
}

// Build a tree mirroring auditGetTree but without any responses — used
// before the audit row exists in the DB.
function auditDraftTree(int $templateId): array {
    $db = getDb();
    $cats = auditGetCategories($templateId);
    $catIds = array_column($cats, 'id');
    $params = [];
    if ($catIds) {
        $ph = implode(',', array_fill(0, count($catIds), '?'));
        $st = $db->prepare("SELECT * FROM audit_parameters WHERE category_id IN ({$ph}) AND is_active = 1 ORDER BY category_id, sort_order, id");
        $st->execute($catIds);
        $params = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $tree = [];
    foreach ($cats as $c) {
        $c['modified_weightage'] = (float)$c['weightage'];
        $c['parameters'] = [];
        foreach ($params as $p) {
            if ((int)$p['category_id'] === (int)$c['id']) {
                $p['response']    = null;
                $p['attachments'] = [];
                $c['parameters'][] = $p;
            }
        }
        $tree[] = $c;
    }
    return $tree;
}

// ===========================================================
// PAGE: Audit read-only view
// ===========================================================
function pageAuditView(): void {
    $id = (int)($_GET['id'] ?? 0);
    $a  = $id > 0 ? auditGetById($id) : null;
    if (!$a) { echo '<p>Audit not found.</p>'; return; }
    if (!auditCanViewRow($a)) { echo '<p>Access denied.</p>'; return; }
    // Log this open. Done after the permission check so we don't record
    // failed access attempts as legitimate views.
    auditLogView($id, 'page');
    $tree = auditGetTree($id, (int)$a['template_id']);
    renderAuditHeader($a);
    renderOpenPinsBanner($a);
    renderAuditEditTable($tree, $id, true, (int)($a['location_id'] ?? 0));
    renderAuditViewLog($id);
    ?>
    <div class="form-actions" style="margin-top:18px">
        <a class="btn btn-ghost" href="?page=audit_list">Back</a>
    </div>
    <?php
}

// ── View log block — collapsible "who's opened this audit" ──
// Renders below the response table on pageAuditView. Each row labels the
// viewer, when they opened it, whether it was a page or attachment, and
// the originating IP / browser.
function renderAuditViewLog(int $auditId): void {
    $rows = auditGetViewLog($auditId, 200);
    ?>
    <details class="form-card" style="max-width:none;margin-top:18px" <?= count($rows) > 0 ? '' : 'open' ?>>
        <summary style="cursor:pointer;font-weight:600;font-size:14px;list-style:none;display:flex;align-items:center;gap:8px">
            <span>📜 View Log</span>
            <span style="font-size:11px;font-weight:400;color:var(--muted)">
                — <?= count($rows) ?> entr<?= count($rows) === 1 ? 'y' : 'ies' ?>
            </span>
        </summary>
        <?php if (!$rows): ?>
            <div style="margin-top:10px;color:var(--muted);font-size:13px">No views yet.</div>
        <?php else: ?>
            <div class="table-wrap" style="margin-top:10px">
                <table class="table" style="font-size:12.5px">
                    <thead>
                        <tr>
                            <th style="width:160px">When</th>
                            <th>Viewer</th>
                            <th style="width:120px">Action</th>
                            <th>File</th>
                            <th style="width:130px">IP</th>
                            <th>Browser</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= h($r['viewed_at']) ?></td>
                            <td><?= h($r['viewer_name'] ?? $r['employee_code']) ?>
                                <span style="color:var(--muted);font-size:11px">(<?= h($r['employee_code']) ?>)</span>
                            </td>
                            <td>
                                <?php if ($r['view_type'] === 'attachment'): ?>
                                    <span class="badge" style="background:rgba(26,143,227,.18);color:#9ed1f6">📎 Attachment</span>
                                <?php else: ?>
                                    <span class="badge" style="background:rgba(39,174,96,.18);color:var(--green)">👁 Page</span>
                                <?php endif; ?>
                            </td>
                            <td style="color:var(--muted)"><?= h($r['attachment_name'] ?? '—') ?></td>
                            <td style="color:var(--muted);font-family:Consolas,monospace;font-size:11.5px"><?= h($r['ip_address'] ?? '—') ?></td>
                            <td style="color:var(--muted);font-size:11px;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                                title="<?= h($r['user_agent'] ?? '') ?>">
                                <?= h($r['user_agent'] ?? '—') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </details>
    <?php
}

// ===========================================================
// PAGE: Audit approver view
// ===========================================================
// Approver step in the 5-stage flow: forward to Management or send back
// to the Operation Team. Send-back to Auditor/SM is no longer the
// Approver's responsibility — those belong to Operation Team.
function pageAuditApprove(): void {
    if (!auditCanApprove()) { echo '<p>Access denied.</p>'; return; }
    $id = (int)($_GET['id'] ?? 0);
    $a  = $id > 0 ? auditGetById($id) : null;
    if (!$a) { echo '<p>Audit not found.</p>'; return; }
    if ($a['status'] !== 'approver_review') {
        header('Location: ?page=audit_view&id=' . $id);
        exit;
    }
    $tree = auditGetTree($id, (int)$a['template_id']);
    renderAuditHeader($a);
    renderOpenPinsBanner($a);
    ?>
    <form method="POST" id="auditApproveForm">
        <input type="hidden" name="action" value="approve_audit">
        <input type="hidden" name="audit_id" value="<?= (int)$id ?>">
        <?php renderAuditApproveTable($tree, $id, (int)($a['location_id'] ?? 0)); ?>
        <div class="form-actions audit-approve-bar"
             style="position:sticky;bottom:0;z-index:50;
                    margin:20px -8px 0;padding:12px 16px;
                    background:var(--surface);border-top:1px solid var(--border);
                    border-radius:8px 8px 0 0;
                    box-shadow:0 -6px 18px rgba(0,0,0,.45);
                    display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <button class="btn btn-success" type="submit" name="decision" value="approve">Approve &amp; Forward to Management</button>
            <button class="btn btn-danger"  type="submit" name="decision" value="send_back_ops"
                    title="Send back to the Operation Team for re-review">
                Send Back to Operation Team
            </button>
            <a class="btn btn-ghost" href="?page=audit_list" style="margin-left:auto">Back</a>
        </div>
    </form>
    <?php
}

// ===========================================================
// PAGE: Operation Team review (between SM and Approver)
// ===========================================================
// Operation Team can comment per-parameter, then either forward to the
// Approver or send back to the Auditor or Store Manager. UI mirrors the
// SM review page; the send-back picker is an inline button group.
function pageAuditOperationReview(): void {
    if (!auditCanOperationReview()) { echo '<p>Access denied.</p>'; return; }
    $id = (int)($_GET['id'] ?? 0);
    $a  = $id > 0 ? auditGetById($id) : null;
    if (!$a) { echo '<p>Audit not found.</p>'; return; }
    if ($a['status'] !== 'operation_review') {
        flash('error', 'Audit not in Operation Team queue.');
        header('Location: ?page=audit_view&id=' . $id); return;
    }
    $tree = auditGetTree($id, (int)$a['template_id']);
    renderAuditHeader($a);
    renderOpenPinsBanner($a);
    ?>
    <div class="alert alert-info" style="margin-bottom:14px;background:rgba(26,143,227,.10);color:#9ed1f6;border:1px solid rgba(26,143,227,.25);padding:10px 14px;border-radius:6px">
        Review the audit. Comment per-parameter where needed, then <strong>Comment &amp; Forward to Approver</strong>, or send back to the Auditor or Store Manager with remarks.
    </div>
    <form method="POST" id="auditOpsReviewForm">
        <input type="hidden" name="action" value="operation_review_audit">
        <input type="hidden" name="audit_id" value="<?= (int)$id ?>">
        <?php renderAuditOperationReviewTable($tree, $id, (int)($a['location_id'] ?? 0)); ?>
        <div class="form-actions audit-ops-bar"
             style="position:sticky;bottom:0;z-index:50;
                    margin:20px -8px 0;padding:12px 16px;
                    background:var(--surface);border-top:1px solid var(--border);
                    border-radius:8px 8px 0 0;
                    box-shadow:0 -6px 18px rgba(0,0,0,.45);
                    display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <button class="btn btn-primary" type="submit" name="op_action" value="save">Save</button>
            <button class="btn btn-success" type="submit" name="op_action" value="forward">Comment &amp; Forward to Approver</button>
            <button class="btn btn-danger"  type="submit" name="op_action" value="send_back_auditor"
                    title="Auditor re-edits via 'Fix &amp; Resubmit'">
                Send Back to Auditor
            </button>
            <button class="btn" type="submit" name="op_action" value="send_back_sm"
                    style="background:var(--yellow);color:#1a1612"
                    title="Store Manager re-justifies via 'Justify' queue">
                Send Back to Store Manager
            </button>
            <a class="btn btn-ghost" href="?page=audit_list" style="margin-left:auto">Back</a>
        </div>
    </form>
    <?php
}

// ===========================================================
// PAGE: Management review (final step)
// ===========================================================
// Management gets the audit after the Approver has forwarded. Two
// actions: Final Approve (status → approved) or Send Back to Approver
// with remarks.
function pageAuditManagementReview(): void {
    if (!auditCanManagementReview()) { echo '<p>Access denied.</p>'; return; }
    $id = (int)($_GET['id'] ?? 0);
    $a  = $id > 0 ? auditGetById($id) : null;
    if (!$a) { echo '<p>Audit not found.</p>'; return; }
    if ($a['status'] !== 'management_review') {
        flash('error', 'Audit not in Management queue.');
        header('Location: ?page=audit_view&id=' . $id); return;
    }
    $tree = auditGetTree($id, (int)$a['template_id']);
    renderAuditHeader($a);
    renderOpenPinsBanner($a);
    ?>
    <form method="POST" id="auditMgmtReviewForm">
        <input type="hidden" name="action" value="management_approve_audit">
        <input type="hidden" name="audit_id" value="<?= (int)$id ?>">
        <?php renderAuditManagementReviewTable($tree, $id, (int)($a['location_id'] ?? 0)); ?>
        <div class="form-actions audit-mgmt-bar"
             style="position:sticky;bottom:0;z-index:50;
                    margin:20px -8px 0;padding:12px 16px;
                    background:var(--surface);border-top:1px solid var(--border);
                    border-radius:8px 8px 0 0;
                    box-shadow:0 -6px 18px rgba(0,0,0,.45);
                    display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <button class="btn btn-success" type="submit" name="decision" value="approve">Final Approve</button>
            <button class="btn btn-danger"  type="submit" name="decision" value="send_back_approver"
                    title="Send back to the Approver for re-review">
                Send Back to Approver
            </button>
            <a class="btn btn-ghost" href="?page=audit_list" style="margin-left:auto">Back</a>
        </div>
    </form>
    <?php
}

// ── Open-pin agenda banner ──────────────────────────────
// Shown above the form on every action page (and on audit_view). Lists
// pins the current user must reply to before the forward action will go
// through. Server-side gate (auditOpenPinsBlockingFor) is the source of
// truth — this is a heads-up so the user can address pins first.
function renderOpenPinsBanner(array $a): void {
    $blocking = auditOpenPinsBlockingFor((int)$a['id'], myCode());
    if (!$blocking) return;
    $n = count($blocking);
    ?>
    <div class="alert alert-warning"
         style="margin-bottom:14px;background:rgba(255,180,40,.10);color:#ffce6b;
                border:1px solid rgba(255,180,40,.32);padding:12px 14px;border-radius:6px">
        <div style="font-weight:600;margin-bottom:6px">
            <?= (int)$n ?> open pin<?= $n === 1 ? '' : 's' ?> on attached images need your reply
            before you can forward this audit.
        </div>
        <ul style="margin:0;padding-left:18px;font-size:12.5px;line-height:1.5">
        <?php foreach ($blocking as $p): ?>
            <li>
                <a href="?page=audit_annotation_image&audit_att=<?= (int)$p['audit_attachment_id'] ?>&pin=<?= (int)$p['pin_number'] ?>"
                   target="_blank" style="color:#ffe2a8;text-decoration:underline">
                    <?= h($p['original_name']) ?> &middot; pin #<?= (int)$p['pin_number'] ?>
                </a>
                <span class="text-muted" style="font-size:11px"> &middot; by <?= h($p['creator_name'] ?? $p['created_by']) ?></span>
            </li>
        <?php endforeach; ?>
        </ul>
    </div>
    <?php
}

// ===========================================================
// PAGE: Store Manager review (justification before approver)
// ===========================================================
// Sits between submit and approve: the SM assigned to the audit can
// add a per-question justification, then forwards the audit to the
// approver. Auditor's values are locked here — only SM remarks save.
function pageAuditManagerReview(): void {
    $id = (int)($_GET['id'] ?? 0);
    $a  = $id > 0 ? auditGetById($id) : null;
    if (!$a) { echo '<p>Audit not found.</p>'; return; }
    if (!auditCanManagerReview($a)) {
        flash('error', 'You can only justify audits assigned to you, after the auditor submits.');
        header('Location: ?page=audit_view&id=' . $id);
        exit;
    }
    $tree = auditGetTree($id, (int)$a['template_id']);
    renderAuditHeader($a);
    ?>
    <div class="alert alert-info" style="margin-bottom:14px;background:rgba(26,143,227,.10);color:#9ed1f6;border:1px solid rgba(26,143,227,.25);padding:10px 14px;border-radius:6px">
        Add justification or context for any question the auditor flagged. Leave a row blank if no comment is needed. When you're done, click <strong>Forward to Approver</strong>.
    </div>
    <form method="POST" id="auditManagerReviewForm">
        <input type="hidden" name="action" value="manager_review_audit">
        <input type="hidden" name="audit_id" value="<?= (int)$id ?>">
        <?php renderAuditManagerReviewTable($tree, $id, (int)($a['location_id'] ?? 0)); ?>
        <!-- Sticky-bottom action bar so Save / Forward stay visible while
             the SM scrolls through long audits. Same pattern as the
             approver page. -->
        <div class="form-actions audit-sm-bar"
             style="position:sticky;bottom:0;z-index:50;
                    margin:20px -8px 0;padding:12px 16px;
                    background:var(--surface);border-top:1px solid var(--border);
                    border-radius:8px 8px 0 0;
                    box-shadow:0 -6px 18px rgba(0,0,0,.45);
                    display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <button class="btn btn-primary" type="submit" name="sm_action" value="save">Save</button>
            <button class="btn btn-success" type="submit" name="sm_action" value="forward">Save &amp; Forward to Approver</button>
            <a class="btn btn-ghost" href="?page=audit_list" style="margin-left:auto">Back</a>
        </div>
    </form>
    <?php
}

// ── Header card rendered above edit/view/approve ────────
// ===========================================================
// PAGE: Audit Summary — average score per location for a month
// ===========================================================
// Month-locked date filter. Default range = first of current month to
// today (or last-day-of-month if the user picks a past month). One row
// per location with at least one APPROVED audit in the range; avg of
// audits.total_score, colour-banded green/orange/red on 90/70 thresholds.
// Gated by txn_audit_summary (Audit Summary).
// Sanitise the audit-summary filter inputs and return
// [from, to, template_id] — dates always within the same calendar month;
// template_id 0 = all templates. If the user (or a stale URL) smuggles
// in a "to" date outside the month, it gets clamped to the month-end.
// Defaults: from = 1st of current month, to = today (or last day of
// chosen past month), template = all.
// Indian financial year starts April 1. Returns the FY-start year for the
// supplied date (today if omitted) — e.g. 2026-05-29 → 2026, 2026-03-15 → 2025.
function auditSummaryFyStartYear(?string $ymd = null): int {
    $ts    = $ymd ? strtotime($ymd) : time();
    $month = (int)date('n', $ts);
    $year  = (int)date('Y', $ts);
    return $month >= 4 ? $year : $year - 1;
}

// "FY 2026-27" given 2026.
function auditSummaryFyLabel(int $fyStart): string {
    $endShort = substr((string)($fyStart + 1), -2);
    return 'FY ' . $fyStart . '-' . $endShort;
}

function auditSummaryFilters(): array {
    $today = date('Y-m-d');

    // ── View mode: month | year ──
    // Default = month. Year mode aggregates a full Indian financial year
    // (April 1 → March 31). Day mode was removed; legacy ?mode=day URLs
    // fall through to month.
    $mode = ($_GET['mode'] ?? 'month') === 'year' ? 'year' : 'month';

    // Month / Year — used in month mode.
    $month = (int)($_GET['month'] ?? 0);
    $year  = (int)($_GET['year']  ?? 0);
    if ($month < 1 || $month > 12)        $month = (int)date('n');
    if ($year  < 2020 || $year  > 2099)   $year  = (int)date('Y');

    // FY start year — used in year mode.
    $fyStartYear = (int)($_GET['fy'] ?? 0);
    if ($fyStartYear < 2020 || $fyStartYear > 2099) $fyStartYear = auditSummaryFyStartYear();

    // Back-compat: legacy URL with from_date but no mode → infer month/year.
    if (!isset($_GET['mode']) && !isset($_GET['month']) && !isset($_GET['year']) && !empty($_GET['from_date'])) {
        $ts = strtotime((string)$_GET['from_date']);
        if ($ts) { $month = (int)date('n', $ts); $year = (int)date('Y', $ts); }
    }

    // ── Date range that drives auditSummaryQuery ──
    if ($mode === 'year') {
        $fromDate = sprintf('%04d-04-01', $fyStartYear);
        $toDate   = sprintf('%04d-03-31', $fyStartYear + 1);
    } else {
        // Month mode: month/year selects pin the default window, but
        // from_date / to_date inputs (if present) override so the user
        // can narrow to any range INSIDE the month. For the *current*
        // month the default "to" is today, not the future last-day —
        // users don't want a range that runs past the present.
        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd   = date('Y-m-t', strtotime($monthStart));
        if ($monthEnd > $today) $monthEnd = $today;
        $fromRaw    = trim((string)($_GET['from_date'] ?? ''));
        $toRaw      = trim((string)($_GET['to_date']   ?? ''));
        $fromDate   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromRaw) ? $fromRaw : $monthStart;
        $toDate     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $toRaw)   ? $toRaw   : $monthEnd;
        // Clamp into the picked month so a stale legacy value can't sneak through.
        if ($fromDate < $monthStart) $fromDate = $monthStart;
        if ($fromDate > $monthEnd)   $fromDate = $monthEnd;
        if ($toDate   < $fromDate)   $toDate   = $fromDate;
        if ($toDate   > $monthEnd)   $toDate   = $monthEnd;
    }

    $templateId = (int)($_GET['template_id'] ?? 0);
    if ($templateId < 0) $templateId = 0;

    $allStatuses = ['draft','submitted','manager_review','operation_review','approver_review','management_review','approved','sent_back'];
    if (isset($_GET['status'])) {
        $raw = is_array($_GET['status']) ? $_GET['status'] : [$_GET['status']];
        $status = array_values(array_intersect($allStatuses, $raw));
    } else {
        $status = ['submitted','operation_review','approver_review','management_review','approved','sent_back'];
    }

    return [$mode, $month, $year, $fyStartYear, $fromDate, $toDate, $templateId, $status];
}

// Average score per location across audits in the date range, scoped to
// the caller-provided list of audit statuses. An empty list returns no
// rows (the user explicitly unchecked everything). Optional templateId
// narrows to a single template (0 = all). Shared by the page renderer
// and the CSV export.
function auditSummaryQuery(string $fromDate, string $toDate, int $templateId = 0, array $statuses = []): array {
    $sql = "SELECT a.location_id,
                   l.location_name,
                   AVG(a.total_score) AS avg_score,
                   MIN(a.total_score) AS min_score,
                   MAX(a.total_score) AS max_score,
                   COUNT(*)           AS audit_count
            FROM audits a
            LEFT JOIN locations l ON l.location_id = a.location_id
            WHERE a.audit_date BETWEEN ? AND ?";
    $params = [$fromDate, $toDate];
    if ($statuses) {
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $sql .= " AND a.status IN ($placeholders)";
        foreach ($statuses as $s) $params[] = $s;
    } else {
        // No statuses checked → no data, but keep query valid.
        $sql .= ' AND 1=0';
    }
    if ($templateId > 0) {
        $sql .= ' AND a.template_id = ?';
        $params[] = $templateId;
    }
    $sql .= ' GROUP BY a.location_id, l.location_name
              ORDER BY l.location_name ASC';
    try {
        $st = getDb()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

// Year-mode aggregate: avg score per location per month across an FY.
// Returns rows keyed by [location_id][month_num] = ['avg', 'count'] plus a
// flat list of locations (with their FY-overall avg) for the leftmost column.
// Month_num is the calendar month (1-12); the renderer reorders into Apr-Mar.
function auditSummaryMonthlyQuery(int $fyStartYear, int $templateId, array $statuses): array {
    $fromDate = sprintf('%04d-04-01', $fyStartYear);
    $toDate   = sprintf('%04d-03-31', $fyStartYear + 1);

    $sql = "SELECT a.location_id,
                   l.location_name,
                   MONTH(a.audit_date) AS m,
                   YEAR(a.audit_date)  AS y,
                   AVG(a.total_score)  AS avg_score,
                   COUNT(*)            AS audit_count
            FROM audits a
            LEFT JOIN locations l ON l.location_id = a.location_id
            WHERE a.audit_date BETWEEN ? AND ?";
    $params = [$fromDate, $toDate];
    if ($statuses) {
        $ph = implode(',', array_fill(0, count($statuses), '?'));
        $sql .= " AND a.status IN ($ph)";
        foreach ($statuses as $s) $params[] = $s;
    } else {
        $sql .= ' AND 1=0';
    }
    if ($templateId > 0) {
        $sql .= ' AND a.template_id = ?';
        $params[] = $templateId;
    }
    $sql .= " GROUP BY a.location_id, l.location_name, m, y
              ORDER BY l.location_name ASC, y ASC, m ASC";

    $cell = []; // [location_id][month] = ['avg' => x, 'count' => n]
    $loc  = []; // location_id => ['location_id', 'location_name', 'total_audits', 'sum_weighted']
    try {
        $st = getDb()->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $lid   = (int)$r['location_id'];
            $month = (int)$r['m'];
            $cnt   = (int)$r['audit_count'];
            $avg   = $r['avg_score'] !== null ? (float)$r['avg_score'] : null;
            $cell[$lid][$month] = ['avg' => $avg, 'count' => $cnt];
            if (!isset($loc[$lid])) {
                $loc[$lid] = ['location_id' => $lid, 'location_name' => (string)($r['location_name'] ?? ''), 'total_audits' => 0, 'sum_weighted' => 0.0];
            }
            $loc[$lid]['total_audits'] += $cnt;
            if ($avg !== null) $loc[$lid]['sum_weighted'] += $avg * $cnt;
        }
    } catch (Exception $e) { /* fail open */ }

    // Compute per-location FY-overall avg.
    foreach ($loc as &$l) {
        $l['avg_score'] = $l['total_audits'] > 0 ? round($l['sum_weighted'] / $l['total_audits'], 2) : null;
    }
    unset($l);
    $locList = array_values($loc);
    usort($locList, fn($a, $b) => strcasecmp((string)$a['location_name'], (string)$b['location_name']));

    return ['locations' => $locList, 'cells' => $cell];
}

// Human label for the status filter — used by the on-screen banner
// and the CSV header so the export tells the reader which slice of
// audits they're looking at.
function auditSummaryStatusLabel(array $statuses): string {
    if (!$statuses) return 'None selected';
    $allStatuses = ['draft','submitted','manager_review','operation_review','approver_review','management_review','approved','sent_back'];
    $missing = array_values(array_diff($allStatuses, $statuses));
    if (!$missing)                                   return 'All statuses';
    if ($missing === ['draft'])                      return 'All (excludes drafts)';
    $labels = [
        'draft'             => 'Draft',
        'submitted'         => 'Pending SM Justify',
        'manager_review'    => 'Pending Operation Review (legacy)',
        'operation_review'  => 'Pending Operation Review',
        'approver_review'   => 'Pending Approval',
        'management_review' => 'Pending Management Approval',
        'approved'          => 'Approved',
        'sent_back'         => 'Sent Back',
    ];
    $picked = [];
    foreach ($allStatuses as $s) {
        if (in_array($s, $statuses, true)) $picked[] = $labels[$s];
    }
    return implode(', ', $picked);
}

function pageAuditSummary(): void {
    if (!isSuperadmin() && !hasTxn('audit_summary')) {
        echo '<p>Access denied.</p>'; return;
    }

    [$mode, $month, $year, $fyStartYear, $fromDate, $toDate, $templateId, $status] = auditSummaryFilters();
    // Don't run the aggregate query (or render the table) until the user
    // has actually clicked View — the form posts ?view=1 to opt in.
    $doLoad   = !empty($_GET['view']);
    $rows     = ($doLoad && $mode !== 'year') ? auditSummaryQuery($fromDate, $toDate, $templateId, $status) : [];
    $monthly  = ($doLoad && $mode === 'year') ? auditSummaryMonthlyQuery($fyStartYear, $templateId, $status) : ['locations' => [], 'cells' => []];
    $statusLabel = auditSummaryStatusLabel($status);
    $templates   = auditGetTemplates(false); // include inactive — we may be filtering on an old one
    // Resolve the picked template's name for the CSV header / on-screen banner.
    $templateName = '';
    foreach ($templates as $t) {
        if ((int)$t['id'] === $templateId) { $templateName = (string)$t['name']; break; }
    }

    $exportQs = http_build_query([
        'page'        => 'export_audit_summary',
        'mode'        => $mode,
        'month'       => $month,
        'year'        => $year,
        'fy'          => $fyStartYear,
        'from_date'   => $fromDate,
        'to_date'     => $toDate,
        'template_id' => $templateId,
        'status'      => $status,
    ]);

    // Aggregate totals across all locations
    $totalAudits = 0; $weightedSum = 0.0;
    if ($mode === 'year') {
        foreach ($monthly['locations'] as $l) {
            $n = (int)$l['total_audits'];
            $totalAudits += $n;
            if ($l['avg_score'] !== null) $weightedSum += $n * (float)$l['avg_score'];
        }
    } else {
        foreach ($rows as $r) {
            $n = (int)$r['audit_count'];
            $totalAudits += $n;
            $weightedSum += $n * (float)$r['avg_score'];
        }
    }
    $overallAvg = $totalAudits > 0 ? $weightedSum / $totalAudits : null;

    // Colour band per the spec (different thresholds from the per-question
    // tints used elsewhere — keep these inline so they don't drift).
    $bandClass = function (?float $s): string {
        if ($s === null) return '';
        if ($s >= 90) return 'aud-sum-green';
        if ($s >= 80) return 'aud-sum-orange';
        return 'aud-sum-red';
    };
    ?>
    <div class="page-header"><h2>📊 Audit Summary</h2></div>

    <div class="form-card" style="max-width:none;margin-bottom:14px">
        <form method="GET" id="auditSummaryFilter" class="aud-sum-mode-<?= h($mode) ?>" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
            <input type="hidden" name="page" value="audit_summary">
            <input type="hidden" name="view" value="1">
            <div class="form-group">
                <label>View</label>
                <select name="mode" id="aud-sum-mode" class="form-control" style="width:110px">
                    <option value="month" <?= $mode === 'month' ? 'selected' : '' ?>>Month</option>
                    <option value="year"  <?= $mode === 'year'  ? 'selected' : '' ?>>Year (FY)</option>
                </select>
            </div>
            <?php
            // Inputs in the inactive mode get `disabled` so the browser
            // doesn't validate (or submit) them. A date input with a
            // value past `max` blocks the form's submit entirely if
            // it's not disabled — that was the "Year view: button does
            // nothing" bug.
            $monthDisabled = $mode === 'year' ? 'disabled' : '';
            $yearDisabled  = $mode === 'month' ? 'disabled' : '';
            // For month mode we want the date inputs anchored to the
            // picked month — but value="2027-03-31" in year mode would
            // exceed max="<today>" and block submit. Keep a safe value
            // when disabled.
            $fromValDisplay = $mode === 'month' ? $fromDate : sprintf('%04d-%02d-01', $year, $month);
            $monthBound     = sprintf('%04d-%02d-01', $year, $month);
            $monthBoundEnd  = date('Y-m-t', strtotime($monthBound));
            if ($monthBoundEnd > date('Y-m-d')) $monthBoundEnd = date('Y-m-d');
            $toValDisplay   = $mode === 'month' ? $toDate : $monthBoundEnd;
            ?>
            <div class="form-group aud-sum-month-only">
                <label>Month</label>
                <select name="month" id="aud-sum-month" class="form-control" style="width:140px" <?= $monthDisabled ?>>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $month === $m ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="form-group aud-sum-month-only">
                <label>Year</label>
                <select name="year" id="aud-sum-year" class="form-control" style="width:100px" <?= $monthDisabled ?>>
                    <?php $curY = (int)date('Y'); for ($y = $curY - 2; $y <= $curY + 1; $y++): ?>
                        <option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="form-group aud-sum-month-only">
                <label>From</label>
                <input type="date" name="from_date" id="aud-sum-from" class="form-control" style="width:160px"
                       value="<?= h($fromValDisplay) ?>" max="<?= h(date('Y-m-d')) ?>" <?= $monthDisabled ?>>
            </div>
            <div class="form-group aud-sum-month-only">
                <label>To</label>
                <input type="date" name="to_date" id="aud-sum-to" class="form-control" style="width:160px"
                       value="<?= h($toValDisplay) ?>" max="<?= h(date('Y-m-d')) ?>" <?= $monthDisabled ?>>
            </div>
            <div class="form-group aud-sum-year-only">
                <label>Financial Year</label>
                <select name="fy" id="aud-sum-fy" class="form-control" style="width:160px" <?= $yearDisabled ?>>
                    <?php
                    $currentFy = auditSummaryFyStartYear();
                    for ($f = $currentFy - 3; $f <= $currentFy + 1; $f++):
                    ?>
                        <option value="<?= $f ?>" <?= $fyStartYear === $f ? 'selected' : '' ?>><?= h(auditSummaryFyLabel($f)) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <?php
            $statusOptions = [
                'draft'             => 'Draft',
                'submitted'         => 'Pending SM Justify',
                'operation_review'  => 'Pending Operation Review',
                'approver_review'   => 'Pending Approval',
                'management_review' => 'Pending Management Approval',
                'approved'          => 'Approved',
                'sent_back'         => 'Sent Back',
            ];
            $pickedCount = count($status);
            $pickedLabel = $pickedCount === 0
                ? 'None selected'
                : ($pickedCount === count($statusOptions)
                    ? 'All statuses'
                    : ($pickedCount . ' selected'));
            ?>
            <div class="form-group" style="position:relative">
                <label>Status</label>
                <button type="button" id="aud-sum-status-btn" class="form-control"
                        style="text-align:left;cursor:pointer;min-width:200px;display:flex;align-items:center;justify-content:space-between;gap:6px">
                    <span id="aud-sum-status-label"><?= h($pickedLabel) ?></span>
                    <span style="color:var(--muted);font-size:10px">▾</span>
                </button>
                <div id="aud-sum-status-panel"
                     style="display:none;position:absolute;top:calc(100% + 4px);left:0;background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:6px 0;z-index:100;min-width:240px;box-shadow:0 8px 20px rgba(0,0,0,.35)">
                    <label style="display:flex;align-items:center;gap:8px;padding:6px 12px;font-size:12px;color:var(--muted);border-bottom:1px solid var(--border);cursor:pointer">
                        <input type="checkbox" id="aud-sum-status-all">
                        <span>Select all</span>
                    </label>
                    <?php foreach ($statusOptions as $val => $lbl): ?>
                    <label style="display:flex;align-items:center;gap:8px;padding:6px 12px;cursor:pointer;font-size:13px">
                        <input type="checkbox" class="aud-sum-status-cb" name="status[]" value="<?= h($val) ?>"
                               <?= in_array($val, $status, true) ? 'checked' : '' ?>>
                        <span><?= h($lbl) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-group">
                <label>Audit Template</label>
                <select name="template_id" class="form-control">
                    <option value="0">All templates</option>
                    <?php foreach ($templates as $t): ?>
                        <option value="<?= (int)$t['id'] ?>" <?= $templateId === (int)$t['id'] ? 'selected' : '' ?>>
                            <?= h($t['name']) ?><?= empty($t['is_active']) ? ' (inactive)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">View</button>
            <?php if ($doLoad): ?>
            <a href="?<?= h($exportQs) ?>" class="btn btn-ghost" target="_blank">📥 Export CSV</a>
            <span class="hint" style="font-size:11px;margin-left:6px">
                Showing <strong><?= h($statusLabel) ?></strong> audits for
                <?php if ($mode === 'year'): ?>
                    <strong><?= h(auditSummaryFyLabel($fyStartYear)) ?></strong>
                <?php else:
                    $monthStart = sprintf('%04d-%02d-01', $year, $month);
                    $monthEnd   = date('Y-m-t', strtotime($monthStart));
                    $monthSpan  = ($fromDate === $monthStart && $toDate === $monthEnd);
                ?>
                    <?php if ($monthSpan): ?>
                        <strong><?= h(date('F Y', mktime(0, 0, 0, $month, 1, $year))) ?></strong>
                    <?php else: ?>
                        <strong><?= h(date('d-M-Y', strtotime($fromDate))) ?></strong>
                        to
                        <strong><?= h(date('d-M-Y', strtotime($toDate))) ?></strong>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($templateId > 0 && $templateName !== ''): ?>
                    · template <strong><?= h($templateName) ?></strong>
                <?php endif; ?>.
            </span>
            <?php endif; ?>
        </form>
    </div>

    <style>
    /* Colour bands for the average-score cells. Same hue family as the
       audit register's per-question tints, but the thresholds differ
       (90 / 70) so we use a distinct class prefix. */
    .aud-sum-green  { background:rgba(39,174,96,.15);  color:#3ddb87; font-weight:600; }
    .aud-sum-orange { background:rgba(255,150,40,.18); color:#ffb347; font-weight:600; }
    .aud-sum-red    { background:rgba(220,64,64,.15);  color:#ff7878; font-weight:600; }
    .aud-sum-legend span { display:inline-block; padding:2px 10px; border-radius:4px; font-size:11px; margin-right:6px; }
    </style>

    <?php if (!$doLoad): ?>
    <div class="rpt-prompt">Apply filters and click <strong>View</strong> to load the summary.</div>
    <?php elseif ($mode === 'year'):
        // FY months in display order: Apr (4) … Mar (3 of next year).
        $fyMonths = [
            [4,  $fyStartYear],     [5,  $fyStartYear],     [6,  $fyStartYear],
            [7,  $fyStartYear],     [8,  $fyStartYear],     [9,  $fyStartYear],
            [10, $fyStartYear],     [11, $fyStartYear],     [12, $fyStartYear],
            [1,  $fyStartYear + 1], [2,  $fyStartYear + 1], [3,  $fyStartYear + 1],
        ];
        $locs    = $monthly['locations'];
        $cells   = $monthly['cells'];
    ?>
    <div class="form-card" style="max-width:none">
        <div class="form-section-title" style="display:flex;align-items:center;flex-wrap:wrap;gap:10px">
            <span>Monthly Average Score by Location</span>
            <span class="hint" style="font-size:11px;font-weight:400">
                <?= count($locs) ?> location<?= count($locs) === 1 ? '' : 's' ?>,
                <?= $totalAudits ?> audit<?= $totalAudits === 1 ? '' : 's' ?>
            </span>
            <div class="aud-sum-legend" style="margin-left:auto">
                <span class="aud-sum-green">≥ 90</span>
                <span class="aud-sum-orange">≥ 80</span>
                <span class="aud-sum-red">&lt; 80</span>
            </div>
        </div>
        <div class="table-wrap" data-stack style="overflow-x:auto">
            <table class="table" style="min-width:1100px">
                <thead>
                    <tr>
                        <th style="position:sticky;left:0;background:var(--surface);z-index:2;min-width:220px">Location</th>
                        <?php foreach ($fyMonths as [$m, $y]): ?>
                            <th class="num" style="text-align:center;min-width:78px"><?= h(date('M Y', mktime(0,0,0,$m,1,$y))) ?></th>
                        <?php endforeach; ?>
                        <th class="num" style="text-align:right;min-width:100px;background:rgba(255,255,255,.04)">FY Avg</th>
                        <th class="num" style="text-align:right;min-width:80px">Audits</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$locs): ?>
                    <tr><td colspan="<?= 1 + count($fyMonths) + 2 ?>" style="text-align:center;color:var(--muted);padding:18px">
                        No audits matched the selected statuses in this range.
                    </td></tr>
                <?php else: foreach ($locs as $l):
                    $lid = (int)$l['location_id'];
                ?>
                    <tr>
                        <td style="position:sticky;left:0;background:var(--surface);z-index:1;font-weight:500"><?= h((string)$l['location_name']) ?></td>
                        <?php foreach ($fyMonths as [$m, $y]):
                            $c    = $cells[$lid][$m] ?? null;
                            $cAvg = $c && $c['avg'] !== null ? round((float)$c['avg'], 2) : null;
                        ?>
                            <td class="num <?= $bandClass($cAvg) ?>" style="text-align:center;font-family:Consolas,monospace">
                                <?php if ($c && $cAvg !== null): ?>
                                    <?= number_format($cAvg, 2) ?>
                                    <div style="font-size:9px;font-weight:400;opacity:.75;margin-top:-2px"><?= (int)$c['count'] ?></div>
                                <?php else: ?>
                                    <span style="color:var(--muted);font-weight:400">—</span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="num <?= $bandClass($l['avg_score']) ?>" style="text-align:right;font-family:Consolas,monospace;background:rgba(255,255,255,.04)">
                            <?= $l['avg_score'] !== null ? number_format((float)$l['avg_score'], 2) : '—' ?>
                        </td>
                        <td class="num" style="text-align:right;font-family:Consolas,monospace;color:var(--muted)">
                            <?= (int)$l['total_audits'] ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
                <?php if ($locs): ?>
                <tfoot>
                    <tr style="background:var(--border);font-weight:700">
                        <td style="position:sticky;left:0;background:var(--border);z-index:1">Overall (<?= count($locs) ?> location<?= count($locs) === 1 ? '' : 's' ?>)</td>
                        <?php
                        // Month-totals across all locations.
                        foreach ($fyMonths as [$m, $y]):
                            $sumW = 0.0; $cntM = 0;
                            foreach ($locs as $l) {
                                $c = $cells[(int)$l['location_id']][$m] ?? null;
                                if ($c && $c['avg'] !== null) {
                                    $sumW += $c['avg'] * $c['count'];
                                    $cntM += (int)$c['count'];
                                }
                            }
                            $monthAvg = $cntM > 0 ? round($sumW / $cntM, 2) : null;
                        ?>
                            <td class="num <?= $bandClass($monthAvg) ?>" style="text-align:center;font-family:Consolas,monospace">
                                <?= $monthAvg !== null ? number_format($monthAvg, 2) : '—' ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="num <?= $bandClass($overallAvg) ?>" style="text-align:right;font-family:Consolas,monospace">
                            <?= $overallAvg !== null ? number_format($overallAvg, 2) : '—' ?>
                        </td>
                        <td class="num" style="text-align:right;font-family:Consolas,monospace"><?= (int)$totalAudits ?></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
    <?php else: ?>
    <div class="form-card" style="max-width:none">
        <div class="form-section-title" style="display:flex;align-items:center;flex-wrap:wrap;gap:10px">
            <span>Average Score by Location</span>
            <span class="hint" style="font-size:11px;font-weight:400">
                <?= count($rows) ?> location<?= count($rows) === 1 ? '' : 's' ?>,
                <?= $totalAudits ?> audit<?= $totalAudits === 1 ? '' : 's' ?>
            </span>
            <div class="aud-sum-legend" style="margin-left:auto">
                <span class="aud-sum-green">≥ 90</span>
                <span class="aud-sum-orange">≥ 80</span>
                <span class="aud-sum-red">&lt; 80</span>
            </div>
        </div>
        <div class="table-wrap" data-stack style="overflow-x:auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Location</th>
                        <th class="num" style="width:120px;text-align:right">Audits</th>
                        <th class="num" style="width:140px;text-align:right">Avg Score</th>
                        <th class="num" style="width:120px;text-align:right">Min</th>
                        <th class="num" style="width:120px;text-align:right">Max</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:18px">
                        No audits matched the selected statuses in this range.
                    </td></tr>
                <?php else: foreach ($rows as $r):
                    $avg = $r['avg_score'] !== null ? round((float)$r['avg_score'], 2) : null;
                ?>
                    <tr>
                        <td><?= h((string)($r['location_name'] ?? '—')) ?></td>
                        <td class="num" style="text-align:right;font-family:Consolas,monospace"><?= (int)$r['audit_count'] ?></td>
                        <td class="num <?= $bandClass($avg) ?>" style="text-align:right;font-family:Consolas,monospace">
                            <?= $avg !== null ? number_format($avg, 2) : '—' ?>
                        </td>
                        <td class="num" style="text-align:right;font-family:Consolas,monospace;color:var(--muted)">
                            <?= $r['min_score'] !== null ? number_format((float)$r['min_score'], 2) : '—' ?>
                        </td>
                        <td class="num" style="text-align:right;font-family:Consolas,monospace;color:var(--muted)">
                            <?= $r['max_score'] !== null ? number_format((float)$r['max_score'], 2) : '—' ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
                <?php if ($rows): ?>
                <tfoot>
                    <tr style="background:var(--border);font-weight:700">
                        <td>Overall (<?= count($rows) ?> location<?= count($rows) === 1 ? '' : 's' ?>)</td>
                        <td class="num" style="text-align:right;font-family:Consolas,monospace"><?= (int)$totalAudits ?></td>
                        <td class="num <?= $bandClass($overallAvg) ?>" style="text-align:right;font-family:Consolas,monospace">
                            <?= $overallAvg !== null ? number_format($overallAvg, 2) : '—' ?>
                        </td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <style>
    /* Toggle Month vs Year control groups based on the form's mode class. */
    .aud-sum-mode-month .aud-sum-year-only  { display:none; }
    .aud-sum-mode-year  .aud-sum-month-only { display:none; }
    </style>
    <script>
    // Mode toggle + Month/Year auto-fill for the from/to dates.
    (function () {
        var modeEl  = document.getElementById('aud-sum-mode');
        var form    = document.getElementById('auditSummaryFilter');
        var monthEl = document.getElementById('aud-sum-month');
        var yearEl  = document.getElementById('aud-sum-year');
        var fromEl  = document.getElementById('aud-sum-from');
        var toEl    = document.getElementById('aud-sum-to');
        var fyEl    = document.getElementById('aud-sum-fy');
        if (!modeEl || !form) return;

        var TODAY = <?= json_encode(date('Y-m-d')) ?>;

        function pad(n) { return n < 10 ? '0' + n : '' + n; }
        function lastDay(y, m) { return new Date(y, m, 0).getDate(); }

        // When the user changes Month or Year, snap From/To to that month
        // bounds. The "To" date is capped at today for the current month
        // so the default range never points into the future. NOTE: we
        // intentionally don't lock fromEl.min/max here — the calendar
        // popup needs to scroll freely across months, otherwise the user
        // can't pick May from an April baseline without reloading the
        // page. The change handler below re-syncs everything once a new
        // date is picked.
        function snapDates() {
            if (!monthEl || !yearEl || !fromEl || !toEl) return;
            var y = parseInt(yearEl.value, 10);
            var m = parseInt(monthEl.value, 10);
            if (!y || !m) return;
            var first = y + '-' + pad(m) + '-01';
            var last  = y + '-' + pad(m) + '-' + pad(lastDay(y, m));
            if (last > TODAY) last = TODAY;
            if (first > TODAY) first = TODAY; // future month → collapse
            fromEl.value = first;
            toEl.value   = last;
        }
        if (monthEl) monthEl.addEventListener('change', snapDates);
        if (yearEl)  yearEl .addEventListener('change', snapDates);

        // When the user picks a From date manually, sync the Month/Year
        // selects to its month and snap To to that month's last day
        // (capped at today). Keeps the picker row internally consistent
        // — picking 01-04-2026 from a May/2026 baseline lands on April
        // 2026 with To=30-Apr-2026 rather than 29-May-2026.
        if (fromEl) {
            fromEl.addEventListener('change', function () {
                var v = fromEl.value;
                var m = /^(\d{4})-(\d{2})-\d{2}$/.exec(v);
                if (!m) return;
                var y = +m[1], mo = +m[2];
                if (monthEl) monthEl.value = String(mo);
                if (yearEl)  yearEl.value  = String(y);
                var lastN = lastDay(y, mo);
                var last  = y + '-' + pad(mo) + '-' + pad(lastN);
                if (last > TODAY) last = TODAY;
                toEl.value = last;
            });
        }

        // Mode swap — flip the CSS classes and toggle `disabled` on
        // inputs in the inactive mode so the browser doesn't validate
        // them (an out-of-bounds date silently blocks form submit).
        function applyMode() {
            var isMonth = modeEl.value === 'month';
            form.classList.toggle('aud-sum-mode-month',  isMonth);
            form.classList.toggle('aud-sum-mode-year',  !isMonth);
            if (monthEl) monthEl.disabled = !isMonth;
            if (yearEl)  yearEl.disabled  = !isMonth;
            if (fromEl)  fromEl.disabled  = !isMonth;
            if (toEl)    toEl.disabled    = !isMonth;
            if (fyEl)    fyEl.disabled    =  isMonth;
            if (isMonth) snapDates();
        }
        modeEl.addEventListener('change', applyMode);
    })();

    // Status checkbox-dropdown: click button to toggle, click outside to
    // close, "Select all" toggles every status, button label reflects the
    // current selection count.
    (function () {
        var btn   = document.getElementById('aud-sum-status-btn');
        var panel = document.getElementById('aud-sum-status-panel');
        var label = document.getElementById('aud-sum-status-label');
        var all   = document.getElementById('aud-sum-status-all');
        if (!btn || !panel || !label || !all) return;
        var boxes = panel.querySelectorAll('.aud-sum-status-cb');

        function syncLabel() {
            var n = 0;
            boxes.forEach(function (b) { if (b.checked) n++; });
            if (n === 0)             label.textContent = 'None selected';
            else if (n === boxes.length) label.textContent = 'All statuses';
            else                     label.textContent = n + ' selected';
            all.checked = (n === boxes.length);
            all.indeterminate = (n > 0 && n < boxes.length);
        }
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            panel.style.display = (panel.style.display === 'block') ? 'none' : 'block';
        });
        panel.addEventListener('click', function (e) { e.stopPropagation(); });
        document.addEventListener('click', function () { panel.style.display = 'none'; });
        all.addEventListener('change', function () {
            boxes.forEach(function (b) { b.checked = all.checked; });
            syncLabel();
        });
        boxes.forEach(function (b) { b.addEventListener('change', syncLabel); });
        syncLabel();
    })();
    </script>
    <?php
}

// CSV export of the same dataset rendered on the page. Same access gate
// (txn_audit_summary), same filter parsing, same query — just streams
// rows out as CSV with a totals tail. fputcsv() takes the explicit
// `escape: ''` arg so the call stays clean on PHP 8.4 (the implicit
// default was deprecated).
function exportAuditSummary(): void {
    if (!isSuperadmin() && !hasTxn('audit_summary')) { echo 'Access denied.'; exit; }

    [$mode, $month, $year, $fyStartYear, $fromDate, $toDate, $templateId, $status] = auditSummaryFilters();
    $rows         = auditSummaryQuery($fromDate, $toDate, $templateId, $status);
    $statusLabel  = auditSummaryStatusLabel($status);

    // Resolve template name once for the CSV header (purely cosmetic).
    $templateName = 'All';
    if ($templateId > 0) {
        try {
            $st = getDb()->prepare('SELECT name FROM audit_templates WHERE id = ?');
            $st->execute([$templateId]);
            $templateName = (string)($st->fetchColumn() ?: ('#' . $templateId));
        } catch (Exception $e) { $templateName = '#' . $templateId; }
    }

    $tplSlug    = preg_replace('/[^a-zA-Z0-9]+/', '_', $templateName) ?: 'All';
    $statusSlug = preg_replace('/[^a-zA-Z0-9]+/', '_', $statusLabel) ?: 'All';
    if ($mode === 'year') {
        $periodSlug  = 'FY' . $fyStartYear . '-' . substr((string)($fyStartYear + 1), -2);
        $periodLabel = auditSummaryFyLabel($fyStartYear);
    } else {
        $periodSlug  = $fromDate . '_to_' . $toDate;
        $monthStart  = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd    = date('Y-m-t', strtotime($monthStart));
        $periodLabel = ($fromDate === $monthStart && $toDate === $monthEnd)
            ? date('F Y', mktime(0, 0, 0, $month, 1, $year))
            : ($fromDate . ' to ' . $toDate);
    }
    $filename   = "audit_summary_{$periodSlug}_{$statusSlug}_{$tplSlug}.csv";
    $filename   = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');

    fputcsv($out, ['Audit Summary'], escape: '');
    fputcsv($out, ['View', ucfirst($mode), 'Period', $periodLabel], escape: '');
    fputcsv($out, ['Status', $statusLabel], escape: '');
    fputcsv($out, ['Audit Template', $templateName], escape: '');
    fputcsv($out, [], escape: '');
    fputcsv($out, ['Location', 'Audits', 'Avg Score', 'Min Score', 'Max Score', 'Band'], escape: '');

    $totalAudits = 0; $weightedSum = 0.0;
    foreach ($rows as $r) {
        $n   = (int)$r['audit_count'];
        $avg = $r['avg_score'] !== null ? round((float)$r['avg_score'], 2) : null;
        $totalAudits += $n;
        if ($avg !== null) $weightedSum += $n * $avg;

        $band = '';
        if ($avg !== null) {
            if      ($avg >= 90) $band = 'Green (>=90)';
            else if ($avg >= 80) $band = 'Orange (>=80)';
            else                 $band = 'Red (<80)';
        }
        fputcsv($out, [
            (string)($r['location_name'] ?? ''),
            $n,
            $avg !== null ? number_format($avg, 2, '.', '') : '',
            $r['min_score'] !== null ? number_format((float)$r['min_score'], 2, '.', '') : '',
            $r['max_score'] !== null ? number_format((float)$r['max_score'], 2, '.', '') : '',
            $band,
        ], escape: '');
    }

    fputcsv($out, [], escape: '');
    $overallAvg = $totalAudits > 0 ? round($weightedSum / $totalAudits, 2) : null;
    fputcsv($out, [
        'Overall (' . count($rows) . ' location' . (count($rows) === 1 ? '' : 's') . ')',
        $totalAudits,
        $overallAvg !== null ? number_format($overallAvg, 2, '.', '') : '',
        '', '', '',
    ], escape: '');

    fclose($out);
    exit;
}

function renderAuditHeader(array $a): void {
    // Resolve Operation / Management actor names lazily — auditGetById
    // joins the auditor/SM/approver but not the new role codes, so we
    // ask once if either is set. Cheap (single point lookup).
    $opName = null; $mgName = null;
    if (!empty($a['operation_code']) || !empty($a['management_code'])) {
        try {
            $codes = array_values(array_filter([$a['operation_code'] ?? null, $a['management_code'] ?? null]));
            if ($codes) {
                $ph = implode(',', array_fill(0, count($codes), '?'));
                $st = getDb()->prepare("SELECT employee_code, full_name FROM employees WHERE employee_code IN ($ph)");
                $st->execute($codes);
                foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $code => $name) {
                    if ($code === ($a['operation_code'] ?? null))  $opName = $name;
                    if ($code === ($a['management_code'] ?? null)) $mgName = $name;
                }
            }
        } catch (Exception $e) { /* pre-migration DB — column missing, ignore */ }
    }
    ?>
    <div class="page-header">
        <h2>Audit <code><?= $a['audit_number'] ? h($a['audit_number']) : '<span style="color:var(--muted);font-style:italic;font-weight:400">(unsaved draft)</span>' ?></code></h2>
        <div><?= auditStatusBadge($a['status']) ?></div>
    </div>
    <?php
    // Compact "submitted on" line under each role-name cell. Each stage
    // gets its own datetime column on `audits`; show the stage label that
    // matches the column so the timeline reads naturally.
    $stageDate = function (?string $val, string $label) {
        if (empty($val)) return '';
        return '<div class="stat-sub" style="font-size:11px;color:var(--muted);margin-top:2px">'
            . h($label) . ': ' . h($val) . '</div>';
    };
    ?>
    <div class="form-card" style="max-width:none;margin-bottom:18px">
        <div class="form-grid" style="grid-template-columns:repeat(4,1fr)">
            <div><div class="stat-lbl">Date</div><div><?= h($a['audit_date']) ?></div></div>
            <div><div class="stat-lbl">Store</div><div><?= h($a['location_name'] ?? '—') ?></div></div>
            <div><div class="stat-lbl">Audit Template</div><div><?= h($a['template_name'] ?? '—') ?></div></div>
            <div><div class="stat-lbl">Score</div><div><strong><?= $a['total_score'] !== null ? number_format((float)$a['total_score'], 2) : '—' ?></strong></div></div>

            <div>
                <div class="stat-lbl">Auditor</div>
                <div><?= h($a['auditor_name'] ?? $a['auditor_code']) ?></div>
                <?= $stageDate($a['submitted_at'] ?? null, 'Submitted') ?>
            </div>
            <div>
                <div class="stat-lbl">Store Manager</div>
                <div><?= h($a['store_manager_name'] ?? '—') ?></div>
                <?= $stageDate($a['manager_reviewed_at'] ?? null, 'Reviewed') ?>
            </div>
            <div>
                <div class="stat-lbl">Present Store Executive</div>
                <div><?= h($a['store_executive_name'] ?? '—') ?></div>
            </div>
            <div>
                <div class="stat-lbl">Operation Team</div>
                <div><?= $opName !== null ? h($opName) : '—' ?></div>
                <?= $stageDate($a['operation_reviewed_at'] ?? null, 'Reviewed') ?>
            </div>
            <div>
                <div class="stat-lbl">Approver</div>
                <div><?= h($a['approver_name'] ?? '—') ?></div>
                <?= $stageDate($a['approved_at'] ?? null, 'Approved') ?>
            </div>

            <div>
                <div class="stat-lbl">Management</div>
                <div><?= $mgName !== null ? h($mgName) : '—' ?></div>
                <?= $stageDate($a['management_approved_at'] ?? null, 'Approved (Final)') ?>
            </div>
        </div>
    </div>
    <?php
}

// ── Main category/parameter accordion for edit OR view ──
// $locationId scopes the per-question history popup to the same store as
// this audit (auditors compare a single location across time, not the
// same question across stores).
function renderAuditEditTable(array $tree, int $auditId, bool $readonly, int $locationId = 0): void {
    ?>
    <div class="table-wrap" data-stack>
        <table class="table audit-table">
            <thead>
                <tr>
                    <th style="width:46px">#</th>
                    <th>Audit Category / Parameter</th>
                    <th style="width:90px">Weightage</th>
                    <?php if ($readonly): ?>
                        <th style="width:110px">Modified Wt.</th>
                    <?php endif; ?>
                    <th style="width:130px">Value</th>
                    <th style="width:90px">Obtain Score</th>
                    <th style="width:110px">Obtain %</th>
                    <th style="min-width:150px">Auditor Remarks</th>
                    <th style="min-width:140px">Documents</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $idx = 0;
            foreach ($tree as $c):
                $idx++;
                // Pre-compute the category's weighted obtain (0..100 within
                // the category) for read-only renders. Edit mode lets the
                // JS recalc handle this so the values track input changes.
                $catSumObt   = null;
                $catObtPct   = null;
                $catCls      = '';
                $catBarCls   = '';
                if ($readonly) {
                    $sum = 0.0; $any = false;
                    foreach ($c['parameters'] as $pp) {
                        $rr = $pp['response'] ?? null;
                        if ($rr && $rr['obtain_score'] !== null) {
                            $any = true;
                            $sum += (float)$rr['modified_weightage'] * (float)$rr['obtain_score'] / 100.0;
                        }
                    }
                    if ($any) {
                        $catSumObt = round($sum, 2);
                        $catObtPct = round((float)$c['modified_weightage'] * $catSumObt / 100.0, 2);
                        $catCls    = auditScoreColor($catSumObt);
                        $catBarCls = $catCls ? str_replace('audit-score-', 'cat-score-', $catCls) : '';
                    }
                }
            ?>
                <tr class="audit-cat-row<?= $catBarCls ? ' ' . h($catBarCls) : '' ?>" data-cat-id="<?= (int)$c['id'] ?>">
                    <td class="srno" data-label="#"><?= $idx ?></td>
                    <td class="cat-name" data-label="Category"><strong><?= $idx ?>. <?= h($c['name']) ?></strong></td>
                    <td class="num" data-label="Weightage"><?= number_format((float)$c['weightage'], 0) ?></td>
                    <?php if ($readonly): ?>
                        <td data-label="Modified Wt."><?= number_format((float)$c['modified_weightage'], 0) ?></td>
                    <?php endif; ?>
                    <td></td>
                    <td class="num cat-obtain <?= h($catCls) ?>" data-label="Obtain Score"><?= $catSumObt !== null ? number_format($catSumObt, 2) : '—' ?></td>
                    <td class="num cat-obtain-pct <?= h($catCls) ?>" data-label="Obtain %"><?= $catObtPct !== null ? number_format($catObtPct, 2) : '—' ?></td>
                    <td colspan="2"></td>
                </tr>
                <?php foreach ($c['parameters'] as $pIdx => $p):
                    $r = $p['response'] ?? null;
                    $respId = $r ? (int)$r['id'] : 0;
                    $valEnt = $r ? $r['value_entered'] : null;
                    $obt    = $r ? $r['obtain_score']  : null;
                    $modW   = $r ? (float)$r['modified_weightage'] : (float)$p['score_weightage'];
                    // Display weightage: use the response snapshot when available (historical
                    // value at audit-creation time), otherwise the current master value.
                    $actW   = $r && isset($r['actual_weightage']) ? (float)$r['actual_weightage'] : (float)$p['score_weightage'];
                    $auRmk  = $r ? ($r['auditor_remark'] ?? '') : '';
                    $apRmk  = $r ? ($r['approver_remark'] ?? '') : '';
                    $smRmk  = $r ? ($r['store_manager_remark'] ?? '') : '';
                    $opRmk  = $r ? ($r['operation_remark'] ?? '') : '';
                    $mgRmk  = $r ? ($r['management_remark'] ?? '') : '';
                    $obtPct = ($obt !== null) ? round($modW * $obt / 100, 2) : null;
                    $scoreCls = auditScoreColor($obt !== null ? (float)$obt : null);
                ?>
                <tr class="audit-param-row<?= !empty($p['is_orphan']) ? ' is-orphan' : '' ?>" data-cat-id="<?= (int)$c['id'] ?>" data-param-id="<?= (int)$p['id'] ?>"
                    data-type="<?= h($p['type']) ?>" data-max="<?= $p['max_value'] !== null ? h($p['max_value']) : '' ?>">
                    <td class="srno"></td>
                    <td class="param-text" data-label="Parameter">
                        <div class="param-text-wrap">
                            <span class="param-text-label"><?= h($p['parameter_text']) ?><?php if (!empty($p['is_orphan'])): ?> <span class="orphan-tag" title="This parameter has been removed from the template since this audit was filed. The recorded answer is preserved.">(archived)</span><?php endif; ?></span>
                            <button type="button" class="btn-icon-history" title="View history of this question for this store"
                                    data-param-id="<?= (int)$p['id'] ?>"
                                    data-audit-id="<?= (int)$auditId ?>"
                                    data-location-id="<?= (int)$locationId ?>"
                                    data-param-text="<?= h($p['parameter_text']) ?>"
                                    data-param-type="<?= h($p['type']) ?>">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M3 12a9 9 0 1 0 3-6.7"></path>
                                    <polyline points="3 4 3 10 9 10"></polyline>
                                    <polyline points="12 7 12 12 15 14"></polyline>
                                </svg>
                            </button>
                        </div>
                        <?php if ($p['type'] !== 'rating'): ?>
                            <span class="hint">[<?= h($p['type']) ?><?= $p['type'] === 'value' && $p['max_value'] !== null ? ' max ' . h($p['max_value']) : '' ?>]</span>
                        <?php endif; ?>
                        <?php if (!empty($smRmk)): ?>
                            <div class="sm-remark-banner">Store Manager: <?= h($smRmk) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($opRmk)): ?>
                            <div class="ops-remark-banner">Operation: <?= h($opRmk) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($apRmk)): ?>
                            <div class="approver-remark-banner">Approver: <?= h($apRmk) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($mgRmk)): ?>
                            <div class="mgmt-remark-banner">Management: <?= h($mgRmk) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="num" data-label="Weightage"><?= number_format($actW, 0) ?></td>
                    <?php if ($readonly): ?>
                        <td data-label="Modified Wt."><?= number_format((float)$modW, 0) ?></td>
                    <?php endif; ?>
                    <td data-label="Value">
                        <?php if ($readonly): ?>
                            <?php
                            // Show "value / out-of" so the reader can read the
                            // raw answer against its scale at a glance. Scale
                            // depends on the parameter type:
                            //   rating  → out of 5
                            //   value   → out of max_value (omitted if unset)
                            //   boolean → out of 1, labelled Yes/No for clarity
                            if ($valEnt === null) {
                                echo '—';
                            } elseif ($p['type'] === 'rating') {
                                echo h((string)(float)$valEnt) . ' <span style="color:var(--muted)">/ 5</span>';
                            } elseif ($p['type'] === 'boolean') {
                                $isYes = (float)$valEnt > 0;
                                echo ($isYes ? 'Yes' : 'No') . ' <span style="color:var(--muted)">/ 1</span>';
                            } else { // value
                                $rawV = h((string)(float)$valEnt);
                                if ($p['max_value'] !== null && (float)$p['max_value'] > 0) {
                                    echo $rawV . ' <span style="color:var(--muted)">/ ' . h((string)(float)$p['max_value']) . '</span>';
                                } else {
                                    echo $rawV;
                                }
                            }
                            ?>
                        <?php elseif ($p['type'] === 'boolean'): ?>
                            <select class="form-control param-value" name="param_value[<?= (int)$p['id'] ?>]">
                                <option value="">—</option>
                                <option value="1" <?= $valEnt !== null && (float)$valEnt > 0 ? 'selected' : '' ?>>Yes</option>
                                <option value="0" <?= $valEnt !== null && (float)$valEnt == 0 ? 'selected' : '' ?>>No</option>
                            </select>
                        <?php elseif ($p['type'] === 'rating'): ?>
                            <input type="number" step="0.5" min="0" max="5" class="form-control param-value param-rating"
                                   name="param_value[<?= (int)$p['id'] ?>]"
                                   value="<?= $valEnt !== null ? h((string)(float)$valEnt) : '' ?>"
                                   placeholder="0–5 (steps of 0.5)"
                                   title="Allowed values: 0, 0.5, 1, 1.5, 2, 2.5, 3, 3.5, 4, 4.5, 5"
                                   inputmode="decimal"
                                   onblur="if(this.value!==''){var v=parseFloat(this.value);if(!isNaN(v)){v=Math.max(0,Math.min(5,Math.round(v*2)/2));this.value=v;}}">
                        <?php else: /* value */ ?>
                            <input type="number" step="0.01" min="0" class="form-control param-value" name="param_value[<?= (int)$p['id'] ?>]" value="<?= $valEnt !== null ? h((string)(float)$valEnt) : '' ?>" placeholder="numeric">
                        <?php endif; ?>
                    </td>
                    <td class="num param-obtain <?= h($scoreCls) ?>" data-label="Obtain Score"><?= $obt !== null ? number_format((float)$obt, 2) : '—' ?></td>
                    <td class="num param-obtain-pct <?= h($scoreCls) ?>" data-label="Obtain %"><?= $obtPct !== null ? number_format($obtPct, 2) : '—' ?></td>
                    <td class="wide-cell" data-label="Auditor Remarks">
                        <?php if ($readonly): ?>
                            <?= nl2br(h($auRmk)) ?: '—' ?>
                        <?php else: ?>
                            <textarea class="form-control" rows="2" name="param_remark[<?= (int)$p['id'] ?>]"><?= h($auRmk) ?></textarea>
                        <?php endif; ?>
                    </td>
                    <td class="wide-cell" data-label="Documents">
                        <?php if ($p['attachments']): ?>
                            <div class="att-list">
                                <?php foreach ($p['attachments'] as $att):
                                    $isImg     = isset($att['mime_type']) && stripos((string)$att['mime_type'], 'image/') === 0;
                                    $pinsOpen  = (int)($att['pins_open']  ?? 0);
                                    $pinsTotal = (int)($att['pins_total'] ?? 0);
                                    $chipCls   = $pinsOpen > 0 ? ' has-open-pins'
                                              : ($pinsTotal > 0 ? ' has-resolved-pins' : '');
                                ?>
                                    <a class="att-chip<?= $chipCls ?>" href="?page=download_audit_attachment&audit_id=<?= $auditId ?>&att_id=<?= (int)$att['id'] ?>" target="_blank"
                                       title="<?= $pinsTotal > 0 ? ($pinsOpen . ' open / ' . $pinsTotal . ' total pin' . ($pinsTotal === 1 ? '' : 's')) : 'No annotations yet' ?>">
                                        <?= h($att['filename']) ?>
                                    </a>
                                    <?php if ($isImg): ?>
                                        <a class="att-annotate<?= $chipCls ?>" href="?page=audit_annotation_image&audit_att=<?= (int)$att['id'] ?>" target="_blank"
                                           title="<?= $pinsTotal > 0 ? ('Open viewer · ' . $pinsOpen . ' open of ' . $pinsTotal . ' pin' . ($pinsTotal === 1 ? '' : 's')) : 'Drop pins and comment on this image' ?>">
                                            📌 Annotate
                                            <?php if ($pinsOpen > 0): ?>
                                                <span class="att-pin-badge att-pin-badge-open"><?= $pinsOpen ?></span>
                                            <?php elseif ($pinsTotal > 0): ?>
                                                <span class="att-pin-badge att-pin-badge-done">✓</span>
                                            <?php endif; ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if (!$readonly): ?>
                                        <button type="button" class="btn-ghost-x"
                                            onclick="if(confirm('Delete this file?')){document.getElementById('auditAttDelAuditId').value='<?= $auditId ?>';document.getElementById('auditAttDelAttId').value='<?= (int)$att['id'] ?>';document.getElementById('auditAttDelForm').submit();}">×</button>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!$readonly): ?>
                            <input type="file" class="form-control param-files" name="param_files[<?= (int)$p['id'] ?>][]" accept="image/*,application/pdf" multiple capture="environment" style="font-size:11px;margin-top:4px">
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php // Modified-weight sum rows removed — only the approver edits weights now,
                      // and they get their own live-sum block in renderAuditApproveTable. ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php renderAuditHistoryModal(); ?>
    <?php
}

// ── Per-question history modal — markup + scoped CSS + click handler ──
// Rendered once on any page that shows audit responses. The history
// icons in each parameter row dispatch into the same modal.
function renderAuditHistoryModal(): void {
    static $rendered = false;
    if ($rendered) return;
    $rendered = true;
    ?>
    <style>
    /* Dark-theme aware — pulls colors from the global tokens defined in styles.php
       (--bg, --surface, --border, --text, --muted, --accent). */
    .param-text-wrap{display:flex;align-items:flex-start;gap:8px;flex-wrap:wrap}
    .param-text-label{flex:1;min-width:0}
    .orphan-tag{display:inline-block;background:rgba(201,168,0,.18);color:var(--yellow);font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;padding:1px 6px;border-radius:999px;margin-left:6px;vertical-align:middle}
    tr.is-orphan{opacity:.85}
    .btn-icon-history{appearance:none;border:1px solid var(--border);background:transparent;color:var(--muted);width:26px;height:26px;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;transition:background .12s,color .12s,border-color .12s}
    .btn-icon-history:hover{background:var(--accent);color:#fff;border-color:var(--accent)}
    .audit-hist-overlay{position:fixed;inset:0;background:rgba(0,0,0,.65);display:none;z-index:9000;align-items:flex-start;justify-content:center;padding:40px 16px;overflow:auto}
    .audit-hist-overlay.open{display:flex}
    .audit-hist-modal{background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:10px;width:100%;max-width:1000px;box-shadow:0 16px 48px rgba(0,0,0,.6);display:flex;flex-direction:column;max-height:calc(100vh - 80px);overflow:hidden}
    .audit-hist-head{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
    .audit-hist-head h3{margin:0;font-size:15px;font-weight:600;line-height:1.4;color:var(--text)}
    .audit-hist-head .sub{font-size:12px;color:var(--muted);margin-top:2px}
    .audit-hist-close{appearance:none;border:none;background:transparent;font-size:24px;line-height:1;cursor:pointer;color:var(--muted);padding:0 4px}
    .audit-hist-close:hover{color:var(--text)}
    .audit-hist-body{padding:14px 20px;overflow:auto;flex:1;color:var(--text)}
    .audit-hist-table{width:100%;border-collapse:collapse;font-size:12.5px;color:var(--text)}
    .audit-hist-table th,.audit-hist-table td{padding:8px 10px;border-bottom:1px solid var(--border);text-align:left;vertical-align:top}
    .audit-hist-table th{background:rgba(255,255,255,.04);font-weight:600;font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)}
    .audit-hist-table td.num{text-align:right;font-variant-numeric:tabular-nums}
    .audit-hist-table tbody tr:hover{background:rgba(255,255,255,.02)}
    .audit-hist-empty{padding:24px;text-align:center;color:var(--muted);font-style:italic}
    .audit-hist-loading{padding:24px;text-align:center;color:var(--muted)}
    @media (max-width: 720px){
        .audit-hist-modal{max-width:none}
        .audit-hist-table thead{display:none}
        .audit-hist-table,.audit-hist-table tbody,.audit-hist-table tr,.audit-hist-table td{display:block;width:100%}
        .audit-hist-table tr{border:1px solid var(--border);border-radius:8px;margin-bottom:10px;padding:6px}
        .audit-hist-table td{border-bottom:1px dashed var(--border);padding:6px 4px;display:flex;justify-content:space-between;gap:10px}
        .audit-hist-table td:last-child{border-bottom:none}
        .audit-hist-table td::before{content:attr(data-label);font-weight:600;color:var(--muted);font-size:11px;text-transform:uppercase}
        .audit-hist-table td.num{text-align:right}
    }
    </style>
    <div class="audit-hist-overlay" id="auditHistOverlay" role="dialog" aria-modal="true" aria-labelledby="auditHistTitle">
        <div class="audit-hist-modal">
            <div class="audit-hist-head">
                <div>
                    <h3 id="auditHistTitle">Question History</h3>
                    <div class="sub" id="auditHistSub"></div>
                </div>
                <button type="button" class="audit-hist-close" aria-label="Close" onclick="auditHistClose()">×</button>
            </div>
            <div class="audit-hist-body" id="auditHistBody">
                <div class="audit-hist-empty">No history.</div>
            </div>
        </div>
    </div>
    <script>
    (function(){
        var overlay = document.getElementById('auditHistOverlay');
        if (!overlay) return;
        var titleEl = document.getElementById('auditHistTitle');
        var subEl   = document.getElementById('auditHistSub');
        var bodyEl  = document.getElementById('auditHistBody');

        function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        }); }
        function fmt(v, decimals){
            if (v === null || v === undefined || v === '') return '—';
            var n = parseFloat(v); if (isNaN(n)) return esc(v);
            return n.toFixed(decimals == null ? 2 : decimals);
        }
        function fmtInt(v){
            if (v === null || v === undefined || v === '') return '—';
            var n = parseFloat(v); if (isNaN(n)) return esc(v);
            return Math.round(n).toString();
        }

        window.auditHistOpen = function(btn){
            var pid    = btn.getAttribute('data-param-id');
            var aid    = btn.getAttribute('data-audit-id') || '';
            var lid    = btn.getAttribute('data-location-id') || '';
            var pTxt   = btn.getAttribute('data-param-text') || '';
            var scoped = lid && lid !== '0';
            titleEl.textContent = pTxt || 'Question History';
            subEl.textContent = scoped
                ? 'Past responses for this store (newest first). Loading…'
                : 'Past responses across audits (newest first).';
            bodyEl.innerHTML = '<div class="audit-hist-loading">Loading…</div>';
            overlay.classList.add('open');
            document.body.style.overflow = 'hidden';

            var url = 'index.php?page=audit_param_history&param_id=' + encodeURIComponent(pid)
                    + (aid ? '&exclude_audit_id=' + encodeURIComponent(aid) : '')
                    + (scoped ? '&location_id=' + encodeURIComponent(lid) : '');
            fetch(url, { credentials: 'same-origin' })
                .then(function(r){ return r.json(); })
                .then(function(data){
                    if (data.error) {
                        bodyEl.innerHTML = '<div class="audit-hist-empty">' + esc(data.error) + '</div>';
                        return;
                    }
                    // Reflect the resolved scope back to the user — when the
                    // server confirms a location we promote the store name
                    // into the subline so it's clear what's being filtered.
                    if (data.location_id && data.location_name) {
                        subEl.textContent = 'Past responses for ' + data.location_name + ' (newest first).';
                    } else {
                        subEl.textContent = 'Past responses across audits (newest first).';
                    }
                    var rows = data.rows || [];
                    if (!rows.length) {
                        bodyEl.innerHTML = '<div class="audit-hist-empty">No previous responses for this question'
                            + (data.location_name ? ' at ' + esc(data.location_name) : '')
                            + '.</div>';
                        return;
                    }
                    // When scoped to one store the Store column would repeat
                    // the same value on every row, so drop it.
                    var showStore = !data.location_id;
                    var html = '<table class="audit-hist-table"><thead><tr>'
                             + '<th>Audit</th><th>Date</th>'
                             + (showStore ? '<th>Store</th>' : '')
                             + '<th>Store Manager</th>'
                             + '<th class="num">Weightage</th>'
                             + '<th class="num">Modified Wt.</th>'
                             + '<th class="num">Value</th>'
                             + '<th class="num">Obtain</th>'
                             + '<th>Auditor Remarks</th>'
                             + '</tr></thead><tbody>';
                    // Highlight history rows where the question's wording
                    // at that audit differs from the current text — keeps
                    // the auditor honest about what was actually asked at
                    // the time, even if the master template has been
                    // edited since.
                    var currentText = data.parameter_text || '';
                    rows.forEach(function(r){
                        var asked = r.hist_parameter_text || '';
                        var differs = asked && asked !== currentText;
                        var remarkCell = r.auditor_remark
                            ? esc(r.auditor_remark).replace(/\n/g,'<br>')
                            : '—';
                        if (differs) {
                            remarkCell = '<div style="font-size:11px;color:var(--yellow);margin-bottom:4px" title="Wording at the time of this audit">Asked then: ' + esc(asked) + '</div>' + remarkCell;
                        }
                        html += '<tr>'
                              + '<td data-label="Audit"><code>' + esc(r.audit_number || '#' + r.audit_id) + '</code></td>'
                              + '<td data-label="Date">' + esc(r.audit_date || '') + '</td>'
                              + (showStore ? '<td data-label="Store">' + esc(r.location_name || '—') + '</td>' : '')
                              + '<td data-label="Store Manager">' + esc(r.store_manager_name || '—') + '</td>'
                              + '<td data-label="Weightage" class="num">' + fmtInt(r.actual_weightage) + '</td>'
                              + '<td data-label="Modified Wt." class="num">' + fmtInt(r.modified_weightage) + '</td>'
                              + '<td data-label="Value" class="num">' + (r.value_entered === null ? '—' : esc(r.value_entered)) + '</td>'
                              + '<td data-label="Obtain" class="num">' + fmt(r.obtain_score) + '</td>'
                              + '<td data-label="Auditor Remarks">' + remarkCell + '</td>'
                              + '</tr>';
                    });
                    html += '</tbody></table>';
                    bodyEl.innerHTML = html;
                })
                .catch(function(){
                    bodyEl.innerHTML = '<div class="audit-hist-empty">Could not load history.</div>';
                });
        };
        window.auditHistClose = function(){
            overlay.classList.remove('open');
            document.body.style.overflow = '';
        };
        overlay.addEventListener('click', function(e){
            if (e.target === overlay) auditHistClose();
        });
        document.addEventListener('keydown', function(e){
            if (e.key === 'Escape' && overlay.classList.contains('open')) auditHistClose();
        });
        document.addEventListener('click', function(e){
            var btn = e.target.closest && e.target.closest('.btn-icon-history');
            if (btn) { e.preventDefault(); auditHistOpen(btn); }
        });
    })();
    </script>
    <?php
}

// ── Approver table — lock inputs, show approver_remark textareas ──
function renderAuditApproveTable(array $tree, int $auditId, int $locationId = 0): void {
    ?>
    <div class="table-wrap" data-stack>
        <table class="table audit-table">
            <thead>
                <tr>
                    <th style="width:46px">#</th>
                    <th>Audit Category / Parameter</th>
                    <th style="width:90px">Weightage</th>
                    <th style="width:110px">Modified Wt.</th>
                    <th style="width:100px">Value</th>
                    <th style="width:90px">Obtain</th>
                    <th style="width:110px">Obtain %</th>
                    <th>Auditor Remark</th>
                    <th>Store Manager Remark</th>
                    <th>Operation Remark</th>
                    <th>Your Remark</th>
                </tr>
            </thead>
            <tbody>
            <?php $idx = 0; foreach ($tree as $c): $idx++; ?>
                <tr class="audit-cat-row" data-cat-id="<?= (int)$c['id'] ?>">
                    <td><?= $idx ?></td>
                    <td><strong><?= h($c['name']) ?></strong></td>
                    <td class="num"><?= number_format((float)$c['weightage'], 0) ?></td>
                    <td>
                        <input type="number" step="1" min="0" max="100" inputmode="numeric" pattern="[0-9]*"
                               class="form-control cat-mod-wt"
                               name="cat_mod[<?= (int)$c['id'] ?>]"
                               value="<?= (int)round((float)$c['modified_weightage']) ?>">
                    </td>
                    <td colspan="7"></td>
                </tr>
                <?php foreach ($c['parameters'] as $p):
                    $r = $p['response'] ?? null;
                    $valEnt = $r ? $r['value_entered'] : null;
                    $obt    = $r ? $r['obtain_score']  : null;
                    $modW   = $r ? (float)$r['modified_weightage'] : (float)$p['score_weightage'];
                    $actW   = $r && isset($r['actual_weightage']) ? (float)$r['actual_weightage'] : (float)$p['score_weightage'];
                    $obtPct = ($obt !== null) ? round($modW * $obt / 100, 2) : null;
                    $scoreCls = auditScoreColor($obt !== null ? (float)$obt : null);
                ?>
                <tr class="audit-param-row" data-cat-id="<?= (int)$c['id'] ?>" data-param-id="<?= (int)$p['id'] ?>">
                    <td></td>
                    <td>
                        <div class="param-text-wrap">
                            <span class="param-text-label"><?= h($p['parameter_text']) ?></span>
                            <button type="button" class="btn-icon-history" title="View history of this question for this store"
                                    data-param-id="<?= (int)$p['id'] ?>"
                                    data-audit-id="<?= (int)$auditId ?>"
                                    data-location-id="<?= (int)$locationId ?>"
                                    data-param-text="<?= h($p['parameter_text']) ?>"
                                    data-param-type="<?= h($p['type']) ?>">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M3 12a9 9 0 1 0 3-6.7"></path>
                                    <polyline points="3 4 3 10 9 10"></polyline>
                                    <polyline points="12 7 12 12 15 14"></polyline>
                                </svg>
                            </button>
                        </div>
                        <?php renderAuditAttachmentChips($p['attachments'] ?? [], $auditId, true); ?>
                    </td>
                    <td class="num"><?= number_format($actW, 0) ?></td>
                    <td>
                        <input type="number" step="1" min="0" max="100" inputmode="numeric" pattern="[0-9]*"
                               class="form-control param-mod-wt"
                               name="param_mod[<?= (int)$p['id'] ?>]"
                               value="<?= (int)round($modW) ?>"
                               data-cat-id="<?= (int)$c['id'] ?>">
                    </td>
                    <td class="num"><?= $valEnt !== null ? h((string)(float)$valEnt) : '—' ?></td>
                    <td class="num <?= h($scoreCls) ?>"><?= $obt !== null ? number_format((float)$obt, 2) : '—' ?></td>
                    <td class="num <?= h($scoreCls) ?>"><?= $obtPct !== null ? number_format($obtPct, 2) : '—' ?></td>
                    <td><?= nl2br(h($r['auditor_remark'] ?? '')) ?: '—' ?></td>
                    <td><?= nl2br(h($r['store_manager_remark'] ?? '')) ?: '—' ?></td>
                    <td><?= nl2br(h($r['operation_remark'] ?? '')) ?: '—' ?></td>
                    <td><textarea class="form-control" rows="2" name="approver_remark[<?= (int)$p['id'] ?>]"><?= h($r['approver_remark'] ?? '') ?></textarea></td>
                </tr>
                <?php endforeach; ?>
                <tr class="audit-cat-sum" data-cat-id="<?= (int)$c['id'] ?>">
                    <td></td>
                    <td colspan="2" style="text-align:right;color:var(--muted);font-size:12px">Within-category param Modified Weightage sum:</td>
                    <td colspan="8" class="cat-param-sum" data-cat-id="<?= (int)$c['id'] ?>" style="font-weight:600">0 / 100</td>
                </tr>
            <?php endforeach; ?>
                <tr class="audit-total-sum">
                    <td></td>
                    <td colspan="2" style="text-align:right;font-weight:600">Category Modified Weightage sum:</td>
                    <td colspan="8" id="catTotalSum" style="font-weight:600">0 / 100</td>
                </tr>
            </tbody>
        </table>
    </div>

    <script>
    // Approver-side live sum recalc. Same shape the auditor used to have:
    // each category's param-mod sum must hit 100, and the category-mod
    // sum across all categories must hit 100. Visual feedback only — the
    // server re-validates on submit.
    (function () {
        var rows = document.querySelectorAll('.audit-param-row');
        function num(v) { var n = parseFloat(v); return isNaN(n) ? 0 : n; }
        function recalc() {
            var byCat = {};
            document.querySelectorAll('.param-mod-wt').forEach(function (el) {
                var cid = el.getAttribute('data-cat-id');
                byCat[cid] = (byCat[cid] || 0) + num(el.value);
            });
            document.querySelectorAll('.cat-param-sum').forEach(function (el) {
                var cid = el.getAttribute('data-cat-id');
                var sum = byCat[cid] || 0;
                el.textContent = sum + ' / 100';
                el.style.color = (sum === 100) ? 'var(--green)' : 'var(--red)';
            });
            var total = 0;
            document.querySelectorAll('.cat-mod-wt').forEach(function (el) { total += num(el.value); });
            var totEl = document.getElementById('catTotalSum');
            if (totEl) {
                totEl.textContent = total + ' / 100';
                totEl.style.color = (total === 100) ? 'var(--green)' : 'var(--red)';
            }
        }
        document.addEventListener('input',  function (e) { if (e.target.matches('.param-mod-wt, .cat-mod-wt')) recalc(); });
        document.addEventListener('change', function (e) { if (e.target.matches('.param-mod-wt, .cat-mod-wt')) recalc(); });
        recalc();
    })();
    </script>
    <?php renderAuditHistoryModal(); ?>
    <?php
}

// ── Operation Team review table ──
// Mirrors the Approver table but the user-editable column is operation_remark
// (the Approver Remark column shows blank/historic from a prior cycle).
function renderAuditOperationReviewTable(array $tree, int $auditId, int $locationId = 0): void {
    ?>
    <div class="table-wrap" data-stack>
        <table class="table audit-table">
            <thead>
                <tr>
                    <th style="width:38px">#</th>
                    <th style="width:22%">Audit Category / Parameter</th>
                    <th style="width:62px">Weightage</th>
                    <th style="width:72px">Modified Wt.</th>
                    <th style="width:62px">Value</th>
                    <th style="width:62px">Obtain</th>
                    <th style="width:72px">Obtain %</th>
                    <th>Auditor Remark</th>
                    <th>Store Manager Remark</th>
                    <th style="min-width:320px">Your Comment</th>
                </tr>
            </thead>
            <tbody>
            <?php $idx = 0; foreach ($tree as $c): $idx++; ?>
                <tr>
                    <td><?= $idx ?></td>
                    <td><strong><?= h($c['name']) ?></strong></td>
                    <td class="num"><?= number_format((float)$c['weightage'], 0) ?></td>
                    <td class="num"><?= number_format((float)$c['modified_weightage'], 0) ?></td>
                    <td colspan="6"></td>
                </tr>
                <?php foreach ($c['parameters'] as $p):
                    $r = $p['response'] ?? null;
                    $valEnt = $r ? $r['value_entered'] : null;
                    $obt    = $r ? $r['obtain_score']  : null;
                    $modW   = $r ? (float)$r['modified_weightage'] : (float)$p['score_weightage'];
                    $actW   = $r && isset($r['actual_weightage']) ? (float)$r['actual_weightage'] : (float)$p['score_weightage'];
                    $obtPct = ($obt !== null) ? round($modW * $obt / 100, 2) : null;
                    $scoreCls = auditScoreColor($obt !== null ? (float)$obt : null);
                ?>
                <tr>
                    <td></td>
                    <td>
                        <div class="param-text-wrap">
                            <span class="param-text-label"><?= h($p['parameter_text']) ?></span>
                            <button type="button" class="btn-icon-history" title="View history of this question for this store"
                                    data-param-id="<?= (int)$p['id'] ?>"
                                    data-audit-id="<?= (int)$auditId ?>"
                                    data-location-id="<?= (int)$locationId ?>"
                                    data-param-text="<?= h($p['parameter_text']) ?>"
                                    data-param-type="<?= h($p['type']) ?>">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M3 12a9 9 0 1 0 3-6.7"></path>
                                    <polyline points="3 4 3 10 9 10"></polyline>
                                    <polyline points="12 7 12 12 15 14"></polyline>
                                </svg>
                            </button>
                        </div>
                        <?php renderAuditAttachmentChips($p['attachments'] ?? [], $auditId, true); ?>
                    </td>
                    <td class="num"><?= number_format($actW, 0) ?></td>
                    <td class="num"><?= number_format($modW, 0) ?></td>
                    <td class="num"><?= $valEnt !== null ? h((string)(float)$valEnt) : '—' ?></td>
                    <td class="num <?= h($scoreCls) ?>"><?= $obt !== null ? number_format((float)$obt, 2) : '—' ?></td>
                    <td class="num <?= h($scoreCls) ?>"><?= $obtPct !== null ? number_format($obtPct, 2) : '—' ?></td>
                    <td><?= nl2br(h($r['auditor_remark'] ?? '')) ?: '—' ?></td>
                    <td><?= nl2br(h($r['store_manager_remark'] ?? '')) ?: '—' ?></td>
                    <td><textarea class="form-control" rows="2" name="operation_remark[<?= (int)$p['id'] ?>]" placeholder="Comment / observation for the Approver"><?= h($r['operation_remark'] ?? '') ?></textarea></td>
                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php renderAuditHistoryModal(); ?>
    <?php
}

// ── Management review table ──
// Shows every prior-stage remark read-only; Management edits management_remark.
function renderAuditManagementReviewTable(array $tree, int $auditId, int $locationId = 0): void {
    ?>
    <div class="table-wrap" data-stack>
        <table class="table audit-table">
            <thead>
                <tr>
                    <th style="width:46px">#</th>
                    <th>Audit Category / Parameter</th>
                    <th style="width:90px">Weightage</th>
                    <th style="width:110px">Modified Wt.</th>
                    <th style="width:90px">Obtain</th>
                    <th>Auditor</th>
                    <th>Store Manager</th>
                    <th>Operation</th>
                    <th>Approver</th>
                    <th>Your Remark</th>
                </tr>
            </thead>
            <tbody>
            <?php $idx = 0; foreach ($tree as $c): $idx++; ?>
                <tr>
                    <td><?= $idx ?></td>
                    <td><strong><?= h($c['name']) ?></strong></td>
                    <td class="num"><?= number_format((float)$c['weightage'], 0) ?></td>
                    <td class="num"><?= number_format((float)$c['modified_weightage'], 0) ?></td>
                    <td colspan="6"></td>
                </tr>
                <?php foreach ($c['parameters'] as $p):
                    $r = $p['response'] ?? null;
                    $obt    = $r ? $r['obtain_score']  : null;
                    $modW   = $r ? (float)$r['modified_weightage'] : (float)$p['score_weightage'];
                    $actW   = $r && isset($r['actual_weightage']) ? (float)$r['actual_weightage'] : (float)$p['score_weightage'];
                    $scoreCls = auditScoreColor($obt !== null ? (float)$obt : null);
                ?>
                <tr>
                    <td></td>
                    <td>
                        <div class="param-text-wrap">
                            <span class="param-text-label"><?= h($p['parameter_text']) ?></span>
                        </div>
                        <?php renderAuditAttachmentChips($p['attachments'] ?? [], $auditId, true); ?>
                    </td>
                    <td class="num"><?= number_format($actW, 0) ?></td>
                    <td class="num"><?= number_format($modW, 0) ?></td>
                    <td class="num <?= h($scoreCls) ?>"><?= $obt !== null ? number_format((float)$obt, 2) : '—' ?></td>
                    <td><?= nl2br(h($r['auditor_remark'] ?? '')) ?: '—' ?></td>
                    <td><?= nl2br(h($r['store_manager_remark'] ?? '')) ?: '—' ?></td>
                    <td><?= nl2br(h($r['operation_remark'] ?? '')) ?: '—' ?></td>
                    <td><?= nl2br(h($r['approver_remark'] ?? '')) ?: '—' ?></td>
                    <td><textarea class="form-control" rows="2" name="management_remark[<?= (int)$p['id'] ?>]" placeholder="Final remark / decision context"><?= h($r['management_remark'] ?? '') ?></textarea></td>
                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php renderAuditHistoryModal(); ?>
    <?php
}

// Render the attachment chip row (download + annotate links) under a
// parameter text cell. Shared by every review table so the "Annotate"
// affordance shows up at every post-submit stage. $readonly hides the
// delete X — true everywhere except in auditor edit mode.
function renderAuditAttachmentChips(array $attachments, int $auditId, bool $readonly): void {
    if (!$attachments) return;
    ?>
    <div class="att-list" style="margin-top:6px">
    <?php foreach ($attachments as $att):
        $isImg     = isset($att['mime_type']) && stripos((string)$att['mime_type'], 'image/') === 0;
        $pinsOpen  = (int)($att['pins_open']  ?? 0);
        $pinsTotal = (int)($att['pins_total'] ?? 0);
        // Highlight rule: any pin (open or resolved) marks the chip as
        // annotated; open pins keep the red callout, all-resolved gets a
        // softer green callout so reviewers can spot un-addressed images
        // at a glance.
        $chipCls = '';
        if ($pinsOpen   > 0) $chipCls = ' has-open-pins';
        elseif ($pinsTotal > 0) $chipCls = ' has-resolved-pins';
    ?>
        <a class="att-chip<?= $chipCls ?>" href="?page=download_audit_attachment&audit_id=<?= $auditId ?>&att_id=<?= (int)$att['id'] ?>" target="_blank"
           title="<?= $pinsTotal > 0 ? ($pinsOpen . ' open / ' . $pinsTotal . ' total pin' . ($pinsTotal === 1 ? '' : 's')) : 'No annotations yet' ?>">
            <?= h($att['filename']) ?>
        </a>
        <?php if ($isImg): ?>
            <a class="att-annotate<?= $chipCls ?>" href="?page=audit_annotation_image&audit_att=<?= (int)$att['id'] ?>" target="_blank"
               title="<?= $pinsTotal > 0 ? ('Open viewer · ' . $pinsOpen . ' open of ' . $pinsTotal . ' pin' . ($pinsTotal === 1 ? '' : 's')) : 'Drop pins and comment on this image' ?>">
                📌 Annotate
                <?php if ($pinsOpen > 0): ?>
                    <span class="att-pin-badge att-pin-badge-open"><?= $pinsOpen ?></span>
                <?php elseif ($pinsTotal > 0): ?>
                    <span class="att-pin-badge att-pin-badge-done">✓</span>
                <?php endif; ?>
            </a>
        <?php endif; ?>
    <?php endforeach; ?>
    </div>
    <?php
}

// ── Manager Review table — locked inputs + per-question SM remark textareas ──
// Mirrors renderAuditApproveTable but replaces the approver column with a
// Store Manager justification column. Approver_remark is shown read-only in
// case this is a re-review after a send-back (rarely populated yet, but
// rendered for parity).
function renderAuditManagerReviewTable(array $tree, int $auditId, int $locationId = 0): void {
    ?>
    <div class="table-wrap" data-stack>
        <table class="table audit-table">
            <thead>
                <tr>
                    <th style="width:46px">#</th>
                    <th>Audit Category / Parameter</th>
                    <th style="width:90px">Weightage</th>
                    <th style="width:110px">Modified Wt.</th>
                    <th style="width:100px">Value</th>
                    <th style="width:90px">Obtain</th>
                    <th style="width:110px">Obtain %</th>
                    <th>Auditor Remark</th>
                    <th>Your Justification</th>
                </tr>
            </thead>
            <tbody>
            <?php $idx = 0; foreach ($tree as $c): $idx++; ?>
                <tr>
                    <td><?= $idx ?></td>
                    <td><strong><?= h($c['name']) ?></strong></td>
                    <td class="num"><?= number_format((float)$c['weightage'], 0) ?></td>
                    <td class="num"><?= number_format((float)$c['modified_weightage'], 0) ?></td>
                    <td colspan="5"></td>
                </tr>
                <?php foreach ($c['parameters'] as $p):
                    $r = $p['response'] ?? null;
                    $valEnt = $r ? $r['value_entered'] : null;
                    $obt    = $r ? $r['obtain_score']  : null;
                    $modW   = $r ? (float)$r['modified_weightage'] : (float)$p['score_weightage'];
                    $actW   = $r && isset($r['actual_weightage']) ? (float)$r['actual_weightage'] : (float)$p['score_weightage'];
                    $obtPct = ($obt !== null) ? round($modW * $obt / 100, 2) : null;
                    $scoreCls = auditScoreColor($obt !== null ? (float)$obt : null);
                ?>
                <tr>
                    <td></td>
                    <td>
                        <div class="param-text-wrap">
                            <span class="param-text-label"><?= h($p['parameter_text']) ?></span>
                            <button type="button" class="btn-icon-history" title="View history of this question for this store"
                                    data-param-id="<?= (int)$p['id'] ?>"
                                    data-audit-id="<?= (int)$auditId ?>"
                                    data-location-id="<?= (int)$locationId ?>"
                                    data-param-text="<?= h($p['parameter_text']) ?>"
                                    data-param-type="<?= h($p['type']) ?>">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M3 12a9 9 0 1 0 3-6.7"></path>
                                    <polyline points="3 4 3 10 9 10"></polyline>
                                    <polyline points="12 7 12 12 15 14"></polyline>
                                </svg>
                            </button>
                        </div>
                        <?php renderAuditAttachmentChips($p['attachments'] ?? [], $auditId, true); ?>
                    </td>
                    <td class="num"><?= number_format($actW, 0) ?></td>
                    <td class="num"><?= number_format($modW, 0) ?></td>
                    <td class="num"><?= $valEnt !== null ? h((string)(float)$valEnt) : '—' ?></td>
                    <td class="num <?= h($scoreCls) ?>"><?= $obt !== null ? number_format((float)$obt, 2) : '—' ?></td>
                    <td class="num <?= h($scoreCls) ?>"><?= $obtPct !== null ? number_format($obtPct, 2) : '—' ?></td>
                    <td><?= nl2br(h($r['auditor_remark'] ?? '')) ?: '—' ?></td>
                    <td><textarea class="form-control" rows="2" name="store_manager_remark[<?= (int)$p['id'] ?>]" placeholder="Justification / context for the approver"><?= h($r['store_manager_remark'] ?? '') ?></textarea></td>
                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php renderAuditHistoryModal(); ?>
    <?php
}

function renderAuditEditJs(): void {
    ?>
    <script>
    (function(){
        function num(v){ v = parseFloat(v); return isNaN(v) ? 0 : v; }
        function compObtain(type, max, v){
            if (v === '' || v === null || v === undefined) return null;
            v = parseFloat(v); if (isNaN(v)) return null;
            if (type === 'rating')  { v = Math.max(0, Math.min(5, v)); return +(v/5*100).toFixed(2); }
            if (type === 'value')   { var m = parseFloat(max); if (!m || m<=0) return null; var x = Math.max(0, v)/m; if (x>1) x=1; return +(x*100).toFixed(2); }
            if (type === 'boolean') { return v > 0 ? 100 : 0; }
            return null;
        }
        // Mirror modules/audit.php auditScoreColor() — same thresholds so
        // the live cell tint matches what the read-only/report view shows.
        function scoreClass(obt){
            if (obt === null || obt === undefined) return '';
            if (obt < 50) return 'audit-score-red';
            if (obt < 75) return 'audit-score-orange';
            return 'audit-score-green';
        }
        function setScoreClass(cell, cls){
            if (!cell) return;
            cell.classList.remove('audit-score-red','audit-score-orange','audit-score-green');
            if (cls) cell.classList.add(cls);
        }
        function recalc(){
            var rows = document.querySelectorAll('tr.audit-param-row');
            var catSums = {};
            var catObtain = {}; // sum of obtain_score for category (simple sum, 0..100 when weights sum to 100)
            rows.forEach(function(row){
                var type = row.dataset.type, max = row.dataset.max, cid = row.dataset.catId;
                var valEl = row.querySelector('.param-value'); if (!valEl) return;
                var v = valEl.value;
                var obt = compObtain(type, max, v);
                var modWEl = row.querySelector('.param-mod-wt');
                var modW = modWEl ? num(modWEl.value) : 0;
                var obtCell = row.querySelector('.param-obtain');
                var pctCell = row.querySelector('.param-obtain-pct');
                var cls = scoreClass(obt);
                setScoreClass(obtCell, cls);
                setScoreClass(pctCell, cls);
                if (obt === null) {
                    if (obtCell) obtCell.textContent = '—';
                    if (pctCell) pctCell.textContent = '—';
                } else {
                    if (obtCell) obtCell.textContent = obt.toFixed(2);
                    if (pctCell) pctCell.textContent = (modW * obt / 100).toFixed(2);
                }
                catSums[cid] = (catSums[cid] || 0) + modW;
                catObtain[cid] = (catObtain[cid] || 0) + (obt === null ? 0 : (modW * obt / 100));
            });
            // Update per-category displays + tint the whole category bar.
            // sumObt is the category's weighted obtain on a 0..100 scale
            // (sum of param modW × obt / 100 within the category, since
            // within-category weights sum to 100). We bucket that into the
            // same R/O/G thresholds used at the parameter level.
            document.querySelectorAll('tr.audit-cat-row').forEach(function(row){
                var cid = row.dataset.catId;
                var catModEl = row.querySelector('.cat-mod-wt');
                var catMod = catModEl ? num(catModEl.value) : 0;
                var obtCell = row.querySelector('.cat-obtain');
                var pctCell = row.querySelector('.cat-obtain-pct');
                var sumObt = catObtain[cid] || 0;
                // Only colour when at least one param in the category has been
                // answered — otherwise sumObt is a meaningless 0 from no input.
                var anyAnswered = false;
                document.querySelectorAll('tr.audit-param-row[data-cat-id="' + cid + '"] .param-value').forEach(function(el){
                    if (el.value !== '' && el.value !== null) anyAnswered = true;
                });
                if (obtCell) obtCell.textContent = anyAnswered ? sumObt.toFixed(2) : '—';
                if (pctCell) pctCell.textContent = anyAnswered ? (catMod * sumObt / 100).toFixed(2) : '—';
                var catCls = anyAnswered ? scoreClass(sumObt) : '';
                setScoreClass(obtCell, catCls);
                setScoreClass(pctCell, catCls);
                row.classList.remove('cat-score-red','cat-score-orange','cat-score-green');
                if (catCls) row.classList.add(catCls.replace('audit-score-','cat-score-'));
            });
            // Per-category sum badges (Modified Wt. is integer-only → show whole numbers)
            document.querySelectorAll('.cat-param-sum').forEach(function(el){
                var cid = el.dataset.catId;
                var s = Math.round(catSums[cid] || 0);
                el.textContent = s + ' / 100';
                el.style.color = s !== 100 ? 'var(--red)' : 'var(--green)';
            });
            // Total category mod weightage (integer-only)
            var total = 0;
            document.querySelectorAll('.cat-mod-wt').forEach(function(el){ total += num(el.value); });
            var t = document.getElementById('catTotalSum');
            if (t) {
                var ti = Math.round(total);
                t.textContent = ti + ' / 100';
                t.style.color = ti !== 100 ? 'var(--red)' : 'var(--green)';
            }
        }
        document.addEventListener('input', function(e){
            if (e.target.matches('.param-value, .param-mod-wt, .cat-mod-wt')) recalc();
        });
        document.addEventListener('change', function(e){
            if (e.target.matches('.param-value, .param-mod-wt, .cat-mod-wt')) recalc();
        });
        recalc();
    })();
    </script>
    <script>
    // ── Client-side image compression for per-parameter file inputs ──
    // Audits often produce dozens of phone photos at full resolution
    // (4–8 MB each). Without compression a single submit can balloon to
    // 100 MB+ and fail outright on patchy LTE. Same rules as elsewhere:
    // image (jpeg/png/gif/webp/heic) > 600 KB → downscale long-edge to
    // 1600 px and re-encode JPEG q=0.75; PDFs and small files pass
    // through. Submit is gated until every in-flight compression
    // resolves so a heavy original never sneaks into the POST.
    (function () {
        var form = document.getElementById('auditForm');
        if (!form) return;

        var MAX_EDGE     = 1600;
        var SKIP_BELOW   = 600 * 1024;
        var JPEG_QUALITY = 0.75;
        var IMAGE_RE     = /^image\/(jpeg|png|gif|webp|heic|heif)$/i;
        var inflight     = 0;
        var submitBtns   = form.querySelectorAll('button[type="submit"]');

        function fmtSize(b) {
            if (b < 1024) return b + ' B';
            if (b < 1024 * 1024) return (b / 1024).toFixed(1) + ' KB';
            return (b / 1024 / 1024).toFixed(2) + ' MB';
        }
        function setSubmitDisabled(disabled) {
            submitBtns.forEach(function (b) { b.disabled = disabled; });
        }
        function statusNodeFor(input) {
            // One <div> per input, inserted right after it on first use.
            var node = input.nextElementSibling;
            if (!node || !node.classList || !node.classList.contains('param-att-status')) {
                node = document.createElement('div');
                node.className = 'param-att-status';
                node.style.cssText = 'font-size:10px;margin-top:2px;color:var(--muted);min-height:12px';
                input.parentNode.insertBefore(node, input.nextSibling);
            }
            return node;
        }

        function setFiles(input, fileArr) {
            try {
                var dt = new DataTransfer();
                fileArr.forEach(function (f) { dt.items.add(f); });
                input.files = dt.files;
                return true;
            } catch (e) { return false; }
        }
        function decode(file) {
            if (typeof createImageBitmap === 'function') {
                try { return createImageBitmap(file, { imageOrientation: 'from-image' }); }
                catch (e) { return createImageBitmap(file); }
            }
            return new Promise(function (resolve, reject) {
                var url = URL.createObjectURL(file);
                var img = new Image();
                img.onload  = function () { URL.revokeObjectURL(url); resolve(img); };
                img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('image decode failed')); };
                img.src = url;
            });
        }
        function compressOne(file) {
            if (!IMAGE_RE.test(file.type)) return Promise.resolve(file);
            if (file.size <= SKIP_BELOW)   return Promise.resolve(file);
            return decode(file).then(function (bmp) {
                var w = bmp.width || bmp.naturalWidth;
                var h = bmp.height || bmp.naturalHeight;
                if (!w || !h) return file;
                var scale = Math.min(1, MAX_EDGE / Math.max(w, h));
                var tw = Math.round(w * scale), th = Math.round(h * scale);
                var canvas = document.createElement('canvas');
                canvas.width = tw; canvas.height = th;
                canvas.getContext('2d').drawImage(bmp, 0, 0, tw, th);
                return new Promise(function (resolve) {
                    canvas.toBlob(function (blob) {
                        if (!blob || blob.size >= file.size) { resolve(file); return; }
                        var nameBase = (file.name || 'photo').replace(/\.(png|jpe?g|gif|webp|heic|heif)$/i, '');
                        resolve(new File([blob], nameBase + '.jpg', { type: 'image/jpeg', lastModified: Date.now() }));
                    }, 'image/jpeg', JPEG_QUALITY);
                });
            }).catch(function () { return file; });
        }

        // Delegated change listener — covers every .param-files input on
        // the page without us having to wire each one individually.
        document.addEventListener('change', function (e) {
            if (!e.target.matches || !e.target.matches('.param-files')) return;
            var input  = e.target;
            var status = statusNodeFor(input);
            var files  = Array.from(input.files || []);
            if (!files.length) { status.textContent = ''; return; }

            var origTotal = files.reduce(function (n, f) { return n + f.size; }, 0);
            var compressableAny = files.some(function (f) { return IMAGE_RE.test(f.type) && f.size > SKIP_BELOW; });
            if (!compressableAny) {
                status.style.color = 'var(--muted)';
                status.textContent = files.length + ' file(s) — ' + fmtSize(origTotal);
                return;
            }

            inflight++;
            setSubmitDisabled(true);
            status.style.color = 'var(--muted)';
            status.textContent = 'Compressing photo(s)…';

            Promise.all(files.map(compressOne)).then(function (out) {
                var newTotal = out.reduce(function (n, f) { return n + f.size; }, 0);
                if (!setFiles(input, out)) {
                    status.style.color = 'var(--yellow)';
                    status.textContent = 'Could not replace selected files — uploading originals (' + fmtSize(origTotal) + ').';
                } else if (newTotal < origTotal) {
                    status.style.color = 'var(--green)';
                    status.textContent = fmtSize(origTotal) + ' → ' + fmtSize(newTotal)
                        + ' (' + Math.round((1 - newTotal / origTotal) * 100) + '% smaller).';
                } else {
                    status.style.color = 'var(--muted)';
                    status.textContent = out.length + ' file(s) — ' + fmtSize(newTotal);
                }
            }).catch(function (err) {
                status.style.color = 'var(--yellow)';
                status.textContent = 'Compression failed — uploading originals (' + fmtSize(origTotal) + '). ' + (err && err.message ? err.message : '');
            }).then(function () {
                inflight = Math.max(0, inflight - 1);
                if (inflight === 0) setSubmitDisabled(false);
            });
        }, true);

        // Block submit while any compression is still running. Once
        // done, the click goes through. Existing form-level handlers
        // (validation etc.) fire afterward unaffected.
        form.addEventListener('submit', function (e) {
            if (inflight > 0) {
                e.preventDefault();
                alert('Still compressing photo(s) — please wait a moment and try again.');
            }
        }, true);
    })();
    </script>
    <?php
}
