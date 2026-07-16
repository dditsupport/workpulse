<?php
// =========================================================
// Store Manager mapping — one store manager per location.
// Maintained by the Operation Review role (txn_audit_operation);
// audit_new auto-fills the Store Manager from this map.
// =========================================================

function locMgrCanManage(): bool {
    return isSuperadmin() || hasTxn('audit_operation');
}

// [location_id => store_manager_code] — used by audit_new for defaults.
function getLocationManagerMap(): array {
    try {
        $rows = getDb()->query('SELECT location_id, store_manager_code FROM location_managers')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
    $map = [];
    foreach ($rows as $r) $map[(int)$r['location_id']] = (string)$r['store_manager_code'];
    return $map;
}

// ── POST: upsert one mapping ─────────────────────────────
function doSaveLocationManager(): void {
    if (!locMgrCanManage()) { flash('error', 'Access denied.'); header('Location: index.php'); exit; }
    $db   = getDb();
    $loc  = (int)($_POST['location_id'] ?? 0);
    $code = trim($_POST['store_manager_code'] ?? '');
    $back = 'index.php?page=location_managers';

    if ($loc <= 0 || $code === '') {
        flash('error', 'Select a location and a store manager.');
        header("Location: $back"); exit;
    }
    $locOk = $db->prepare('SELECT 1 FROM locations WHERE location_id = ? LIMIT 1');
    $locOk->execute([$loc]);
    if (!$locOk->fetchColumn()) { flash('error', 'Location not found.'); header("Location: $back"); exit; }

    $empOk = $db->prepare('SELECT 1 FROM employees WHERE employee_code = ? AND is_active = 1 LIMIT 1');
    $empOk->execute([$code]);
    if (!$empOk->fetchColumn()) { flash('error', 'Store manager must be an active employee.'); header("Location: $back"); exit; }

    try {
        $db->prepare(
            'INSERT INTO location_managers (location_id, store_manager_code, updated_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE store_manager_code = VALUES(store_manager_code), updated_by = VALUES(updated_by)'
        )->execute([$loc, $code, myCode()]);
        flash('success', 'Store manager mapped.');
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

// ── Page ─────────────────────────────────────────────────
function pageLocationManagers(): void {
    if (!locMgrCanManage()) { echo '<p>Access denied.</p>'; return; }
    $db = getDb();
    $locations = getActiveLocations();
    $employees = array_values(array_filter(getEmployeesLite(), fn($e) => !empty($e['is_active'])));

    // Current mappings: location_id => ['code'=>, 'name'=>]
    $mapped = [];
    try {
        $rows = $db->query(
            'SELECT lm.location_id, lm.store_manager_code, e.full_name
             FROM location_managers lm
             LEFT JOIN employees e ON e.employee_code = lm.store_manager_code'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $mapped[(int)$r['location_id']] = ['code' => (string)$r['store_manager_code'], 'name' => (string)($r['full_name'] ?? $r['store_manager_code'])];
        }
    } catch (Exception $e) { /* table not migrated yet */ }
?>
<div class="page-header"><h2>🏬 Store Manager Mapping</h2></div>
<p class="text-muted" style="font-size:12px;margin-bottom:12px">Map one store manager to each location. Create Audit auto-fills the manager from here (still editable).</p>

<div class="form-card" style="margin-bottom:16px;max-width:none">
    <h3 style="font-size:15px;margin-bottom:12px">Set / update mapping</h3>
    <form method="POST" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="action" value="save_location_manager">
        <div class="form-group" style="margin:0">
            <label>Location</label>
            <select name="location_id" id="lmLoc" class="form-control" style="width:240px" required>
                <option value="">— Select location —</option>
                <?php foreach ($locations as $l): $lid = (int)$l['location_id']; ?>
                <option value="<?= $lid ?>"><?= h($l['location_name']) ?><?= isset($mapped[$lid]) ? ' • ' . h($mapped[$lid]['name']) : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0">
            <label>Store Manager</label>
            <span class="input-clear-wrap" style="display:flex;width:280px">
                <input type="hidden" name="store_manager_code" id="lmCode" value="">
                <input type="text" id="lmSearch" class="form-control" placeholder="Type to search employee" autocomplete="off" required>
                <button type="button" id="lmClear" class="input-clear-btn" data-no-auto aria-label="Clear" tabindex="-1">&times;</button>
                <div id="lmList" style="position:absolute;top:100%;left:0;right:0;background:var(--surface);border:1px solid var(--border);border-radius:6px;margin-top:2px;max-height:280px;overflow-y:auto;display:none;z-index:100;box-shadow:0 6px 18px rgba(0,0,0,.35)"></div>
            </span>
        </div>
        <button type="submit" class="btn btn-primary">Save mapping</button>
    </form>
</div>

<div class="table-wrap" data-stack>
    <table class="table" style="font-size:13px">
        <thead><tr><th>Location</th><th style="width:280px">Store Manager</th><th style="width:120px"></th></tr></thead>
        <tbody>
        <?php foreach ($locations as $l): $lid = (int)$l['location_id']; $m = $mapped[$lid] ?? null; ?>
            <tr>
                <td><?= h($l['location_name']) ?></td>
                <td><?= $m ? h($m['name']) . ' <span class="text-muted">(' . h($m['code']) . ')</span>' : '<span class="text-muted">— not set —</span>' ?></td>
                <td style="white-space:nowrap">
                    <?php if ($m): ?>
                    <button type="button" class="btn btn-ghost btn-sm"
                        onclick="lmEdit(<?= $lid ?>, <?= h(json_encode($m['code'])) ?>, <?= h(json_encode($m['name'] . ' (' . $m['code'] . ')')) ?>)">Edit</button>
                    <form method="POST" class="inline-form" style="display:inline" onsubmit="return confirm('Remove this mapping?')">
                        <input type="hidden" name="action" value="delete_location_manager">
                        <input type="hidden" name="location_id" value="<?= $lid ?>">
                        <button type="submit" class="btn btn-sm badge-red" style="cursor:pointer">Clear</button>
                    </form>
                    <?php else: ?>
                    <button type="button" class="btn btn-ghost btn-sm" onclick="lmEdit(<?= $lid ?>, '', '')">Set</button>
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
    var search = document.getElementById('lmSearch');
    var hidden = document.getElementById('lmCode');
    var clearBtn = document.getElementById('lmClear');
    var list = document.getElementById('lmList');
    var locSel = document.getElementById('lmLoc');
    var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
    function render(q) {
        q = (q || '').trim().toLowerCase();
        var m = q === '' ? data : data.filter(function (x) { return x.name.toLowerCase().indexOf(q) !== -1 || x.code.toLowerCase().indexOf(q) !== -1; });
        if (!m.length) { list.innerHTML = '<div style="padding:10px 12px;color:var(--muted);font-size:13px">No matches</div>'; }
        else { list.innerHTML = m.slice(0, 300).map(function (x) {
            return '<div class="lm-opt" data-code="' + esc(x.code) + '" data-label="' + esc(x.name + ' (' + x.code + ')') + '" style="padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid rgba(255,255,255,.04)">' + esc(x.name) + ' <span style="color:var(--muted)">(' + esc(x.code) + ')</span></div>';
        }).join(''); }
        list.style.display = 'block';
    }
    function hide() { list.style.display = 'none'; }
    search.addEventListener('focus', function () { render(search.value); });
    search.addEventListener('input', function () { hidden.value = ''; render(search.value); });
    list.addEventListener('mousedown', function (ev) {
        var o = ev.target.closest('.lm-opt'); if (!o) return;
        ev.preventDefault();
        hidden.value = o.getAttribute('data-code');
        search.value = o.getAttribute('data-label');
        hide();
    });
    document.addEventListener('mousedown', function (ev) {
        if (ev.target !== search && !list.contains(ev.target) && ev.target !== clearBtn) hide();
    });
    if (clearBtn) clearBtn.addEventListener('click', function () { search.value = ''; hidden.value = ''; search.focus(); render(''); });

    window.lmEdit = function (locId, code, label) {
        if (locSel) locSel.value = String(locId);
        hidden.value = code || '';
        search.value = label || '';
        search.focus();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };
})();
</script>
<?php }
