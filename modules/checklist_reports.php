<?php
// =========================================================
// Checklist Reports — monthly report + audit report + CSV export
// =========================================================

// ── Export Monthly Checklist Report as CSV ─────────────────
function exportChecklistReport(): void {
    $db = getDb();
    $selectedMonth = (int)($_GET['month'] ?? date('m'));
    $selectedYear  = (int)($_GET['year']  ?? date('Y'));
    $locationId    = (int)($_GET['location_id'] ?? 0);

    if ($locationId < 1) { echo 'Location required.'; exit; }

    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $selectedMonth, $selectedYear);
    $questions   = $db->query("SELECT id, task_description, section_name, is_active FROM chk_items ORDER BY section_name, id ASC")->fetchAll(PDO::FETCH_ASSOC);

    $responses = [];
    $st = $db->prepare(
        "SELECT item_id, DAY(log_date) AS day, response_value
         FROM chk_daily_responses
         WHERE location_id = ? AND MONTH(log_date) = ? AND YEAR(log_date) = ?"
    );
    $st->execute([$locationId, $selectedMonth, $selectedYear]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $responses[$row['item_id']][$row['day']] = $row['response_value'];
    }

    // Location name
    $lst = $db->prepare("SELECT location_name FROM locations WHERE location_id = ?");
    $lst->execute([$locationId]);
    $locationName = $lst->fetchColumn() ?: 'Unknown';

    $monthName = date('F Y', mktime(0, 0, 0, $selectedMonth, 1, $selectedYear));
    $filename  = "checklist_{$locationName}_{$monthName}.csv";
    $filename  = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');

    // Header row: #, Section, Task, Day 01, Day 02, ...
    $header = ['#', 'Section', 'Task'];
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $header[] = sprintf('%02d', $d);
    }
    fputcsv($out, $header, escape: '');

    // Store opening/closing rows from first IN / last OUT, 4AM shift cutoff
    $openByDay = []; $closeByDay = [];
    $monthStart = sprintf('%04d-%02d-01', $selectedYear, $selectedMonth);
    $monthEnd   = date('Y-m-t', strtotime($monthStart));
    $hours      = getStoreHoursData($locationId, $monthStart, $monthEnd);
    foreach (($hours[$locationId]['days'] ?? []) as $shiftDate => $info) {
        $d = (int)date('j', strtotime($shiftDate));
        if (!empty($info['first_in'])) $openByDay[$d]  = date('H:i', strtotime($info['first_in']));
        if (!empty($info['last_out'])) $closeByDay[$d] = date('H:i', strtotime($info['last_out']));
    }
    $openRow  = ['★', 'Store Hours', 'Store opening time'];
    $closeRow = ['★', 'Store Hours', 'Store closing time'];
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $openRow[]  = $openByDay[$d]  ?? '';
        $closeRow[] = $closeByDay[$d] ?? '';
    }
    fputcsv($out, $openRow, escape: '');

    // Banking/Cash Deposit — Yes when a validated, non-invalidated transaction exists for the day
    $bankingByDay = [];
    $bs = $db->prepare(
        "SELECT DISTINCT DAY(txn_date) AS day
         FROM transactions
         WHERE location_id = ?
           AND MONTH(txn_date) = ?
           AND YEAR(txn_date) = ?
           AND validated_at IS NOT NULL
           AND invalidated_at IS NULL"
    );
    $bs->execute([$locationId, $selectedMonth, $selectedYear]);
    foreach ($bs->fetchAll(PDO::FETCH_ASSOC) as $brow) {
        $bankingByDay[(int)$brow['day']] = 'Yes';
    }

    $sr = 1; $lastSection = null;
    foreach ($questions as $q) {
        $hasData = isset($responses[$q['id']]) && count($responses[$q['id']]) > 0;
        if (!$q['is_active'] && !$hasData) continue;

        if ($q['section_name'] === '2.Afternoon' && $lastSection !== '2.Afternoon') {
            $bankingRow = ['★', '2.Afternoon', 'Complete Banking/Cash Deposit'];
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $bankingRow[] = $bankingByDay[$d] ?? '';
            }
            fputcsv($out, $bankingRow, escape: '');
        }
        $lastSection = $q['section_name'];

        $row = [
            $sr++,
            $q['section_name'] ?: 'General',
            $q['task_description'] . (!$q['is_active'] ? ' (Retired)' : ''),
        ];
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $row[] = $responses[$q['id']][$d] ?? '';
        }
        fputcsv($out, $row, escape: '');
    }

    fputcsv($out, $closeRow, escape: '');
    fclose($out);
    exit;
}

// ── Monthly Report ────────────────────────────────────────
function pageChecklistReport(): void {
    $db = getDb();
    $selectedMonth = (int)($_GET['month'] ?? date('m'));
    $selectedYear  = (int)($_GET['year']  ?? date('Y'));
    $locationId    = (int)($_GET['location_id'] ?? 0);

    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $selectedMonth, $selectedYear);
    $locations   = getActiveLocations();
    $questions   = $db->query("SELECT id, task_description, section_name, is_active FROM chk_items ORDER BY section_name, id ASC")->fetchAll(PDO::FETCH_ASSOC);

    $responses = [];
    $valByItemDay = [];   // [item_id][day] => 'done' | 'not_done' (operation validation)
    $openByDay = []; $closeByDay = []; $bankingByDay = [];
    if ($locationId > 0) {
        $st = $db->prepare(
            "SELECT item_id, DAY(log_date) AS day, response_value
             FROM chk_daily_responses
             WHERE location_id = ? AND MONTH(log_date) = ? AND YEAR(log_date) = ?"
        );
        $st->execute([$locationId, $selectedMonth, $selectedYear]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $responses[$row['item_id']][$row['day']] = $row['response_value'];
        }

        // Operation-team validations for the month (cell borders).
        try {
            $vst = $db->prepare(
                "SELECT item_id, DAY(log_date) AS day, status
                 FROM chk_validations
                 WHERE location_id = ? AND MONTH(log_date) = ? AND YEAR(log_date) = ?"
            );
            $vst->execute([$locationId, $selectedMonth, $selectedYear]);
            foreach ($vst->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $valByItemDay[(int)$row['item_id']][(int)$row['day']] = $row['status'];
            }
        } catch (Exception $e) { /* table not migrated yet — no borders */ }

        // Store opening/closing times from first IN / last OUT, 4AM shift cutoff
        $monthStart = sprintf('%04d-%02d-01', $selectedYear, $selectedMonth);
        $monthEnd   = date('Y-m-t', strtotime($monthStart));
        $hours      = getStoreHoursData($locationId, $monthStart, $monthEnd);
        $days       = $hours[$locationId]['days'] ?? [];
        foreach ($days as $shiftDate => $info) {
            $d = (int)date('j', strtotime($shiftDate));
            if (!empty($info['first_in'])) $openByDay[$d]  = date('H:i', strtotime($info['first_in']));
            if (!empty($info['last_out'])) $closeByDay[$d] = date('H:i', strtotime($info['last_out']));
        }

        // Banking/Cash Deposit — Yes when a validated, non-invalidated transaction exists for the day
        $bs = $db->prepare(
            "SELECT DISTINCT DAY(txn_date) AS day
             FROM transactions
             WHERE location_id = ?
               AND MONTH(txn_date) = ?
               AND YEAR(txn_date) = ?
               AND validated_at IS NOT NULL
               AND invalidated_at IS NULL"
        );
        $bs->execute([$locationId, $selectedMonth, $selectedYear]);
        foreach ($bs->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $bankingByDay[(int)$row['day']] = 'Yes';
        }
    }

    $locationName = '';
    foreach ($locations as $loc) {
        if ($loc['location_id'] == $locationId) { $locationName = $loc['location_name']; break; }
    }
?>
<div class="page-header"><h2>📈 Monthly Checklist Report</h2></div>

<!-- Filters -->
<div class="form-card" style="margin-bottom:14px;max-width:none">
    <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="page" value="checklist_report">
        <div class="form-group" style="flex:1 1 260px;min-width:260px">
            <label>Location</label>
            <input type="hidden" name="location_id" id="crLocId" value="<?= (int)$locationId ?>" required>
            <?php
                $crLocLabel = '';
                foreach ($locations as $loc) {
                    if ($loc['location_id'] == $locationId) { $crLocLabel = $loc['location_name']; break; }
                }
            ?>
            <span class="input-clear-wrap" style="display:flex;width:100%">
                <input type="text" id="crLocSearch" class="form-control"
                       placeholder="Type to search location"
                       value="<?= h($crLocLabel) ?>" autocomplete="off" required>
                <button type="button" id="crLocClear" class="input-clear-btn" data-no-auto aria-label="Clear" tabindex="-1">&times;</button>
                <div id="crLocList" style="position:absolute;top:100%;left:0;right:0;background:var(--surface);border:1px solid var(--border);border-radius:6px;margin-top:2px;max-height:280px;overflow-y:auto;display:none;z-index:100;box-shadow:0 6px 18px rgba(0,0,0,.35)"></div>
            </span>
        </div>
        <div class="form-group">
            <label>Month</label>
            <select name="month" class="form-control" style="width:130px">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $selectedMonth == $m ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Year</label>
            <select name="year" class="form-control" style="width:90px">
                <?php for ($y = 2025; $y <= 2028; $y++): ?>
                <option value="<?= $y ?>" <?= $selectedYear == $y ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">View Report</button>
        <?php if ($locationId > 0): ?>
        <a href="?page=export_checklist_report&location_id=<?= $locationId ?>&month=<?= $selectedMonth ?>&year=<?= $selectedYear ?>"
           class="btn btn-ghost" target="_blank">📥 Export CSV</a>
        <?php endif; ?>
    </form>
</div>

<?php if ($locationId > 0): ?>
<div class="report-header-box">
    <strong><?= h($locationName) ?></strong> — <?= date('F Y', mktime(0,0,0,$selectedMonth,1,$selectedYear)) ?>
</div>
<div class="table-wrap" style="overflow-x:auto" id="reportWrap">
    <table class="rpt-table table">
        <thead>
            <tr>
                <th class="rpt-sr">#</th>
                <th class="rpt-name" style="min-width:280px">PARTICULAR</th>
                <?php for ($d = 1; $d <= $daysInMonth; $d++):
                    $dayStr  = sprintf('%02d', $d);
                    $logDate = sprintf('%04d-%02d-%02d', $selectedYear, $selectedMonth, $d);
                ?>
                <th style="min-width:36px;text-align:center">
                    <a href="?page=checklist_audit&view=1&log_date=<?= $logDate ?>&location_id=<?= (int)$locationId ?>"
                       style="color:inherit;text-decoration:none"
                       title="Open audit for <?= $logDate ?>"><?= $dayStr ?></a>
                </th>
                <?php endfor; ?>
            </tr>
        </thead>
        <tbody>
        <?php
        // Store opening time — first synthetic row
        ?>
        <tr>
            <td class="rpt-sr">★</td>
            <td style="text-align:left;font-weight:600">Store opening time</td>
            <?php for ($d = 1; $d <= $daysInMonth; $d++): $v = $openByDay[$d] ?? ''; ?>
                <td style="text-align:center;font-size:11px;font-family:Consolas,monospace;<?= $v ? 'background:rgba(39,174,96,.15);color:var(--green);font-weight:700' : '' ?>"><?= h($v) ?></td>
            <?php endfor; ?>
        </tr>
        <?php
        $currentSection = null; $sr = 1;
        foreach ($questions as $q):
            $hasData = isset($responses[$q['id']]) && count($responses[$q['id']]) > 0;
            if (!$q['is_active'] && !$hasData) continue;
            if ($q['section_name'] !== $currentSection):
                $currentSection = $q['section_name'];
        ?>
            <tr><td colspan="<?= $daysInMonth + 2 ?>" style="background:var(--border);font-weight:700;font-size:11px;padding:7px 10px;text-transform:uppercase">
                <?= h($currentSection ?: 'General') ?>
            </td></tr>
            <?php if ($currentSection === '2.Afternoon'): ?>
            <tr>
                <td class="rpt-sr">★</td>
                <td style="text-align:left;font-weight:600">Complete Banking/Cash Deposit</td>
                <?php for ($d = 1; $d <= $daysInMonth; $d++): $v = $bankingByDay[$d] ?? ''; ?>
                    <td style="text-align:center;font-size:11px;<?= $v ? 'background:rgba(39,174,96,.2);color:var(--green);font-weight:700' : '' ?>"><?= h($v) ?></td>
                <?php endfor; ?>
            </tr>
            <?php endif; ?>
        <?php endif; ?>
            <tr class="<?= !$q['is_active'] ? 'row-inactive' : '' ?>">
                <td class="rpt-sr"><?= $sr++ ?></td>
                <td style="text-align:left;white-space:normal;word-break:break-word">
                    <?= h($q['task_description']) ?>
                    <?= !$q['is_active'] ? '<span class="text-muted"> (Retired)</span>' : '' ?>
                </td>
                <?php for ($d = 1; $d <= $daysInMonth; $d++):
                    $val = $responses[$q['id']][$d] ?? '';
                    $style = 'text-align:center;font-size:11px';
                    if (mb_strtolower($val) === 'yes')     $style .= ';background:rgba(39,174,96,.2);color:var(--green);font-weight:700';
                    elseif (mb_strtolower($val) === 'no')  $style .= ';background:rgba(220,64,64,.2);color:var(--red);font-weight:700';
                    // Operation validation → inset square outline (Done=green, Not done=red).
                    // box-shadow inset draws inside the cell so adjacent borders never
                    // collapse/overlap the way table-cell `border` does.
                    $vstat = $valByItemDay[(int)$q['id']][$d] ?? '';
                    $vtitle = '';
                    if ($vstat === 'done')         { $style .= ';box-shadow:inset 0 0 0 2px var(--green)'; $vtitle = 'Validated: Done'; }
                    elseif ($vstat === 'not_done') { $style .= ';box-shadow:inset 0 0 0 2px var(--red)';   $vtitle = 'Validated: Not done'; }
                ?>
                    <td style="<?= $style ?>"<?= $vtitle ? ' title="' . h($vtitle) . '"' : '' ?>><?= h($val) ?></td>
                <?php endfor; ?>
            </tr>
        <?php endforeach; ?>
        <?php
        // Store closing time — last synthetic row
        ?>
        <tr>
            <td class="rpt-sr">★</td>
            <td style="text-align:left;font-weight:600">Store closing time</td>
            <?php for ($d = 1; $d <= $daysInMonth; $d++): $v = $closeByDay[$d] ?? ''; ?>
                <td style="text-align:center;font-size:11px;font-family:Consolas,monospace;<?= $v ? 'background:rgba(26,143,227,.15);color:var(--blue);font-weight:700' : '' ?>"><?= h($v) ?></td>
            <?php endfor; ?>
        </tr>
        </tbody>
    </table>
</div>
<?php else: ?>
<div class="rpt-prompt">Select a location and click <strong>View Report</strong>.</div>
<?php endif; ?>
<script>
(function () {
    var search = document.getElementById('crLocSearch');
    var hidden = document.getElementById('crLocId');
    var clearBtn = document.getElementById('crLocClear');
    var list = document.getElementById('crLocList');
    if (!search || !hidden || !list) return;
    var locData = <?= json_encode(array_map(fn($l) => ['id' => (int)$l['location_id'], 'name' => $l['location_name']], $locations), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var escHtml = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
    var render = function (q) {
        q = (q || '').trim().toLowerCase();
        var matches = q === '' ? locData : locData.filter(function (l) { return l.name.toLowerCase().indexOf(q) !== -1; });
        if (matches.length === 0) {
            list.innerHTML = '<div style="padding:10px 12px;color:var(--muted);font-size:13px">No locations match</div>';
        } else {
            list.innerHTML = matches.slice(0, 200).map(function (l) {
                return '<div class="cr-loc-opt" data-id="' + l.id + '" data-name="' + escHtml(l.name) + '" style="padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid rgba(255,255,255,.04)">' + escHtml(l.name) + '</div>';
            }).join('');
        }
        list.style.display = 'block';
    };
    var hide = function () { list.style.display = 'none'; };
    var wrap = search.closest('.input-clear-wrap');
    var syncVis = function () { if (wrap) wrap.classList.toggle('has-value', !!search.value); };
    var selectLoc = function (id, name) { hidden.value = id; search.value = name; syncVis(); hide(); };
    var clearAll = function () { search.value = ''; hidden.value = ''; syncVis(); };
    syncVis();
    search.addEventListener('focus', function () { render(search.value); });
    search.addEventListener('input', function () { hidden.value = ''; render(search.value); });
    list.addEventListener('mousedown', function (ev) {
        var opt = ev.target.closest('.cr-loc-opt');
        if (!opt) return;
        ev.preventDefault();
        selectLoc(opt.getAttribute('data-id'), opt.getAttribute('data-name'));
    });
    list.addEventListener('mouseover', function (ev) {
        var opt = ev.target.closest('.cr-loc-opt');
        if (!opt) return;
        Array.prototype.forEach.call(list.querySelectorAll('.cr-loc-opt'), function (o) { o.style.background = ''; });
        opt.style.background = 'rgba(26,143,227,.12)';
    });
    document.addEventListener('mousedown', function (ev) {
        if (ev.target !== search && !list.contains(ev.target) && ev.target !== clearBtn) hide();
    });
    // Click on the input when not focused clears it so the full list re-opens
    search.addEventListener('mousedown', function () {
        if (document.activeElement !== search && search.value !== '') clearAll();
    });
    if (clearBtn) clearBtn.addEventListener('click', function () { clearAll(); search.focus(); render(''); });
})();
</script>
<?php }

// ── Audit Report ──────────────────────────────────────────
function pageChecklistAudit(): void {
    $db = getDb();

    // ── Filters (same multi-select pattern as Checklist Overview; day-only) ──
    $viewClicked = !empty($_GET['view']);
    $filterDate  = (string)($_GET['log_date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) $filterDate = date('Y-m-d');

    $sectionOptions = ['1.Morning', '2.Afternoon', '3.Evening'];
    $statusOptions  = ['complete' => 'Complete', 'partial' => 'Partial', 'pending' => 'Pending'];

    $sections = array_values(array_intersect((array)($_GET['section'] ?? []), $sectionOptions));
    $statuses = array_values(array_intersect((array)($_GET['status']  ?? []), array_keys($statusOptions)));
    if (!$sections) $sections = $sectionOptions;
    if (!$statuses) $statuses = array_keys($statusOptions);
    $sectionFilterActive = count($sections) < count($sectionOptions);
    $statusFilterActive  = count($statuses) < count($statusOptions);

    // Deep-link from Checklist Overview tiles: pre-expand a specific location.
    $preExpandLoc = (int)($_GET['location_id'] ?? 0);

    // Location filter — multi-select. Uses a distinct param name (loc_ids)
    // so the scalar deep-link `location_id` keeps its pre-expand role.
    // When the user arrives via a deep-link (scalar set, no array yet),
    // narrow the filter to that one location so the tree isn't cluttered
    // with everything else; subsequent form submits override via loc_ids[].
    $allLocations  = getActiveLocations();
    $locSubmitted  = isset($_GET['loc_ids']) ? (array)$_GET['loc_ids'] : null;
    if ($locSubmitted === null && $preExpandLoc > 0) {
        $selectedLocs = [$preExpandLoc];
    } else {
        $selectedLocs = resolveReportLocationFilter($allLocations, $locSubmitted);
    }
    $locFilterActive = count($selectedLocs) < count($allLocations);

    $locations = []; $itemsBySection = []; $responsesByLoc = []; $locTree = []; $valByLoc = [];
    if ($viewClicked) {
        $selSet    = array_flip($selectedLocs);
        $locations = array_values(array_filter(
            $allLocations,
            fn($l) => isset($selSet[(int)$l['location_id']])
        ));

        // Active items, restricted to selected sections.
        $itemSql = "SELECT id, task_description, section_name FROM chk_items WHERE is_active = 1";
        $itemParams = [];
        if ($sectionFilterActive) {
            $ph = implode(',', array_fill(0, count($sections), '?'));
            $itemSql .= " AND section_name IN ($ph)";
            $itemParams = $sections;
        }
        $itemSql .= " ORDER BY section_name, id ASC";
        $itemSt = $db->prepare($itemSql);
        $itemSt->execute($itemParams);
        $allItems = $itemSt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($sections as $s) $itemsBySection[$s] = [];
        foreach ($allItems as $it) {
            $itemsBySection[$it['section_name']][] = $it;
        }

        // Responses for the chosen day, indexed by [locId][itemId].
        $respSt = $db->prepare(
            "SELECT a.location_id, a.item_id, a.response_value, a.submitted_at,
                    e.full_name AS staff_member, e.employee_code AS staff_code
             FROM chk_daily_responses a
             LEFT JOIN employees e ON a.employee_code = e.employee_code
             WHERE a.log_date = ?"
        );
        $respSt->execute([$filterDate]);
        foreach ($respSt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $responsesByLoc[(int)$row['location_id']][(int)$row['item_id']] = $row;
        }

        // Operation-team validations for the chosen day, indexed by [locId][itemId].
        try {
            $valSt = $db->prepare(
                "SELECT cv.location_id, cv.item_id, cv.status, cv.remarks, cv.validated_at,
                        e.full_name AS val_name
                 FROM chk_validations cv
                 LEFT JOIN employees e ON cv.validated_by = e.employee_code
                 WHERE cv.log_date = ?"
            );
            $valSt->execute([$filterDate]);
            foreach ($valSt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $valByLoc[(int)$row['location_id']][(int)$row['item_id']] = $row;
            }
        } catch (Exception $e) { /* table not migrated yet */ }

        // Build the per-location tree (apply status filter at location level).
        foreach ($locations as $loc) {
            $locId = (int)$loc['location_id'];
            $resps = $responsesByLoc[$locId] ?? [];

            $secStats = [];
            $locTotal = 0; $locDone = 0;
            foreach ($sections as $sec) {
                $items = $itemsBySection[$sec] ?? [];
                $total = count($items);
                $done  = 0;
                foreach ($items as $it) {
                    if (isset($resps[(int)$it['id']])) $done++;
                }
                $locTotal += $total;
                $locDone  += $done;
                if    ($total > 0 && $done >= $total) { $st = 'complete'; }
                elseif ($done > 0)                     { $st = 'partial';  }
                else                                   { $st = 'pending';  }
                $secStats[$sec] = ['total' => $total, 'done' => $done, 'status' => $st];
            }

            if    ($locTotal > 0 && $locDone >= $locTotal) { $locStatus = 'complete'; }
            elseif ($locDone > 0)                          { $locStatus = 'partial';  }
            else                                           { $locStatus = 'pending';  }

            if ($statusFilterActive && !in_array($locStatus, $statuses, true)) continue;

            $locTree[] = [
                'loc'      => $loc,
                'total'    => $locTotal,
                'done'     => $locDone,
                'status'   => $locStatus,
                'sections' => $secStats,
            ];
        }
    }
?>
<div class="page-header"><h2>🔍 Checklist Audit Report</h2></div>

<div class="form-card" style="margin-bottom:14px;max-width:none">
    <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="page" value="checklist_audit">
        <input type="hidden" name="view" value="1">
        <div class="form-group">
            <label>Date</label>
            <input type="date" name="log_date" class="form-control" style="width:160px"
                   value="<?= h($filterDate) ?>" max="<?= h(date('Y-m-d')) ?>">
        </div>
        <div class="form-group">
            <label>Location</label>
            <div class="ms-filter" data-label="Location" style="width:220px">
                <button type="button" class="form-control ms-toggle" aria-haspopup="listbox" aria-expanded="false">Location</button>
                <div class="ms-panel" role="listbox">
                    <label class="ms-row ms-all-row"><input type="checkbox" class="ms-all"> <span>Select all</span></label>
                    <div class="ms-divider"></div>
                    <?php foreach ($allLocations as $l): ?>
                    <label class="ms-row">
                        <input type="checkbox" name="loc_ids[]" value="<?= (int)$l['location_id'] ?>"
                               <?= in_array((int)$l['location_id'], $selectedLocs, true) ? 'checked' : '' ?>>
                        <span><?= h($l['location_name']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="form-group">
            <label>Section</label>
            <div class="ms-filter" data-label="Section" style="width:170px">
                <button type="button" class="form-control ms-toggle" aria-haspopup="listbox" aria-expanded="false">Section</button>
                <div class="ms-panel" role="listbox">
                    <label class="ms-row ms-all-row"><input type="checkbox" class="ms-all"> <span>Select all</span></label>
                    <div class="ms-divider"></div>
                    <?php foreach ($sectionOptions as $s): ?>
                    <label class="ms-row">
                        <input type="checkbox" name="section[]" value="<?= h($s) ?>"
                               <?= in_array($s, $sections, true) ? 'checked' : '' ?>>
                        <span><?= h($s) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="form-group">
            <label>Status</label>
            <div class="ms-filter" data-label="Status" style="width:170px">
                <button type="button" class="form-control ms-toggle" aria-haspopup="listbox" aria-expanded="false">Status</button>
                <div class="ms-panel" role="listbox">
                    <label class="ms-row ms-all-row"><input type="checkbox" class="ms-all"> <span>Select all</span></label>
                    <div class="ms-divider"></div>
                    <?php foreach ($statusOptions as $sv => $sl): ?>
                    <label class="ms-row">
                        <input type="checkbox" name="status[]" value="<?= h($sv) ?>"
                               <?= in_array($sv, $statuses, true) ? 'checked' : '' ?>>
                        <span><?= h($sl) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">View</button>
        <?php if ($viewClicked): ?>
        <a href="?page=checklist_audit" class="btn btn-ghost">Reset</a>
        <?php endif; ?>
    </form>
</div>

<style>
.ms-filter{position:relative;display:inline-block;vertical-align:top}
.ms-toggle{display:flex;align-items:center;justify-content:space-between;gap:6px;width:100%;cursor:pointer;text-align:left;padding-right:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ms-toggle::after{content:'\25BE';opacity:.55;font-size:11px;flex:0 0 auto;margin-left:6px}
.ms-filter.open .ms-toggle::after{transform:rotate(180deg)}
.ms-panel{display:none;position:absolute;top:calc(100% + 4px);left:0;min-width:100%;max-height:280px;overflow-y:auto;background:var(--surface,#1f1f23);color:var(--text,#eee);border:1px solid var(--border,#444);border-radius:6px;box-shadow:0 8px 24px rgba(0,0,0,.4);z-index:50;padding:6px 0;white-space:nowrap}
.ms-filter.open .ms-panel{display:block}
.ms-row{display:flex;align-items:center;gap:8px;padding:6px 12px;cursor:pointer;font-size:13px;font-weight:400;margin:0}
.ms-row:hover{background:rgba(255,255,255,.06)}
.ms-row input{margin:0;cursor:pointer}
.ms-row span{flex:1;min-width:0}
.ms-all-row{font-weight:600}
.ms-divider{height:1px;background:var(--border,#444);margin:4px 0}

/* Tree styles — mirrors audit_templates look. */
.chk-tree{border:1px solid var(--border);border-radius:10px;overflow:hidden;background:var(--surface);color:var(--text)}
.chk-tree-head{display:grid;grid-template-columns:1fr 120px 220px;gap:6px;background:rgba(255,255,255,.04);padding:10px 14px;font-size:11.5px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);border-bottom:1px solid var(--border)}
.chk-tree-head .num{text-align:right}
.chk-tree-cat{border-bottom:1px solid var(--border)}
.chk-tree-cat:last-child{border-bottom:none}
.chk-tree-row{display:grid;grid-template-columns:1fr 120px 220px;gap:6px;align-items:center;padding:9px 14px;color:var(--text)}
.chk-tree-row .name{display:flex;align-items:center;gap:8px;min-width:0;color:var(--text)}
.chk-tree-row .name .lbl{overflow:hidden;text-overflow:ellipsis;color:var(--text)}
.chk-tree-row .num{text-align:right;font-variant-numeric:tabular-nums;font-size:13px;color:var(--text)}
.chk-tree-row .caret{appearance:none;border:none;background:transparent;cursor:pointer;font-size:12px;color:var(--muted);width:18px;padding:0;line-height:1}
.chk-tree-row .caret[aria-expanded="false"]{transform:rotate(-90deg)}
.chk-tree-row .folder{font-size:14px}
.chk-tree-row .diamond{font-size:11px;color:var(--yellow)}
.chk-tree-children.collapsed{display:none}
.loc-row{background:rgba(255,255,255,.03);font-weight:600}
.sec-row{background:rgba(255,255,255,.015);font-weight:500;font-size:13px}
.item-row{font-size:12.5px}
.item-row.missing .name .lbl{color:var(--muted)}
.item-row.empty{color:var(--muted);font-style:italic}
.status-pill{display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
.status-pill.complete{background:rgba(39,174,96,.20);color:var(--green)}
.status-pill.partial{background:rgba(201,168,0,.22);color:var(--yellow)}
.status-pill.pending{background:rgba(220,64,64,.20);color:var(--red)}
@media (max-width: 720px){
    .chk-tree-head,.chk-tree-row{grid-template-columns:1fr 90px 140px;gap:4px;padding:8px 10px}
}
</style>

<script>
(function(){
    document.querySelectorAll('.ms-filter').forEach(initMs);
    document.addEventListener('click', function(e){
        document.querySelectorAll('.ms-filter.open').forEach(function(f){
            if (!f.contains(e.target)) {
                f.classList.remove('open');
                var t = f.querySelector('.ms-toggle');
                if (t) t.setAttribute('aria-expanded', 'false');
            }
        });
    });
    function initMs(f) {
        var label  = f.dataset.label || 'Filter';
        var toggle = f.querySelector('.ms-toggle');
        var allBox = f.querySelector('.ms-all');
        var boxes  = Array.prototype.slice.call(f.querySelectorAll('input[type="checkbox"]:not(.ms-all)'));
        if (!toggle || !boxes.length) return;
        function refreshLabel() {
            var checked = boxes.filter(function(b){ return b.checked; });
            var n = checked.length, total = boxes.length;
            var text;
            if      (n === 0)     text = label + ': None';
            else if (n === total) text = label + ': All';
            else if (n === 1)     text = label + ': ' + (checked[0].parentNode.querySelector('span') || {}).textContent;
            else                  text = label + ': ' + n + ' selected';
            toggle.textContent = text;
        }
        function refreshAll() {
            if (!allBox) return;
            allBox.checked = boxes.every(function(b){ return b.checked; });
            allBox.indeterminate = !allBox.checked && boxes.some(function(b){ return b.checked; });
        }
        toggle.addEventListener('click', function(e){
            e.stopPropagation();
            var willOpen = !f.classList.contains('open');
            document.querySelectorAll('.ms-filter.open').forEach(function(o){
                if (o !== f) {
                    o.classList.remove('open');
                    var ot = o.querySelector('.ms-toggle');
                    if (ot) ot.setAttribute('aria-expanded', 'false');
                }
            });
            f.classList.toggle('open', willOpen);
            toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });
        if (allBox) {
            allBox.addEventListener('change', function(){
                boxes.forEach(function(b){ b.checked = allBox.checked; });
                refreshLabel();
            });
        }
        boxes.forEach(function(b){
            b.addEventListener('change', function(){ refreshAll(); refreshLabel(); });
        });
        refreshAll();
        refreshLabel();
    }
    window.chkTreeToggle = function(btn){
        var row = btn.closest('.chk-tree-cat');
        if (!row) return;
        var kids = row.querySelector('.chk-tree-children');
        if (!kids) return;
        var open = btn.getAttribute('aria-expanded') !== 'false';
        btn.setAttribute('aria-expanded', open ? 'false' : 'true');
        kids.classList.toggle('collapsed', open);
    };
})();
</script>

<?php if (!$viewClicked): ?>
<div class="rpt-prompt">Pick filters and click <strong>View</strong> to load the audit tree.</div>
<?php else: ?>
<div class="text-muted" style="margin-bottom:8px">
    Tree for <strong><?= h(date('D, d M Y', strtotime($filterDate))) ?></strong> — <?= count($locTree) ?> location<?= count($locTree) === 1 ? '' : 's' ?> shown.
</div>

<?php if (!$locTree): ?>
<div class="rpt-prompt">No locations match the current filters for <strong><?= h(date('d M Y', strtotime($filterDate))) ?></strong>.</div>
<?php else: ?>
<div class="chk-tree">
    <div class="chk-tree-head">
        <div>Location / Section / Task</div>
        <div class="num">Status</div>
        <div class="num">Filled</div>
    </div>
    <?php foreach ($locTree as $node):
        $loc   = $node['loc'];
        $locId = (int)$loc['location_id'];
        $isOpen = ($preExpandLoc > 0 && $preExpandLoc === $locId);
    ?>
    <div class="chk-tree-cat">
        <div class="chk-tree-row loc-row" data-loc-id="<?= $locId ?>">
            <div class="name">
                <button type="button" class="caret" onclick="chkTreeToggle(this)" aria-expanded="<?= $isOpen ? 'true' : 'false' ?>">&#9662;</button>
                <span class="folder" aria-hidden="true">📁</span>
                <span class="lbl"><?= h($loc['location_name']) ?></span>
            </div>
            <div class="num"><span class="status-pill <?= h($node['status']) ?>"><?= h(ucfirst($node['status'])) ?></span></div>
            <div class="num"><?= (int)$node['done'] ?>/<?= (int)$node['total'] ?></div>
        </div>
        <div class="chk-tree-children<?= $isOpen ? '' : ' collapsed' ?>">
            <?php foreach ($sections as $sec):
                $stat = $node['sections'][$sec];
                $secItems = $itemsBySection[$sec] ?? [];
                $resps    = $responsesByLoc[$locId] ?? [];
            ?>
            <div class="chk-tree-row sec-row">
                <div class="name" style="padding-left:46px">
                    <span class="folder" aria-hidden="true">📂</span>
                    <span class="lbl"><?= h($sec) ?></span>
                </div>
                <div class="num"><span class="status-pill <?= h($stat['status']) ?>"><?= h(ucfirst($stat['status'])) ?></span></div>
                <div class="num"><?= (int)$stat['done'] ?>/<?= (int)$stat['total'] ?></div>
            </div>
            <?php if (!$secItems): ?>
                <div class="chk-tree-row item-row empty">
                    <div class="name" style="padding-left:74px"><em>No active items in this section.</em></div>
                    <div class="num">—</div>
                    <div class="num">—</div>
                </div>
            <?php else: foreach ($secItems as $it):
                $r = $resps[(int)$it['id']] ?? null;
                $isNo = $r && mb_strtolower($r['response_value']) === 'no';
            ?>
                <div class="chk-tree-row item-row<?= $r ? '' : ' missing' ?>">
                    <div class="name" style="padding-left:74px">
                        <span class="diamond" aria-hidden="true">◆</span>
                        <span class="lbl"><?= h($it['task_description']) ?></span>
                    </div>
                    <div class="num">
                        <?php if ($r): ?>
                            <span style="font-weight:700;color:<?= $isNo ? 'var(--red)' : 'var(--green)' ?>"><?= h($r['response_value']) ?></span>
                        <?php else: ?>
                            <span style="color:var(--muted)">—</span>
                        <?php endif; ?>
                    </div>
                    <div class="num" style="color:var(--muted)">
                        <?php if ($r): ?>
                            <?= h($r['staff_member'] ?? $r['staff_code'] ?? '—') ?> · <?= h(date('H:i', strtotime($r['submitted_at']))) ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                        <?php $cv = $valByLoc[$locId][(int)$it['id']] ?? null; if ($cv): $cvDone = $cv['status'] === 'done'; ?>
                            <div style="font-size:11px;margin-top:3px;color:<?= $cvDone ? 'var(--green)' : 'var(--red)' ?>">
                                <?= $cvDone ? '&#10003; Done' : '&#10007; Not done' ?> · <?= h($cv['val_name'] ?? '—') ?> · <?= h(date('H:i', strtotime($cv['validated_at']))) ?>
                                <?php if (!empty($cv['remarks'])): ?><br><span style="color:var(--muted)">&ldquo;<?= h($cv['remarks']) ?>&rdquo;</span><?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>
<?php }

// ===========================================================
// PAGE: Checklist Overview — every location in one grid
// ===========================================================
// Same colour rules as the per-location calendar in pageChecklist():
//   green  = all active items answered for that day
//   yellow = some answered (partial)
//   red    = none answered AND the day is today / in the past (overdue)
//   grey   = future day (not yet eligible)
// One row per active location, columns are days 1..N of the chosen
// month. Gated by txn_checklist_report (same as the existing per-
// location report). No data loads until the user clicks View — keeps
// the page snappy when the operator just lands on it.
function pageChecklistOverview(): void {
    if (!isSuperadmin() && !hasTxn('checklist_report')) {
        echo '<p>Access denied.</p>'; return;
    }

    $db = getDb();
    $viewClicked   = !empty($_GET['view']);
    $selectedMonth = max(1, min(12, (int)($_GET['month'] ?? date('m'))));
    $selectedYear  = max(2024, min(2099, (int)($_GET['year']  ?? date('Y'))));

    // View mode — "month" (locations × days grid) or "day" (single-day list).
    $mode = ($_GET['mode'] ?? 'month') === 'day' ? 'day' : 'month';
    // Day picker — used in day mode only. Defaults to today; must be ISO.
    $dayParam = (string)($_GET['day'] ?? date('Y-m-d'));
    $selectedDay = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayParam) ? $dayParam : date('Y-m-d');

    // Section + Status filters — multi-select checkboxes, default-all.
    // First load (no view yet): all options pre-checked. On submit: keep what
    // the user checked, intersected with the allowlist. If they uncheck
    // everything, fall back to "all" so the grid never goes blank by accident.
    $sectionOptions = ['1.Morning', '2.Afternoon', '3.Evening'];
    $statusOptions  = ['complete' => 'Complete', 'partial' => 'Partial', 'pending' => 'Pending'];
    if ($viewClicked) {
        $sections = array_values(array_intersect((array)($_GET['section'] ?? []), $sectionOptions));
        $statuses = array_values(array_intersect((array)($_GET['status']  ?? []), array_keys($statusOptions)));
        if (!$sections) $sections = $sectionOptions;
        if (!$statuses) $statuses = array_keys($statusOptions);
    } else {
        $sections = $sectionOptions;
        $statuses = array_keys($statusOptions);
    }
    $sectionFilterActive = count($sections) < count($sectionOptions);
    $statusFilterActive  = count($statuses) < count($statusOptions);

    $daysInMonth = (int)cal_days_in_month(CAL_GREGORIAN, $selectedMonth, $selectedYear);
    $monthStart  = sprintf('%04d-%02d-01', $selectedYear, $selectedMonth);
    $monthEnd    = date('Y-m-t', strtotime($monthStart));
    $today       = date('Y-m-d');

    // Location filter — multi-select. Always populate the dropdown source
    // so the form renders even before the user clicks View.
    $allLocations  = getActiveLocations();
    $locSubmitted  = isset($_GET['location_id']) ? (array)$_GET['location_id'] : null;
    $selectedLocs  = resolveReportLocationFilter($allLocations, $locSubmitted);
    $locFilterActive = count($selectedLocs) < count($allLocations);

    $locations = []; $totalQ = 0; $cell = []; // month mode: cell[loc_id][day]
    $dayCell  = [];                            // day   mode: dayCell[loc_id] = done
    if ($viewClicked) {
        $selSet = array_flip($selectedLocs);
        $locations = array_values(array_filter(
            $allLocations,
            fn($l) => isset($selSet[(int)$l['location_id']])
        ));

        if ($sectionFilterActive) {
            $ph = implode(',', array_fill(0, count($sections), '?'));
            $tq = $db->prepare("SELECT COUNT(*) FROM chk_items WHERE is_active = 1 AND section_name IN ($ph)");
            $tq->execute($sections);
            $totalQ = (int)($tq->fetchColumn() ?: 0);
        } else {
            $totalQ = (int)($db->query("SELECT COUNT(*) FROM chk_items WHERE is_active = 1")->fetchColumn() ?: 0);
        }

        if ($mode === 'day') {
            // Day mode — one aggregate row per location for the chosen date.
            if ($sectionFilterActive) {
                $ph = implode(',', array_fill(0, count($sections), '?'));
                $st = $db->prepare(
                    "SELECT r.location_id, COUNT(*) AS done
                     FROM chk_daily_responses r
                     JOIN chk_items i ON i.id = r.item_id
                     WHERE r.log_date = ? AND i.section_name IN ($ph)
                     GROUP BY r.location_id"
                );
                $st->execute(array_merge([$selectedDay], $sections));
            } else {
                $st = $db->prepare(
                    "SELECT location_id, COUNT(*) AS done
                     FROM chk_daily_responses
                     WHERE log_date = ?
                     GROUP BY location_id"
                );
                $st->execute([$selectedDay]);
            }
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $dayCell[(int)$r['location_id']] = (int)$r['done'];
            }
        } else {
            // Month mode — items answered per (location, day) in chosen month.
            if ($sectionFilterActive) {
                $ph = implode(',', array_fill(0, count($sections), '?'));
                $st = $db->prepare(
                    "SELECT r.location_id, DAY(r.log_date) AS day, COUNT(*) AS done
                     FROM chk_daily_responses r
                     JOIN chk_items i ON i.id = r.item_id
                     WHERE r.log_date BETWEEN ? AND ? AND i.section_name IN ($ph)
                     GROUP BY r.location_id, DAY(r.log_date)"
                );
                $st->execute(array_merge([$monthStart, $monthEnd], $sections));
            } else {
                $st = $db->prepare(
                    "SELECT location_id, DAY(log_date) AS day, COUNT(*) AS done
                     FROM chk_daily_responses
                     WHERE log_date BETWEEN ? AND ?
                     GROUP BY location_id, DAY(log_date)"
                );
                $st->execute([$monthStart, $monthEnd]);
            }
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cell[(int)$r['location_id']][(int)$r['day']] = (int)$r['done'];
            }
        }
    }
?>
<div class="page-header"><h2>📅 Checklist Overview <span style="font-size:13px;color:var(--muted);font-weight:400">— all locations</span></h2></div>

<div class="form-card" style="margin-bottom:14px;max-width:none">
    <form method="GET" id="chkOvForm" class="chk-ov-mode-<?= h($mode) ?>" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="page" value="checklist_overview">
        <input type="hidden" name="view" value="1">
        <div class="form-group">
            <label>View</label>
            <select name="mode" id="chkOvMode" class="form-control" style="width:110px">
                <option value="month" <?= $mode === 'month' ? 'selected' : '' ?>>Month</option>
                <option value="day"   <?= $mode === 'day'   ? 'selected' : '' ?>>Day</option>
            </select>
        </div>
        <div class="form-group chk-ov-month-only">
            <label>Month</label>
            <select name="month" class="form-control" style="width:140px">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $selectedMonth === $m ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="form-group chk-ov-month-only">
            <label>Year</label>
            <select name="year" class="form-control" style="width:100px">
                <?php for ($y = 2025; $y <= 2028; $y++): ?>
                <option value="<?= $y ?>" <?= $selectedYear === $y ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="form-group chk-ov-day-only">
            <label>Date</label>
            <input type="date" name="day" class="form-control" style="width:160px" value="<?= h($selectedDay) ?>" max="<?= h(date('Y-m-d')) ?>">
        </div>
        <div class="form-group">
            <label>Location</label>
            <div class="ms-filter" data-label="Location" style="width:220px">
                <button type="button" class="form-control ms-toggle" aria-haspopup="listbox" aria-expanded="false">Location</button>
                <div class="ms-panel" role="listbox">
                    <label class="ms-row ms-all-row"><input type="checkbox" class="ms-all"> <span>Select all</span></label>
                    <div class="ms-divider"></div>
                    <?php foreach ($allLocations as $l): ?>
                    <label class="ms-row">
                        <input type="checkbox" name="location_id[]" value="<?= (int)$l['location_id'] ?>"
                               <?= in_array((int)$l['location_id'], $selectedLocs, true) ? 'checked' : '' ?>>
                        <span><?= h($l['location_name']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="form-group">
            <label>Section</label>
            <div class="ms-filter" data-label="Section" style="width:170px">
                <button type="button" class="form-control ms-toggle" aria-haspopup="listbox" aria-expanded="false">Section</button>
                <div class="ms-panel" role="listbox">
                    <label class="ms-row ms-all-row"><input type="checkbox" class="ms-all"> <span>Select all</span></label>
                    <div class="ms-divider"></div>
                    <?php foreach ($sectionOptions as $s): ?>
                    <label class="ms-row">
                        <input type="checkbox" name="section[]" value="<?= h($s) ?>"
                               <?= in_array($s, $sections, true) ? 'checked' : '' ?>>
                        <span><?= h($s) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="form-group">
            <label>Status</label>
            <div class="ms-filter" data-label="Status" style="width:170px">
                <button type="button" class="form-control ms-toggle" aria-haspopup="listbox" aria-expanded="false">Status</button>
                <div class="ms-panel" role="listbox">
                    <label class="ms-row ms-all-row"><input type="checkbox" class="ms-all"> <span>Select all</span></label>
                    <div class="ms-divider"></div>
                    <?php foreach ($statusOptions as $sv => $sl): ?>
                    <label class="ms-row">
                        <input type="checkbox" name="status[]" value="<?= h($sv) ?>"
                               <?= in_array($sv, $statuses, true) ? 'checked' : '' ?>>
                        <span><?= h($sl) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">View</button>
        <?php if ($viewClicked): ?>
        <a href="?page=checklist_overview" class="btn btn-ghost">Reset</a>
        <?php endif; ?>
    </form>
</div>

<style>
.ms-filter{position:relative;display:inline-block;vertical-align:top}
.ms-toggle{display:flex;align-items:center;justify-content:space-between;gap:6px;width:100%;cursor:pointer;text-align:left;padding-right:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ms-toggle::after{content:'\25BE';opacity:.55;font-size:11px;flex:0 0 auto;margin-left:6px}
.ms-filter.open .ms-toggle::after{transform:rotate(180deg)}
.ms-panel{display:none;position:absolute;top:calc(100% + 4px);left:0;min-width:100%;max-height:280px;overflow-y:auto;background:var(--surface,#1f1f23);color:var(--text,#eee);border:1px solid var(--border,#444);border-radius:6px;box-shadow:0 8px 24px rgba(0,0,0,.4);z-index:50;padding:6px 0;white-space:nowrap}
.ms-filter.open .ms-panel{display:block}
.ms-row{display:flex;align-items:center;gap:8px;padding:6px 12px;cursor:pointer;font-size:13px;font-weight:400;margin:0}
.ms-row:hover{background:rgba(255,255,255,.06)}
.ms-row input{margin:0;cursor:pointer}
.ms-row span{flex:1;min-width:0}
.ms-all-row{font-weight:600}
.ms-divider{height:1px;background:var(--border,#444);margin:4px 0}
.chk-ov-mode-day  .chk-ov-month-only { display:none; }
.chk-ov-mode-month .chk-ov-day-only  { display:none; }
</style>
<script>
(function(){
    var form = document.getElementById('chkOvForm');
    var modeSel = document.getElementById('chkOvMode');
    function applyMode(){
        if (!form || !modeSel) return;
        form.classList.toggle('chk-ov-mode-day',   modeSel.value === 'day');
        form.classList.toggle('chk-ov-mode-month', modeSel.value !== 'day');
    }
    if (modeSel) modeSel.addEventListener('change', applyMode);
    applyMode();
    document.querySelectorAll('.ms-filter').forEach(initMs);
    document.addEventListener('click', function(e){
        document.querySelectorAll('.ms-filter.open').forEach(function(f){
            if (!f.contains(e.target)) {
                f.classList.remove('open');
                var t = f.querySelector('.ms-toggle');
                if (t) t.setAttribute('aria-expanded', 'false');
            }
        });
    });
    function initMs(f) {
        var label  = f.dataset.label || 'Filter';
        var toggle = f.querySelector('.ms-toggle');
        var allBox = f.querySelector('.ms-all');
        var boxes  = Array.prototype.slice.call(f.querySelectorAll('input[type="checkbox"]:not(.ms-all)'));
        if (!toggle || !boxes.length) return;
        function refreshLabel() {
            var checked = boxes.filter(function(b){ return b.checked; });
            var n = checked.length, total = boxes.length;
            var text;
            if      (n === 0)     text = label + ': None';
            else if (n === total) text = label + ': All';
            else if (n === 1)     text = label + ': ' + (checked[0].parentNode.querySelector('span') || {}).textContent;
            else                  text = label + ': ' + n + ' selected';
            toggle.textContent = text;
        }
        function refreshAll() {
            if (!allBox) return;
            allBox.checked = boxes.every(function(b){ return b.checked; });
            allBox.indeterminate = !allBox.checked && boxes.some(function(b){ return b.checked; });
        }
        toggle.addEventListener('click', function(e){
            e.stopPropagation();
            var willOpen = !f.classList.contains('open');
            document.querySelectorAll('.ms-filter.open').forEach(function(o){
                if (o !== f) {
                    o.classList.remove('open');
                    var ot = o.querySelector('.ms-toggle');
                    if (ot) ot.setAttribute('aria-expanded', 'false');
                }
            });
            f.classList.toggle('open', willOpen);
            toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });
        if (allBox) {
            allBox.addEventListener('change', function(){
                boxes.forEach(function(b){ b.checked = allBox.checked; });
                refreshLabel();
            });
        }
        boxes.forEach(function(b){
            b.addEventListener('change', function(){
                refreshAll();
                refreshLabel();
            });
        });
        refreshAll();
        refreshLabel();
    }
})();
</script>

<?php if (isSuperadmin()): ?>
<div class="form-card" style="max-width:none;margin-bottom:14px;border-left:3px solid var(--red)">
    <div class="form-section-title" style="color:var(--red)">⚠️ Delete Checklist Data for a Month</div>
    <form method="POST" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap"
          onsubmit="return confirm('Delete ALL checklist responses for the selected month across every location? This cannot be undone.');">
        <input type="hidden" name="action" value="delete_checklist_month">
        <div class="form-group">
            <label>Month</label>
            <select name="month" class="form-control" style="width:140px" required>
                <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $selectedMonth === $m ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Year</label>
            <select name="year" class="form-control" style="width:100px" required>
                <?php for ($y = 2025; $y <= 2028; $y++): ?>
                <option value="<?= $y ?>" <?= $selectedYear === $y ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-danger-solid"
                title="Delete all checklist responses for the selected month">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="3 6 5 6 21 6"/>
                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                <path d="M10 11v6"/>
                <path d="M14 11v6"/>
                <path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/>
            </svg>
            Delete Month Data
        </button>
    </form>
</div>
<?php endif; ?>

<?php if (!$viewClicked): ?>
<div class="rpt-prompt">Pick a month and click <strong>View</strong> to load the overview.</div>
<?php elseif (!$locations): ?>
<div class="rpt-prompt">No active locations.</div>
<?php else: ?>
<style>
/* Compact day-tile table — one row per location, columns are day numbers. */
.chk-ov-wrap   { overflow-x:auto; border:1px solid var(--border); border-radius:8px; background:var(--surface); }
.chk-ov        { border-collapse:collapse; font-size:12px; min-width:100%; }
.chk-ov th, .chk-ov td { padding:0; }
.chk-ov thead th { background:var(--surface); position:sticky; top:0; z-index:2; padding:8px 6px; border-bottom:1px solid var(--border); font-weight:600; color:var(--muted); text-align:center; }
.chk-ov thead th.chk-loc-head { text-align:left; padding-left:14px; min-width:240px; position:sticky; left:0; z-index:3; background:var(--surface); }
.chk-ov tbody td.chk-loc-name { padding:8px 14px; border-bottom:1px solid var(--border); font-weight:500; color:var(--text); position:sticky; left:0; background:var(--surface); z-index:1; min-width:240px; white-space:nowrap; }
.chk-ov tbody td.chk-tile     { padding:4px 3px; border-bottom:1px solid var(--border); text-align:center; vertical-align:middle; }
.chk-ov-tile { display:block; min-width:34px; padding:5px 3px; border-radius:5px; color:#fff; font-weight:700; font-size:11px; line-height:1.2; text-decoration:none; }
.chk-ov-tile span { font-weight:400; font-size:10px; opacity:.85 }
.chk-ov-complete { background:var(--green); }
.chk-ov-partial  { background:var(--yellow); color:#1a1612; }
.chk-ov-pending  { background:var(--red); }
.chk-ov-future   { background:#4b5563; opacity:.55; cursor:not-allowed; color:#cbd5e1; }
.chk-ov-blank    { background:transparent; min-height:30px; }
.chk-ov-legend   { display:flex; gap:14px; margin-top:10px; font-size:11px; color:var(--muted); align-items:center; flex-wrap:wrap; }
.chk-ov-legend i { display:inline-block; width:10px; height:10px; border-radius:2px; margin-right:5px; vertical-align:middle; }
</style>

<div class="report-header-box">
    <?php if ($mode === 'day'): ?>
        <strong><?= h(date('D, d M Y', strtotime($selectedDay))) ?></strong>
    <?php else: ?>
        <strong><?= h(date('F Y', mktime(0,0,0,$selectedMonth,1,$selectedYear))) ?></strong>
    <?php endif; ?>
    — <?= count($locations) ?> location<?= count($locations) === 1 ? '' : 's' ?>,
    <?= (int)$totalQ ?> active checklist item<?= $totalQ === 1 ? '' : 's' ?><?php
        if ($sectionFilterActive) echo ' in <strong>' . h(implode(', ', $sections)) . '</strong>';
    ?>.
</div>

<?php if ($mode === 'day'):
    // Day mode — one row per location with data, status-filtered. Future days
    // never have data so the "have data" rule collapses them out naturally.
    $dayRows = [];
    foreach ($locations as $loc) {
        $locId = (int)$loc['location_id'];
        $done  = $dayCell[$locId] ?? 0;
        if ($done <= 0) continue; // only rows with data
        if    ($totalQ > 0 && $done >= $totalQ) { $cls = 'chk-ov-complete'; $tileStatus = 'complete'; }
        elseif ($done > 0)                       { $cls = 'chk-ov-partial';  $tileStatus = 'partial';  }
        else                                     { continue; }
        if ($statusFilterActive && !in_array($tileStatus, $statuses, true)) continue;
        $dayRows[] = ['loc' => $loc, 'done' => $done, 'cls' => $cls];
    }
?>
<?php if (!$dayRows): ?>
<div class="rpt-prompt">No locations have data for <?= h(date('d M Y', strtotime($selectedDay))) ?> matching the current filters.</div>
<?php else: ?>
<div class="chk-ov-wrap">
<table class="chk-ov">
    <thead>
        <tr>
            <th class="chk-loc-head">Location</th>
            <th style="text-align:left;padding:8px 14px">Status</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($dayRows as $row):
        $loc   = $row['loc'];
        $locId = (int)$loc['location_id'];
        $done  = (int)$row['done'];
        $cls   = $row['cls'];
        $title = h($loc['location_name']) . ' — ' . h(date('d M Y', strtotime($selectedDay)))
               . ' — ' . $done . '/' . $totalQ;
    ?>
        <tr>
            <td class="chk-loc-name"><?= h($loc['location_name']) ?></td>
            <td style="padding:6px 14px;border-bottom:1px solid var(--border)">
                <a class="chk-ov-tile <?= $cls ?>" style="min-width:80px;display:inline-block;padding:6px 12px;text-align:center"
                   href="?page=checklist_audit&view=1&log_date=<?= h($selectedDay) ?>&location_id=<?= $locId ?>"
                   title="<?= $title ?>">
                    <?= $done ?>/<?= (int)$totalQ ?>
                </a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<div class="chk-ov-legend">
    <span><i style="background:var(--green)"></i> Complete</span>
    <span><i style="background:var(--yellow)"></i> Partial</span>
    <span style="margin-left:auto">Click a status tile to open that day's audit for that location.</span>
</div>
<?php endif; ?>

<?php else: ?>
<div class="chk-ov-wrap">
<table class="chk-ov">
    <thead>
        <tr>
            <th class="chk-loc-head">Location</th>
            <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                <th><?= $d ?></th>
            <?php endfor; ?>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($locations as $loc):
        $locId = (int)$loc['location_id'];
    ?>
        <tr>
            <td class="chk-loc-name"><?= h($loc['location_name']) ?></td>
            <?php for ($d = 1; $d <= $daysInMonth; $d++):
                $tileDate = sprintf('%04d-%02d-%02d', $selectedYear, $selectedMonth, $d);
                $isFuture = ($tileDate > $today);
                $done     = $cell[$locId][$d] ?? 0;
                if ($isFuture)                     { $cls = 'chk-ov-future'; $tileStatus = 'future'; }
                elseif ($totalQ > 0 && $done >= $totalQ) { $cls = 'chk-ov-complete'; $tileStatus = 'complete'; }
                elseif ($done > 0)                 { $cls = 'chk-ov-partial';  $tileStatus = 'partial';  }
                else                                { $cls = 'chk-ov-pending';  $tileStatus = 'pending';  }
                $title = h($loc['location_name']) . ' — ' . h(date('d M Y', strtotime($tileDate)))
                       . ' — ' . $done . '/' . $totalQ;
                $hideTile = ($statusFilterActive && !in_array($tileStatus, $statuses, true));
            ?>
                <td class="chk-tile">
                    <?php if ($hideTile): ?>
                        <span class="chk-ov-tile chk-ov-blank">&nbsp;</span>
                    <?php elseif ($isFuture): ?>
                        <span class="chk-ov-tile chk-ov-future" title="<?= $title ?>">
                            <?= $d ?><br><span>—</span>
                        </span>
                    <?php else: ?>
                        <a class="chk-ov-tile <?= $cls ?>"
                           href="?page=checklist_audit&view=1&log_date=<?= h($tileDate) ?>&location_id=<?= $locId ?>"
                           title="<?= $title ?>">
                            <?= $d ?><br><span><?= $done ?>/<?= (int)$totalQ ?></span>
                        </a>
                    <?php endif; ?>
                </td>
            <?php endfor; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<div class="chk-ov-legend">
    <span><i style="background:var(--green)"></i> Complete</span>
    <span><i style="background:var(--yellow)"></i> Partial</span>
    <span><i style="background:var(--red)"></i> Pending</span>
    <span><i style="background:#4b5563"></i> Future / not yet eligible</span>
    <span style="margin-left:auto">Click any past tile to open that day's audit for that location.</span>
</div>
<?php endif; ?>
<?php endif; ?>
<?php }

// ── Bulk delete checklist responses for a month (superadmin only) ─
function doDeleteChecklistMonth(): void {
    if (!isSuperadmin()) {
        flash('error', 'Access denied.');
        header('Location: index.php?page=checklist_overview'); exit;
    }
    $month = (int)($_POST['month'] ?? 0);
    $year  = (int)($_POST['year']  ?? 0);
    if ($month < 1 || $month > 12 || $year < 2024 || $year > 2099) {
        flash('error', 'Invalid month or year.');
        header('Location: index.php?page=checklist_overview'); exit;
    }
    $monthStart = sprintf('%04d-%02d-01', $year, $month);
    $monthEnd   = date('Y-m-t', strtotime($monthStart));
    $monthName  = date('F Y', strtotime($monthStart));

    $db = getDb();
    try {
        $count = (int)$db->query(
            "SELECT COUNT(*) FROM chk_daily_responses
             WHERE log_date BETWEEN " . $db->quote($monthStart) . " AND " . $db->quote($monthEnd)
        )->fetchColumn();
        if ($count === 0) {
            flash('error', 'No checklist responses found for ' . $monthName . '.');
            header('Location: index.php?page=checklist_overview&view=1&month=' . $month . '&year=' . $year); exit;
        }
        $del = $db->prepare(
            "DELETE FROM chk_daily_responses WHERE log_date BETWEEN ? AND ?"
        );
        $del->execute([$monthStart, $monthEnd]);
        flash('success', 'Deleted ' . $count . ' checklist response' . ($count === 1 ? '' : 's') . ' for ' . $monthName . '.');
    } catch (Exception $e) {
        flash('error', 'Delete failed: ' . $e->getMessage());
    }
    header('Location: index.php?page=checklist_overview&view=1&month=' . $month . '&year=' . $year); exit;
}

// ===========================================================
// PAGE: Validate Checklist — operation-team sign-off
// ===========================================================
// Operation team picks any location + any past date, sees each task with
// the store manager's response, and marks it Done / Not done with a remark.
// Stored in chk_validations (one row per location/item/day). Gated by
// txn_checklist_validate. Surfaced on the Monthly Report (cell borders)
// and the Checklist Audit (validator name/time/remark).
function pageChecklistValidate(): void {
    if (!isSuperadmin() && !hasTxn('checklist_validate')) { echo '<p>Access denied.</p>'; return; }
    $db = getDb();

    $locationId = (int)($_GET['location_id'] ?? 0);
    $logDate    = (string)($_GET['log_date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $logDate)) $logDate = date('Y-m-d');
    $today      = date('Y-m-d');
    $locations  = getActiveLocations();

    $viewClicked = $locationId > 0;
    $locationName = '';
    $items = []; $responses = []; $vals = [];
    if ($viewClicked) {
        foreach ($locations as $loc) {
            if ((int)$loc['location_id'] === $locationId) { $locationName = $loc['location_name']; break; }
        }
        $items = $db->query("SELECT id, task_description, section_name FROM chk_items WHERE is_active = 1 ORDER BY section_name, id ASC")->fetchAll(PDO::FETCH_ASSOC);

        $rs = $db->prepare("SELECT item_id, response_value FROM chk_daily_responses WHERE location_id = ? AND log_date = ?");
        $rs->execute([$locationId, $logDate]);
        foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) $responses[(int)$r['item_id']] = $r['response_value'];

        $vs = $db->prepare("SELECT item_id, status, remarks FROM chk_validations WHERE location_id = ? AND log_date = ?");
        $vs->execute([$locationId, $logDate]);
        foreach ($vs->fetchAll(PDO::FETCH_ASSOC) as $v) $vals[(int)$v['item_id']] = $v;
    }
?>
<div class="page-header"><h2>✔️ Validate Checklist</h2></div>

<div class="form-card" style="margin-bottom:14px;max-width:none">
    <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="page" value="checklist_validate">
        <div class="form-group" style="flex:1 1 260px;min-width:260px">
            <label>Location</label>
            <input type="hidden" name="location_id" id="cvLocId" value="<?= (int)$locationId ?>" required>
            <span class="input-clear-wrap" style="display:flex;width:100%">
                <input type="text" id="cvLocSearch" class="form-control"
                       placeholder="Type to search location"
                       value="<?= h($locationName) ?>" autocomplete="off" required>
                <button type="button" id="cvLocClear" class="input-clear-btn" data-no-auto aria-label="Clear" tabindex="-1">&times;</button>
                <div id="cvLocList" style="position:absolute;top:100%;left:0;right:0;background:var(--surface);border:1px solid var(--border);border-radius:6px;margin-top:2px;max-height:280px;overflow-y:auto;display:none;z-index:100;box-shadow:0 6px 18px rgba(0,0,0,.35)"></div>
            </span>
        </div>
        <div class="form-group">
            <label>Date</label>
            <input type="date" name="log_date" class="form-control" style="width:160px"
                   value="<?= h($logDate) ?>" max="<?= h($today) ?>">
        </div>
        <button type="submit" class="btn btn-primary">Load</button>
    </form>
</div>

<?php if (!$viewClicked): ?>
<div class="rpt-prompt">Pick a location and date, then click <strong>Load</strong> to validate that day's tasks.</div>
<?php elseif (!$items): ?>
<div class="rpt-prompt">No active checklist tasks found.</div>
<?php else: ?>
<div class="report-header-box">
    <strong><?= h($locationName ?: ('Location #' . $locationId)) ?></strong> — <?= date('D, d M Y', strtotime($logDate)) ?>
</div>
<form method="POST">
    <input type="hidden" name="action" value="save_checklist_validation">
    <input type="hidden" name="location_id" value="<?= (int)$locationId ?>">
    <input type="hidden" name="log_date" value="<?= h($logDate) ?>">
    <div class="table-wrap">
        <table class="table">
            <thead><tr>
                <th style="width:48px;text-align:center">#</th>
                <th>Task</th>
                <th style="width:110px">Response</th>
                <th style="width:150px">Validation</th>
                <th style="width:300px">Remarks</th>
            </tr></thead>
            <tbody>
            <?php $section = null; $sr = 1; foreach ($items as $it):
                if (($it['section_name'] ?? '') !== $section): $section = (string)($it['section_name'] ?? ''); ?>
                <tr><td colspan="5" style="background:var(--border);font-weight:700;font-size:12px;padding:8px 13px"><?= h($section ?: 'General') ?></td></tr>
            <?php endif;
                $iid     = (int)$it['id'];
                $resp    = $responses[$iid] ?? '';
                $v       = $vals[$iid] ?? null;
                $vstatus = $v['status']  ?? '';
                $vrem    = $v['remarks'] ?? '';
            ?>
                <tr>
                    <td style="text-align:center;color:var(--muted);font-size:12px"><?= $sr++ ?></td>
                    <td style="white-space:normal;word-break:break-word"><?= h($it['task_description']) ?></td>
                    <td>
                        <?php if ($resp !== ''): ?>
                        <span class="badge <?= mb_strtolower($resp) === 'no' ? 'badge-red' : 'badge-green' ?>"><?= h($resp) ?></span>
                        <?php else: ?>
                        <span class="text-muted" style="font-size:12px">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <select name="status[<?= $iid ?>]" class="form-control" style="width:140px">
                            <option value="">— Not validated —</option>
                            <option value="done"     <?= $vstatus === 'done' ? 'selected' : '' ?>>Done</option>
                            <option value="not_done" <?= $vstatus === 'not_done' ? 'selected' : '' ?>>Not done</option>
                        </select>
                    </td>
                    <td><input type="text" name="remark[<?= $iid ?>]" class="form-control" value="<?= h($vrem) ?>" maxlength="500" placeholder="Optional remark"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div style="margin-top:12px"><button type="submit" class="btn btn-primary">Save validation</button></div>
</form>
<?php endif; ?>

<script>
(function () {
    var search = document.getElementById('cvLocSearch');
    var hidden = document.getElementById('cvLocId');
    var clearBtn = document.getElementById('cvLocClear');
    var list = document.getElementById('cvLocList');
    if (!search || !hidden || !list) return;
    var locData = <?= json_encode(array_map(fn($l) => ['id' => (int)$l['location_id'], 'name' => $l['location_name']], $locations), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var escHtml = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
    var render = function (q) {
        q = (q || '').trim().toLowerCase();
        var matches = q === '' ? locData : locData.filter(function (l) { return l.name.toLowerCase().indexOf(q) !== -1; });
        if (matches.length === 0) {
            list.innerHTML = '<div style="padding:10px 12px;color:var(--muted);font-size:13px">No locations match</div>';
        } else {
            list.innerHTML = matches.slice(0, 200).map(function (l) {
                return '<div class="cv-loc-opt" data-id="' + l.id + '" data-name="' + escHtml(l.name) + '" style="padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid rgba(255,255,255,.04)">' + escHtml(l.name) + '</div>';
            }).join('');
        }
        list.style.display = 'block';
    };
    var hide = function () { list.style.display = 'none'; };
    var wrap = search.closest('.input-clear-wrap');
    var syncVis = function () { if (wrap) wrap.classList.toggle('has-value', !!search.value); };
    var selectLoc = function (id, name) { hidden.value = id; search.value = name; syncVis(); hide(); };
    var clearAll = function () { search.value = ''; hidden.value = ''; syncVis(); };
    syncVis();
    search.addEventListener('focus', function () { render(search.value); });
    search.addEventListener('input', function () { hidden.value = ''; render(search.value); });
    list.addEventListener('mousedown', function (ev) {
        var opt = ev.target.closest('.cv-loc-opt');
        if (!opt) return;
        ev.preventDefault();
        selectLoc(opt.getAttribute('data-id'), opt.getAttribute('data-name'));
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
<?php }

// POST: save (upsert) the operation team's validations for one location/day.
function doSaveChecklistValidation(): void {
    if (!isSuperadmin() && !hasTxn('checklist_validate')) {
        flash('error', 'Access denied.');
        header('Location: index.php?page=checklist_validate'); exit;
    }
    $db         = getDb();
    $locationId = (int)($_POST['location_id'] ?? 0);
    $logDate    = (string)($_POST['log_date'] ?? '');
    if ($locationId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $logDate)) {
        flash('error', 'Invalid location or date.');
        header('Location: index.php?page=checklist_validate'); exit;
    }
    $statuses = (array)($_POST['status'] ?? []);
    $remarks  = (array)($_POST['remark'] ?? []);
    $code     = myCode();

    $up = $db->prepare(
        "INSERT INTO chk_validations (location_id, item_id, log_date, status, remarks, validated_by)
         VALUES (?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE status=VALUES(status), remarks=VALUES(remarks),
                                 validated_by=VALUES(validated_by), validated_at=NOW()"
    );
    $del = $db->prepare("DELETE FROM chk_validations WHERE location_id=? AND item_id=? AND log_date=?");

    $saved = 0;
    try {
        foreach ($statuses as $itemId => $st) {
            $itemId = (int)$itemId;
            if ($itemId <= 0) continue;
            $st  = (string)$st;
            $rem = trim((string)($remarks[$itemId] ?? ''));
            if ($st === 'done' || $st === 'not_done') {
                $up->execute([$locationId, $itemId, $logDate, $st, ($rem !== '' ? mb_substr($rem, 0, 500) : null), $code]);
                $saved++;
            } else {
                $del->execute([$locationId, $itemId, $logDate]);
            }
        }
        flash('success', "Validation saved — {$saved} task(s) marked.");
    } catch (Exception $e) {
        flash('error', $e->getMessage());
    }
    header('Location: index.php?page=checklist_validate&location_id=' . $locationId . '&log_date=' . urlencode($logDate));
    exit;
}
