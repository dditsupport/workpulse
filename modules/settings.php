<?php
// =========================================================
// System Settings CRUD + page renderer
// =========================================================

function doTestSmtp(): void {
    ob_start();
    try {
        $toEmail = trim($_POST['test_email'] ?? '');
        if (!$toEmail) { flash('error', 'Enter a test email address.'); header('Location: index.php?page=settings'); exit; }

        $host = getSetting('SmtpHost');
        $port = getSetting('SmtpPort', '465');
        $user = getSetting('SmtpUser');
        $pass = getSetting('SmtpPass');

        if (!$host || !$user || !$pass) {
            $msg = 'SMTP not configured: ';
            if (!$host) $msg .= 'SmtpHost empty. ';
            if (!$user) $msg .= 'SmtpUser empty. ';
            if (!$pass) $msg .= 'SmtpPass empty. ';
            flash('error', $msg);
            header('Location: index.php?page=settings'); exit;
        }

        $result = @sendSmtpEmail($toEmail, 'Work Pulse — SMTP Test', '<h2>SMTP is working!</h2><p>This is a test email from Work Pulse settings.</p>');
        if ($result) {
            flash('success', "Test email sent to {$toEmail}.");
        } else {
            flash('error', "SMTP send failed. Host={$host}, Port={$port}, User={$user}. Check credentials or firewall.");
        }
    } catch (Exception $e) {
        flash('error', 'SMTP error: ' . $e->getMessage());
    }
    ob_end_clean();
    header('Location: index.php?page=settings'); exit;
}

function doSaveSettings(): void {
    $keys = $_POST['keys']   ?? [];
    $vals = $_POST['values'] ?? [];
    if (empty($keys)) { flash('error', 'Nothing to save.'); header('Location: index.php?page=settings'); exit; }
    try {
        $st = getDb()->prepare('UPDATE system_settings SET setting_value=? WHERE setting_key=?');
        $saved = 0;
        foreach ($keys as $i => $key) {
            $key = trim($key);
            $val = trim($vals[$i] ?? '');
            if ($key === '') continue;
            $st->execute([$val, $key]);
            $saved++;
        }
        flash('success', "{$saved} setting(s) saved.");
    } catch (Exception $e) { flash('error', $e->getMessage()); }
    header('Location: index.php?page=settings'); exit;
}

function getSystemSettings(): array {
    try { return getDb()->query('SELECT id, setting_key, setting_value, description FROM system_settings ORDER BY id')->fetchAll(); }
    catch (Exception $e) { return []; }
}

// ── Page: Settings ───────────────────────────────────────
function pageSettings(): void {
    $settings = getSystemSettings();
    $sensitiveKeys = ['AdminPassword','SuperadminPassword','SmtpPass','Msg91AuthKey','sms_auth_key'];
    $groups = [
        'Authentication' => ['AdminPassword','SuperadminPassword','EmployeePortalEnabled'],
        'Email Settings' => ['SmtpHost','SmtpPort','SmtpUser','SmtpPass','SmtpFromEmail','SmtpFromName'],
        // Biometric / Attendance: device-OTP SMS (MSG91 v5 flow + legacy), OTP
        // generation, fingerprint match thresholds, punch interval.
        'Biometric' => [
            'Msg91AuthKey','Msg91SenderId','Msg91TemplateId','Msg91OtpFlowId','Msg91V5Url',
            'Msg91ApiUrl','Msg91Route','Msg91OtpTemplate',
            'OtpLength','OtpExpiryMinutes','OtpMinSendIntervalSeconds','ShiftCutoffHour',
            'MatchThreshold_Attendance','MatchThreshold_EnrollDuplicate','MinPunchIntervalMinutes',
        ],
        // Discount SMS: corporate coupon notifications (MSG91 v5 offer flow + legacy).
        'Discount SMS' => [
            'sms_auth_key','sms_sender_id','sms_dlt_id','sms_offer_flow_id','sms_v5_url',
            'sms_api_url','sms_route','sms_admin_mobile','email_admin',
            'CouponNotifyChannel','Approvers','MaxBillAmount',
        ],
        // Policy: consent OTP SMS (MSG91 v5 policy flow + legacy template/DLT) + verify cap.
        'Policy' => ['sms_policy_otp_flow_id','sms_policy_otp_template','sms_policy_otp_dlt_id','OtpMaxVerifyAttempts'],
        'Price Variation' => ['PriceSlotsActive','PriceVariationNotifyEmails'],
        'Punch Requests' => ['PunchRequestNotifyHR','PunchRequestNotifyOps'],
        'Location'       => ['LocationClaimRequiresPunch'],
        'General'        => ['AppTimezone'],
    ];
?>
<div class="page-header"><h2>System Settings</h2></div>

<?php if (empty($settings)): ?>
<div class="alert alert-error">No settings found in system_settings table.</div>
<?php else: ?>
<form method="POST">
    <input type="hidden" name="action" value="save_settings">
    <div class="form-card" style="max-width:900px">
        <?php
        $grouped = [];
        foreach ($groups as $grpName => $keys)
            foreach ($settings as $s)
                if (in_array($s['setting_key'], $keys))
                    $grouped[$grpName][] = $s;

        $allGroupedKeys = array_merge(...array_values($groups));
        $ungrouped = array_values(array_filter($settings, fn($s) => !in_array($s['setting_key'], $allGroupedKeys)));
        if ($ungrouped) $grouped['Other'] = $ungrouped;

        foreach ($grouped as $grpName => $rows):
            if (empty($rows)) continue;
        ?>
        <div class="form-section-title"><?= h($grpName) ?></div>
        <div class="settings-grid">
        <?php foreach ($rows as $s):
            $isSensitive = in_array($s['setting_key'], $sensitiveKeys);
            $isLong      = strlen($s['setting_value']) > 60 || $s['setting_key'] === 'Msg91OtpTemplate';
        ?>
            <div class="form-group <?= $isLong ? 'settings-full' : '' ?>">
                <label>
                    <?= h($s['setting_key']) ?>
                    <?php if ($s['description']): ?>
                    <span class="hint"> — <?= h($s['description']) ?></span>
                    <?php endif; ?>
                </label>
                <input type="hidden" name="keys[]" value="<?= h($s['setting_key']) ?>">
                <?php if ($isLong): ?>
                    <textarea name="values[]" class="form-control" rows="2"
                              style="resize:vertical"><?= h($s['setting_value']) ?></textarea>
                <?php else: ?>
                    <input type="text" name="values[]" class="form-control"
                           value="<?= h($s['setting_value']) ?>" autocomplete="off">
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        <div class="form-actions" style="margin-top:24px">
            <button type="submit" class="btn btn-primary">💾 Save All Settings</button>
        </div>
    </div>
</form>

<!-- SMTP Test -->
<div class="form-card" style="max-width:900px;margin-top:24px">
    <div class="form-section-title">Test SMTP Email</div>
    <form method="POST" style="display:flex;gap:12px;align-items:end">
        <input type="hidden" name="action" value="test_smtp">
        <div class="form-group" style="flex:1;margin:0">
            <label>Recipient Email</label>
            <input type="email" name="test_email" class="form-control" placeholder="your@email.com" required>
        </div>
        <button type="submit" class="btn btn-secondary" style="height:38px">Send Test Email</button>
    </form>
</div>
<?php endif; ?>
<?php
}
