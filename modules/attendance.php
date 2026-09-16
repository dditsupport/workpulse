<?php
// =========================================================
// Attendance page renderers: Dashboard, Attendance, My Punches
// =========================================================

// pageDashboard() now lives in modules/dashboard.php (adds the
// "Pending For You" widget + retains the stat cards).

// The device API synthesises an OUT at (cutoff-1):59:59 -- 05:59:59 on a
// 6 o'clock cutoff -- whenever someone goes home without punching out, so
// that the next day's punch still validates. It is a placeholder, not a
// punch: left in, it invents working hours, feeds the ERP a wrong OUT and
// makes a forgotten punch look like a complete in→out trace. Every report
// and every alert strips it; only the raw attendance_logs CSV keeps it.
function attStripAutoClose(array $rows): array {
    return array_values(array_filter($rows, fn($r) => ($r['punch_method'] ?? '') !== 'auto_close'));
}

// ── Page: Attendance Report (admin/superadmin/hr/operations view) ─
function pageAttendance(): void {
    $empCode      = trim($_GET['emp']       ?? '');
    $fromDate     = trim($_GET['from_date'] ?? '');
    $toDate       = trim($_GET['to_date']   ?? '');
    $locationId   = (int)($_GET['loc']      ?? 0);
    $withLocation = !empty($_GET['with_location']);
    $doLoad       = isset($_GET['filter']);

    // Defaults — from = 1st of current month, to = today.
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) $fromDate = date('Y-m-01');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate))   $toDate   = date('Y-m-d');
    // Lock to_date inside from_date's calendar month + from <= to.
    if (substr($fromDate, 0, 7) !== substr($toDate, 0, 7)) {
        $toDate = date('Y-m-t', strtotime($fromDate));
    }
    if ($fromDate > $toDate) $toDate = $fromDate;

    $monthEnd = date('Y-m-t', strtotime($fromDate));

    $emps = [];
    try {
        $emps = getDb()->query('SELECT employee_code,full_name FROM employees ORDER BY full_name')->fetchAll();
    } catch (Exception $e) {}

    $locs = getActiveLocations();

    $empLabel = '';
    foreach ($emps as $e) {
        if ($e['employee_code'] === $empCode) {
            $empLabel = $e['full_name'] . ' (' . $e['employee_code'] . ')';
            break;
        }
    }

    $rows = $summary = [];
    $totalDays = $totalEmps = 0;
    if ($doLoad) {
        $rows    = attStripAutoClose(getAttendance($empCode, $fromDate, $toDate, $locationId));
        $summary = buildDaySummary($rows);
        $totalEmps = count($summary);
        foreach ($summary as $emp) $totalDays += count($emp['days']);
    }
?>
<div class="page-header"><h2>Attendance Report</h2></div>

<form method="GET" class="rpt-filter">
    <input type="hidden" name="page"   value="attendance">
    <input type="hidden" name="filter" value="1">
    <input type="hidden" name="emp" id="attEmpCode" value="<?= h($empCode) ?>">
    <span class="input-clear-wrap" style="flex:1 1 auto;min-width:200px">
        <input type="text" id="attEmpSearch" class="form-control rpt-filter-emp"
               placeholder="All Employees — type to search"
               value="<?= h($empLabel) ?>" autocomplete="off"
               style="max-width:none">
        <button type="button" id="attEmpClear" class="input-clear-btn" data-no-auto aria-label="Clear" tabindex="-1">&times;</button>
        <div id="attEmpList" style="position:absolute;top:100%;left:0;right:0;background:var(--surface);border:1px solid var(--border);border-radius:6px;margin-top:2px;max-height:280px;overflow-y:auto;display:none;z-index:100;box-shadow:0 6px 18px rgba(0,0,0,.35)"></div>
    </span>
    <input type="date" name="from_date" id="attFromDate" class="form-control" style="width:160px"
           value="<?= h($fromDate) ?>" required>
    <input type="date" name="to_date"   id="attToDate"   class="form-control" style="width:160px"
           value="<?= h($toDate)   ?>" min="<?= h($fromDate) ?>" max="<?= h($monthEnd) ?>" required>
    <select name="loc" class="form-control" style="width:200px">
        <option value="0">All Locations</option>
        <?php foreach ($locs as $l): ?>
        <option value="<?= (int)$l['location_id'] ?>" <?= $locationId === (int)$l['location_id'] ? 'selected' : '' ?>>
            <?= h($l['location_name']) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <label class="rpt-filter-chk">
        <input type="checkbox" name="with_location" value="1" <?= $withLocation ? 'checked' : '' ?>>
        Show Location
    </label>
    <button class="btn btn-primary">View</button>
    <?php
        // Always shown, never gated on $doLoad: picking filters and hitting
        // Export straight away is the common case, and having to render the
        // report on screen first was pure ceremony. rpt-go rebuilds the query
        // string from the live form on click (see attFilterScript), so an
        // export follows the dates now in the boxes, not the ones last
        // submitted. The href is the server-side equivalent, so no-JS and
        // right-click → open in new tab still work.
        $q = ['emp' => $empCode, 'from_date' => $fromDate, 'to_date' => $toDate, 'loc' => $locationId];
        attGoButton('odd_punches',             'Odd Punches',   $q + ['filter' => 1]);
        attGoButton('export_attendance',        'Export CSV',    $q, true);
        attGoButton('export_attendance_report', 'Export Report', $q, true);
    ?>
</form>
<?php attFilterScript($emps); ?>

<?php if (!$doLoad): ?>
<div class="rpt-prompt">Select filters and click <strong>View</strong> to load attendance data.</div>
<?php else: ?>

<div class="stats-grid-sm" style="margin-bottom:16px">
    <div class="stat-card stat-green"><div class="stat-val"><?= $totalEmps ?></div><div class="stat-lbl">Employees</div></div>
    <div class="stat-card stat-blue"><div class="stat-val"><?= $totalDays ?></div><div class="stat-lbl">Day Entries</div></div>
    <div class="stat-card"><div class="stat-val"><?= count($rows) ?></div><div class="stat-lbl">Total Punches</div></div>
</div>

<?php if (empty($summary)): ?>
<div class="table-wrap"><p class="empty-row">No attendance recorded for the selected period.</p></div>
<?php else: ?>

<div class="report-header-box">
    <strong>Dangee Dums Ltd</strong><br>
    Date wise Punch Report<?= $withLocation ? ' (With Punch Machine Location)' : '' ?>
    &nbsp;From Date — <?= date('d/m/Y', strtotime($fromDate)) ?>
    &nbsp;To Date <?= date('d/m/Y', strtotime($toDate)) ?>
</div>

<div class="table-wrap" data-stack>
<table class="table rpt-table">
    <thead>
        <tr>
            <th class="rpt-sr">Sr No</th>
            <th class="rpt-id">Employee ID</th>
            <th class="rpt-name">Employee Name</th>
            <th class="rpt-date">Date</th>
            <th>Punches <?php if ($withLocation): ?><span class="rpt-loc-hdr">(with location)</span><?php endif; ?></th>
            <th class="rpt-hrs">Productive<br>Working Hours</th>
            <th class="rpt-hrs">Total<br>Working Hours</th>
        </tr>
    </thead>
    <tbody>
    <?php $sr = 1; $empIdx = 0; foreach ($summary as $code => $emp): $empIdx++; ?>
        <?php $dayKeys = array_keys($emp['days']); $firstDay = true; ?>
        <?php foreach ($dayKeys as $day): ?>
            <?php $d = $emp['days'][$day]; $punches = $d['punches']; ?>
            <?php $hrs = fmtHours($d['first']['punch_time'] ?? null, $d['last']['punch_time'] ?? null); ?>
            <tr class="<?= ($firstDay && $empIdx > 1) ? 'rpt-emp-start' : '' ?>">
                <?php if ($firstDay): ?>
                <td class="rpt-sr" rowspan="<?= count($dayKeys) ?>"><?= $sr++ ?></td>
                <td class="rpt-id" rowspan="<?= count($dayKeys) ?>"><code><?= h($code) ?></code></td>
                <td class="rpt-name" rowspan="<?= count($dayKeys) ?>">
                    <?= h($emp['name']) ?>
                    <?php if ($emp['dept']): ?><br><small class="text-muted"><?= h($emp['dept']) ?></small><?php endif; ?>
                </td>
                <?php $firstDay = false; endif; ?>
                <td class="rpt-date" data-label="Date"><?= date('d/m/Y', strtotime($day)) ?></td>
                <td class="rpt-punches-cell" data-label="Punches">
                    <?php foreach ($punches as $p): $isAuto = (($p['punch_method'] ?? '') === 'auto_close'); ?>
                    <span class="punch-chip punch-chip-<?= mb_strtolower($p['punch_type']) ?>"<?= $isAuto ? ' style="border:1px dashed var(--yellow)" title="Auto-close placeholder — system-generated, not a real punch (creates wrong ERP timing)"' : '' ?>>
                        <span class="punch-chip-type"><?= $p['punch_type'] ?><?= $isAuto ? ' · AUTO' : '' ?></span>
                        <span class="punch-chip-time"><?= date('H:i:s', strtotime($p['punch_time'])) ?></span>
                        <?php if ($withLocation && !empty($p['location_name'])): ?>
                        <span class="punch-chip-loc"><?= h($p['location_name']) ?></span>
                        <?php endif; ?>
                    </span>
                    <?php endforeach; ?>
                </td>
                <td class="rpt-hrs" data-label="Productive Hours"><?= $hrs ?></td>
                <td class="rpt-hrs" data-label="Total Hours"><?= $hrs ?></td>
            </tr>
        <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>
<?php
}

// One filter-strip button that carries the current filters to another page.
// $blank opens in a new tab, which is what an export wants.
function attGoButton(string $page, string $label, array $params, bool $blank = false): void {
    $href = '?' . http_build_query(['page' => $page] + $params);
    echo '<a href="' . h($href) . '" class="btn btn-ghost btn-sm rpt-go"'
       . ' data-page="' . h($page) . '"'
       . ($blank ? ' target="_blank" data-blank="1"' : '')
       . '>' . h($label) . '</a>';
}

// The filter strip's behaviour: to_date locked inside from_date's month, plus
// the type-to-search employee dropdown. Shared by the Attendance Report and
// the Odd Punch Report, which carry the same filters and the same element ids.
function attFilterScript(array $emps): void {
?>
<script>
(function () {
    var f = document.getElementById('attFromDate');
    var t = document.getElementById('attToDate');
    if (f && t) {
        var lastDayOfMonth = function (ymd) {
            var p = ymd.split('-');
            var d = new Date(Number(p[0]), Number(p[1]), 0);
            var m = String(d.getMonth() + 1).padStart(2, '0');
            var day = String(d.getDate()).padStart(2, '0');
            return d.getFullYear() + '-' + m + '-' + day;
        };
        var sync = function () {
            // from_date is unrestricted; to_date is locked inside from_date's month.
            if (!f.value) { t.min = ''; t.max = ''; return; }
            var monthEnd = lastDayOfMonth(f.value);
            t.min = f.value;
            t.max = monthEnd;
            if (t.value < f.value)  t.value = f.value;
            if (t.value > monthEnd) t.value = monthEnd;
        };
        f.addEventListener('change', sync);
        t.addEventListener('change', sync);
    }

    // Filter-strip buttons (Odd Punches / the exports) follow whatever is in
    // the form RIGHT NOW, not what was last submitted -- otherwise changing a
    // date and hitting Export silently exports the previous range.
    var form = document.querySelector('form.rpt-filter');
    if (form) {
        form.querySelectorAll('.rpt-go').forEach(function (a) {
            a.addEventListener('click', function (ev) {
                ev.preventDefault();
                var q = new URLSearchParams();
                q.set('page', a.dataset.page);
                new FormData(form).forEach(function (v, k) {
                    if (k !== 'page' && k !== 'filter') q.append(k, v);
                });
                // Report pages need ?filter=1 to actually run; exports don't.
                if (a.dataset.blank !== '1') q.set('filter', '1');
                var url = 'index.php?' + q.toString();
                if (a.dataset.blank === '1') window.open(url, '_blank');
                else window.location.href = url;
            });
        });
    }

    // Employee keyword search → custom dropdown below input
    var empSearch = document.getElementById('attEmpSearch');
    var empHidden = document.getElementById('attEmpCode');
    var empClear  = document.getElementById('attEmpClear');
    var empList   = document.getElementById('attEmpList');
    var empData   = <?= json_encode(array_map(fn($e) => ['code' => $e['employee_code'], 'name' => $e['full_name']], $emps), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    if (empSearch && empHidden && empList) {
        var escHtml = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
        var render = function (q) {
            q = (q || '').trim().toLowerCase();
            var matches = q === '' ? empData : empData.filter(function (e) {
                return e.name.toLowerCase().indexOf(q) !== -1 || e.code.toLowerCase().indexOf(q) !== -1;
            });
            if (matches.length === 0) {
                empList.innerHTML = '<div style="padding:10px 12px;color:var(--muted);font-size:13px">No employees match</div>';
            } else {
                empList.innerHTML = matches.slice(0, 200).map(function (e) {
                    return '<div class="att-emp-opt" data-code="' + escHtml(e.code) + '" data-label="' + escHtml(e.name + ' (' + e.code + ')') + '" style="padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid rgba(255,255,255,.04)">' + escHtml(e.name) + ' <span style="color:var(--muted)">(' + escHtml(e.code) + ')</span></div>';
                }).join('');
            }
            empList.style.display = 'block';
        };
        var hide = function () { empList.style.display = 'none'; };
        var empWrap = empSearch.closest('.input-clear-wrap');
        var syncVis = function () { if (empWrap) empWrap.classList.toggle('has-value', !!empSearch.value); };
        var selectEmp = function (code, label) {
            empHidden.value = code;
            empSearch.value = label;
            syncVis();
            hide();
        };
        var clearEmp = function () {
            empSearch.value = '';
            empHidden.value = '';
            syncVis();
        };
        syncVis();
        empSearch.addEventListener('focus', function () { render(empSearch.value); });
        empSearch.addEventListener('input', function () {
            empHidden.value = ''; // invalidate until a pick or exact-label match
            render(empSearch.value);
        });
        empList.addEventListener('mousedown', function (ev) {
            var opt = ev.target.closest('.att-emp-opt');
            if (!opt) return;
            ev.preventDefault();
            selectEmp(opt.getAttribute('data-code'), opt.getAttribute('data-label'));
        });
        empList.addEventListener('mouseover', function (ev) {
            var opt = ev.target.closest('.att-emp-opt');
            if (!opt) return;
            Array.prototype.forEach.call(empList.querySelectorAll('.att-emp-opt'), function (o) { o.style.background = ''; });
            opt.style.background = 'rgba(26,143,227,.12)';
        });
        document.addEventListener('mousedown', function (ev) {
            if (ev.target !== empSearch && !empList.contains(ev.target) && ev.target !== empClear) hide();
        });
        // Click on the input when not focused clears it so the full list re-opens
        empSearch.addEventListener('mousedown', function () {
            if (document.activeElement !== empSearch && empSearch.value !== '') clearEmp();
        });
        if (empClear) empClear.addEventListener('click', function () { clearEmp(); empSearch.focus(); render(''); });
    }
})();
</script>
<?php
}

// ── Page: My Punches (employee self-view) ────────────────
function pageMyPunches(): void {
    $month        = (int)($_GET['month']        ?? date('n'));
    $year         = (int)($_GET['year']         ?? date('Y'));
    $withLocation = !empty($_GET['with_location']);
    $doLoad       = isset($_GET['filter']);

    $rows = $summary = [];
    $days = [];
    if ($doLoad) {
        $rows    = attStripAutoClose(getMyPunches(myCode(), $month, $year));
        $summary = buildDaySummary($rows);
        $empData = reset($summary) ?: ['name' => myName(), 'dept' => '', 'days' => []];
        $days    = $empData['days'];
    }
?>
<div class="page-header"><h2>My Attendance</h2></div>

<form method="GET" class="rpt-filter">
    <input type="hidden" name="page"   value="mypunches">
    <input type="hidden" name="filter" value="1">
    <select name="month" class="form-control rpt-filter-month">
        <?php for ($m=1; $m<=12; $m++): ?>
        <option value="<?= $m ?>" <?= $month===$m?'selected':'' ?>><?= date('F',mktime(0,0,0,$m,1)) ?></option>
        <?php endfor; ?>
    </select>
    <select name="year" class="form-control rpt-filter-year">
        <?php for ($y=(int)date('Y'); $y>=(int)date('Y')-2; $y--): ?>
        <option value="<?= $y ?>" <?= $year===$y?'selected':'' ?>><?= $y ?></option>
        <?php endfor; ?>
    </select>
    <label class="rpt-filter-chk">
        <input type="checkbox" name="with_location" value="1" <?= $withLocation ? 'checked' : '' ?>>
        Show Location
    </label>
    <button class="btn btn-primary">View</button>
    <?php if ($doLoad): ?>
    <a href="?page=export_mypunches&month=<?= $month ?>&year=<?= $year ?>" class="btn btn-ghost btn-sm" target="_blank">Export CSV</a>
    <?php endif; ?>
</form>

<?php if (!$doLoad): ?>
<div class="rpt-prompt">Select month and click <strong>View</strong> to load your attendance.</div>
<?php else: ?>

<div class="stats-grid-sm" style="margin-bottom:16px">
    <div class="stat-card stat-blue"><div class="stat-val"><?= count($days) ?></div><div class="stat-lbl">Days Present</div></div>
    <div class="stat-card"><div class="stat-val"><?= count($rows) ?></div><div class="stat-lbl">Total Punches</div></div>
</div>

<?php if (empty($days)): ?>
<div class="table-wrap"><p class="empty-row">No attendance recorded for the selected month.</p></div>
<?php else: ?>
<div class="table-wrap" data-stack>
<table class="table rpt-table">
    <thead>
        <tr>
            <th class="rpt-date">Date</th>
            <th>Punches <?php if ($withLocation): ?><span class="rpt-loc-hdr">(with location)</span><?php endif; ?></th>
            <th class="rpt-hrs">Working Hours</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($days as $day => $d): ?>
        <?php $punches = $d['punches']; ?>
        <?php $hrs = fmtHours($d['first']['punch_time'] ?? null, $d['last']['punch_time'] ?? null); ?>
        <tr>
            <td class="rpt-date">
                <?= date('d/m/Y', strtotime($day)) ?>
                <small class="text-muted"><?= date('D', strtotime($day)) ?></small>
            </td>
            <td class="rpt-punches-cell">
                <?php foreach ($punches as $p): $isAuto = (($p['punch_method'] ?? '') === 'auto_close'); ?>
                <span class="punch-chip punch-chip-<?= mb_strtolower($p['punch_type']) ?>"<?= $isAuto ? ' style="border:1px dashed var(--yellow)" title="Auto-close placeholder — system-generated, not a real punch (creates wrong ERP timing)"' : '' ?>>
                    <span class="punch-chip-type"><?= $p['punch_type'] ?><?= $isAuto ? ' · AUTO' : '' ?></span>
                    <span class="punch-chip-time"><?= date('H:i:s', strtotime($p['punch_time'])) ?></span>
                    <?php if ($withLocation && !empty($p['location_name'])): ?>
                    <span class="punch-chip-loc"><?= h($p['location_name']) ?></span>
                    <?php endif; ?>
                </span>
                <?php endforeach; ?>
            </td>
            <td class="rpt-hrs"><?= $hrs ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>

<?php
}

// ── Page: My Location (separate page for location self-claim) ─
function pageMyLocation(): void {
    $currentLocId = myLocationId();
    $currentLocName = '';
    if ($currentLocId > 0) {
        $loc = getLocation($currentLocId);
        $currentLocName = $loc['location_name'] ?? '';
    }
    $allLocs = getActiveLocations();
    $otpSent = !empty($_SESSION['loc_otp']);
    $lastPunch = getLastPunchForEmployee(myCode());
    $hasPunchHistory = ($lastPunch !== null);

    // Location change history
    $logs = [];
    if (myCode()) {
        $st = getDb()->prepare(
            "SELECT ll.*, lo.location_name AS old_name, ln.location_name AS new_name
             FROM employee_location_logs ll
             LEFT JOIN locations lo ON ll.old_location_id = lo.location_id
             LEFT JOIN locations ln ON ll.new_location_id = ln.location_id
             WHERE ll.employee_code = ?
             ORDER BY ll.changed_at DESC LIMIT 10"
        );
        $st->execute([myCode()]);
        $logs = $st->fetchAll(PDO::FETCH_ASSOC);
    }
?>
<div class="page-header"><h2>My Location</h2></div>
<p class="text-muted" style="margin:-8px 0 14px;font-size:13px">Self Claim your location for Issue tracking and Store Checklist filling.</p>

<div class="form-card" style="margin-bottom:16px">
    <p style="font-size:14px;margin-bottom:12px">
        <span class="text-muted">Current Location:</span>
        <?php if ($currentLocName): ?>
            <span class="badge badge-green" style="font-size:14px;padding:6px 14px"><?= h($currentLocName) ?></span>
        <?php else: ?>
            <span class="badge badge-grey" style="font-size:14px;padding:6px 14px">Not assigned</span>
        <?php endif; ?>
    </p>

    <p style="font-size:14px;margin-bottom:12px">
        <span class="text-muted">Last Punch Location:</span>
        <?php if ($lastPunch): ?>
            <span class="badge badge-blue" style="font-size:14px;padding:6px 14px"><?= h($lastPunch['location_name'] ?? 'Unknown') ?></span>
            <span class="text-muted" style="font-size:12px;margin-left:8px">
                <?= $lastPunch['punch_type'] ?> at <?= date('d M Y H:i', strtotime($lastPunch['punch_time'])) ?>
            </span>
        <?php else: ?>
            <span class="badge badge-grey" style="font-size:14px;padding:6px 14px">No punches recorded</span>
        <?php endif; ?>
    </p>

    <?php
    $requirePunch = (int)getSetting('LocationClaimRequiresPunch', '1');
    if ($requirePunch && !$hasPunchHistory): ?>
    <div class="alert alert-error" style="margin-top:12px">
        You must have at least one punch record before you can self-claim a location.
    </div>
    <?php elseif ($otpSent): ?>
    <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        <form method="POST" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
            <input type="hidden" name="action" value="verify_location_otp">
            <div class="form-group">
                <label>Enter OTP sent to location email</label>
                <input type="text" name="otp" class="form-control" required maxlength="6" pattern="\d{6}" placeholder="6-digit OTP" style="width:180px" autofocus>
            </div>
            <button type="submit" class="btn btn-primary">Verify</button>
        </form>
        <form method="POST">
            <input type="hidden" name="action" value="cancel_location_otp">
            <button type="submit" class="btn btn-ghost">Cancel</button>
        </form>
    </div>
    <?php else: ?>
    <form method="POST" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="action" value="request_location_otp">
        <div class="form-group">
            <label>Transfer to Location</label>
            <select name="target_location_id" class="form-control" required style="min-width:200px">
                <option value="">— Select Location —</option>
                <?php foreach ($allLocs as $loc): ?>
                <option value="<?= $loc['location_id'] ?>" <?= $currentLocId === (int)$loc['location_id'] ? 'disabled' : '' ?>>
                    <?= h($loc['location_name']) ?>
                    <?= $currentLocId === (int)$loc['location_id'] ? ' (current)' : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Request Transfer</button>
    </form>
    <p class="hint" style="margin-top:6px">An OTP will be sent to the selected location's contact email for verification.</p>
    <?php endif; ?>
</div>

<?php if ($logs): ?>
<h3 style="font-size:14px;margin-bottom:8px">Transfer History</h3>
<div class="table-wrap" data-stack>
    <table class="table">
        <thead><tr><th>#</th><th>From</th><th>To</th><th>Date</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $i => $l): ?>
        <tr>
            <td><?= $i + 1 ?></td>
            <td><?= h($l['old_name'] ?? '—') ?></td>
            <td><?= h($l['new_name'] ?? '—') ?></td>
            <td class="text-muted"><?= date('d M Y H:i', strtotime($l['changed_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php
}

// ── Location OTP handlers ───────────────────────────────
function doRequestLocationOtp(): void {
    $targetId = (int)($_POST['target_location_id'] ?? 0);
    if ($targetId <= 0) {
        flash('error', 'Select a valid location.');
        header('Location: index.php?page=my_location'); exit;
    }

    $loc = getLocation($targetId);
    if (!$loc || !$loc['is_active']) {
        flash('error', 'Location not found or inactive.');
        header('Location: index.php?page=my_location'); exit;
    }

    $email = trim($loc['contact_email'] ?? '');
    if (!$email) {
        flash('error', 'Location has no contact email configured.');
        header('Location: index.php?page=my_location'); exit;
    }

    // Generate 6-digit OTP
    $otp = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['loc_otp'] = $otp;
    $_SESSION['loc_otp_target'] = $targetId;
    $_SESSION['loc_otp_expires'] = time() + 300; // 5 minutes

    // Send email
    $empName = myName() ?: myCode();
    $locName = $loc['location_name'];
    $body = "
    <div style='font-family:Arial,sans-serif;max-width:500px;margin:0 auto'>
        <div style='background:#1a1d2e;color:#fff;padding:16px 20px;border-radius:8px 8px 0 0'>
            <h2 style='margin:0;font-size:16px'>Location Transfer OTP</h2>
        </div>
        <div style='background:#f8f9fa;padding:20px;border:1px solid #dee2e6;border-top:0;border-radius:0 0 8px 8px'>
            <p style='font-size:14px'>Employee <strong>" . htmlspecialchars($empName) . "</strong> is requesting to transfer to <strong>" . htmlspecialchars($locName) . "</strong>.</p>
            <div style='text-align:center;margin:20px 0'>
                <div style='display:inline-block;background:#4f46e5;color:#fff;padding:14px 32px;border-radius:8px;font-size:24px;letter-spacing:6px;font-weight:bold'>{$otp}</div>
            </div>
            <p style='font-size:12px;color:#999'>This OTP expires in 5 minutes. If you did not request this, please ignore.</p>
        </div>
    </div>";

    $sent = sendSmtpEmail($email, 'Work Pulse — Location Transfer OTP', $body);
    if ($sent) {
        flash('success', "OTP sent to location email. Please enter it to verify.");
    } else {
        unset($_SESSION['loc_otp'], $_SESSION['loc_otp_target'], $_SESSION['loc_otp_expires']);
        flash('error', 'Failed to send OTP email. Check SMTP settings.');
    }
    header('Location: index.php?page=my_location'); exit;
}

function doVerifyLocationOtp(): void {
    $otp = trim($_POST['otp'] ?? '');

    if (empty($_SESSION['loc_otp'])) {
        flash('error', 'No OTP request found. Please try again.');
        header('Location: index.php?page=my_location'); exit;
    }

    if (time() > ($_SESSION['loc_otp_expires'] ?? 0)) {
        unset($_SESSION['loc_otp'], $_SESSION['loc_otp_target'], $_SESSION['loc_otp_expires']);
        flash('error', 'OTP expired. Please request a new one.');
        header('Location: index.php?page=my_location'); exit;
    }

    if ($otp !== $_SESSION['loc_otp']) {
        flash('error', 'Invalid OTP. Please try again.');
        header('Location: index.php?page=my_location'); exit;
    }

    $targetId = (int)$_SESSION['loc_otp_target'];
    $oldLocId = myLocationId();
    $empCode = myCode();

    // Update employee location
    $db = getDb();
    $db->prepare("UPDATE employees SET location_id = ?, updated_at = NOW() WHERE employee_code = ?")
       ->execute([$targetId, $empCode]);

    // Log the change
    $db->prepare("INSERT INTO employee_location_logs (employee_code, old_location_id, new_location_id) VALUES (?, ?, ?)")
       ->execute([$empCode, $oldLocId ?: null, $targetId]);

    // Update session
    $_SESSION['bio_location_id'] = $targetId;

    // Clean up OTP
    unset($_SESSION['loc_otp'], $_SESSION['loc_otp_target'], $_SESSION['loc_otp_expires']);

    $loc = getLocation($targetId);
    flash('success', 'Location updated to ' . ($loc['location_name'] ?? 'new location') . '.');
    header('Location: index.php?page=my_location'); exit;
}

function doCancelLocationOtp(): void {
    unset($_SESSION['loc_otp'], $_SESSION['loc_otp_target'], $_SESSION['loc_otp_expires']);
    flash('success', 'OTP request cancelled.');
    header('Location: index.php?page=my_location'); exit;
}

// ── Export attendance CSV (hr, operations, superadmin) ─────
function exportAttendance(): void {
    if (!canViewAttendance()) { flash('error', 'Access denied.'); header('Location: index.php'); exit; }

    $empCode    = trim($_GET['emp']       ?? '');
    $fromDate   = trim($_GET['from_date'] ?? '');
    $toDate     = trim($_GET['to_date']   ?? '');
    $locationId = (int)($_GET['loc']      ?? 0);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) $fromDate = date('Y-m-01');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate))   $toDate   = date('Y-m-d');

    $rows = getAttendance($empCode, $fromDate, $toDate, $locationId);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="attendance_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['emp_id', 'employee_name', 'date', 'punchtime', 'inout', 'punch_method', 'location_id', 'location_name'], escape: '');
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['employee_code'],
            $r['full_name'] ?? '',
            date('Y-m-d', strtotime($r['punch_time'])),
            date('H:i:s', strtotime($r['punch_time'])),
            $r['punch_type'],
            $r['punch_method'] ?? '',
            $r['location_id'] ?? '',
            $r['location_name'] ?? '',
        ], escape: '');
    }
    fclose($out);
    exit;
}

// ── Export own punches CSV (user) ─────────────────────────
function exportMyPunches(): void {
    $month = (int)($_GET['month'] ?? date('n'));
    $year  = (int)($_GET['year']  ?? date('Y'));

    $rows = getMyPunches(myCode(), $month, $year);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="my_punches_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['emp_id', 'date', 'punchtime', 'inout', 'punch_method', 'location_id', 'location_name'], escape: '');
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['employee_code'],
            date('Y-m-d', strtotime($r['punch_time'])),
            date('H:i:s', strtotime($r['punch_time'])),
            $r['punch_type'],
            $r['punch_method'] ?? '',
            $r['location_id'] ?? '',
            $r['location_name'] ?? '',
        ], escape: '');
    }
    fclose($out);
    exit;
}

// ── Export attendance report (as displayed on page) ─────
function exportAttendanceReport(): void {
    if (!canViewAttendance()) { flash('error', 'Access denied.'); header('Location: index.php'); exit; }

    $empCode    = trim($_GET['emp']       ?? '');
    $fromDate   = trim($_GET['from_date'] ?? '');
    $toDate     = trim($_GET['to_date']   ?? '');
    $locationId = (int)($_GET['loc']      ?? 0);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) $fromDate = date('Y-m-01');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate))   $toDate   = date('Y-m-d');

    // Same rows the on-screen report shows: auto-close placeholders out, so the
    // export and the page agree on punch times and working hours.
    $rows    = attStripAutoClose(getAttendance($empCode, $fromDate, $toDate, $locationId));
    $summary = buildDaySummary($rows);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="attendance_report_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Sr No', 'Employee ID', 'Employee Name', 'Department', 'Date', 'Punch Times', 'Working Hours'], escape: '');

    $sr = 1;
    foreach ($summary as $code => $emp) {
        foreach ($emp['days'] as $day => $d) {
            $punchTimes = implode(', ', array_map(
                fn($p) => $p['punch_type'] . ' ' . date('H:i:s', strtotime($p['punch_time'])),
                $d['punches']
            ));
            $hrs = fmtHours($d['first']['punch_time'] ?? null, $d['last']['punch_time'] ?? null);
            fputcsv($out, [
                $sr++,
                $code,
                $emp['name'],
                $emp['dept'],
                date('d/m/Y', strtotime($day)),
                $punchTimes,
                $hrs,
            ], escape: '');
        }
    }
    fclose($out);
    exit;
}

// ═════════════════════════════════════════════════════════
//  Odd Punch Report — incomplete in→out traces
// ═════════════════════════════════════════════════════════
// A finished shift day always carries an EVEN number of punches: IN/OUT,
// IN/OUT/IN/OUT, and so on (2, 4, 6, 8…). An odd count means one half of a
// pair never happened — someone forgot to punch — and the day cannot be
// traced end to end. The auto-close placeholder is deliberately excluded
// (attStripAutoClose): the system's 05:59:59 OUT would pad an odd day back
// to even and hide the very thing this report exists to find.

// The shift day that is still running, on the devices' own cutoff. Punches
// keep arriving for it, so an odd count there is simply "still at work" —
// never an exception. Everything strictly before it is final.
function attOpenShiftDay(): string {
    return shiftDay(date('Y-m-d H:i:s'));
}

// Keep only the days whose punch count is odd, and drop employees left with
// nothing. Days in the still-open shift are never flagged.
function attOddPunchSummary(array $summary): array {
    $open = attOpenShiftDay();
    $out  = [];
    foreach ($summary as $code => $emp) {
        $days = [];
        foreach ($emp['days'] as $day => $d) {
            if ($day >= $open) continue;
            if (count($d['punches']) % 2 === 1) $days[$day] = $d;
        }
        if (!$days) continue;
        $emp['days'] = $days;
        $out[$code]  = $emp;
    }
    return $out;
}

// A department selection only narrows anything when it is a real subset.
// Everything ticked (or nothing ticked) means no filter -- which also keeps
// employees who have no department at all in the report, and this report must
// not quietly drop anyone.
function attDeptFilter(array $selected, array $all): array {
    return ($selected && count($selected) < count($all)) ? $selected : [];
}

// Odd-punch days for a window. Grouping comes from shiftDay(), which follows
// the devices' configured cutoff -- so an OUT punched at 05:30 closes the
// shift it belongs to instead of leaving an odd day on both sides of midnight.
function attOddPunchDays(string $empCode, string $fromDate, string $toDate,
                         int $locationId = 0, array $deptIds = []): array {
    $rows = attStripAutoClose(getAttendance($empCode, $fromDate, $toDate, $locationId));
    if ($deptIds) {
        $keep = array_flip(array_map('intval', $deptIds));
        $rows = array_values(array_filter($rows, fn($r) => isset($keep[(int)($r['department_id'] ?? 0)])));
    }
    return attOddPunchSummary(buildDaySummary($rows));
}

// "IN 09:02:11, OUT 14:30:00, IN 15:10:42" — the day's trace on one line.
function attPunchTrace(array $punches): string {
    return implode(', ', array_map(
        fn($p) => $p['punch_type'] . ' ' . date('H:i:s', strtotime($p['punch_time'])),
        $punches
    ));
}

// ── Page: Odd Punch Report ───────────────────────────────
function pageOddPunches(): void {
    if (!canViewAttendance()) { echo '<div class="alert alert-error">Access denied.</div>'; return; }

    $empCode      = trim($_GET['emp']       ?? '');
    $fromDate     = trim($_GET['from_date'] ?? '');
    $toDate       = trim($_GET['to_date']   ?? '');
    $locationId   = (int)($_GET['loc']      ?? 0);
    $withLocation = !empty($_GET['with_location']);
    $doLoad       = isset($_GET['filter']);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) $fromDate = date('Y-m-01');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate))   $toDate   = date('Y-m-d');
    if (substr($fromDate, 0, 7) !== substr($toDate, 0, 7)) {
        $toDate = date('Y-m-t', strtotime($fromDate));
    }
    if ($fromDate > $toDate) $toDate = $fromDate;

    $monthEnd = date('Y-m-t', strtotime($fromDate));

    $emps = [];
    try {
        $emps = getDb()->query('SELECT employee_code,full_name FROM employees ORDER BY full_name')->fetchAll();
    } catch (Exception $e) {}

    $locs  = getActiveLocations();
    $depts = getDepartments();

    // Department multi-select, same control as the Employees page. Everything
    // is ticked on a first load so the page reads "Department: All".
    $allDeptIds = array_map(fn($d) => (string)$d['id'], $depts);
    $deptIds    = $doLoad
        ? array_values(array_intersect(array_map('strval', (array)($_GET['dept'] ?? [])), $allDeptIds))
        : $allDeptIds;

    $empLabel = '';
    foreach ($emps as $e) {
        if ($e['employee_code'] === $empCode) {
            $empLabel = $e['full_name'] . ' (' . $e['employee_code'] . ')';
            break;
        }
    }

    $summary = [];
    $totalDays = $totalEmps = $totalPunches = 0;
    if ($doLoad) {
        $summary   = attOddPunchDays($empCode, $fromDate, $toDate, $locationId, attDeptFilter($deptIds, $allDeptIds));
        $totalEmps = count($summary);
        foreach ($summary as $emp) {
            $totalDays += count($emp['days']);
            foreach ($emp['days'] as $d) $totalPunches += count($d['punches']);
        }
    }
?>
<div class="page-header"><h2>Odd Punch Report</h2></div>

<form method="GET" class="rpt-filter">
    <input type="hidden" name="page"   value="odd_punches">
    <input type="hidden" name="filter" value="1">
    <input type="hidden" name="emp" id="attEmpCode" value="<?= h($empCode) ?>">
    <span class="input-clear-wrap" style="flex:1 1 auto;min-width:200px">
        <input type="text" id="attEmpSearch" class="form-control rpt-filter-emp"
               placeholder="All Employees — type to search"
               value="<?= h($empLabel) ?>" autocomplete="off"
               style="max-width:none">
        <button type="button" id="attEmpClear" class="input-clear-btn" data-no-auto aria-label="Clear" tabindex="-1">&times;</button>
        <div id="attEmpList" style="position:absolute;top:100%;left:0;right:0;background:var(--surface);border:1px solid var(--border);border-radius:6px;margin-top:2px;max-height:280px;overflow-y:auto;display:none;z-index:100;box-shadow:0 6px 18px rgba(0,0,0,.35)"></div>
    </span>
    <input type="date" name="from_date" id="attFromDate" class="form-control" style="width:160px"
           value="<?= h($fromDate) ?>" required>
    <input type="date" name="to_date"   id="attToDate"   class="form-control" style="width:160px"
           value="<?= h($toDate)   ?>" min="<?= h($fromDate) ?>" max="<?= h($monthEnd) ?>" required>
    <select name="loc" class="form-control" style="width:200px">
        <option value="0">All Locations</option>
        <?php foreach ($locs as $l): ?>
        <option value="<?= (int)$l['location_id'] ?>" <?= $locationId === (int)$l['location_id'] ? 'selected' : '' ?>>
            <?= h($l['location_name']) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <?php
        $deptOptions = [];
        foreach ($depts as $d) $deptOptions[(string)$d['id']] = $d['department_name'];
        msFilterField('dept', 'Department', $deptOptions, $deptIds);
    ?>
    <label class="rpt-filter-chk">
        <input type="checkbox" name="with_location" value="1" <?= $withLocation ? 'checked' : '' ?>>
        Show Location
    </label>
    <button class="btn btn-primary">View</button>
    <?php
        $q = ['emp' => $empCode, 'from_date' => $fromDate, 'to_date' => $toDate,
              'loc' => $locationId, 'dept' => $deptIds];
        attGoButton('attendance',         'Full Report', $q + ['filter' => 1]);
        attGoButton('export_odd_punches', 'Export CSV',  $q, true);
    ?>
</form>
<?php attFilterScript($emps); msFilterScript(); ?>

<?php if (!$doLoad): ?>
<div class="rpt-prompt">Select filters and click <strong>View</strong> to list the days with an odd number of punches.</div>
<?php else: ?>

<div class="stats-grid-sm" style="margin-bottom:16px">
    <div class="stat-card stat-red"><div class="stat-val"><?= $totalDays ?></div><div class="stat-lbl">Odd Punch Days</div></div>
    <div class="stat-card stat-yellow"><div class="stat-val"><?= $totalEmps ?></div><div class="stat-lbl">Employees</div></div>
    <div class="stat-card"><div class="stat-val"><?= $totalPunches ?></div><div class="stat-lbl">Punches Involved</div></div>
</div>

<?php if (empty($summary)): ?>
<div class="table-wrap"><p class="empty-row">Every completed shift in this period has an even punch count — nothing to fix.</p></div>
<?php else: ?>

<div class="report-header-box">
    <strong>Dangee Dums Ltd</strong><br>
    Odd Punch Report — incomplete in/out trace<?= $withLocation ? ' (With Punch Machine Location)' : '' ?>
    &nbsp;From Date — <?= date('d/m/Y', strtotime($fromDate)) ?>
    &nbsp;To Date <?= date('d/m/Y', strtotime($toDate)) ?>
</div>

<div class="table-wrap" data-stack>
<table class="table rpt-table">
    <thead>
        <tr>
            <th class="rpt-sr">Sr No</th>
            <th class="rpt-id">Employee ID</th>
            <th class="rpt-name">Employee Name</th>
            <th class="rpt-date">Date</th>
            <th>Punches <?php if ($withLocation): ?><span class="rpt-loc-hdr">(with location)</span><?php endif; ?></th>
            <th class="rpt-hrs">Punch<br>Count</th>
            <th class="rpt-hrs">Missing</th>
        </tr>
    </thead>
    <tbody>
    <?php $sr = 1; $empIdx = 0; foreach ($summary as $code => $emp): $empIdx++; ?>
        <?php $dayKeys = array_keys($emp['days']); $firstDay = true; ?>
        <?php foreach ($dayKeys as $day): ?>
            <?php
                $punches = $emp['days'][$day]['punches'];
                // Odd count: whichever type the trace ends on is the one whose
                // pair never arrived — an open IN needs an OUT, and vice versa.
                $missing = (end($punches)['punch_type'] === 'IN') ? 'OUT' : 'IN';
            ?>
            <tr class="<?= ($firstDay && $empIdx > 1) ? 'rpt-emp-start' : '' ?>">
                <?php if ($firstDay): ?>
                <td class="rpt-sr" rowspan="<?= count($dayKeys) ?>"><?= $sr++ ?></td>
                <td class="rpt-id" rowspan="<?= count($dayKeys) ?>"><code><?= h($code) ?></code></td>
                <td class="rpt-name" rowspan="<?= count($dayKeys) ?>">
                    <?= h($emp['name']) ?>
                    <?php if ($emp['dept']): ?><br><small class="text-muted"><?= h($emp['dept']) ?></small><?php endif; ?>
                </td>
                <?php $firstDay = false; endif; ?>
                <td class="rpt-date" data-label="Date"><?= date('d/m/Y', strtotime($day)) ?></td>
                <td class="rpt-punches-cell" data-label="Punches">
                    <?php foreach ($punches as $p): ?>
                    <span class="punch-chip punch-chip-<?= mb_strtolower($p['punch_type']) ?>">
                        <span class="punch-chip-type"><?= $p['punch_type'] ?></span>
                        <span class="punch-chip-time"><?= date('H:i:s', strtotime($p['punch_time'])) ?></span>
                        <?php if ($withLocation && !empty($p['location_name'])): ?>
                        <span class="punch-chip-loc"><?= h($p['location_name']) ?></span>
                        <?php endif; ?>
                    </span>
                    <?php endforeach; ?>
                </td>
                <td class="rpt-hrs" data-label="Punch Count"><?= count($punches) ?></td>
                <td class="rpt-hrs" data-label="Missing"><?= $missing ?></td>
            </tr>
        <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>
<?php
}

// ── Export odd punch report CSV ──────────────────────────
function exportOddPunches(): void {
    if (!canViewAttendance()) { flash('error', 'Access denied.'); header('Location: index.php'); exit; }

    $empCode    = trim($_GET['emp']       ?? '');
    $fromDate   = trim($_GET['from_date'] ?? '');
    $toDate     = trim($_GET['to_date']   ?? '');
    $locationId = (int)($_GET['loc']      ?? 0);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) $fromDate = date('Y-m-01');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate))   $toDate   = date('Y-m-d');

    $allDeptIds = array_map(fn($d) => (string)$d['id'], getDepartments());
    $deptIds    = array_values(array_intersect(array_map('strval', (array)($_GET['dept'] ?? [])), $allDeptIds));

    $summary = attOddPunchDays($empCode, $fromDate, $toDate, $locationId,
                               attDeptFilter($deptIds, $allDeptIds));

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="odd_punches_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Sr No', 'Employee ID', 'Employee Name', 'Department', 'Date', 'Punch Times', 'Punch Count', 'Missing'], escape: '');

    $sr = 1;
    foreach ($summary as $code => $emp) {
        foreach ($emp['days'] as $day => $d) {
            $punches = $d['punches'];
            fputcsv($out, [
                $sr++,
                $code,
                $emp['name'],
                $emp['dept'],
                date('d/m/Y', strtotime($day)),
                attPunchTrace($punches),
                count($punches),
                end($punches)['punch_type'] === 'IN' ? 'OUT' : 'IN',
            ], escape: '');
        }
    }
    fclose($out);
    exit;
}

// ── Daily odd-punch alert ────────────────────────────────
// Two mails go out each morning, from one pass over the data:
//   * one CONSOLIDATED digest to HR + Operations, every location in it;
//   * one mail PER LOCATION to that store's own contact_email, carrying
//     only its own people.
// Grouping is by the location the EMPLOYEE has claimed (employees.location_id),
// not by the machine the punch landed on -- someone covering a shift at
// another store is still their own manager's to chase.

// HR + Operations, the consolidated recipients. These are the addresses that
// already receive punch-request notifications, which is exactly the audience
// for a missing punch.
function attOddPunchNotifyEmails(): array {
    return attParseEmails(implode(',', [
        (string)getSetting('PunchRequestNotifyHR', ''),
        (string)getSetting('PunchRequestNotifyOps', ''),
    ]));
}

// Tolerates a comma/semicolon/whitespace list or a JSON array of {"email":…}
// objects -- the two shapes the settings boxes hold across this app.
function attParseEmails(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') return [];
    $rows = json_decode($raw, true);
    if (!is_array($rows)) $rows = preg_split('/[,;\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach ($rows as $r) {
        $e = is_array($r) ? trim((string)($r['email'] ?? '')) : trim((string)$r);
        if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $out[] = $e;
    }
    return array_values(array_unique($out));
}

// The shift day the 07:00 run reports on: the one that closed at
// (cutoff-1):59:59 this morning, i.e. the day before the one now running.
function attOddPunchTargetDay(): string {
    return date('Y-m-d', strtotime(attOpenShiftDay() . ' -1 day'));
}

// Regroup an odd-punch summary under the employee's claimed location.
// Location id 0 collects everyone who has never claimed one; they ride the
// consolidated mail only, since there is no store to send theirs to.
// Returns [locId => ['name' => …, 'days' => n, 'employees' => [code => emp]]],
// stores first by name with the unassigned bucket last.
function attOddPunchByLocation(array $summary): array {
    $out = [];
    foreach ($summary as $code => $emp) {
        $firstDay = reset($emp['days']);
        $row      = $firstDay['punches'][0] ?? [];
        $locId    = (int)($row['emp_location_id'] ?? 0);
        $locName  = trim((string)($row['emp_location_name'] ?? ''));
        if (!isset($out[$locId])) {
            $out[$locId] = [
                'name'      => $locId > 0 && $locName !== '' ? $locName : 'Unassigned location',
                'days'      => 0,
                'employees' => [],
            ];
        }
        $out[$locId]['employees'][$code] = $emp;
        $out[$locId]['days'] += count($emp['days']);
    }
    uksort($out, function ($a, $b) use ($out) {
        if ($a === 0 || $b === 0) return $a === 0 ? 1 : -1;   // unassigned last
        return strcasecmp($out[$a]['name'], $out[$b]['name']);
    });
    return $out;
}

// locations.contact_email keyed by location id -- the established "mail this
// store" address, same one the location-claim OTP and the price-variation
// decisions use. A store with no address simply gets no mail of its own; its
// rows are still in the consolidated digest.
function attLocationEmails(): array {
    $out = [];
    foreach (getLocations() as $l) {
        $emails = attParseEmails((string)($l['contact_email'] ?? ''));
        if ($emails) $out[(int)$l['location_id']] = $emails;
    }
    return $out;
}

// One table of odd-punch days. "Punched At" is the machine's location, which
// is worth showing even in a store's own mail: it is how you spot someone
// punching at a store they are not assigned to.
function attOddPunchTable(array $employees): string {
    $th = "style='padding:6px 8px;text-align:left;border-bottom:1px solid #ddd'";
    $td = "style='padding:5px 8px;border-bottom:1px solid #eee'";

    $h = "<table cellpadding='0' cellspacing='0' style='border-collapse:collapse;width:100%;font:13px/1.5 Arial,sans-serif'>"
       . "<thead><tr style='background:#fafafa'>"
       . "<th {$th}>Employee</th><th {$th}>Department</th><th {$th}>Date</th>"
       . "<th {$th}>Punched At</th><th {$th}>Punches</th>"
       . "<th style='padding:6px 8px;text-align:right;border-bottom:1px solid #ddd'>Count</th>"
       . "<th {$th}>Missing</th>"
       . '</tr></thead><tbody>';

    foreach ($employees as $code => $emp) {
        foreach ($emp['days'] as $day => $d) {
            $punches = $d['punches'];
            $at      = trim((string)($punches[0]['location_name'] ?? ''));
            $missing = end($punches)['punch_type'] === 'IN' ? 'OUT' : 'IN';
            $h .= '<tr>'
               . "<td {$td}>" . h($emp['name']) . " <span style='color:#888'>(" . h((string)$code) . ')</span></td>'
               . "<td {$td}>" . h($emp['dept'] !== '' ? $emp['dept'] : '—') . '</td>'
               . "<td {$td}>" . h(date('d/m/Y', strtotime($day))) . '</td>'
               . "<td {$td}>" . h($at !== '' ? $at : '—') . '</td>'
               . "<td {$td}>" . h(attPunchTrace($punches)) . '</td>'
               . "<td style='padding:5px 8px;border-bottom:1px solid #eee;text-align:right;color:#c0392b;font-weight:700'>" . count($punches) . '</td>'
               . "<td {$td}><strong>" . $missing . '</strong></td>'
               . '</tr>';
        }
    }
    return $h . '</tbody></table>';
}

// $grouped is attOddPunchByLocation() output, already narrowed to whatever
// this recipient should see -- one location for a store's mail, all of them
// for the consolidated digest. Section headings are dropped when there is
// only one, so a store's mail is not headed by its own name twice.
function attBuildOddPunchEmail(array $grouped, string $shiftDate, string $heading): string {
    $base  = rtrim((string)getSetting('AppBaseUrl', ''), '/');
    $days  = array_sum(array_column($grouped, 'days'));
    $multi = count($grouped) > 1;

    $body = '';
    foreach ($grouped as $loc) {
        if ($multi) {
            $body .= "<h3 style='font:600 15px/1.4 Arial,sans-serif;color:#c0392b;margin:22px 0 8px'>"
                   . h($loc['name']) . ' (' . $loc['days'] . ")</h3>";
        }
        $body .= attOddPunchTable($loc['employees']);
    }

    $link = '';
    if ($base !== '') {
        $url  = $base . '/index.php?' . http_build_query([
            'page' => 'odd_punches', 'filter' => 1, 'emp' => '',
            'from_date' => $shiftDate, 'to_date' => $shiftDate, 'loc' => 0,
        ]);
        $link = "<p style='margin:18px 0 0'><a href='" . h($url)
              . "' style='color:#1a8fe3;text-decoration:none'>Open the Odd Punch Report &rarr;</a></p>";
    }

    return "<div style='font:14px/1.6 Arial,sans-serif;color:#222'>"
         . "<h2 style='font:600 18px/1.4 Arial,sans-serif;margin:0 0 6px'>" . h($heading) . '</h2>'
         . "<p style='margin:0 0 14px;color:#555'>The shift closed at "
         . h(sprintf('%02d:59:59', shiftCutoffHour() - 1))
         . '. A complete in&rarr;out trace always has an even number of punches (2, 4, 6, 8&hellip;); '
         . 'the ' . $days . ' day' . ($days === 1 ? '' : 's') . ' below ended on an odd count, so a punch is missing. '
         . 'Raise a Missing Punch request in Work Pulse and HR can approve the one that was not made.</p>'
         . $body
         . $link
         . "<p style='margin:22px 0 0;color:#888;font-size:12px'>Work Pulse &middot; HRMS &rsaquo; Odd Punch Report</p>"
         . '</div>';
}

// Digest for one closed shift day. Silent when every trace is even -- an
// empty "nothing to report" mail every morning trains people to ignore it.
// Returns the number of odd-punch days reported.
function attSendOddPunchDigest(string $shiftDate = ''): int {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $shiftDate)) $shiftDate = attOddPunchTargetDay();

    $summary = attOddPunchDays('', $shiftDate, $shiftDate, 0);
    if (!$summary) return 0;

    $grouped = attOddPunchByLocation($summary);
    $days    = array_sum(array_column($grouped, 'days'));
    $nice    = date('d M Y', strtotime($shiftDate));

    // Each recipient's subject counts only what that recipient can see, so a
    // store with one bad day reads "1 incomplete in/out trace", not "traces".
    $subject = fn(int $n, string $where): string =>
        'Odd punches — ' . ($where !== '' ? $where . ' — ' : '') . $n
        . ' incomplete in/out trace' . ($n === 1 ? '' : 's') . ' — ' . $nice;

    // 1. Consolidated digest — HR + Operations, every location.
    $emails = attOddPunchNotifyEmails();
    if ($emails) {
        $body = attBuildOddPunchEmail($grouped, $shiftDate, 'Odd punches — shift of ' . $nice);
        foreach ($emails as $e) sendSmtpEmailQuiet($e, $subject($days, ''), $body);
    } else {
        error_log('[attendance] ' . $days . ' odd-punch day(s) on ' . $shiftDate
                . ' but PunchRequestNotifyHR/Ops are both empty');
    }

    // 2. One mail per location, to that store's own contact_email. The
    //    unassigned bucket (id 0) has no store to write to and stays in the
    //    consolidated digest only.
    $byLoc = attLocationEmails();
    foreach ($grouped as $locId => $loc) {
        if ($locId === 0 || empty($byLoc[$locId])) continue;
        $body = attBuildOddPunchEmail([$locId => $loc], $shiftDate,
                                      'Odd punches — ' . $loc['name'] . ' — shift of ' . $nice);
        foreach ($byLoc[$locId] as $e) sendSmtpEmailQuiet($e, $subject($loc['days'], $loc['name']), $body);
    }

    return $days;
}

// ── Page: Failed Punches (superadmin) ───────────────────
// POST: superadmin-only — wipe every row from failed_punch_logs.
// There is no "soft" version: this is the maintenance escape hatch
// when the table grows too large to scroll. Issued from the page UI
// behind a JS confirm + a checkbox.
function doDeleteAllFailedPunches(): void {
    if (!isSuperadmin()) {
        flash('error', 'Access denied.');
        header('Location: index.php?page=failed_punches'); exit;
    }
    try {
        $deleted = (int)getDb()->exec('DELETE FROM failed_punch_logs');
        flash('success', 'Deleted ' . $deleted . ' failed-punch log row(s).');
    } catch (Exception $e) {
        flash('error', 'Delete failed: ' . $e->getMessage());
    }
    header('Location: index.php?page=failed_punches'); exit;
}

function pageFailedPunches(): void {
    if (!isSuperadmin() && !hasTxn('failed_punches')) { flash('error', 'Access denied.'); header('Location: index.php'); exit; }

    $db = getDb();
    $empCode  = trim($_GET['emp'] ?? '');
    $fromDate = trim($_GET['from_date'] ?? (isset($_GET['view']) ? '' : date('Y-m-d')));
    $toDate   = trim($_GET['to_date'] ?? (isset($_GET['view']) ? '' : date('Y-m-d')));
    $doLoad   = isset($_GET['view']);

    $emps = [];
    try {
        $emps = $db->query('SELECT employee_code, full_name FROM employees ORDER BY full_name')->fetchAll();
    } catch (Exception $e) {}

    $rows = [];
    if ($doLoad) {
        $where = []; $params = [];
        if ($empCode !== '') {
            $where[] = 'f.employee_code = ?';
            $params[] = $empCode;
        }
        if ($fromDate !== '') {
            $where[] = 'f.attempted_at >= ?';
            $params[] = $fromDate . ' 00:00:00';
        }
        if ($toDate !== '') {
            $where[] = 'f.attempted_at <= ?';
            $params[] = $toDate . ' 23:59:59';
        }

        $sql = "SELECT f.*, COALESCE(e.full_name, f.employee_code) AS full_name,
                       l.location_name
                FROM failed_punch_logs f
                LEFT JOIN employees e ON f.employee_code = e.employee_code
                LEFT JOIN locations l ON f.location_id = l.location_id";
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY f.attempted_at DESC LIMIT 500';

        $st = $db->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
?>
<div class="page-header"><h2>Failed Punches</h2></div>

<?php if (isSuperadmin()): ?>
<form method="POST" style="margin-bottom:14px"
      onsubmit="return confirm('Permanently delete EVERY failed-punch log row across all employees and dates? This cannot be undone.');">
    <input type="hidden" name="action" value="delete_all_failed_punches">
    <button type="submit" class="btn btn-danger" style="font-size:12px;padding:6px 12px;display:inline-flex;align-items:center;gap:6px">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <polyline points="3 6 5 6 21 6"/>
            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
            <path d="M10 11v6"/>
            <path d="M14 11v6"/>
            <path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/>
        </svg>
        Delete All Failed-Punch Logs
    </button>
    <span class="hint" style="margin-left:8px;font-size:11px">Superadmin maintenance — wipes the entire <code>failed_punch_logs</code> table.</span>
</form>
<?php endif; ?>

<form method="GET" class="rpt-filter">
    <input type="hidden" name="page" value="failed_punches">
    <input type="hidden" name="view" value="1">
    <select name="emp" class="form-control" style="flex:1 1 auto;min-width:200px">
        <option value="">All Employees</option>
        <?php foreach ($emps as $e): ?>
        <option value="<?= h($e['employee_code']) ?>" <?= $empCode === $e['employee_code'] ? 'selected' : '' ?>>
            <?= h($e['full_name']) ?> (<?= h($e['employee_code']) ?>)
        </option>
        <?php endforeach; ?>
    </select>
    <input type="date" name="from_date" class="form-control" style="width:150px" value="<?= h($fromDate) ?>">
    <input type="date" name="to_date" class="form-control" style="width:150px" value="<?= h($toDate) ?>">
    <button type="submit" class="btn btn-primary">View</button>
    <?php if ($doLoad): ?>
    <a href="?page=failed_punches" class="btn btn-ghost">Clear</a>
    <?php endif; ?>
</form>

<?php if (!$doLoad): ?>
<div class="rpt-prompt">Apply filters and click <strong>View</strong> to load failed punch logs.</div>
<?php elseif (empty($rows)): ?>
<div class="rpt-prompt">No failed punches found for the selected criteria.</div>
<?php else: ?>
<div class="table-wrap" data-stack>
    <table class="table">
        <thead>
            <tr>
                <th style="width:50px">#</th>
                <th>Employee</th>
                <th>Device</th>
                <th>Type</th>
                <th>Method</th>
                <th>Score</th>
                <th>Threshold</th>
                <th>Reason</th>
                <th>Location</th>
                <th>App Ver</th>
                <th>Time</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $i => $r): ?>
        <tr>
            <td><?= $i + 1 ?></td>
            <td>
                <?= h($r['full_name']) ?>
                <br><small class="text-muted"><?= h($r['employee_code'] ?? '—') ?></small>
            </td>
            <td>
                <span class="badge badge-grey"><?= h($r['device_type']) ?></span>
                <br><small class="text-muted"><?= h($r['device_serial']) ?></small>
            </td>
            <td><?php if ($r['punch_type']): ?>
                <span class="badge <?= $r['punch_type'] === 'IN' ? 'badge-green' : 'badge-blue' ?>"><?= $r['punch_type'] ?></span>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td><span class="badge <?= ($r['punch_method'] ?? '') === 'otp' ? 'badge-purple' : 'badge-grey' ?>"><?= h($r['punch_method'] ?? 'fingerprint') ?></span></td>
            <td style="text-align:center"><?= $r['match_score'] !== null ? (int)$r['match_score'] : '—' ?></td>
            <td style="text-align:center"><?= $r['threshold_used'] !== null ? (int)$r['threshold_used'] : '—' ?></td>
            <td style="font-size:12px">
                <?php
                    $reason = $r['fail_reason'] ?? '';
                    $cls = match(true) {
                        str_contains($reason, 'threshold') => 'badge-yellow',
                        str_contains($reason, 'not found') => 'badge-red',
                        str_contains($reason, 'otp') || str_contains($reason, 'OTP') => 'badge-purple',
                        default => 'badge-grey',
                    };
                ?>
                <span class="badge <?= $cls ?>"><?= h($reason ?: '—') ?></span>
            </td>
            <td><?= h($r['location_name'] ?? '—') ?></td>
            <td style="font-size:12px">
                <?php if (!empty($r['app_version'])): ?>
                <span class="badge badge-grey"><?= h($r['app_version']) ?></span>
                <?php else: ?>
                <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
            <td class="text-muted" style="font-size:12px">
                <?= date('d M Y', strtotime($r['attempted_at'])) ?>
                <br><?= date('H:i:s', strtotime($r['attempted_at'])) ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<div class="table-count"><?= count($rows) ?> failed punch(es)</div>
<?php endif; ?>
<?php }
