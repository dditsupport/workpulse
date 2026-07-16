<?php
// =========================================================
// Offer Module — coupon form, submission, generation
// Config read from system_settings (no app.php dependency)
// =========================================================
require_once __DIR__ . '/CouponService.php';
require_once __DIR__ . '/SmsService.php';

// ── Helper: send coupon notification via configured channel ──
function sendCouponNotification(string $mobile, string $email, string $smsMessage, string $emailSubject, string $emailBody, array $smsVars = []): void {
    $channel = getSetting('CouponNotifyChannel', 'sms');
    if ($channel === 'sms' || $channel === 'both') {
        if ($mobile) {
            // Prefer MSG91 v5 Flow when an offer flow template_id is configured;
            // otherwise fall back to the legacy free-text send().
            $flowId = getSetting('sms_offer_flow_id', '');
            if ($flowId !== '' && $smsVars) {
                SmsService::sendFlow($flowId, '91' . $mobile, $smsVars);
            } else {
                SmsService::send('91' . $mobile, $smsMessage);
            }
        }
    }
    if ($channel === 'email' || $channel === 'both') {
        // Deferred — submit-offer is user-facing, the redirect shouldn't
        // wait for SMTP.
        if ($email) sendSmtpEmailQuiet($email, $emailSubject, $emailBody);
    }
}

// ── Submit offer (user) ───────────────────────────────────
function doSubmitOffer(): void {
    $mobno   = trim($_POST['mobno']   ?? '');
    $bill    = trim($_POST['bill']    ?? '');
    $outlet  = trim($_POST['outlet']  ?? '');
    $offer   = trim($_POST['offer']   ?? '');
    $approve = trim($_POST['approve'] ?? '');
    $remark  = trim($_POST['remark']  ?? '');
    $name    = myName();

    $maxBill = (int)getSetting('MaxBillAmount', '500000');
    $approvers = SmsService::getApprovers();
    $approverNames = array_column($approvers, 'name');

    // Validation
    $errors = [];
    if (!preg_match('/^\d{10}$/', $mobno))          $errors[] = 'Mobile must be 10 digits.';
    if (!preg_match('/^\d+$/', $bill))               $errors[] = 'Bill amount must be numeric.';
    if ((int)$bill > $maxBill)                       $errors[] = 'Bill amount exceeds limit.';
    if ($remark !== '' && (strlen($remark) > 50 || !preg_match('/^[a-zA-Z0-9\s]+$/', $remark)))
                                                      $errors[] = 'Invalid remark (alphanumeric, max 50 chars).';
    if (!in_array($approve, $approverNames))         $errors[] = 'Invalid approver.';
    if (!$offer)                                      $errors[] = 'Select an offer type.';

    if ($errors) {
        flash('error', implode(' ', $errors));
        header('Location: index.php?page=offer'); exit;
    }

    $tz = getSetting('AppTimezone', 'Asia/Kolkata');
    date_default_timezone_set($tz);
    $couponRow = CouponService::fetchAvailable($offer);
    $coupon    = $couponRow ? $couponRow['Coupon'] : null;
    $couponId  = $couponRow ? (int)$couponRow['id'] : null;

    $marked = false;
    if ($coupon) {
        $marked = CouponService::markUsed($coupon, [
            'name'          => $name,
            'mobile'        => $mobno,
            'bill'          => $bill,
            'outlet'        => $outlet,
            'approver'      => $approve,
            'remark'        => $remark,
            'ip'            => $_SERVER['REMOTE_ADDR'] ?? '',
            'date'          => date('Y-m-d'),
            'time'          => date('H:i:s'),
            'employee_code' => myCode(),
        ]);
    }

    if ($marked && $coupon) {
        $msgData = [
            'offer'       => $offer,
            'bill'        => $bill,
            'name'        => $name,
            'store'       => $outlet,
            'remark'      => $remark,
            'coupon_code' => $coupon,
            'coupon_id'   => (string)$couponId,
        ];

        $approverMobile = SmsService::approverMobile($approve);
        $approverEmail  = SmsService::approverEmail($approve);

        // Build email body for coupon notification
        $emailBody = "
        <div style='font-family:Arial,sans-serif;max-width:500px;margin:0 auto;background:#f8f9fa;padding:20px;border-radius:8px'>
            <h2 style='color:#1a1d2e;margin-bottom:12px'>Coupon Notification</h2>
            <table style='width:100%;font-size:14px;border-collapse:collapse'>
                <tr><td style='padding:6px;font-weight:bold'>Offer</td><td style='padding:6px'>" . htmlspecialchars($offer) . "</td></tr>
                <tr><td style='padding:6px;font-weight:bold'>Bill Amount</td><td style='padding:6px'>Rs." . htmlspecialchars($bill) . "</td></tr>
                <tr><td style='padding:6px;font-weight:bold'>Coupon Code</td><td style='padding:6px'><strong>" . htmlspecialchars($coupon) . "</strong></td></tr>
                <tr><td style='padding:6px;font-weight:bold'>Employee</td><td style='padding:6px'>" . htmlspecialchars($name) . "</td></tr>
                <tr><td style='padding:6px;font-weight:bold'>Store</td><td style='padding:6px'>" . htmlspecialchars($outlet) . "</td></tr>
                <tr><td style='padding:6px;font-weight:bold'>Remark</td><td style='padding:6px'>" . htmlspecialchars($remark) . "</td></tr>
            </table>
        </div>";
        $emailSubject = "Coupon {$offer} — Rs.{$bill} at {$outlet}";

        // Notify approver (with coupon code)
        sendCouponNotification(
            $approverMobile ?? '',
            $approverEmail ?? '',
            SmsService::buildOfferMessage($msgData, 'approver'),
            $emailSubject,
            $emailBody,
            SmsService::buildOfferVars($msgData, 'approver')
        );

        // CC to admin
        $adminMobile = getSetting('sms_admin_mobile', '');
        $adminEmail  = getSetting('email_admin', '');
        if ($adminMobile || $adminEmail) {
            sendCouponNotification($adminMobile, $adminEmail,
                SmsService::buildOfferMessage($msgData, 'approver'),
                $emailSubject, $emailBody,
                SmsService::buildOfferVars($msgData, 'approver'));
        }

        // Notify requester (employee) — send coupon ID, not actual code
        $requesterMobile = '';
        $requesterEmail  = '';
        if (myCode()) {
            $empRow = getDb()->prepare("SELECT phone, email FROM employees WHERE employee_code = ?");
            $empRow->execute([myCode()]);
            $empData = $empRow->fetch(PDO::FETCH_ASSOC);
            if ($empData) {
                $requesterMobile = $empData['phone'] ?? '';
                $requesterEmail  = $empData['email'] ?? '';
            }
        }

        $requesterEmailBody = str_replace(
            htmlspecialchars($coupon),
            '<strong>#' . $couponId . '</strong>',
            $emailBody
        );
        sendCouponNotification(
            $requesterMobile,
            $requesterEmail,
            SmsService::buildOfferMessage($msgData, 'employee'),
            $emailSubject . ' (Your Copy)',
            $requesterEmailBody,
            SmsService::buildOfferVars($msgData, 'employee')
        );

        flash('success', "Coupon issued! Notification sent to {$approve}.");
    } else {
        flash('error', $coupon ? 'Failed to process coupon.' : 'No coupons available for this offer type.');
    }
    header('Location: index.php?page=offer'); exit;
}

// ── Resend offer SMS (user) ──────────────────────────────
function doResendOffer(): void {
    $couponId = (int)($_POST['coupon_id'] ?? 0);
    if ($couponId <= 0) {
        flash('error', 'Invalid coupon.');
        header('Location: index.php?page=offer'); exit;
    }

    $db = getDb();
    $st = $db->prepare(
        "SELECT id, Coupon, Offer, Name, Bill_Amount, Outlet, Approver, Remark, datestamp, timestamp, employee_code
         FROM offer_coupons WHERE id = ? AND is_redeemed = 1"
    );
    $st->execute([$couponId]);
    $coupon = $st->fetch(PDO::FETCH_ASSOC);

    if (!$coupon) {
        flash('error', 'Coupon not found.');
        header('Location: index.php?page=offer'); exit;
    }

    if ($coupon['employee_code'] !== myCode()) {
        flash('error', 'Access denied.');
        header('Location: index.php?page=offer'); exit;
    }

    $tz = getSetting('AppTimezone', 'Asia/Kolkata');
    date_default_timezone_set($tz);
    if (date('Y-m-d') !== $coupon['datestamp']) {
        flash('error', 'Resend is only available on the same day.');
        header('Location: index.php?page=offer'); exit;
    }
    $redeemTime = strtotime($coupon['datestamp'] . ' ' . $coupon['timestamp']);
    if ((time() - $redeemTime) < 600) {
        flash('error', 'Please wait at least 10 minutes before resending.');
        header('Location: index.php?page=offer'); exit;
    }

    $approver = $coupon['Approver'] ?? '';
    $approverMobile = SmsService::approverMobile($approver);
    $approverEmail  = SmsService::approverEmail($approver);
    if (!$approverMobile && !$approverEmail) {
        flash('error', 'Approver not found.');
        header('Location: index.php?page=offer'); exit;
    }

    $msgData = [
        'offer'       => $coupon['Offer'],
        'bill'        => $coupon['Bill_Amount'],
        'name'        => $coupon['Name'] ?? myName(),
        'store'       => $coupon['Outlet'] ?? '',
        'remark'      => $coupon['Remark'] ?? '',
        'coupon_code' => $coupon['Coupon'],
        'coupon_id'   => (string)$coupon['id'],
    ];

    $emailBody = "
    <div style='font-family:Arial,sans-serif;max-width:500px;margin:0 auto;background:#f8f9fa;padding:20px;border-radius:8px'>
        <h2 style='color:#1a1d2e;margin-bottom:12px'>Coupon Resend</h2>
        <table style='width:100%;font-size:14px;border-collapse:collapse'>
            <tr><td style='padding:6px;font-weight:bold'>Offer</td><td style='padding:6px'>" . htmlspecialchars($coupon['Offer']) . "</td></tr>
            <tr><td style='padding:6px;font-weight:bold'>Bill Amount</td><td style='padding:6px'>Rs." . htmlspecialchars($coupon['Bill_Amount']) . "</td></tr>
            <tr><td style='padding:6px;font-weight:bold'>Coupon Code</td><td style='padding:6px'><strong>" . htmlspecialchars($coupon['Coupon']) . "</strong></td></tr>
            <tr><td style='padding:6px;font-weight:bold'>Employee</td><td style='padding:6px'>" . htmlspecialchars($coupon['Name'] ?? myName()) . "</td></tr>
            <tr><td style='padding:6px;font-weight:bold'>Store</td><td style='padding:6px'>" . htmlspecialchars($coupon['Outlet'] ?? '') . "</td></tr>
        </table>
    </div>";

    sendCouponNotification(
        $approverMobile ?? '',
        $approverEmail ?? '',
        SmsService::buildOfferMessage($msgData, 'approver'),
        "Coupon Resend — {$coupon['Offer']}",
        $emailBody,
        SmsService::buildOfferVars($msgData, 'approver')
    );

    // CC to admin
    $adminMobile = getSetting('sms_admin_mobile', '');
    $adminEmail  = getSetting('email_admin', '');
    if ($adminMobile || $adminEmail) {
        sendCouponNotification($adminMobile, $adminEmail,
            SmsService::buildOfferMessage($msgData, 'approver'),
            "Coupon Resend — {$coupon['Offer']}", $emailBody,
            SmsService::buildOfferVars($msgData, 'approver'));
    }

    flash('success', "Notification resent to {$approver}.");
    header('Location: index.php?page=offer'); exit;
}

// ── Generate coupons (superadmin) ─────────────────────────
function doGenerateCoupons(): void {
    $prefix   = mb_strtoupper(trim($_POST['prefix'] ?? ''));
    $length   = (int)($_POST['random_length'] ?? 8);
    $quantity = (int)($_POST['quantity'] ?? 0);

    if ($prefix === '' || $quantity < 1 || $quantity > 5000) {
        flash('error', 'Prefix is required and quantity must be 1-5000.');
        header('Location: index.php?page=generate_coupons'); exit;
    }
    if (!in_array($length, [6, 8, 10, 12], true)) {
        flash('error', 'Code length must be 6, 8, 10, or 12.');
        header('Location: index.php?page=generate_coupons'); exit;
    }
    // Offer label uses the canonical "{pct}% Discount" form so old and new
    // batches group together on the offer page. Prefix is just code-formatting
    // ("05", "10", "00") — convert it back to its discount %:
    //   "00" → 100  (special: 2-char prefix can't hold "100")
    //   "05" → 5,  "10" → 10, "25" → 25, etc. (strip leading zeros)
    if ($prefix === '00') {
        $pct = '100';
    } else {
        $pct = ltrim($prefix, '0');
        if ($pct === '') $pct = '0'; // edge-case: prefix was all zeros
    }
    $offer = $pct . '% Discount';

    $db = getDb();
    $st = $db->prepare("INSERT IGNORE INTO offer_coupons (Coupon, Offer) VALUES (?, ?)");
    $inserted = 0;
    $attempts = 0;
    $maxAttempts = $quantity * 3;
    $generatedCodes = [];

    while ($inserted < $quantity && $attempts < $maxAttempts) {
        $random = mb_strtoupper(substr(bin2hex(random_bytes($length)), 0, $length));
        $code = $prefix . $random;
        $st->execute([$code, $offer]);
        if ($st->rowCount() > 0) {
            $generatedCodes[] = $code;
            $inserted++;
        }
        $attempts++;
    }

    // Output CSV for POS import
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="coupons_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Coupon Code'], escape: '');
    foreach ($generatedCodes as $code) fputcsv($out, [$code], escape: '');
    fclose($out);
    exit;
}

// ── Offer form page ───────────────────────────────────────
function pageOffer(): void {
    $locations = getActiveLocations();
    $availability = CouponService::getAvailabilitySummary();
    $approvers = SmsService::getApprovers();
    $maxBill = (int)getSetting('MaxBillAmount', '500000');

    // Get employee phone from DB
    $phone = '';
    if (myCode()) {
        $st = getDb()->prepare("SELECT phone FROM employees WHERE employee_code = ?");
        $st->execute([myCode()]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) $phone = $row['phone'] ?? '';
    }
?>
<div class="page-header"><h2>Offer Coupon</h2></div>

<h3 style="font-size:14px;margin-bottom:8px">Available Coupons</h3>
<?php if ($availability): ?>
<div class="stats-grid" style="margin-bottom:18px">
    <?php foreach ($availability as $av): ?>
    <div class="stat-card">
        <div class="stat-val stat-green"><?= (int)$av['offer_count'] ?></div>
        <div class="stat-lbl"><?= h($av['Offer']) ?> — Available</div>
    </div>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div class="alert" style="margin-bottom:18px;padding:12px;background:#2a1a1a;border:1px solid #c0392b;border-radius:6px;color:#e74c3c;">
    No coupons available. Please contact superadmin to generate coupons.
</div>
<?php endif; ?>

<div class="form-card">
    <form method="POST">
        <input type="hidden" name="action" value="submit_offer">
        <div class="form-grid">
            <div class="form-group">
                <label>Employee Name</label>
                <input type="text" class="form-control" value="<?= h(myName()) ?>" readonly>
            </div>
            <div class="form-group">
                <label>Mobile Number <span class="required">*</span></label>
                <input type="text" name="mobno" class="form-control" value="<?= h($phone) ?>"
                       pattern="\d{10}" maxlength="10" required placeholder="10-digit mobile">
            </div>
            <div class="form-group">
                <label>Outlet <span class="required">*</span></label>
                <select name="outlet" class="form-control" required>
                    <option value="">— Select Outlet —</option>
                    <?php foreach ($locations as $loc): ?>
                    <option value="<?= h($loc['location_name']) ?>"><?= h($loc['location_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Offer Type <span class="required">*</span></label>
                <select name="offer" class="form-control" required>
                    <option value="">— Select Offer —</option>
                    <?php foreach ($availability as $av): ?>
                    <option value="<?= h($av['Offer']) ?>"><?= h($av['Offer']) ?> (<?= $av['offer_count'] ?> left)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Approver <span class="required">*</span></label>
                <select name="approve" class="form-control" required>
                    <option value="">— Select Approver —</option>
                    <?php foreach ($approvers as $appr): ?>
                    <option value="<?= h($appr['name']) ?>"><?= h($appr['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Bill Amount (Rs.) <span class="required">*</span></label>
                <input type="number" name="bill" class="form-control" min="1" max="<?= $maxBill ?>" required>
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <label>Remark</label>
                <input type="text" name="remark" class="form-control" maxlength="50" placeholder="Optional (max 50 chars)">
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Submit Offer</button>
        </div>
    </form>
</div>

<?php
    // Last 5 redeemed coupons by this employee
    $myCoupons = [];
    if (myCode()) {
        $st = getDb()->prepare(
            "SELECT id, Coupon, Offer, Name, Bill_Amount, Outlet, Approver, Remark, datestamp, timestamp
             FROM offer_coupons
             WHERE employee_code = ? AND is_redeemed = 1
             ORDER BY datestamp DESC, timestamp DESC
             LIMIT 10"
        );
        $st->execute([myCode()]);
        $myCoupons = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    if ($myCoupons):
        $tz = getSetting('AppTimezone', 'Asia/Kolkata');
        date_default_timezone_set($tz);
        $now = time();
?>
<h3 style="font-size:14px;margin:18px 0 8px">My Recent Coupons</h3>
<div class="table-wrap" data-stack>
    <table class="table">
        <thead>
            <tr>
                <th style="width:50px">#</th>
                <th>Date</th>
                <th>Offer Type</th>
                <th>Requester Name</th>
                <th>Coupon</th>
                <th>Bill Amount</th>
                <th>Outlet</th>
                <th>Approver</th>
                <th style="width:80px">Resend</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($myCoupons as $i => $mc):
            $redeemTime = strtotime($mc['datestamp'] . ' ' . $mc['timestamp']);
            $sameDay = date('Y-m-d') === $mc['datestamp'];
            $canResend = $sameDay && ($now - $redeemTime) >= 600;
        ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= $mc['datestamp'] ? date('d M Y', strtotime($mc['datestamp'])) : '—' ?></td>
                <td><?= h($mc['Offer']) ?></td>
                <td><?= h($mc['Name'] ?? '—') ?></td>
                <td><?= (int)$mc['id'] ?></td>
                <td>Rs.<?= number_format((float)$mc['Bill_Amount'], 2) ?></td>
                <td><?= h($mc['Outlet'] ?? '') ?></td>
                <td><?= h($mc['Approver'] ?? '') ?></td>
                <td>
                    <?php if ($canResend): ?>
                    <form method="POST" class="inline-form" onsubmit="return confirm('Resend notification to <?= h($mc['Approver'] ?? '') ?>?')">
                        <input type="hidden" name="action" value="resend_offer">
                        <input type="hidden" name="coupon_id" value="<?= (int)$mc['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-secondary">Resend</button>
                    </form>
                    <?php else: ?>
                    <span class="text-muted" style="font-size:11px">Wait 10m</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php }

// ── Generate coupons page (superadmin) ────────────────────
function pageGenerateCoupons(): void {
    $availability = CouponService::getAvailabilitySummary();
?>
<div class="page-header"><h2>Generate Coupons</h2></div>

<?php if ($availability): ?>
<div class="stats-grid" style="margin-bottom:18px">
    <?php foreach ($availability as $av): ?>
    <div class="stat-card">
        <div class="stat-val stat-green"><?= (int)$av['offer_count'] ?></div>
        <div class="stat-lbl"><?= h($av['Offer']) ?></div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="form-card">
    <form method="POST">
        <input type="hidden" name="action" value="generate_coupons">
        <div class="form-grid">
            <div class="form-group">
                <label>Prefix <span class="required">*</span></label>
                <input type="text" name="prefix" id="prefixInput" class="form-control"
                       maxlength="4" required placeholder="e.g. 05, 10, 00"
                       style="text-transform:uppercase">
                <span class="hint">2-digit code prefix. Discount stored as <code>{pct}% Discount</code> — <code>05</code>→5%, <code>10</code>→10%, <code>00</code>→100%.</span>
            </div>
            <div class="form-group">
                <label>Code Length</label>
                <select name="random_length" class="form-control">
                    <option value="6">6 characters</option>
                    <option value="8" selected>8 characters</option>
                    <option value="10">10 characters</option>
                    <option value="12">12 characters</option>
                </select>
            </div>
            <div class="form-group">
                <label>Quantity <span class="required">*</span></label>
                <input type="number" name="quantity" class="form-control"
                       min="1" max="5000" required placeholder="e.g. 100">
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Generate Coupons</button>
        </div>
    </form>
</div>
<?php }

// ── Coupon redeemed page (superadmin + store managers) ───
// Scope:
//   • Superadmin → every redeemed coupon, every outlet.
//   • Anyone else with txn_coupon_redeemed → only coupons whose
//     offer_coupons.Outlet matches the location_name of their
//     self-claim location (employees.location_id → locations.location_name).
//   • txn_coupon_redeemed but no self-claim location → empty view with hint.
function pageCouponRedeemed(): void {
    if (!isSuperadmin() && !hasTxn('coupon_redeemed')) {
        flash('error', 'Access denied.');
        header('Location: index.php'); exit;
    }

    $db        = getDb();
    $isAdmin   = isSuperadmin();
    $myLocId   = $isAdmin ? 0 : myLocationId();
    $myLocName = '';
    $rows      = [];
    $noLocation = false;

    if ($isAdmin) {
        // Full-fleet view.
        $st = $db->prepare(
            "SELECT id, Name, Mobile, Offer, Bill_Amount, Outlet, Approver, Remark,
                    datestamp, timestamp, employee_code
             FROM offer_coupons
             WHERE is_redeemed = 1
             ORDER BY datestamp DESC, timestamp DESC"
        );
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($myLocId > 0) {
        // Look up the manager's location name, then filter on the
        // string `Outlet` column (legacy schema — no FK on offer_coupons).
        $ls = $db->prepare('SELECT location_name FROM locations WHERE location_id = ?');
        $ls->execute([$myLocId]);
        $myLocName = (string)$ls->fetchColumn();

        if ($myLocName !== '') {
            $st = $db->prepare(
                "SELECT id, Name, Mobile, Offer, Bill_Amount, Outlet, Approver, Remark,
                        datestamp, timestamp, employee_code
                 FROM offer_coupons
                 WHERE is_redeemed = 1 AND Outlet = ?
                 ORDER BY datestamp DESC, timestamp DESC"
            );
            $st->execute([$myLocName]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        $noLocation = true;
    }

    $scopeLabel = $isAdmin
        ? 'All outlets'
        : ($myLocName !== '' ? $myLocName : '—');
?>
<div class="page-header">
    <h2>Coupon Redeemed</h2>
    <div class="page-sub" style="font-size:12px;color:#6b7280;margin-top:4px">
        Outlet: <strong><?= h($scopeLabel) ?></strong>
        <?php if ($rows): ?>
            · <?= count($rows) ?> redeemed coupon<?= count($rows) === 1 ? '' : 's' ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($noLocation): ?>
<div class="table-wrap">
    <p class="empty-row">
        Your account isn't tied to a self-claim location yet, so there are no
        outlet-scoped coupons to show. Claim a location from
        <a href="?page=my_location">My Location</a> first.
    </p>
</div>
<?php elseif (empty($rows)): ?>
<div class="table-wrap"><p class="empty-row">No redeemed coupons found for this outlet.</p></div>
<?php else: ?>
<div class="table-wrap" data-stack>
    <table class="table">
        <thead>
            <tr>
                <th>#</th>
                <th>Date</th>
                <th>ID</th>
                <th>Offer</th>
                <th>Requester Name</th>
                <th>Mobile</th>
                <th>Bill Amt</th>
                <?php if ($isAdmin): ?><th>Outlet</th><?php endif; ?>
                <th>Approver</th>
                <th>Remark</th>
                <th>Employee</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $i => $r): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= $r['datestamp'] ? date('d M Y', strtotime($r['datestamp'])) : '—' ?>
                    <?php if ($r['timestamp']): ?><br><small class="text-muted"><?= $r['timestamp'] ?></small><?php endif; ?>
                </td>
                <td><?= (int)$r['id'] ?></td>
                <td><?= h($r['Offer']) ?></td>
                <td><?= h($r['Name'] ?? '—') ?></td>
                <td><?= h($r['Mobile'] ?? '—') ?></td>
                <td><?= $r['Bill_Amount'] ? 'Rs.' . number_format((float)$r['Bill_Amount'], 2) : '—' ?></td>
                <?php if ($isAdmin): ?><td><?= h($r['Outlet'] ?? '—') ?></td><?php endif; ?>
                <td><?= h($r['Approver'] ?? '—') ?></td>
                <td><?= h($r['Remark'] ?? '—') ?></td>
                <td><code><?= h($r['employee_code'] ?? '—') ?></code></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php }

// ── Generate vouchers (batch of 999) ─────────────────────
function doGenerateVouchers(): void {
    $prefix    = mb_strtoupper(trim($_POST['prefix'] ?? ''));
    $total     = (int)($_POST['total'] ?? 0);
    $codeLen   = (int)($_POST['code_length'] ?? 8);

    if (!preg_match('/^[A-Z]{2}$/', $prefix)) {
        flash('error', 'Prefix must be exactly 2 letters.');
        header('Location: index.php?page=generate_vouchers'); exit;
    }
    if (!in_array($codeLen, [6, 8, 10, 12], true)) {
        flash('error', 'Code length must be 6, 8, 10, or 12.');
        header('Location: index.php?page=generate_vouchers'); exit;
    }
    if ($total < 999 || $total > 9990 || $total % 999 !== 0) {
        flash('error', 'Total must be a multiple of 999 (max 9990).');
        header('Location: index.php?page=generate_vouchers'); exit;
    }

    $batches = $total / 999;
    $db = getDb();

    // Find next batch number for this prefix
    $st = $db->prepare("SELECT COALESCE(MAX(batch_number), 0) FROM vouchers WHERE prefix = ?");
    $st->execute([$prefix]);
    $startBatch = (int)$st->fetchColumn() + 1;

    $ins = $db->prepare("INSERT IGNORE INTO vouchers (code, batch_number, prefix) VALUES (?, ?, ?)");
    $allCodes = [];

    for ($b = $startBatch; $b < $startBatch + $batches; $b++) {
        $batchPrefix = $prefix . $b;
        $randomLen   = $codeLen - mb_strlen($batchPrefix);
        if ($randomLen < 2) $randomLen = 2;

        $inserted = 0;
        $attempts = 0;
        $maxAttempts = 999 * 3;

        while ($inserted < 999 && $attempts < $maxAttempts) {
            $random = mb_strtoupper(substr(bin2hex(random_bytes($randomLen)), 0, $randomLen));
            $code   = $batchPrefix . $random;
            $ins->execute([$code, $b, $prefix]);
            if ($ins->rowCount() > 0) {
                $allCodes[] = $code;
                $inserted++;
            }
            $attempts++;
        }
    }

    // Output CSV
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="vouchers_' . $prefix . '_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Voucher Code'], escape: '');
    foreach ($allCodes as $code) fputcsv($out, [$code], escape: '');
    fclose($out);
    exit;
}

// ── Generate vouchers page ───────────────────────────────
function pageGenerateVouchers(): void {
    $db = getDb();
    $batches = $db->query(
        "SELECT prefix, batch_number, COUNT(*) AS cnt, MIN(created_at) AS created
         FROM vouchers GROUP BY prefix, batch_number ORDER BY prefix, batch_number"
    )->fetchAll(PDO::FETCH_ASSOC);

    $totalByPrefix = $db->query(
        "SELECT prefix, COUNT(*) AS total FROM vouchers GROUP BY prefix ORDER BY prefix"
    )->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="page-header"><h2>Generate Vouchers</h2></div>

<?php if ($totalByPrefix): ?>
<h3 style="font-size:14px;margin-bottom:8px">Existing Vouchers</h3>
<div class="stats-grid" style="margin-bottom:18px">
    <?php foreach ($totalByPrefix as $tp): ?>
    <div class="stat-card">
        <div class="stat-val"><?= (int)$tp['total'] ?></div>
        <div class="stat-lbl"><?= h($tp['prefix']) ?> — Total</div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="form-card" style="margin-bottom:20px">
    <div class="form-section-title">Generate New Vouchers</div>
    <form method="POST">
        <input type="hidden" name="action" value="generate_vouchers">
        <div class="form-grid">
            <div class="form-group">
                <label>Prefix (2 letters) <span class="required">*</span></label>
                <input type="text" name="prefix" class="form-control" maxlength="2"
                       pattern="[A-Za-z]{2}" required placeholder="e.g. KC"
                       style="text-transform:uppercase">
            </div>
            <div class="form-group">
                <label>Code Length <span class="required">*</span></label>
                <select name="code_length" class="form-control" required>
                    <option value="6">6 characters</option>
                    <option value="8" selected>8 characters</option>
                    <option value="10">10 characters</option>
                    <option value="12">12 characters</option>
                </select>
            </div>
            <div class="form-group">
                <label>Total Vouchers <span class="required">*</span> <span class="hint">multiple of 999, max 9990</span></label>
                <select name="total" class="form-control" required>
                    <option value="">— Select —</option>
                    <option value="999">999 (1 batch)</option>
                    <option value="1998">1998 (2 batches)</option>
                    <option value="2997">2997 (3 batches)</option>
                    <option value="3996">3996 (4 batches)</option>
                    <option value="4995">4995 (5 batches)</option>
                    <option value="5994">5994 (6 batches)</option>
                    <option value="6993">6993 (7 batches)</option>
                    <option value="7992">7992 (8 batches)</option>
                    <option value="8991">8991 (9 batches)</option>
                    <option value="9990">9990 (10 batches)</option>
                </select>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Generate &amp; Download CSV</button>
        </div>
    </form>
</div>

<?php if ($batches): ?>
<h3 style="font-size:14px;margin-bottom:8px">Batch History</h3>
<div class="table-wrap" data-stack>
    <table class="table">
        <thead>
            <tr>
                <th>Prefix</th>
                <th>Batch #</th>
                <th>Count</th>
                <th>Created</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($batches as $b): ?>
            <tr>
                <td><strong><?= h($b['prefix']) ?></strong></td>
                <td><?= (int)$b['batch_number'] ?></td>
                <td><?= (int)$b['cnt'] ?></td>
                <td class="text-muted"><?= date('d M Y H:i', strtotime($b['created'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php }
