<?php
// =========================================================
// Cron entrypoint — negative-feedback escalation, hourly.
// Standalone (no login/session). Wire to an hourly cPanel cron:
//   0 * * * *  curl -s "https://wp.aromen.biz/cron/run_feedback_escalation.php?token=XXX"
// where XXX matches the 'CronToken' system setting.
//
// A complaint still Open (no resolution submitted) longer than
// FeedbackEscalateHours is escalated: the outlet's Operation Manager and
// every FeedbackCloserCodes employee are emailed, once per wait. The
// Negative Feedback list page runs the same check lazily, and each
// complaint is claimed before it is mailed, so the two never double up.
// =========================================================
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../config.php';                 // getDb(), getSetting()
require_once __DIR__ . '/../modules/auth.php';
require_once __DIR__ . '/../modules/helpers.php';        // sendSmtpEmailQuiet(), getEmployeeEmails()
require_once __DIR__ . '/../modules/location_managers.php';
require_once __DIR__ . '/../modules/feedback.php';       // fbRunEscalation()

header('Content-Type: text/plain; charset=utf-8');

$token    = (string)($_GET['token'] ?? ($argv[1] ?? ''));
$expected = function_exists('getSetting') ? (string)getSetting('CronToken', '') : '';

if ($expected === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    echo "Forbidden — missing or invalid token.\n";
    exit;
}

$n = fbRunEscalation();
echo 'OK escalated=' . $n . ' at ' . date('Y-m-d H:i:s') . "\n";
