<?php
// =========================================================
// Cron entrypoint — daily inward-barcode expiry email.
// Standalone (no login/session). Wire to a daily cPanel cron:
//   curl -s "https://wp.aromen.biz/cron/run_barcode_expiry.php?token=XXX"
// where XXX matches the 'CronToken' system setting (set it in Settings).
// A once-per-day lazy fallback in index.php also covers this if cron
// isn't configured.
//
// Reports barcodes expiring today plus every already-expired barcode the
// validator hasn't yet confirmed removed from the ERP. Rows included in a
// send are stamped with last_alert_date, so re-running the same day is a
// no-op while tomorrow's run picks the outstanding ones back up.
//
// Recipients come from the 'InwardBarcodeNotifyEmails' system setting.
// =========================================================
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../config.php';               // getDb(), getSetting()
require_once __DIR__ . '/../modules/auth.php';         // isSuperadmin()/hasTxn() used by the module's gates
require_once __DIR__ . '/../modules/helpers.php';      // sendSmtpEmailQuiet()
require_once __DIR__ . '/../modules/inward_items.php'; // inwSendDailyExpiryDigest()

header('Content-Type: text/plain; charset=utf-8');

$token    = (string)($_GET['token'] ?? ($argv[1] ?? ''));
$expected = function_exists('getSetting') ? (string)getSetting('CronToken', '') : '';

if ($expected === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    echo "Forbidden — missing or invalid token.\n";
    exit;
}

$sent = inwSendDailyExpiryDigest();
echo 'OK sent=' . $sent . ' at ' . date('Y-m-d H:i:s') . "\n";
