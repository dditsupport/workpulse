<?php
// =========================================================
// Cron entrypoint — create tickets for due schedules.
// Standalone (no login/session). Wire to a daily cPanel cron:
//   curl -s "https://wp.aromen.biz/cron/run_ticket_schedules.php?token=XXX"
// where XXX matches the 'CronToken' system setting (set it in Settings).
// A once-per-day lazy fallback in index.php also covers this if cron
// isn't configured.
// =========================================================
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../config.php';            // getDb(), getSetting()
require_once __DIR__ . '/../modules/helpers.php';   // mailer + employee/department helpers
require_once __DIR__ . '/../modules/issues.php';    // notifyIssue()
require_once __DIR__ . '/../modules/ticket_scheduler.php';

header('Content-Type: text/plain; charset=utf-8');

$token    = (string)($_GET['token'] ?? ($argv[1] ?? ''));
$expected = function_exists('getSetting') ? (string)getSetting('CronToken', '') : '';

if ($expected === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    echo "Forbidden — missing or invalid token.\n";
    exit;
}

$created = processDueTicketSchedules();
echo 'OK created=' . $created . ' at ' . date('Y-m-d H:i:s') . "\n";
