<?php
// =========================================================
// Cron entrypoint — daily odd-punch alert, 07:00.
// Standalone (no login/session). Wire to a daily cPanel cron at 7 AM:
//   0 7 * * *  curl -s "https://wp.aromen.biz/cron/run_odd_punch_alert.php?token=XXX"
// where XXX matches the 'CronToken' system setting (set it in Settings).
// A lazy fallback in index.php also covers this if cron isn't configured.
//
// The retail shift closes at (ShiftCutoffHour - 1):59:59 — 05:59:59 on the
// standard 6 o'clock cutoff — so by 07:00 the previous shift day is final and
// its punches can be audited. A complete in→out trace always has an EVEN
// number of punches (2, 4, 6, 8…); an odd count means someone forgot to punch
// and the day cannot be traced. Those are the days this email lists.
//
// System 'auto_close' placeholders (the synthesised 05:59:59 OUT) are excluded
// — counting them would pad an odd day back to even and hide the miss.
//
// Two mails go out per run:
//   * a CONSOLIDATED digest to 'PunchRequestNotifyHR' + 'PunchRequestNotifyOps';
//   * one mail PER LOCATION to that store's locations.contact_email, carrying
//     only the people who have claimed that location (employees.location_id).
// Employees who never claimed a location ride the consolidated digest only.
// Nothing is sent on a morning where every trace is even.
//
// Optional ?date=YYYY-MM-DD re-runs the digest for an earlier shift day.
// =========================================================
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../config.php';             // getDb(), getSetting()
require_once __DIR__ . '/../modules/auth.php';       // gates used by the module
require_once __DIR__ . '/../modules/helpers.php';    // getAttendance(), sendSmtpEmailQuiet()
require_once __DIR__ . '/../modules/attendance.php'; // attSendOddPunchDigest()

header('Content-Type: text/plain; charset=utf-8');

$token    = (string)($_GET['token'] ?? ($argv[1] ?? ''));
$expected = function_exists('getSetting') ? (string)getSetting('CronToken', '') : '';

if ($expected === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    echo "Forbidden — missing or invalid token.\n";
    exit;
}

// No ?date= — the scheduled case. Claims the shift day first, so that a run
// here and the lazy fallback in index.php cannot both mail the same morning.
$date = (string)($_GET['date'] ?? ($argv[2] ?? ''));
$report = null;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = attOddPunchTargetDay();
    $days = attRunOddPunchAlert($report);
    if ($days < 0) {
        echo 'SKIP shift=' . $date . ' — this shift day was already claimed by an earlier run.' . "\n"
           . 'Note the claim is written BEFORE the digest runs, so this does NOT mean mail went out.' . "\n"
           . 'Re-send it with &date=' . $date . ', or see uploads/odd_punch.log for what the first run did.' . "\n";
        exit;
    }
} else {
    // Explicit date = someone asking for this day again on purpose. Re-send.
    $days = attSendOddPunchDigest($date, $report);
}

echo 'OK shift=' . $date . ' odd_days=' . $days . ' at ' . date('Y-m-d H:i:s') . "\n";

// Say who it went to. Without this the endpoint reports that it ran but never
// what it did, which makes "no mail arrived" impossible to tell apart from
// "there was nothing to send".
foreach ($report['notes'] ?? [] as $n) echo '  · ' . $n . "\n";
echo '  ' . count($report['sent'] ?? []) . " mail(s) queued. Delivery itself is logged to the PHP error log\n"
   . "  by SmtpQueue (lines starting 'SmtpQueue:'), since the queue drains after this response.\n";
