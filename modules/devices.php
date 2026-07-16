<?php
// =========================================================
// Device CRUD + page renderer
// =========================================================

function doSaveDevice(): void {
    $serial = trim($_POST['device_serial'] ?? '');
    if (!$serial) { flash('error', 'Serial required.'); header('Location: index.php?page=devices'); exit; }
    $id = (int)($_POST['id'] ?? 0);
    $vals = [$serial, $_POST['device_type'] ?? 'MFS500', trim($_POST['device_name'] ?? '') ?: null, (int)($_POST['location_id'] ?? 0)];
    try {
        if ($id) {
            getDb()->prepare('UPDATE devices SET device_serial=?,device_type=?,device_name=?,location_id=?,updated_at=NOW() WHERE device_id=?')->execute([...$vals, $id]);
            flash('success', 'Device updated.');
        } else {
            getDb()->prepare('INSERT INTO devices (device_serial,device_type,device_name,location_id) VALUES (?,?,?,?)')->execute($vals);
            flash('success', 'Device registered.');
        }
    } catch (Exception $e) { flash('error', $e->getMessage()); }
    header('Location: index.php?page=devices'); exit;
}

function doToggleDevice(): void {
    $id = (int)($_POST['id'] ?? 0); $cur = (int)($_POST['is_active'] ?? 0);
    try { getDb()->prepare('UPDATE devices SET is_active=?,updated_at=NOW() WHERE device_id=?')->execute([$cur ? 0 : 1, $id]); flash('success', 'Updated.'); }
    catch (Exception $e) { flash('error', $e->getMessage()); }
    header('Location: index.php?page=devices'); exit;
}

// ── Page: Devices ────────────────────────────────────────
function pageDevices(): void {
    $devices = getDevices();
    $locs    = getActiveLocations();
    $editId  = (int)($_GET['edit'] ?? 0);
    $editDev = $editId ? (array_values(array_filter($devices, fn($d) => (int)$d['id'] === $editId))[0] ?? null) : null;

    $tableExists = true;
    try { getDb()->query('SELECT 1 FROM devices LIMIT 1'); }
    catch (Exception $e) { $tableExists = false; }
?>
<div class="page-header"><h2>Devices</h2></div>

<?php if (!$tableExists): ?>
<div class="alert alert-error">
    The <code>devices</code> table does not exist yet. Please run the setup SQL first.
</div>
<?php else: ?>

<div class="form-card" style="margin-bottom:20px">
    <div class="form-section-title"><?= $editDev ? 'Edit Device' : 'Register New Device' ?></div>
    <?php if (empty($locs)): ?>
    <div class="alert alert-error" style="margin:0">
        No active locations found — <a href="?page=add_location" style="color:inherit;font-weight:600;text-decoration:underline">add a location first</a>.
    </div>
    <?php else: ?>
    <form method="POST" class="filter-bar flex-wrap">
        <input type="hidden" name="action" value="save_device">
        <?php if ($editDev): ?><input type="hidden" name="id" value="<?= $editDev['id'] ?>"> <?php endif; ?>
        <input type="text" name="device_serial" class="form-control" placeholder="Serial No." required
               value="<?= h($editDev['device_serial'] ?? '') ?>">
        <input type="text" name="device_name" class="form-control" placeholder="Name (e.g. Main Gate)"
               value="<?= h($editDev['device_name'] ?? '') ?>">
        <select name="device_type" class="form-control w-auto">
            <option value="MFS500" <?= ($editDev['device_type'] ?? '') === 'MFS500' ? 'selected' : '' ?>>MFS500</option>
            <option value="FM220"  <?= ($editDev['device_type'] ?? '') === 'FM220'  ? 'selected' : '' ?>>FM220</option>
        </select>
        <select name="location_id" class="form-control w-auto" required>
            <option value="">Location...</option>
            <?php foreach ($locs as $l): ?>
            <option value="<?= $l['location_id'] ?>" <?= (int)($editDev['location_id'] ?? 0) === (int)$l['location_id'] ? 'selected' : '' ?>>
                <?= h($l['location_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-primary"><?= $editDev ? 'Update' : 'Register' ?></button>
        <?php if ($editDev): ?><a href="?page=devices" class="btn btn-ghost">Cancel</a><?php endif; ?>
    </form>
    <?php endif; ?>
</div>

<div class="table-wrap" data-stack>
<table class="table">
    <thead><tr><th>Serial</th><th>Name</th><th>Type</th><th>Location</th><th>Status</th><th>App Version</th><th>Registered</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if (empty($devices)): ?>
        <tr><td colspan="8" class="empty-row">No devices registered yet.</td></tr>
    <?php else: foreach ($devices as $d):
        $ver       = (string)($d['app_version'] ?? '');
        $verStamp  = !empty($d['version_updated_at']) ? date('d M Y H:i', strtotime((string)$d['version_updated_at'])) : '';
    ?>
        <tr class="<?= !$d['is_active'] ? 'row-inactive' : '' ?>">
            <td><code><?= h($d['device_serial']) ?></code></td>
            <td><?= h($d['device_name'] ?? '—') ?></td>
            <td><span class="badge <?= $d['device_type']==='MFS500'?'badge-blue':'badge-green' ?>"><?= $d['device_type'] ?></span></td>
            <td><?= h($d['location_name'] ?? '—') ?></td>
            <td><?= $d['is_active'] ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-red">Inactive</span>' ?></td>
            <td>
                <?php if ($ver !== ''): ?>
                    <code title="<?= h($verStamp ? 'Reported ' . $verStamp : '') ?>"><?= h($ver) ?></code>
                    <?php if ($verStamp !== ''): ?>
                        <div style="font-size:10px;color:var(--muted);margin-top:2px"><?= h($verStamp) ?></div>
                    <?php endif; ?>
                <?php else: ?>
                    <span style="color:var(--muted)">—</span>
                <?php endif; ?>
            </td>
            <td><?= date('d M Y', strtotime($d['registered_at'])) ?></td>
            <td class="actions">
                <a href="?page=devices&edit=<?= $d['id'] ?>" class="btn btn-sm btn-secondary">Edit</a>
                <form method="POST" class="inline-form">
                    <input type="hidden" name="action"    value="toggle_device">
                    <input type="hidden" name="id"        value="<?= $d['id'] ?>">
                    <input type="hidden" name="is_active" value="<?= $d['is_active'] ?>">
                    <button class="btn btn-sm <?= $d['is_active'] ? 'btn-danger' : 'btn-success' ?>">
                        <?= $d['is_active'] ? 'Deactivate' : 'Activate' ?>
                    </button>
                </form>
            </td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php
}
