<?php
// =========================================================
// Outlet Directory — View-only for all roles
// =========================================================

function pageOutletDirectory(): void {
    $search = trim($_GET['search'] ?? '');
    $outlets = getOutlets($search);
    $lastInNotOut = getLastInNotOut();
?>
<div class="page-header">
    <h2>Outlet Directory</h2>
</div>

<form class="rpt-filter" method="GET">
    <input type="hidden" name="page" value="outlet_directory">
    <span class="input-clear-wrap" style="flex:1 1 auto;min-width:200px">
        <input type="text" name="search" class="form-control" placeholder="Search name, code, address, email, phone..."
               value="<?= h($search) ?>">
        <button type="button" class="input-clear-btn" aria-label="Clear search" tabindex="-1">&times;</button>
    </span>
    <button class="btn btn-primary" type="submit">Search</button>
</form>

<div class="table-wrap" data-stack>
    <table class="table">
        <thead>
            <tr>
                <th>#</th>
                <th>Outlet Name</th>
                <th>Mobile</th>
                <th style="min-width:200px">Last In (Not Out)</th>
                <th>Email</th>
                <th>Code</th>
                <th class="od-address-col" style="max-width:150px">Address</th>
                <th>Lat</th>
                <th>Long</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($outlets)): ?>
            <tr><td colspan="9" class="empty-row">No outlets found.</td></tr>
        <?php else: $i = 0; foreach ($outlets as $o): $i++; ?>
            <tr>
                <td><?= $i ?></td>
                <td><?= h($o['location_name']) ?></td>
                <td><?= h($o['contact_phone'] ?? '-') ?></td>
                <td style="font-size:12px">
                    <?php $empsAtLoc = $lastInNotOut[(int)$o['location_id']] ?? []; ?>
                    <?php if (empty($empsAtLoc)): ?>
                        <span class="text-muted">—</span>
                    <?php else: foreach ($empsAtLoc as $el): ?>
                        <div style="margin-bottom:2px">
                            <strong><?= h($el['name']) ?></strong>
                            <?php if ($el['phone']): ?>
                                <span class="text-muted"><?= h($el['phone']) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; endif; ?>
                </td>
                <td><?= h($o['contact_email'] ?? '-') ?></td>
                <td><span class="badge badge-blue"><?= h($o['location_code'] ?? '-') ?></span></td>
                <td class="od-address-cell" style="max-width:150px;white-space:normal;word-break:break-word"><?= h($o['address'] ?? '-') ?></td>
                <td><?= $o['latitude'] ? number_format($o['latitude'], 7) : '-' ?></td>
                <td><?= $o['longitude'] ? number_format($o['longitude'], 7) : '-' ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<div class="table-count"><?= count($outlets) ?> outlet(s)</div>
<?php
}
