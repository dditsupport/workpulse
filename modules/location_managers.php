<?php
// =========================================================
// Location manager mapping — one store manager and one operation
// manager per location. Maintained by the Operation Review role
// (txn_audit_operation); audit_new auto-fills the Store Manager from
// this map. The operation manager is recorded here for reference and
// reporting; nothing auto-fills from it yet.
// =========================================================

function locMgrCanManage(): bool {
    return isSuperadmin() || hasTxn('audit_operation');
}

// operation_manager_code arrives with 2026-08-15_location_operation_manager.sql.
// Until that runs the column is hidden everywhere instead of being offered
// and then rejected on INSERT, so PHP may deploy before the SQL.
function locMgrHasOpsColumn(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $cached = (bool)getDb()->query("SHOW COLUMNS FROM location_managers LIKE 'operation_manager_code'")->fetch();
    } catch (Exception $e) {
        $cached = false;
    }
    return $cached;
}

// [location_id => store_manager_code] — used by audit_new for defaults.
function getLocationManagerMap(): array {
    try {
        $rows = getDb()->query('SELECT location_id, store_manager_code FROM location_managers')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
    $map = [];
    foreach ($rows as $r) {
        $code = trim((string)$r['store_manager_code']);
        if ($code !== '') $map[(int)$r['location_id']] = $code;
    }
    return $map;
}

// [location_id => operation_manager_code] — empty until the migration runs.
function getLocationOperationManagerMap(): array {
    if (!locMgrHasOpsColumn()) return [];
    try {
        $rows = getDb()->query('SELECT location_id, operation_manager_code FROM location_managers')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
    $map = [];
    foreach ($rows as $r) {
        $code = trim((string)($r['operation_manager_code'] ?? ''));
        if ($code !== '') $map[(int)$r['location_id']] = $code;
    }
    return $map;
}

// Current mappings: location_id => ['sm'=>code,'sm_name'=>, 'om'=>code,'om_name'=>].
// Names fall back to the code so a mapping to a since-deleted employee
// still shows something the user can act on.
function locMgrMappings(): array {
    $hasOps = locMgrHasOpsColumn();
    $sql = 'SELECT lm.location_id, lm.store_manager_code, sm.full_name AS sm_name'
         . ($hasOps ? ', lm.operation_manager_code, om.full_name AS om_name' : '')
         . ' FROM location_managers lm
            LEFT JOIN employees sm ON sm.employee_code = lm.store_manager_code'
         . ($hasOps ? ' LEFT JOIN employees om ON om.employee_code = lm.operation_manager_code' : '');
    try {
        $rows = getDb()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return []; // table not migrated yet
    }
    $mapped = [];
    foreach ($rows as $r) {
        $sm = trim((string)$r['store_manager_code']);
        $om = trim((string)($r['operation_manager_code'] ?? ''));
        $mapped[(int)$r['location_id']] = [
            'sm'      => $sm,
            'sm_name' => $sm === '' ? '' : ((string)($r['sm_name'] ?? '') ?: $sm),
            'om'      => $om,
            'om_name' => $om === '' ? '' : ((string)($r['om_name'] ?? '') ?: $om),
        ];
    }
    return $mapped;
}

// ── POST: upsert one mapping ─────────────────────────────
function doSaveLocationManager(): void {
    if (!locMgrCanManage()) { flash('error', 'Access denied.'); header('Location: index.php'); exit; }
    $db     = getDb();
    $hasOps = locMgrHasOpsColumn();
    $loc    = (int)($_POST['location_id'] ?? 0);
    $code   = trim($_POST['store_manager_code'] ?? '');
    $opCode = $hasOps ? trim($_POST['operation_manager_code'] ?? '') : '';
    $back   = 'index.php?page=location_managers';

    if ($loc <= 0 || ($code === '' && $opCode === '')) {
        flash('error', $hasOps ? 'Select a location and at least one manager.' : 'Select a location and a store manager.');
        header("Location: $back"); exit;
    }
    $locOk = $db->prepare('SELECT 1 FROM locations WHERE location_id = ? LIMIT 1');
    $locOk->execute([$loc]);
    if (!$locOk->fetchColumn()) { flash('error', 'Location not found.'); header("Location: $back"); exit; }

    $empOk = $db->prepare('SELECT 1 FROM employees WHERE employee_code = ? AND is_active = 1 LIMIT 1');
    if ($code !== '') {
        $empOk->execute([$code]);
        if (!$empOk->fetchColumn()) { flash('error', 'Store manager must be an active employee.'); header("Location: $back"); exit; }
    }
    if ($opCode !== '') {
        $empOk->execute([$opCode]);
        if (!$empOk->fetchColumn()) { flash('error', 'Operation manager must be an active employee.'); header("Location: $back"); exit; }
    }

    try {
        if ($hasOps) {
            $db->prepare(
                'INSERT INTO location_managers (location_id, store_manager_code, operation_manager_code, updated_by)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE store_manager_code = VALUES(store_manager_code),
                                         operation_manager_code = VALUES(operation_manager_code),
                                         updated_by = VALUES(updated_by)'
            )->execute([$loc, $code, ($opCode === '' ? null : $opCode), myCode()]);
        } else {
            $db->prepare(
                'INSERT INTO location_managers (location_id, store_manager_code, updated_by)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE store_manager_code = VALUES(store_manager_code), updated_by = VALUES(updated_by)'
            )->execute([$loc, $code, myCode()]);
        }
        flash('success', 'Mapping saved.');
    } catch (Exception $e) {
        flash('error', $e->getMessage());
    }
    header("Location: $back"); exit;
}

// ── POST: remove a mapping ───────────────────────────────
function doDeleteLocationManager(): void {
    if (!locMgrCanManage()) { flash('error', 'Access denied.'); header('Location: index.php'); exit; }
    $loc = (int)($_POST['location_id'] ?? 0);
    try {
        getDb()->prepare('DELETE FROM location_managers WHERE location_id = ?')->execute([$loc]);
        flash('success', 'Mapping removed.');
    } catch (Exception $e) {
        flash('error', $e->getMessage());
    }
    header('Location: index.php?page=location_managers'); exit;
}

// ── CSV export of the mapping table (one row per active location) ─
function exportLocationManagersCsv(): void {
    if (!locMgrCanManage()) { http_response_code(403); echo 'Access denied.'; return; }
    $hasOps = locMgrHasOpsColumn();
    $mapped = locMgrMappings();
    try {
        $locations = getDb()->query('SELECT location_id, location_code, location_name FROM locations WHERE is_active = 1 ORDER BY location_name')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $locations = [];
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="location_managers_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads non-ASCII names
    $header = ['Location Code', 'Location', 'Store Manager', 'Store Manager Code'];
    if ($hasOps) { $header[] = 'Operation Manager'; $header[] = 'Operation Manager Code'; }
    fputcsv($out, $header, escape: '');

    foreach ($locations as $l) {
        $m   = $mapped[(int)$l['location_id']] ?? null;
        $row = [
            (string)($l['location_code'] ?? ''),
            (string)$l['location_name'],
            $m['sm_name'] ?? '',
            $m['sm'] ?? '',
        ];
        if ($hasOps) { $row[] = $m['om_name'] ?? ''; $row[] = $m['om'] ?? ''; }
        fputcsv($out, $row, escape: '');
    }
    fclose($out);
    exit;
}

// ── Page ─────────────────────────────────────────────────
function pageLocationManagers(): void {
    if (!locMgrCanManage()) { echo '<p>Access denied.</p>'; return; }
    $hasOps    = locMgrHasOpsColumn();
    $locations = getActiveLocations();
    $employees = array_values(array_filter(getEmployeesLite(), fn($e) => !empty($e['is_active'])));
    $mapped    = locMgrMappings();

    // location_id => the two pickers' hidden value + visible label, so
    // picking a location refills the form with what is already mapped
    // instead of silently overwriting the half the user did not touch.
    $formMap = [];
    foreach ($mapped as $lid => $m) {
        $formMap[(string)$lid] = [
            'sm'      => $m['sm'],
            'smLabel' => $m['sm'] === '' ? '' : $m['sm_name'] . ' (' . $m['sm'] . ')',
            'om'      => $m['om'],
            'omLabel' => $m['om'] === '' ? '' : $m['om_name'] . ' (' . $m['om'] . ')',
        ];
    }
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <h2 style="margin:0">🏬 Store Manager Mapping</h2>
    <a href="?page=export_location_managers" class="btn btn-secondary btn-sm">Export CSV</a>
</div>
<p class="text-muted" style="font-size:12px;margin-bottom:12px">Map a store manager<?= $hasOps ? ' and an operation manager' : '' ?> to each location. Create Audit auto-fills the store manager from here (still editable).</p>

<div class="form-card" style="margin-bottom:16px;max-width:none">
    <h3 style="font-size:15px;margin-bottom:12px">Set / update mapping</h3>
    <form method="POST" id="lmForm" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="action" value="save_location_manager">
        <div class="form-group" style="margin:0">
            <label>Location</label>
            <select name="location_id" id="lmLoc" class="form-control" style="width:240px" required>
                <option value="">— Select location —</option>
                <?php foreach ($locations as $l): $lid = (int)$l['location_id']; ?>
                <option value="<?= $lid ?>"><?= h($l['location_name']) ?><?= isset($mapped[$lid]) && $mapped[$lid]['sm_name'] !== '' ? ' • ' . h($mapped[$lid]['sm_name']) : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0">
            <label>Store Manager</label>
            <span class="input-clear-wrap" style="display:flex;width:280px">
                <input type="hidden" name="store_manager_code" id="lmCode" value="">
                <input type="text" id="lmSearch" class="form-control" placeholder="Type to search employee" autocomplete="off"<?= $hasOps ? '' : ' required' ?>>
                <button type="button" id="lmClear" class="input-clear-btn" data-no-auto aria-label="Clear" tabindex="-1">&times;</button>
                <div id="lmList" style="position:absolute;top:100%;left:0;right:0;background:var(--surface);border:1px solid var(--border);border-radius:6px;margin-top:2px;max-height:280px;overflow-y:auto;display:none;z-index:100;box-shadow:0 6px 18px rgba(0,0,0,.35)"></div>
            </span>
        </div>
        <?php if ($hasOps): ?>
        <div class="form-group" style="margin:0">
            <label>Operation Manager</label>
            <span class="input-clear-wrap" style="display:flex;width:280px">
                <input type="hidden" name="operation_manager_code" id="lmOmCode" value="">
                <input type="text" id="lmOmSearch" class="form-control" placeholder="Type to search employee" autocomplete="off">
                <button type="button" id="lmOmClear" class="input-clear-btn" data-no-auto aria-label="Clear" tabindex="-1">&times;</button>
                <div id="lmOmList" style="position:absolute;top:100%;left:0;right:0;background:var(--surface);border:1px solid var(--border);border-radius:6px;margin-top:2px;max-height:280px;overflow-y:auto;display:none;z-index:100;box-shadow:0 6px 18px rgba(0,0,0,.35)"></div>
            </span>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary">Save mapping</button>
    </form>
</div>

<div class="table-wrap" data-stack>
    <table class="table" style="font-size:13px">
        <thead><tr>
            <th>Location</th>
            <th style="width:280px">Store Manager</th>
            <?php if ($hasOps): ?><th style="width:280px">Operation Manager</th><?php endif; ?>
            <th style="width:120px"></th>
        </tr></thead>
        <tbody>
        <?php foreach ($locations as $l): $lid = (int)$l['location_id']; $m = $mapped[$lid] ?? null; ?>
            <tr>
                <td><?= h($l['location_name']) ?></td>
                <td><?= ($m && $m['sm'] !== '') ? h($m['sm_name']) . ' <span class="text-muted">(' . h($m['sm']) . ')</span>' : '<span class="text-muted">— not set —</span>' ?></td>
                <?php if ($hasOps): ?>
                <td><?= ($m && $m['om'] !== '') ? h($m['om_name']) . ' <span class="text-muted">(' . h($m['om']) . ')</span>' : '<span class="text-muted">— not set —</span>' ?></td>
                <?php endif; ?>
                <td style="white-space:nowrap">
                    <button type="button" class="btn btn-ghost btn-sm" onclick="lmEdit(<?= $lid ?>)"><?= $m ? 'Edit' : 'Set' ?></button>
                    <?php if ($m): ?>
                    <form method="POST" class="inline-form" style="display:inline" onsubmit="return confirm('Remove this mapping?')">
                        <input type="hidden" name="action" value="delete_location_manager">
                        <input type="hidden" name="location_id" value="<?= $lid ?>">
                        <button type="submit" class="btn btn-sm badge-red" style="cursor:pointer">Clear</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
(function () {
    var data = <?= json_encode(array_map(fn($e) => ['code' => (string)$e['employee_code'], 'name' => (string)$e['full_name']], $employees), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var formMap = <?= json_encode($formMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var locSel = document.getElementById('lmLoc');
    var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };

    // One employee picker: text box + hidden code + filtered dropdown.
    function picker(searchId, hiddenId, clearId, listId, optClass) {
        var search = document.getElementById(searchId);
        var hidden = document.getElementById(hiddenId);
        var clearBtn = document.getElementById(clearId);
        var list = document.getElementById(listId);
        if (!search) return null;

        function render(q) {
            q = (q || '').trim().toLowerCase();
            var m = q === '' ? data : data.filter(function (x) { return x.name.toLowerCase().indexOf(q) !== -1 || x.code.toLowerCase().indexOf(q) !== -1; });
            if (!m.length) { list.innerHTML = '<div style="padding:10px 12px;color:var(--muted);font-size:13px">No matches</div>'; }
            else { list.innerHTML = m.slice(0, 300).map(function (x) {
                return '<div class="' + optClass + '" data-code="' + esc(x.code) + '" data-label="' + esc(x.name + ' (' + x.code + ')') + '" style="padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid rgba(255,255,255,.04)">' + esc(x.name) + ' <span style="color:var(--muted)">(' + esc(x.code) + ')</span></div>';
            }).join(''); }
            list.style.display = 'block';
        }
        function hide() { list.style.display = 'none'; }

        search.addEventListener('focus', function () { render(search.value); });
        search.addEventListener('input', function () { hidden.value = ''; render(search.value); });
        list.addEventListener('mousedown', function (ev) {
            var o = ev.target.closest('.' + optClass); if (!o) return;
            ev.preventDefault();
            hidden.value = o.getAttribute('data-code');
            search.value = o.getAttribute('data-label');
            hide();
        });
        document.addEventListener('mousedown', function (ev) {
            if (ev.target !== search && !list.contains(ev.target) && ev.target !== clearBtn) hide();
        });
        if (clearBtn) clearBtn.addEventListener('click', function () { search.value = ''; hidden.value = ''; search.focus(); render(''); });

        return {
            set: function (code, label) { hidden.value = code || ''; search.value = label || ''; },
            focus: function () { search.focus(); },
            // Typed-but-not-picked: the hidden code is what gets saved, so
            // leaving it empty would silently blank the name on the row.
            dangling: function () { return search.value.trim() !== '' && hidden.value === ''; }
        };
    }

    var sm = picker('lmSearch', 'lmCode', 'lmClear', 'lmList', 'lm-opt');
    var om = picker('lmOmSearch', 'lmOmCode', 'lmOmClear', 'lmOmList', 'lm-om-opt');

    // Both pickers always show the whole row, so saving one name never
    // wipes the other by leaving its box empty.
    function fill(locId) {
        var cur = formMap[String(locId)] || {};
        if (sm) sm.set(cur.sm, cur.smLabel);
        if (om) om.set(cur.om, cur.omLabel);
    }
    if (locSel) locSel.addEventListener('change', function () { fill(locSel.value); });

    var form = document.getElementById('lmForm');
    if (form) form.addEventListener('submit', function (ev) {
        if (sm && sm.dangling()) { ev.preventDefault(); alert('Pick the store manager from the list.'); sm.focus(); return; }
        if (om && om.dangling()) { ev.preventDefault(); alert('Pick the operation manager from the list.'); om.focus(); return; }
        if (sm && !document.getElementById('lmCode').value && (!om || !document.getElementById('lmOmCode').value)) {
            ev.preventDefault(); alert('Select at least one manager.'); sm.focus();
        }
    });

    window.lmEdit = function (locId) {
        if (locSel) locSel.value = String(locId);
        fill(locId);
        if (sm) sm.focus();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };
})();
</script>
<?php }
