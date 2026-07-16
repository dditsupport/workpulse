<?php
// =========================================================
// Retail Transactions — admin report + CSV export + bulk delete
//
// Permission: superadmin OR txn_transactions_report.
// Bulk delete (by month) is superadmin-only.
// =========================================================

// ── Page: report ────────────────────────────────────────
function pageTransactionsReport(): void {
    if (!canViewTransactionReport()) {
        echo '<div class="page-header"><h2>Banking Cash Deposit Report</h2></div>';
        echo '<div class="rpt-prompt">Access denied.</div>';
        return;
    }

    $db   = getDb();
    $date = txnReportFilters();

    // Location filter (multi-select). On first load HO + Factory are
    // unchecked; the user can toggle them on per-request.
    $allLocations  = getActiveLocations();
    $locSubmitted  = isset($_GET['location_id']) ? (array)$_GET['location_id'] : null;
    $selectedLocs  = resolveReportLocationFilter($allLocations, $locSubmitted);
    $locFilterActive = count($selectedLocs) < count($allLocations);

    $coverage = txnReportCoverage($db, $date);
    if ($locFilterActive) {
        $selSet   = array_flip($selectedLocs);
        $coverage = array_values(array_filter(
            $coverage,
            fn($r) => isset($selSet[(int)$r['location_id']])
        ));
    }
    $hasValCols       = txnHasValidationCols();
    $hasInvCols       = txnHasInvalidateCols();
    $missingCount     = 0;
    $totalAmount      = 0.0;   // excludes invalidated
    $depositCount     = 0;     // excludes invalidated
    $pendingCount     = 0;
    $pendingAmount    = 0.0;
    $invalidatedCount = 0;
    $invalidatedAmount = 0.0;
    foreach ($coverage as $cv) {
        if (empty($cv['deposit_id'])) {
            $missingCount++;
            continue;
        }
        $isInvalidated = $hasInvCols && !empty($cv['invalidated_at']);
        if ($isInvalidated) {
            // Invalidated rows are still rendered, but they don't count
            // toward total deposits and they're skipped in CSV export.
            $invalidatedCount++;
            $invalidatedAmount += (float)$cv['amount'];
            continue;
        }
        $depositCount++;
        $totalAmount += (float)$cv['amount'];
        // Pending = uploaded but not yet validated (and not invalidated).
        // When the validation migration hasn't run, treat every deposit
        // as pending so the pending row still shows something meaningful.
        $isValidated = $hasValCols && !empty($cv['validated_at']);
        if (!$isValidated) {
            $pendingCount++;
            $pendingAmount += (float)$cv['amount'];
        }
    }

    $exportQs = http_build_query([
        'page'        => 'export_transactions_report',
        'date'        => $date,
        'location_id' => $selectedLocs,
    ]);
?>
<div class="page-header"><h2>📊 Banking Cash Deposit Report</h2></div>

<?php if (isSuperadmin()): ?>
<!-- Superadmin: bulk delete by month -->
<div class="form-card" style="max-width:none;margin-bottom:14px;border-left:3px solid var(--red)">
    <div class="form-section-title" style="color:var(--red)">⚠️ Delete All Cash Deposits for a Month</div>
    <form method="POST" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap"
          onsubmit="return confirm('Delete ALL cash deposits (and their attachment files) for the selected month across every location? This cannot be undone.');">
        <input type="hidden" name="action" value="delete_transactions_by_month">
        <div class="form-group">
            <label>Month</label>
            <select name="month" class="form-control" style="width:140px" required>
                <?php $curM = (int)date('m'); for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $m === $curM ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Year</label>
            <select name="year" class="form-control" style="width:100px" required>
                <?php $curY = (int)date('Y'); for ($y = 2024; $y <= $curY + 1; $y++): ?>
                <option value="<?= $y ?>" <?= $y === $curY ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-danger-solid"
                title="Delete all cash deposits (and their attachment files) for the selected month">
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

<!-- Filters -->
<div class="form-card" style="margin-bottom:14px;max-width:none">
    <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="page" value="transactions_report">
        <div class="form-group">
            <label>Date</label>
            <input type="date" name="date" class="form-control" value="<?= h($date) ?>" required>
        </div>
        <div class="form-group">
            <label>Location</label>
            <div class="ms-filter" data-label="Location" style="width:240px">
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
        <button type="submit" class="btn btn-primary">View</button>
        <a href="?<?= h($exportQs) ?>" class="btn btn-ghost" target="_blank">📥 Export CSV</a>
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

<!-- Location Coverage: every active location for the selected date.
     MISSING rows on top (red), then submitted (orange) and validated (green).
     The Validate button on each uploaded row opens a popup with the receipt,
     the location/amount/date, and a Validate button below the image. -->
<?php $hasVal = txnHasValidationCols(); $canVal = canValidateTransaction(); ?>
<?php if (!$hasVal): ?>
<div class="alert alert-error" style="margin-bottom:14px">
    Validation is not enabled — run <code>migration_2026_05_06_txn_validate.sql</code> on the database. The Validate button will not save until that runs.
</div>
<?php elseif (!$canVal): ?>
<div class="alert alert-error" style="margin-bottom:14px">
    You can view this report but don't have the permission to validate deposits. Ask an admin to grant the <code>transactions_report</code> txn.
</div>
<?php endif; ?>
<div class="form-card" style="max-width:none;margin-bottom:14px">
    <div class="form-section-title">
        Location Coverage
        <?php if ($missingCount > 0): ?>
            <span class="badge badge-red" style="margin-left:8px"><?= (int)$missingCount ?> missing</span>
        <?php else: ?>
            <span class="badge badge-green" style="margin-left:8px">All locations uploaded</span>
        <?php endif; ?>
        <span style="font-size:11px;font-weight:400;color:var(--muted);margin-left:8px">
            (<?= h(date('d-M-Y', strtotime($date))) ?>)
        </span>
    </div>
    <div class="table-wrap" data-stack style="overflow-x:auto">
        <table class="table" id="txnCoverageTable">
            <thead>
                <tr>
                    <th>Location</th>
                    <th class="num" style="width:140px;text-align:right">Amount (₹)</th>
                    <th>Remark</th>
                    <th style="width:160px">Uploaded By</th>
                    <th style="width:140px">Uploaded At</th>
                    <th style="width:130px;text-align:center">Status</th>
                    <th style="width:120px;text-align:center">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$coverage): ?>
                <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:18px">No active locations.</td></tr>
            <?php else: foreach ($coverage as $cv):
                $depId         = (int)($cv['deposit_id'] ?? 0);
                $missing       = $depId === 0;
                $isInvalidated = !$missing && $hasInvCols && !empty($cv['invalidated_at']);
                $isValidated   = !$missing && !$isInvalidated && $hasVal && !empty($cv['validated_at']);
                $rowCls        = $missing
                    ? 'txn-row-missing'
                    : ($isInvalidated ? 'txn-row-invalidated'
                        : ($isValidated ? 'txn-row-validated' : 'txn-row-submitted'));
                $mime          = (string)($cv['mime_type'] ?? '');
                $isImage       = strpos($mime, 'image/') === 0;
                $rowId         = $missing ? '' : 'txnRow-' . $depId;
                $invReason     = (string)($cv['invalidate_reason'] ?? '');
                $invBy         = (string)($cv['invalidator_name'] ?? $cv['invalidated_by'] ?? '');
                $invAt         = !empty($cv['invalidated_at']) ? date('d-M-Y H:i', strtotime((string)$cv['invalidated_at'])) : '';
            ?>
                <tr<?= $rowId ? ' id="' . $rowId . '"' : '' ?> class="<?= $rowCls ?>">
                    <td><?= h((string)$cv['location_name']) ?></td>
                    <td class="num" style="text-align:right;font-family:Consolas,monospace<?= $isInvalidated ? ';text-decoration:line-through;opacity:.6' : '' ?>">
                        <?= $missing ? '—' : h(number_format((float)$cv['amount'], 2)) ?>
                    </td>
                    <td><?= $missing ? '—' : h((string)($cv['remark'] ?? '')) ?></td>
                    <td><?= $missing ? '—' : h((string)($cv['uploader_name'] ?? $cv['uploaded_by'])) ?></td>
                    <td style="font-size:11px;color:var(--muted)">
                        <?= $missing ? '—' : h(date('d-M-Y H:i', strtotime((string)$cv['uploaded_at']))) ?>
                    </td>
                    <td style="text-align:center" class="txn-status-cell">
                        <?php if ($missing): ?>
                            <span class="badge badge-red">❌ MISSING</span>
                        <?php elseif ($isInvalidated): ?>
                            <span class="badge" style="background:rgba(140,140,160,.25);color:#bcbccd"
                                  title="Invalidated by <?= h($invBy) ?> on <?= h($invAt) ?><?= $invReason !== '' ? ' — ' . h($invReason) : '' ?>">✗ Invalidated</span>
                        <?php elseif ($isValidated): ?>
                            <span class="badge badge-green" title="Validated by <?= h((string)($cv['validator_name'] ?? $cv['validated_by'])) ?> on <?= h(date('d-M-Y H:i', strtotime((string)$cv['validated_at']))) ?>">✓ Validated</span>
                        <?php else: ?>
                            <span class="badge" style="background:rgba(255,150,40,.20);color:#ffb347">⌛ Submitted</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center" class="txn-action-cell">
                        <?php if ($missing): ?>
                            <span style="color:var(--muted);font-size:11px">—</span>
                        <?php else: ?>
                            <button type="button" class="btn btn-primary"
                                    style="padding:4px 10px;font-size:12px"
                                    onclick="txnOpenValidate(<?= $depId ?>)"
                                    data-img="<?= $isImage ? 1 : 0 ?>"
                                    data-location="<?= h((string)$cv['location_name']) ?>"
                                    data-amount="<?= h(number_format((float)$cv['amount'], 2)) ?>"
                                    data-date="<?= h(date('d-M-Y', strtotime($date))) ?>"
                                    data-remark="<?= h((string)($cv['remark'] ?? '')) ?>"
                                    data-uploader="<?= h((string)($cv['uploader_name'] ?? $cv['uploaded_by'])) ?>"
                                    data-validated="<?= $isValidated ? 1 : 0 ?>"
                                    data-validator="<?= h((string)($cv['validator_name'] ?? $cv['validated_by'] ?? '')) ?>"
                                    data-validated-at="<?= h($cv['validated_at'] ? date('d-M-Y H:i', strtotime((string)$cv['validated_at'])) : '') ?>"
                                    data-invalidated="<?= $isInvalidated ? 1 : 0 ?>"
                                    data-invalidator="<?= h($invBy) ?>"
                                    data-invalidated-at="<?= h($invAt) ?>"
                                    data-invalidate-reason="<?= h($invReason) ?>">
                                <?= $isValidated ? 'View' : ($isInvalidated ? 'Review' : 'Validate') ?>
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if ($depositCount > 0 || $invalidatedCount > 0): ?>
            <tfoot>
                <?php if ($pendingCount > 0): ?>
                <tr style="background:rgba(255,150,40,.18);font-weight:600;color:#ffb347">
                    <td>⌛ Pending validation (<?= (int)$pendingCount ?> deposit<?= $pendingCount === 1 ? '' : 's' ?>)</td>
                    <td class="num" style="text-align:right;font-family:Consolas,monospace">
                        <?= h(number_format($pendingAmount, 2)) ?>
                    </td>
                    <td colspan="5"></td>
                </tr>
                <?php endif; ?>
                <?php if ($invalidatedCount > 0): ?>
                <tr style="background:rgba(140,140,160,.18);font-weight:600;color:#bcbccd">
                    <td>✗ Invalidated (<?= (int)$invalidatedCount ?> deposit<?= $invalidatedCount === 1 ? '' : 's' ?>) — excluded from total &amp; CSV</td>
                    <td class="num" style="text-align:right;font-family:Consolas,monospace;text-decoration:line-through">
                        <?= h(number_format($invalidatedAmount, 2)) ?>
                    </td>
                    <td colspan="5"></td>
                </tr>
                <?php endif; ?>
                <tr style="background:var(--border);font-weight:700">
                    <td>Total (<?= (int)$depositCount ?> deposit<?= $depositCount === 1 ? '' : 's' ?>)</td>
                    <td class="num" style="text-align:right;font-family:Consolas,monospace">
                        <?= h(number_format($totalAmount, 2)) ?>
                    </td>
                    <td colspan="5"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<style>
/* Status row tints — red for missing, orange while awaiting review, green once validated, grey when invalidated. */
tr.txn-row-missing     td{background:rgba(220,64,64,.10)}
tr.txn-row-submitted   td{background:rgba(255,150,40,.10)}
tr.txn-row-validated   td{background:rgba(39,174,96,.10)}
tr.txn-row-invalidated td{background:rgba(140,140,160,.10);color:var(--muted)}

/* Validate popup */
.txn-overlay{position:fixed;inset:0;background:rgba(0,0,0,.7);display:none;z-index:9100;align-items:flex-start;justify-content:center;padding:30px 16px;overflow:auto}
.txn-overlay.open{display:flex}
.txn-modal{background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:10px;width:100%;max-width:760px;max-height:calc(100vh - 60px);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 16px 48px rgba(0,0,0,.6)}
.txn-modal-head{display:flex;align-items:center;justify-content:space-between;padding:12px 18px;border-bottom:1px solid var(--border)}
.txn-modal-head h3{margin:0;font-size:15px;font-weight:600}
.txn-modal-close{background:transparent;border:none;color:var(--muted);font-size:24px;cursor:pointer;line-height:1;padding:0 4px}
.txn-modal-close:hover{color:var(--text)}
.txn-modal-body{padding:14px 18px;overflow:auto;flex:1}
.txn-img-wrap{background:#000;border:1px solid var(--border);border-radius:6px;display:flex;align-items:center;justify-content:center;min-height:280px;max-height:60vh;overflow:auto}
.txn-img-wrap img{max-width:100%;max-height:60vh;display:block}
.txn-meta-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:14px}
.txn-meta-grid .lbl{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px}
.txn-meta-grid .val{font-size:14px;font-weight:600}
.txn-modal-foot{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 18px;border-top:1px solid var(--border);background:rgba(0,0,0,.15)}
.txn-validated-stamp{font-size:12px;color:var(--green);font-weight:600}
@media(max-width:560px){.txn-meta-grid{grid-template-columns:1fr}}
</style>

<div class="txn-overlay" id="txnOverlay" role="dialog" aria-modal="true" aria-labelledby="txnModalTitle">
    <div class="txn-modal">
        <div class="txn-modal-head">
            <h3 id="txnModalTitle">Validate Cash Deposit</h3>
            <button type="button" class="txn-modal-close" aria-label="Close" onclick="txnCloseValidate()">×</button>
        </div>
        <div class="txn-modal-body">
            <div class="txn-img-wrap" id="txnImgWrap">
                <span style="color:var(--muted)">Loading…</span>
            </div>
            <div class="txn-meta-grid">
                <div>
                    <div class="lbl">Location</div>
                    <div class="val" id="txnMetaLocation">—</div>
                </div>
                <div>
                    <div class="lbl">Amount (₹)</div>
                    <div class="val" id="txnMetaAmount">—</div>
                </div>
                <div>
                    <div class="lbl">Date</div>
                    <div class="val" id="txnMetaDate">—</div>
                </div>
                <div style="grid-column:1 / -1">
                    <div class="lbl">Uploaded By / Remark</div>
                    <div class="val" style="font-weight:400" id="txnMetaSecondary">—</div>
                </div>
                <?php if ($canVal && $hasInvCols): ?>
                <div style="grid-column:1 / -1">
                    <div class="lbl">Reason (when invalidating — optional)</div>
                    <input type="text" id="txnInvalidateReason" maxlength="500"
                           placeholder="e.g. duplicate slip, wrong store, …"
                           style="width:100%;background:#fff;color:#1a1612;border:1px solid #d8d0c2;border-radius:4px;padding:6px 8px;font-size:13px;box-sizing:border-box">
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="txn-modal-foot">
            <div id="txnValidatedStamp" class="txn-validated-stamp"></div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <button type="button" class="btn btn-ghost" onclick="txnCloseValidate()">Close</button>
                <a id="txnDownloadLink" class="btn btn-secondary" href="#" target="_blank" style="padding:4px 12px">Open Original</a>
                <?php if ($canVal && $hasInvCols): ?>
                <button type="button" class="btn" id="txnInvalidateBtn"
                        style="background:rgba(220,64,64,.85);color:#fff;border-color:rgba(220,64,64,.85)"
                        onclick="txnInvalidateConfirm()">Mark Invalid</button>
                <?php endif; ?>
                <?php if ($canVal && $hasVal): ?>
                <button type="button" class="btn btn-success" id="txnValidateBtn" onclick="txnValidateConfirm()">Validate</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    var overlay   = document.getElementById('txnOverlay');
    var imgWrap   = document.getElementById('txnImgWrap');
    var locEl     = document.getElementById('txnMetaLocation');
    var amtEl     = document.getElementById('txnMetaAmount');
    var dtEl      = document.getElementById('txnMetaDate');
    var secEl     = document.getElementById('txnMetaSecondary');
    var stampEl    = document.getElementById('txnValidatedStamp');
    var dlEl       = document.getElementById('txnDownloadLink');
    var validBtn   = document.getElementById('txnValidateBtn');
    var invalidBtn = document.getElementById('txnInvalidateBtn');
    var reasonEl   = document.getElementById('txnInvalidateReason');
    var currentId  = 0;

    function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    }); }

    // Sync the popup buttons + stamp text to whatever state the active
    // row is in. Called both on open and after validate/invalidate
    // succeeds, so the popup always reflects what's saved.
    function refreshPopupState(state) {
        // state = { validated, validator, validatedAt, invalidated, invalidator, invalidatedAt, reason }
        if (state.invalidated) {
            stampEl.style.color = 'var(--red)';
            stampEl.textContent = '✗ Invalidated by ' + (state.invalidator || '') + ' on ' + (state.invalidatedAt || '')
                + (state.reason ? ' — ' + state.reason : '');
            if (validBtn)   { validBtn.disabled = false;  validBtn.textContent = 'Re-validate'; }
            if (invalidBtn) { invalidBtn.disabled = true; invalidBtn.textContent = 'Already Invalid'; }
        } else if (state.validated) {
            stampEl.style.color = 'var(--green)';
            stampEl.textContent = '✓ Validated by ' + (state.validator || '') + ' on ' + (state.validatedAt || '');
            if (validBtn)   { validBtn.disabled = true;  validBtn.textContent = 'Already Validated'; }
            if (invalidBtn) { invalidBtn.disabled = false; invalidBtn.textContent = 'Mark Invalid'; }
        } else {
            stampEl.style.color = 'var(--muted)';
            stampEl.textContent = '';
            if (validBtn)   { validBtn.disabled = false; validBtn.textContent = 'Validate'; }
            if (invalidBtn) { invalidBtn.disabled = false; invalidBtn.textContent = 'Mark Invalid'; }
        }
    }

    window.txnOpenValidate = function(id){
        var btn = document.querySelector('button[onclick="txnOpenValidate(' + id + ')"]');
        if (!btn) return;
        currentId = id;
        var src = 'index.php?page=download_txn_attachment&id=' + id;
        var isImg = btn.getAttribute('data-img') === '1';
        imgWrap.innerHTML = isImg
            ? '<img src="' + esc(src) + '" alt="Receipt">'
            : '<div style="padding:30px;text-align:center;color:var(--muted)">Receipt is a PDF — use <strong>Open Original</strong> to view.</div>';
        locEl.textContent = btn.getAttribute('data-location') || '—';
        amtEl.textContent = '₹ ' + (btn.getAttribute('data-amount') || '0.00');
        dtEl.textContent  = btn.getAttribute('data-date') || '—';
        var uploader = btn.getAttribute('data-uploader') || '';
        var remark   = btn.getAttribute('data-remark') || '';
        secEl.textContent = uploader + (remark ? ' — ' + remark : '');
        dlEl.setAttribute('href', src);

        if (reasonEl) reasonEl.value = btn.getAttribute('data-invalidate-reason') || '';

        refreshPopupState({
            validated:     btn.getAttribute('data-validated') === '1',
            validator:     btn.getAttribute('data-validator') || '',
            validatedAt:   btn.getAttribute('data-validated-at') || '',
            invalidated:   btn.getAttribute('data-invalidated') === '1',
            invalidator:   btn.getAttribute('data-invalidator') || '',
            invalidatedAt: btn.getAttribute('data-invalidated-at') || '',
            reason:        btn.getAttribute('data-invalidate-reason') || ''
        });
        overlay.classList.add('open');
        document.body.style.overflow = 'hidden';
    };
    window.txnCloseValidate = function(){
        overlay.classList.remove('open');
        document.body.style.overflow = '';
        currentId = 0;
    };
    window.txnValidateConfirm = function(){
        if (!currentId || !validBtn) return;
        validBtn.disabled = true; validBtn.textContent = 'Saving…';
        var fd = new FormData();
        fd.append('action', 'validate_transaction');
        fd.append('id', String(currentId));
        fd.append('xhr', '1');
        fetch('index.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r){ return r.text().then(function(t){ return { status: r.status, text: t }; }); })
            .then(function(resp){
                var data = null;
                try { data = JSON.parse(resp.text); } catch (e) {}
                if (!data) {
                    var snippet = (resp.text || '').replace(/\s+/g, ' ').trim().slice(0, 300);
                    stampEl.textContent = 'Server returned non-JSON (HTTP ' + resp.status + '): ' + snippet;
                    stampEl.style.color = 'var(--red)';
                    validBtn.disabled = false; validBtn.textContent = 'Retry';
                    return;
                }
                if (!data.ok) {
                    stampEl.textContent = data.error || 'Validation failed.';
                    stampEl.style.color = 'var(--red)';
                    validBtn.disabled = false; validBtn.textContent = 'Retry';
                    return;
                }
                // Mutate the row + button so the user sees the change without a reload.
                var row = document.getElementById('txnRow-' + data.id);
                if (row) {
                    row.classList.remove('txn-row-submitted','txn-row-invalidated');
                    row.classList.add('txn-row-validated');
                    // Clear strike-through on the amount cell (set when invalidated).
                    var amtCell = row.children[1];
                    if (amtCell) { amtCell.style.textDecoration = ''; amtCell.style.opacity = ''; }
                    var statusCell = row.querySelector('.txn-status-cell');
                    if (statusCell) statusCell.innerHTML = '<span class="badge badge-green" title="Validated by ' + esc(data.validator) + ' on ' + esc(data.validated_at) + '">✓ Validated</span>';
                    var rowBtn = row.querySelector('.txn-action-cell button');
                    if (rowBtn) {
                        rowBtn.setAttribute('data-validated', '1');
                        rowBtn.setAttribute('data-validator', data.validator || '');
                        rowBtn.setAttribute('data-validated-at', data.validated_at || '');
                        rowBtn.setAttribute('data-invalidated', '0');
                        rowBtn.setAttribute('data-invalidator', '');
                        rowBtn.setAttribute('data-invalidated-at', '');
                        rowBtn.setAttribute('data-invalidate-reason', '');
                        rowBtn.textContent = 'View';
                    }
                }
                refreshPopupState({
                    validated: true,
                    validator: data.validator || '',
                    validatedAt: data.validated_at || '',
                    invalidated: false
                });
                if (reasonEl) reasonEl.value = '';
            })
            .catch(function(err){
                stampEl.textContent = 'Network error: ' + (err && err.message ? err.message : 'try again');
                stampEl.style.color = 'var(--red)';
                validBtn.disabled = false; validBtn.textContent = 'Retry';
            });
    };

    window.txnInvalidateConfirm = function(){
        if (!currentId || !invalidBtn) return;
        if (!confirm('Mark this deposit invalid? It will be excluded from totals and the CSV export.')) return;
        invalidBtn.disabled = true; invalidBtn.textContent = 'Saving…';
        var fd = new FormData();
        fd.append('action', 'invalidate_transaction');
        fd.append('id', String(currentId));
        fd.append('reason', reasonEl ? reasonEl.value.trim() : '');
        fd.append('xhr', '1');
        fetch('index.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r){ return r.text().then(function(t){ return { status: r.status, text: t }; }); })
            .then(function(resp){
                var data = null;
                try { data = JSON.parse(resp.text); } catch (e) {}
                if (!data) {
                    var snippet = (resp.text || '').replace(/\s+/g, ' ').trim().slice(0, 300);
                    stampEl.textContent = 'Server returned non-JSON (HTTP ' + resp.status + '): ' + snippet;
                    stampEl.style.color = 'var(--red)';
                    invalidBtn.disabled = false; invalidBtn.textContent = 'Retry';
                    return;
                }
                if (!data.ok) {
                    stampEl.textContent = data.error || 'Invalidate failed.';
                    stampEl.style.color = 'var(--red)';
                    invalidBtn.disabled = false; invalidBtn.textContent = 'Retry';
                    return;
                }
                // Mutate the row in place — flip class, badge, button state.
                var row = document.getElementById('txnRow-' + data.id);
                if (row) {
                    row.classList.remove('txn-row-submitted','txn-row-validated');
                    row.classList.add('txn-row-invalidated');
                    var amtCell = row.children[1];
                    if (amtCell) { amtCell.style.textDecoration = 'line-through'; amtCell.style.opacity = '.6'; }
                    var statusCell = row.querySelector('.txn-status-cell');
                    if (statusCell) {
                        var ttl = 'Invalidated by ' + esc(data.invalidator) + ' on ' + esc(data.invalidated_at) + (data.reason ? ' — ' + esc(data.reason) : '');
                        statusCell.innerHTML = '<span class="badge" style="background:rgba(140,140,160,.25);color:#bcbccd" title="' + ttl + '">✗ Invalidated</span>';
                    }
                    var rowBtn = row.querySelector('.txn-action-cell button');
                    if (rowBtn) {
                        rowBtn.setAttribute('data-invalidated', '1');
                        rowBtn.setAttribute('data-invalidator', data.invalidator || '');
                        rowBtn.setAttribute('data-invalidated-at', data.invalidated_at || '');
                        rowBtn.setAttribute('data-invalidate-reason', data.reason || '');
                        rowBtn.setAttribute('data-validated', '0');
                        rowBtn.setAttribute('data-validator', '');
                        rowBtn.setAttribute('data-validated-at', '');
                        rowBtn.textContent = 'Review';
                    }
                }
                refreshPopupState({
                    validated: false,
                    invalidated: true,
                    invalidator: data.invalidator || '',
                    invalidatedAt: data.invalidated_at || '',
                    reason: data.reason || ''
                });
            })
            .catch(function(err){
                stampEl.textContent = 'Network error: ' + (err && err.message ? err.message : 'try again');
                stampEl.style.color = 'var(--red)';
                invalidBtn.disabled = false; invalidBtn.textContent = 'Retry';
            });
    };
    overlay.addEventListener('click', function(e){
        if (e.target === overlay) txnCloseValidate();
    });
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape' && overlay.classList.contains('open')) txnCloseValidate();
    });
})();
</script>
<?php
}

// Shared filter parsing — single date, defaults to today.
function txnReportFilters(): string {
    $today = date('Y-m-d');
    $date  = $_GET['date'] ?? $today;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = $today;
    return $date;
}

// Coverage rows — one row per (active location, deposit). Locations with
// no deposit on the chosen date appear once with NULL deposit fields. The
// row tints (red/orange/green/grey) and status badges still make MISSING
// rows obvious, so we just sort everything alphabetically by location
// name. A location with multiple deposits on the same date yields
// multiple rows (one per deposit) so each can be validated independently.
function txnReportCoverage(PDO $db, string $date): array {
    $hasVal = txnHasValidationCols();
    $hasInv = txnHasInvalidateCols();
    $valCols = $hasVal
        ? 't.validated_at, t.validated_by, ev.full_name AS validator_name'
        : 'NULL AS validated_at, NULL AS validated_by, NULL AS validator_name';
    $valJoin = $hasVal
        ? ' LEFT JOIN employees ev ON ev.employee_code = t.validated_by'
        : '';
    $invCols = $hasInv
        ? 't.invalidated_at, t.invalidated_by, t.invalidate_reason, ei.full_name AS invalidator_name'
        : 'NULL AS invalidated_at, NULL AS invalidated_by, NULL AS invalidate_reason, NULL AS invalidator_name';
    $invJoin = $hasInv
        ? ' LEFT JOIN employees ei ON ei.employee_code = t.invalidated_by'
        : '';
    try {
        $st = $db->prepare(
            'SELECT l.location_id, l.location_name,
                    t.id AS deposit_id, t.amount, t.remark, t.mime_type,
                    t.original_name,
                    t.uploaded_by, t.uploaded_at,
                    eu.full_name AS uploader_name,
                    ' . $valCols . ',
                    ' . $invCols . '
             FROM locations l
             LEFT JOIN transactions t
                    ON t.location_id = l.location_id
                   AND t.txn_date = ?
             LEFT JOIN employees eu ON eu.employee_code = t.uploaded_by'
            . $valJoin . $invJoin .
            ' WHERE l.is_active = 1
              ORDER BY l.location_name ASC, t.id DESC'
        );
        $st->execute([$date]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

// Shared SELECT used by report + export — all deposits on a single date.
// Pulls validation columns when the migration has run; otherwise the
// SELECT falls back to the legacy shape so the page stays usable.
// Invalidated deposits are filtered out at the SQL level so they never
// reach the CSV export — totals likewise reflect only counted rows.
function txnReportQuery(PDO $db, string $date): array {
    $valSelect = txnHasValidationCols()
        ? ', t.validated_at, t.validated_by, ev.full_name AS validator_name'
        : ', NULL AS validated_at, NULL AS validated_by, NULL AS validator_name';
    $valJoin = txnHasValidationCols()
        ? ' LEFT JOIN employees ev ON ev.employee_code = t.validated_by'
        : '';
    $invFilter = txnHasInvalidateCols() ? ' AND t.invalidated_at IS NULL' : '';
    try {
        $st = $db->prepare(
            'SELECT t.id, t.txn_date, t.amount, t.remark, t.original_name,
                    t.mime_type,
                    t.uploaded_by, t.uploaded_at, l.location_name,
                    e.full_name AS uploader_name'
            . $valSelect .
            ' FROM transactions t
              LEFT JOIN locations l ON l.location_id = t.location_id
              LEFT JOIN employees e ON e.employee_code = t.uploaded_by'
            . $valJoin .
            ' WHERE t.txn_date = ?' . $invFilter .
            ' ORDER BY l.location_name ASC, t.id DESC'
        );
        $st->execute([$date]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $rows = []; }

    $total = 0.0;
    foreach ($rows as $r) $total += (float)$r['amount'];
    return [$rows, $total];
}

// ── CSV export ──────────────────────────────────────────
// Uses the same per-location coverage as the on-screen report so every
// active location appears exactly once per deposit — including a row with
// amount 0 and status NOT SUBMITTED for locations that didn't deposit.
// Invalidated deposits are excluded (matching the footer note in the UI).
function exportTransactionsReport(): void {
    if (!canViewTransactionReport()) { echo 'Access denied.'; exit; }

    $db   = getDb();
    $date = txnReportFilters();

    // Mirror the on-screen location filter so the CSV matches what the
    // user just saw. Same default rule: HO + Factory unchecked unless the
    // user explicitly opts in via location_id[].
    $allLocations  = getActiveLocations();
    $locSubmitted  = isset($_GET['location_id']) ? (array)$_GET['location_id'] : null;
    $selectedLocs  = resolveReportLocationFilter($allLocations, $locSubmitted);
    $locFilterActive = count($selectedLocs) < count($allLocations);

    $coverage = txnReportCoverage($db, $date);
    if ($locFilterActive) {
        $selSet   = array_flip($selectedLocs);
        $coverage = array_values(array_filter(
            $coverage,
            fn($r) => isset($selSet[(int)$r['location_id']])
        ));
    }
    $hasInv   = txnHasInvalidateCols();

    $filename = "cash_deposits_{$date}.csv";
    $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');

    fputcsv($out, ['Date', 'Location', 'Amount', 'Status', 'Remark', 'Uploaded By', 'Uploaded At', 'File'], escape: '');

    $total = 0.0;
    foreach ($coverage as $cv) {
        $missing       = empty($cv['deposit_id']);
        $isInvalidated = !$missing && $hasInv && !empty($cv['invalidated_at']);
        // Match the on-screen footer: invalidated rows are excluded from
        // both the total and the CSV.
        if ($isInvalidated) continue;

        if ($missing) {
            fputcsv($out, [
                $date,
                (string)$cv['location_name'],
                '0.00',
                'NOT SUBMITTED',
                '', '', '', '',
            ], escape: '');
            continue;
        }

        $total += (float)$cv['amount'];
        $status = !empty($cv['validated_at']) ? 'VALIDATED' : 'SUBMITTED';
        fputcsv($out, [
            $date,
            (string)$cv['location_name'],
            number_format((float)$cv['amount'], 2, '.', ''),
            $status,
            (string)($cv['remark'] ?? ''),
            (string)($cv['uploader_name'] ?? $cv['uploaded_by'] ?? ''),
            (string)($cv['uploaded_at'] ?? ''),
            (string)($cv['original_name'] ?? ''),
        ], escape: '');
    }

    fputcsv($out, [], escape: '');
    fputcsv($out, ['', 'TOTAL', number_format($total, 2, '.', ''), '', '', '', '', ''], escape: '');

    fclose($out);
    exit;
}

// ── Bulk delete by month (superadmin only) ──────────────
function doDeleteTransactionsByMonth(): void {
    if (!isSuperadmin()) {
        flash('error', 'Access denied.');
        header('Location: index.php?page=transactions_report'); exit;
    }
    $month = (int)($_POST['month'] ?? 0);
    $year  = (int)($_POST['year']  ?? 0);
    if ($month < 1 || $month > 12 || $year < 2000 || $year > 2099) {
        flash('error', 'Invalid month or year.');
        header('Location: index.php?page=transactions_report'); exit;
    }
    $monthStart = sprintf('%04d-%02d-01', $year, $month);
    $monthEnd   = date('Y-m-t', strtotime($monthStart));
    $monthName  = date('F Y', strtotime($monthStart));

    $db = getDb();
    try {
        $st = $db->prepare('SELECT id, txn_date, stored_name FROM transactions WHERE txn_date BETWEEN ? AND ?');
        $st->execute([$monthStart, $monthEnd]);
        $victims = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$victims) {
            flash('error', 'No cash deposits in ' . $monthName . '.');
            header('Location: index.php?page=transactions_report'); exit;
        }

        $db->beginTransaction();
        $del = $db->prepare('DELETE FROM transactions WHERE txn_date BETWEEN ? AND ?');
        $del->execute([$monthStart, $monthEnd]);
        $db->commit();

        // Remove files only after the row delete commits, so a failed
        // commit doesn't strand DB rows pointing at missing files.
        $unlinked = 0;
        foreach ($victims as $v) {
            $p = txnAttachmentPath((string)$v['txn_date'], (string)$v['stored_name']);
            if (is_file($p) && @unlink($p)) $unlinked++;
        }

        flash('success', 'Deleted ' . count($victims) . ' cash deposits for ' . $monthName . ' (' . $unlinked . ' files removed).');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error', 'Bulk delete failed: ' . $e->getMessage());
    }
    header('Location: index.php?page=transactions_report'); exit;
}
