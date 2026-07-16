<?php
// =========================================================
// Store Open/Close Hours — Date-range view of first IN / last OUT
// Supports: optional single location (0 = all), max 1-month range, CSV export
// =========================================================

function pageStoreHours(): void {
    if (!isSuperadmin() && !hasTxn('store_hours')) {
        flash('error', 'Access denied.');
        header('Location: index.php');
        exit;
    }

    $locationId = (int)($_GET['location_id'] ?? 0);
    $today      = date('Y-m-d');
    $yesterday  = date('Y-m-d', strtotime('-1 day'));
    $fromDate   = trim($_GET['from_date'] ?? $yesterday);
    $toDate     = trim($_GET['to_date']   ?? $today);
    $doLoad     = isset($_GET['filter']);
    $locations  = getActiveLocations();

    // Validate dates
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) $fromDate = $yesterday;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate))   $toDate   = $today;
    if (strtotime($toDate) < strtotime($fromDate)) $toDate = $fromDate;
    // Enforce max 1-month range server-side (same calendar month cap)
    $fromTs = strtotime($fromDate);
    $monthEnd = date('Y-m-t', $fromTs);
    if (strtotime($toDate) > strtotime($monthEnd)) $toDate = $monthEnd;

    $byLoc = [];
    if ($doLoad) {
        $byLoc = getStoreHoursData($locationId, $fromDate, $toDate);
    }

    // Build inclusive list of dates in range
    $dateList = [];
    $cur = strtotime($fromDate);
    $end = strtotime($toDate);
    while ($cur <= $end) {
        $dateList[] = date('Y-m-d', $cur);
        $cur = strtotime('+1 day', $cur);
    }
?>
<div class="page-header"><h2>Store Open / Close Hours</h2></div>

<?php
    $shLocLabel = 'All Locations';
    if ($locationId > 0) {
        foreach ($locations as $loc) {
            if ((int)$loc['location_id'] === $locationId) { $shLocLabel = $loc['location_name']; break; }
        }
    }
?>
<form method="GET" class="rpt-filter" id="shFilter">
    <input type="hidden" name="page" value="store_hours">
    <input type="hidden" name="filter" value="1">
    <input type="hidden" name="location_id" id="shLocId" value="<?= (int)$locationId ?>">
    <span class="input-clear-wrap" style="flex:1 1 auto;min-width:220px">
        <input type="text" id="shLocSearch" class="form-control"
               placeholder="All Locations — type to search"
               value="<?= h($locationId > 0 ? $shLocLabel : '') ?>" autocomplete="off">
        <button type="button" id="shLocClear" class="input-clear-btn" data-no-auto aria-label="Clear" tabindex="-1">&times;</button>
        <div id="shLocList" style="position:absolute;top:100%;left:0;right:0;background:var(--surface);border:1px solid var(--border);border-radius:6px;margin-top:2px;max-height:280px;overflow-y:auto;display:none;z-index:100;box-shadow:0 6px 18px rgba(0,0,0,.35)"></div>
    </span>
    <input type="date" name="from_date" id="shFrom" class="form-control" style="width:160px" value="<?= h($fromDate) ?>" onchange="shClamp()">
    <input type="date" name="to_date"   id="shTo"   class="form-control" style="width:160px" value="<?= h($toDate)   ?>">
    <button class="btn btn-primary" type="submit">View</button>
    <?php if ($doLoad): ?>
    <a href="?page=export_store_hours&location_id=<?= $locationId ?>&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>"
       class="btn btn-ghost btn-sm" target="_blank">Export CSV</a>
    <?php endif; ?>
</form>

<script>
function shClamp() {
    var fromEl = document.getElementById('shFrom');
    var toEl   = document.getElementById('shTo');
    if (!fromEl.value) return;
    var d = new Date(fromEl.value + 'T00:00:00');
    // End of the same calendar month
    var lastDay = new Date(d.getFullYear(), d.getMonth() + 1, 0);
    var pad = function(n){ return n < 10 ? '0'+n : n; };
    var maxStr = lastDay.getFullYear() + '-' + pad(lastDay.getMonth()+1) + '-' + pad(lastDay.getDate());
    toEl.min = fromEl.value;
    toEl.max = maxStr;
    if (toEl.value < fromEl.value) toEl.value = fromEl.value;
    if (toEl.value > maxStr)       toEl.value = maxStr;
}
document.addEventListener('DOMContentLoaded', shClamp);

// Location keyword search → custom dropdown below input
(function () {
    var search   = document.getElementById('shLocSearch');
    var hidden   = document.getElementById('shLocId');
    var clearBtn = document.getElementById('shLocClear');
    var list     = document.getElementById('shLocList');
    if (!search || !hidden || !list) return;
    var locData = <?= json_encode(array_map(fn($l) => ['id' => (int)$l['location_id'], 'name' => $l['location_name']], $locations), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    // Include "All Locations" sentinel at top
    var options = [{id: 0, name: 'All Locations'}].concat(locData);
    var escHtml = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
    var render = function (q) {
        q = (q || '').trim().toLowerCase();
        var matches = q === '' ? options : options.filter(function (l) { return l.name.toLowerCase().indexOf(q) !== -1; });
        if (matches.length === 0) {
            list.innerHTML = '<div style="padding:10px 12px;color:var(--muted);font-size:13px">No locations match</div>';
        } else {
            list.innerHTML = matches.slice(0, 200).map(function (l) {
                return '<div class="sh-loc-opt" data-id="' + l.id + '" data-name="' + escHtml(l.name) + '" style="padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid rgba(255,255,255,.04)">' + escHtml(l.name) + '</div>';
            }).join('');
        }
        list.style.display = 'block';
    };
    var hide = function () { list.style.display = 'none'; };
    var wrap = search.closest('.input-clear-wrap');
    var syncVis = function () { if (wrap) wrap.classList.toggle('has-value', !!search.value); };
    var selectLoc = function (id, name) {
        hidden.value = id;
        search.value = (String(id) === '0') ? '' : name;
        syncVis();
        hide();
    };
    var clearAll = function () { search.value = ''; hidden.value = '0'; syncVis(); };
    syncVis();
    search.addEventListener('focus', function () { render(search.value); });
    search.addEventListener('input', function () { hidden.value = '0'; render(search.value); });
    list.addEventListener('mousedown', function (ev) {
        var opt = ev.target.closest('.sh-loc-opt');
        if (!opt) return;
        ev.preventDefault();
        selectLoc(opt.getAttribute('data-id'), opt.getAttribute('data-name'));
    });
    list.addEventListener('mouseover', function (ev) {
        var opt = ev.target.closest('.sh-loc-opt');
        if (!opt) return;
        Array.prototype.forEach.call(list.querySelectorAll('.sh-loc-opt'), function (o) { o.style.background = ''; });
        opt.style.background = 'rgba(26,143,227,.12)';
    });
    document.addEventListener('mousedown', function (ev) {
        if (ev.target !== search && !list.contains(ev.target) && ev.target !== clearBtn) hide();
    });
    search.addEventListener('mousedown', function () {
        if (document.activeElement !== search && search.value !== '') clearAll();
    });
    if (clearBtn) clearBtn.addEventListener('click', function () { clearAll(); search.focus(); render(''); });
})();
</script>

<?php if (!$doLoad): ?>
<div class="rpt-prompt">Choose a date range (max 1 calendar month) and click <strong>View</strong>.</div>
<?php else: ?>

<div class="report-header-box">
    <strong><?= $locationId > 0 ? h($byLoc[$locationId]['location_name'] ?? 'Selected Location') : 'All Locations' ?></strong>
    — Store Hours from <?= date('d/m/Y', strtotime($fromDate)) ?> to <?= date('d/m/Y', strtotime($toDate)) ?>
</div>

<?php if (empty($byLoc)): ?>
<div class="table-wrap"><p class="empty-row">No punches recorded for the selected range.</p></div>
<?php else: ?>

<div class="table-wrap" data-stack>
<table class="table rpt-table">
    <thead>
        <tr>
            <th style="width:50px">#</th>
            <?php if ($locationId <= 0): ?><th>Location</th><?php endif; ?>
            <th class="rpt-date">Date</th>
            <th>Day</th>
            <th>Open (First IN)</th>
            <th>Employee</th>
            <th>Close (Last OUT)</th>
            <th>Employee</th>
            <th class="rpt-hrs">Duration</th>
        </tr>
    </thead>
    <tbody>
    <?php
    $sr = 1;
    foreach ($byLoc as $locId => $locData):
        foreach ($dateList as $dateStr):
            $dayData = $locData['days'][$dateStr] ?? null;
            $dayName = date('D', strtotime($dateStr));
            $firstIn  = $dayData['first_in'] ?? null;
            $lastOut  = $dayData['last_out'] ?? null;
            $duration = fmtHours($firstIn, $lastOut);
    ?>
        <tr>
            <td><?= $sr++ ?></td>
            <?php if ($locationId <= 0): ?><td><?= h($locData['location_name']) ?></td><?php endif; ?>
            <td class="rpt-date"><?= date('d/m/Y', strtotime($dateStr)) ?></td>
            <td><?= $dayName ?></td>
            <td>
                <?php if ($firstIn): ?>
                    <span class="badge badge-green"><?= date('H:i:s', strtotime($firstIn)) ?></span>
                <?php else: ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
            <td style="font-size:12px"><?= $firstIn ? h($dayData['first_in_emp']) : '' ?></td>
            <td>
                <?php if ($lastOut): ?>
                    <span class="badge badge-red"><?= date('H:i:s', strtotime($lastOut)) ?></span>
                <?php else: ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
            <td style="font-size:12px"><?= $lastOut ? h($dayData['last_out_emp']) : '' ?></td>
            <td class="rpt-hrs"><?= $duration ?></td>
        </tr>
    <?php endforeach; endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>
<?php
}

// ── CSV export of store hours ────────────────────────────
function exportStoreHours(): void {
    if (!isSuperadmin() && !hasTxn('store_hours')) {
        flash('error', 'Access denied.');
        header('Location: index.php'); exit;
    }
    $locationId = (int)($_GET['location_id'] ?? 0);
    $today      = date('Y-m-d');
    $yesterday  = date('Y-m-d', strtotime('-1 day'));
    $fromDate   = trim($_GET['from_date'] ?? $yesterday);
    $toDate     = trim($_GET['to_date']   ?? $today);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) $fromDate = $yesterday;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate))   $toDate   = $today;
    if (strtotime($toDate) < strtotime($fromDate)) $toDate = $fromDate;
    $monthEnd = date('Y-m-t', strtotime($fromDate));
    if (strtotime($toDate) > strtotime($monthEnd)) $toDate = $monthEnd;

    $byLoc = getStoreHoursData($locationId, $fromDate, $toDate);

    // Build date list
    $dateList = [];
    $cur = strtotime($fromDate); $end = strtotime($toDate);
    while ($cur <= $end) { $dateList[] = date('Y-m-d', $cur); $cur = strtotime('+1 day', $cur); }

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="store_hours_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Location', 'Date', 'Day', 'First IN', 'First IN Employee', 'Last OUT', 'Last OUT Employee', 'Duration'], escape: '');

    foreach ($byLoc as $locId => $locData) {
        foreach ($dateList as $dateStr) {
            $dayData = $locData['days'][$dateStr] ?? null;
            $firstIn = $dayData['first_in'] ?? null;
            $lastOut = $dayData['last_out'] ?? null;
            fputcsv($out, [
                $locData['location_name'],
                date('d/m/Y', strtotime($dateStr)),
                date('D', strtotime($dateStr)),
                $firstIn ? date('H:i:s', strtotime($firstIn)) : '',
                $firstIn ? ($dayData['first_in_emp'] ?? '') : '',
                $lastOut ? date('H:i:s', strtotime($lastOut)) : '',
                $lastOut ? ($dayData['last_out_emp'] ?? '') : '',
                fmtHours($firstIn, $lastOut),
            ], escape: '');
        }
    }
    fclose($out);
    exit;
}
