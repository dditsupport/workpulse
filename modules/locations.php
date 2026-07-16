<?php
// =========================================================
// Location CRUD + page renderers
// =========================================================

function doSaveLocation(): void {
    $id    = (int)($_POST['id'] ?? 0);
    $newId = (int)($_POST['location_id'] ?? 0);
    $name  = trim($_POST['location_name'] ?? '');

    if (!$name) {
        flash('error', 'Location Name is required.');
        header('Location: index.php?page=' . ($id ? "edit_location&id={$id}" : 'add_location'));
        exit;
    }

    if (!$id && $newId <= 0) {
        flash('error', 'A valid Location ID is required.');
        header('Location: index.php?page=add_location');
        exit;
    }

    $lat = trim($_POST['latitude']  ?? '');
    $lng = trim($_POST['longitude'] ?? '');
    $vals = [
        $name,
        trim($_POST['contact_email'] ?? '') ?: null,
        trim($_POST['contact_phone'] ?? '') ?: null,
        trim($_POST['location_code'] ?? '') ?: null,
        trim($_POST['address'] ?? '') ?: null,
        ($lat !== '' && is_numeric($lat)) ? (float)$lat : null,
        ($lng !== '' && is_numeric($lng)) ? (float)$lng : null,
    ];

    try {
        if ($id) {
            getDb()->prepare('UPDATE locations SET location_name=?, contact_email=?, contact_phone=?,
                             location_code=?, address=?, latitude=?, longitude=?
                             WHERE location_id=?')
                   ->execute([...$vals, $id]);
            flash('success', 'Location updated.');
        } else {
            getDb()->prepare('INSERT INTO locations (location_id, location_name, contact_email, contact_phone,
                             location_code, address, latitude, longitude)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                   ->execute([$newId, ...$vals]);
            flash('success', 'Location created.');
        }
    } catch (Exception $e) {
        flash('error', 'Database Error: ' . $e->getMessage());
    }

    header('Location: index.php?page=locations');
    exit;
}

function doDelLocation(): void {
    $id  = (int)($_POST['id']        ?? 0);
    $cur = (int)($_POST['is_active'] ?? 1);
    $new = $cur ? 0 : 1;
    getDb()->prepare('UPDATE locations SET is_active=? WHERE location_id=?')->execute([$new, $id]);
    flash('success', $new ? 'Location activated.' : 'Location deactivated.');
    header('Location: index.php?page=locations'); exit;
}

// ── Page: Location List ──────────────────────────────────
function pageLocations(): void {
    $locs = getLocations();

    // Devices grouped by location_id
    $devicesByLoc = [];
    try {
        $rows = getDb()->query('SELECT device_id, device_serial, device_name, device_type, location_id, is_active FROM devices ORDER BY is_active DESC, device_serial')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $devicesByLoc[(int)$r['location_id']][] = $r;
        }
    } catch (Exception $e) {}
?>
<div class="page-header">
    <h2>Locations</h2>
    <a href="?page=add_location" class="btn btn-primary">+ Add Location</a>
</div>
<div class="table-wrap" data-stack>
<table class="table">
    <thead>
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Contact Email <small>(OTP)</small></th>
            <th>Contact Phone <small>(OTP)</small></th>
            <th>Devices</th>
            <th>Status</th>
            <th>Actions</th></tr>
    </thead>
    <tbody>
    <?php if (empty($locs)): ?>
        <tr><td colspan="7" class="empty-row">No locations found.</td></tr>
    <?php else: foreach ($locs as $l):
        $devs = $devicesByLoc[(int)$l['location_id']] ?? [];
    ?>
        <tr class="<?= !$l['is_active'] ? 'row-inactive' : '' ?>">
            <td><code><?= $l['location_id'] ?></code></td>
            <td><?= h($l['location_name']) ?></td>
            <td><?= $l['contact_email'] ? h($l['contact_email']) : '<span class="text-muted">Not set</span>' ?></td>
            <td><?= $l['contact_phone'] ? h($l['contact_phone']) : '<span class="text-muted">Not set</span>' ?></td>
            <td>
                <?php if (empty($devs)): ?>
                    <span class="text-muted">None</span>
                <?php else: ?>
                    <div style="display:flex;flex-wrap:wrap;gap:4px">
                    <?php foreach ($devs as $d):
                        $label = $d['device_name'] ? $d['device_name'] . ' (' . $d['device_serial'] . ')' : $d['device_serial'];
                        $cls   = $d['is_active'] ? ($d['device_type']==='MFS500'?'badge-blue':'badge-green') : 'badge-red';
                    ?>
                        <span class="badge <?= $cls ?>" title="<?= h($d['device_type'] . ($d['is_active'] ? '' : ' · Inactive')) ?>"><?= h($label) ?></span>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </td>
            <td><?= $l['is_active'] ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-red">Inactive</span>' ?></td>
            <td class="actions">
                <a href="?page=edit_location&id=<?= $l['location_id'] ?>" class="btn btn-sm btn-secondary">Edit</a>
                <form method="POST" class="inline-form"
                      onsubmit="return confirm('<?= $l['is_active'] ? 'Deactivate' : 'Activate' ?> this location?')">
                    <input type="hidden" name="action"    value="del_location">
                    <input type="hidden" name="id"        value="<?= $l['location_id'] ?>">
                    <input type="hidden" name="is_active" value="<?= $l['is_active'] ?>">
                    <button class="btn btn-sm <?= $l['is_active'] ? 'btn-danger' : 'btn-success' ?>">
                        <?= $l['is_active'] ? 'Deactivate' : 'Activate' ?>
                    </button>
                </form>
            </td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
<?php
}

// ── Page: Location Create/Edit Form ──────────────────────
function pageLocationForm(?array $loc): void {
    if (!isSuperadmin() && !hasTxn('locations')) { pageEmployees(); return; }
    $isEdit = $loc !== null;
?>
<div class="page-header">
    <h2><?= $isEdit ? 'Edit Location' : 'Add Location' ?></h2>
    <a href="?page=locations" class="btn btn-ghost">← Back</a>
</div>

<div class="form-card">
    <form method="POST">
        <input type="hidden" name="action" value="save_location">
        <?php if ($isEdit): ?>
            <input type="hidden" name="id" value="<?= $loc['location_id'] ?>">
        <?php endif; ?>

        <div class="form-grid">
            <div class="form-group">
                <label>Location ID <span class="required">*</span>
                    <?php if (!$isEdit): ?>
                        <span class="hint">— must be unique, cannot be changed later</span>
                    <?php endif; ?>
                </label>
                <?php if ($isEdit): ?>
                    <input class="form-control" value="<?= h($loc['location_id']) ?>" readonly>
                <?php else: ?>
                    <input type="number" name="location_id" class="form-control" required
                           min="1" placeholder="e.g. 86">
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label>Location Name <span class="required">*</span></label>
                <input type="text" name="location_name" class="form-control" required
                       value="<?= h($loc['location_name'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label>Contact Email <span class="hint">For OTP</span></label>
                <input type="email" name="contact_email" class="form-control"
                       value="<?= h($loc['contact_email'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label>Contact Phone <span class="hint">For OTP</span></label>
                <input type="text" name="contact_phone" class="form-control"
                       value="<?= h($loc['contact_phone'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label>Location Code</label>
                <input type="text" name="location_code" class="form-control"
                       value="<?= h($loc['location_code'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label>Latitude</label>
                <input type="number" name="latitude" class="form-control" step="any"
                       value="<?= h($loc['latitude'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label>Longitude</label>
                <input type="number" name="longitude" class="form-control" step="any"
                       value="<?= h($loc['longitude'] ?? '') ?>">
            </div>

            <div class="form-group" style="grid-column:1/-1">
                <label>Address</label>
                <textarea name="address" class="form-control" rows="3"><?= h($loc['address'] ?? '') ?></textarea>
            </div>

            </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save Location</button>
            <a href="?page=locations" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
<?php
}
