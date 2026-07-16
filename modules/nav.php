<?php
// =========================================================
// Navigation, shell renderer, login page
// Transaction-level access (roles.txn_*) — employees.role_id → roles
// =========================================================

function roleLabel(): string {
    if (isSuperadmin()) return 'Super Admin';
    return h(myName());
}

// Returns nav as a list of groups. Each entry is
//   ['group' => 'Label', 'items' => [...]]
// where items have the usual ['page','icon','label'] shape. The renderer
// decides whether to show group headings (long lists, collapsible) or a
// flat list (short lists that fit without scrolling).
function buildNav(): array {
    if (isSuperadmin()) return [
        ['group' => 'Administration', 'items' => [
            ['page' => 'dashboard',         'icon' => navIcon('dashboard'),   'label' => 'Dashboard'],
            ['page' => 'roles',             'icon' => navIcon('roles'),       'label' => 'Roles'],
            ['page' => 'devices',           'icon' => navIcon('devices'),     'label' => 'Devices'],
            ['page' => 'manage_passwords',  'icon' => navIcon('passwords'),   'label' => 'Passwords'],
            ['page' => 'settings',          'icon' => navIcon('settings'),    'label' => 'Settings'],
            ['page' => 'dependencies',      'icon' => navIcon('dependency'),  'label' => 'Dependencies'],
        ]],
        ['group' => 'HRMS', 'items' => [
            ['page' => 'departments',       'icon' => navIcon('departments'), 'label' => 'Departments'],
            ['page' => 'employees',         'icon' => navIcon('employees'),   'label' => 'Employees'],
            ['page' => 'locations',         'icon' => navIcon('locations'),   'label' => 'Locations'],
            ['page' => 'attendance',        'icon' => navIcon('attendance'),  'label' => 'Attendance'],
            ['page' => 'approve_punches',   'icon' => navIcon('punch'),       'label' => 'Punch Requests'],
            ['page' => 'failed_punches',    'icon' => navIcon('alert'),       'label' => 'Punch Issues'],
        ]],
        ['group' => 'Ticket Management', 'items' => [
            ['page' => 'issues',            'icon' => navIcon('issues'),      'label' => 'Tickets'],
            ['page' => 'issue_comments',    'icon' => navIcon('comments'),    'label' => 'Comments Feed'],
            ['page' => 'issue_summary',     'icon' => navIcon('summary'),     'label' => 'Ticket Summary'],
            ['page' => 'issue_overview',    'icon' => navIcon('dashboard'),   'label' => 'Ticket Overview'],
            ['page' => 'manage_categories', 'icon' => navIcon('categories'),  'label' => 'Ticket Categories'],
            ['page' => 'delete_issues',     'icon' => navIcon('alert'),       'label' => 'Delete Tickets'],
        ]],
        ['group' => 'Discount', 'items' => [
            ['page' => 'offer',             'icon' => navIcon('offer'),       'label' => 'Offer Coupon'],
            ['page' => 'coupon_redeemed',   'icon' => navIcon('coupon_used'), 'label' => 'Coupon Redeemed'],
            ['page' => 'generate_coupons',  'icon' => navIcon('coupon'),      'label' => 'Generate Coupons'],
            ['page' => 'generate_vouchers', 'icon' => navIcon('voucher'),     'label' => 'Generate Vouchers'],
        ]],
        ['group' => 'Task Management', 'items' => [
            ['page' => 'checklist',          'icon' => navIcon('checklist'),   'label' => 'Checklists'],
            ['page' => 'manage_tasks',       'icon' => navIcon('tasks'),       'label' => 'Manage Checklists'],
            ['page' => 'checklist_report',   'icon' => navIcon('report'),      'label' => 'Checklist Report'],
            ['page' => 'checklist_overview', 'icon' => navIcon('dashboard'),   'label' => 'Checklist Overview'],
            ['page' => 'checklist_audit',    'icon' => navIcon('task_check'),  'label' => 'Checklist Audit'],
            ['page' => 'checklist_validate', 'icon' => navIcon('task_check'),  'label' => 'Validate Checklist'],
        ]],
        ['group' => 'Audits', 'items' => [
            ['page' => 'audit_list',        'icon' => navIcon('audit_list'),   'label' => 'Audit List'],
            ['page' => 'audit_summary',     'icon' => navIcon('summary'),      'label' => 'Audit Summary'],
            ['page' => 'audit_categories',  'icon' => navIcon('categories'),   'label' => 'Audit Categories'],
            ['page' => 'audit_parameters',  'icon' => navIcon('audit_param'),  'label' => 'Audit Parameters'],
            ['page' => 'audit_templates',   'icon' => navIcon('audit_tpl'),    'label' => 'Audit Templates'],
        ]],
        ['group' => 'Store Operations', 'items' => [
            ['page' => 'outlet_directory',     'icon' => navIcon('outlet'),  'label' => 'Outlet Directory'],
            ['page' => 'shelf_life',           'icon' => navIcon('shelf'),   'label' => 'Shelf Life'],
            ['page' => 'store_hours',          'icon' => navIcon('clock'),   'label' => 'Store Hours'],
            ['page' => 'price_tags',           'icon' => navIcon('tag'),     'label' => 'Price Tags'],
            ['page' => 'transactions',         'icon' => navIcon('summary'), 'label' => 'Banking Cash Deposit'],
            ['page' => 'transactions_report',  'icon' => navIcon('report'),  'label' => 'Banking Cash Deposit Report'],
        ]],
        ['group' => 'Policy & Violation', 'items' => [
            ['page' => 'policies',                'icon' => navIcon('audit_list'),  'label' => 'Policies'],
            ['page' => 'policy_admin_list',       'icon' => navIcon('categories'),  'label' => 'Policy Admin'],
            ['page' => 'policy_consent_dashboard','icon' => navIcon('summary'),     'label' => 'Consent Dashboard'],
            ['page' => 'violations',              'icon' => navIcon('alert'),       'label' => 'Violations'],
            ['page' => 'violation_counter_reset', 'icon' => navIcon('audit_param'), 'label' => 'Reset Counter'],
            ['page' => 'violation_categories',    'icon' => navIcon('categories'),  'label' => 'Violation Categories'],
        ]],
        ['group' => 'Price Variation', 'items' => [
            ['page' => 'price_variations', 'icon' => navIcon('summary'), 'label' => 'Variations'],
            ['page' => 'price_list',       'icon' => navIcon('tag'),     'label' => 'Master Price List'],
        ]],
    ];

    // ── Transaction-level nav for employees ──
    // Each $group local is built only with items the user can reach. Empty
    // groups are dropped at the end so the sidebar never shows a heading
    // with nothing under it.
    $admin = [];
    if (hasTxn('dashboard'))        $admin[] = ['page' => 'dashboard',        'icon' => navIcon('dashboard'),   'label' => 'Dashboard'];
    if (hasTxn('dept_roles'))       $admin[] = ['page' => 'roles',            'icon' => navIcon('roles'),       'label' => 'Roles'];
    if (hasTxn('devices'))          $admin[] = ['page' => 'devices',          'icon' => navIcon('devices'),     'label' => 'Devices'];
    if (hasTxn('manage_passwords')) $admin[] = ['page' => 'manage_passwords', 'icon' => navIcon('passwords'),   'label' => 'Passwords'];
    if (hasTxn('settings'))         $admin[] = ['page' => 'settings',         'icon' => navIcon('settings'),    'label' => 'Settings'];
    if (hasTxn('dependencies'))     $admin[] = ['page' => 'dependencies',     'icon' => navIcon('dependency'),  'label' => 'Dependencies'];

    // My Account — always shown
    $myAccount = [
        ['page' => 'mypunches',       'icon' => navIcon('clock'),     'label' => 'My Punches'],
        ['page' => 'punch_request',   'icon' => navIcon('punch'),     'label' => 'Missing Punch'],
        ['page' => 'profile',         'icon' => navIcon('account'),   'label' => 'My Profile'],
        ['page' => 'my_location',     'icon' => navIcon('locations'), 'label' => 'My Location'],
        ['page' => 'change_password', 'icon' => navIcon('key'),       'label' => 'Change Password'],
    ];

    // Time Tracking — its own module. My Time + Tasks are personal (every
    // employee), the cross-employee report is gated by txn_time_report.
    $timeTrack = [
        ['page' => 'my_time',    'icon' => navIcon('clock'),  'label' => 'My Time'],
        ['page' => 'time_tasks', 'icon' => navIcon('tasks'),  'label' => 'Tasks'],
    ];
    if (hasTxn('time_report')) {
        $timeTrack[] = ['page' => 'time_report', 'icon' => navIcon('report'), 'label' => 'Time Tracking Report'];
    }

    // Departments lives under HRMS now (it's an org-data concern, not an
    // admin concern).
    $hrms = [];
    if (hasTxn('departments'))     $hrms[] = ['page' => 'departments',     'icon' => navIcon('departments'), 'label' => 'Departments'];
    if (hasTxn('employees'))       $hrms[] = ['page' => 'employees',       'icon' => navIcon('employees'),   'label' => 'Employees'];
    if (hasTxn('locations'))       $hrms[] = ['page' => 'locations',       'icon' => navIcon('locations'),   'label' => 'Locations'];
    if (hasTxn('attendance'))      $hrms[] = ['page' => 'attendance',      'icon' => navIcon('attendance'),  'label' => 'Attendance'];
    if (hasTxn('approve_punches')) $hrms[] = ['page' => 'approve_punches', 'icon' => navIcon('punch'),       'label' => 'Punch Requests'];
    if (hasTxn('failed_punches'))  $hrms[] = ['page' => 'failed_punches',  'icon' => navIcon('alert'),       'label' => 'Punch Issues'];

    $issues = [];
    if (hasTxn('issues'))            $issues[] = ['page' => 'issues',            'icon' => navIcon('issues'),      'label' => 'Tickets'];
    if (hasTxn('issue_comments'))    $issues[] = ['page' => 'issue_comments',    'icon' => navIcon('comments'),    'label' => 'Comments Feed'];
    if (hasTxn('issue_summary'))     $issues[] = ['page' => 'issue_summary',     'icon' => navIcon('summary'),     'label' => 'Ticket Summary'];
    if (hasTxn('issues') || hasTxn('issue_summary')) {
        $issues[] = ['page' => 'issue_overview', 'icon' => navIcon('dashboard'), 'label' => 'Ticket Overview'];
    }
    if (hasTxn('manage_categories')) $issues[] = ['page' => 'manage_categories', 'icon' => navIcon('categories'),  'label' => 'Ticket Categories'];
    if (hasTxn('ticket_scheduler'))  $issues[] = ['page' => 'ticket_schedules',  'icon' => navIcon('clock'),       'label' => 'Ticket Scheduler'];

    $discount = [];
    if (hasTxn('offer'))             $discount[] = ['page' => 'offer',             'icon' => navIcon('offer'),       'label' => 'Offer Coupon'];
    if (hasTxn('coupon_redeemed'))   $discount[] = ['page' => 'coupon_redeemed',   'icon' => navIcon('coupon_used'), 'label' => 'Coupon Redeemed'];
    if (hasTxn('generate_coupons'))  $discount[] = ['page' => 'generate_coupons',  'icon' => navIcon('coupon'),      'label' => 'Generate Coupons'];
    if (hasTxn('generate_vouchers')) $discount[] = ['page' => 'generate_vouchers', 'icon' => navIcon('voucher'),     'label' => 'Generate Vouchers'];

    $tasks = [];
    $myChkCode = function_exists('myCode') ? myCode() : '';
    $canSeeHub = hasTxn('checklist') || (function_exists('chkUserHasAssignment') && chkUserHasAssignment($myChkCode));
    $canValidate = hasTxn('checklist_validate') || (function_exists('chkUserHasValidation') && chkUserHasValidation($myChkCode));
    if ($canSeeHub)                 $tasks[] = ['page' => 'checklist',        'icon' => navIcon('checklist'),  'label' => 'Checklists'];
    if (canManageAnyChecklist()) $tasks[] = ['page' => 'manage_tasks',     'icon' => navIcon('tasks'),      'label' => 'Manage Checklists'];
    if (hasTxn('checklist_report')) $tasks[] = ['page' => 'checklist_report', 'icon' => navIcon('report'),     'label' => 'Checklist Report'];
    if (hasTxn('checklist_report')) $tasks[] = ['page' => 'checklist_overview','icon' => navIcon('dashboard'),  'label' => 'Checklist Overview'];
    if (hasTxn('checklist_audit'))  $tasks[] = ['page' => 'checklist_audit',  'icon' => navIcon('task_check'), 'label' => 'Checklist Audit'];
    if ($canValidate)               $tasks[] = ['page' => 'checklist_validate', 'icon' => navIcon('task_check'), 'label' => 'Validate Checklist'];

    // Audits — role-gated. Location owners (employees with a location_id but
    // no audit_* flag) still get audit_list to see their store's submitted
    // audits; the page's server-side scope filter restricts what they can
    // actually open.
    $locOwner = (int)($_SESSION['bio_location_id'] ?? 0) > 0;
    $audit = [];
    if (hasTxn('audit_create') || hasTxn('audit_approve') || hasTxn('audit_view') || hasTxn('audit_admin') || $locOwner) {
        $audit[] = ['page' => 'audit_list', 'icon' => navIcon('audit_list'), 'label' => 'Audit List'];
    }
    if (hasTxn('audit_summary')) $audit[] = ['page' => 'audit_summary',    'icon' => navIcon('summary'),      'label' => 'Audit Summary'];
    // "Create Audit" is no longer a sidebar entry — reach it from the
    // Audit List page. Page itself stays gated server-side via txn_audit_create.
    if (hasTxn('audit_admin'))   $audit[] = ['page' => 'audit_categories', 'icon' => navIcon('categories'),   'label' => 'Audit Categories'];
    if (hasTxn('audit_admin'))   $audit[] = ['page' => 'audit_parameters', 'icon' => navIcon('audit_param'),  'label' => 'Audit Parameters'];
    if (hasTxn('audit_admin'))   $audit[] = ['page' => 'audit_templates',  'icon' => navIcon('audit_tpl'),    'label' => 'Audit Templates'];
    if (hasTxn('audit_operation')) $audit[] = ['page' => 'location_managers', 'icon' => navIcon('locations'),  'label' => 'Store Manager Mapping'];

    $store = [];
    if (hasTxn('outlet_directory')) $store[] = ['page' => 'outlet_directory', 'icon' => navIcon('outlet'), 'label' => 'Outlet Directory'];
    if (hasTxn('shelf_life'))       $store[] = ['page' => 'shelf_life',       'icon' => navIcon('shelf'),  'label' => 'Shelf Life'];
    if (hasTxn('store_hours'))      $store[] = ['page' => 'store_hours',      'icon' => navIcon('clock'),  'label' => 'Store Hours'];
    if (hasTxn('price_tags'))       $store[] = ['page' => 'price_tags',       'icon' => navIcon('tag'),    'label' => 'Price Tags'];
    // Store Operations group focuses on Outlet Directory / Shelf Life /
    // Store Hours / Price Tags / Banking.

    // Transactions: upload page is open to anyone with a self-claim location;
    // the report page is gated by txn_transactions_report.
    if ($locOwner) $store[] = ['page' => 'transactions', 'icon' => navIcon('summary'), 'label' => 'Banking Cash Deposit'];
    if (hasTxn('transactions_report')) $store[] = ['page' => 'transactions_report', 'icon' => navIcon('report'), 'label' => 'Banking Cash Deposit Report'];

    // Policy & Violation — combined nav group.
    // Policies are visible to every employee (the list filters itself by audience).
    // Violations and policy admin are still permission-gated.
    $violations = [];
    $violations[] = ['page' => 'policies', 'icon' => navIcon('audit_list'), 'label' => 'Policies'];
    if (hasTxn('policy_admin')) {
        $violations[] = ['page' => 'policy_admin_list',  'icon' => navIcon('categories'), 'label' => 'Policy Admin'];
    }
    if (hasTxn('policy_admin') || hasTxn('policy_dashboard')) {
        $violations[] = ['page' => 'policy_consent_dashboard', 'icon' => navIcon('summary'), 'label' => 'Consent Dashboard'];
    }
    if (hasTxn('violations_view') || hasTxn('record_violation') || hasTxn('reset_violation_counter') || hasTxn('violation_admin') || $locOwner) {
        if ($violations) $violations[] = ['separator' => true];
        $violations[] = ['page' => 'violations', 'icon' => navIcon('alert'), 'label' => 'Violations'];
    }
    // "Record Violation" is no longer a sidebar entry — reach it from the
    // Violations list page. Page itself stays gated server-side via
    // txn_record_violation + the Retail Sales department exclusion.
    if (hasTxn('reset_violation_counter')) {
        $violations[] = ['page' => 'violation_counter_reset', 'icon' => navIcon('audit_param'), 'label' => 'Reset Counter'];
    }
    if (hasTxn('violation_admin')) {
        $violations[] = ['page' => 'violation_categories', 'icon' => navIcon('categories'), 'label' => 'Violation Categories'];
    }

    // Price Variation — store managers submit + view own; admins manage master + decide.
    // The "New Variation" page itself is reached via the "+ New Variation" button on
    // the Variations list page (kept in allowedPages below) — no separate nav entry.
    $priceVar = [];
    if (hasTxn('price_variation') || hasTxn('price_variation_admin')) {
        $priceVar[] = ['page' => 'price_variations', 'icon' => navIcon('summary'), 'label' => 'Variations'];
    }
    if (hasTxn('price_variation_admin')) {
        $priceVar[] = ['page' => 'price_list', 'icon' => navIcon('tag'), 'label' => 'Master Price List'];
    }

    $groups = [];
    if ($admin)    $groups[] = ['group' => 'Administration',    'items' => $admin];
    $groups[]      =          ['group' => 'My Account',         'items' => $myAccount];
    $groups[]      =          ['group' => 'Time Tracking',      'items' => $timeTrack];
    if ($hrms)     $groups[] = ['group' => 'HRMS',              'items' => $hrms];
    if ($issues)   $groups[] = ['group' => 'Tickets',           'items' => $issues];
    if ($discount) $groups[] = ['group' => 'Discount',          'items' => $discount];
    if ($tasks)    $groups[] = ['group' => 'Checklists',   'items' => $tasks];
    if ($audit)    $groups[] = ['group' => 'Audits',            'items' => $audit];
    if ($store)    $groups[] = ['group' => 'Store Operations',  'items' => $store];
    if ($violations) $groups[] = ['group' => 'Policy & Violation', 'items' => $violations];
    if ($priceVar)   $groups[] = ['group' => 'Price Variation', 'items' => $priceVar];

    return $groups;
}

// Flat item list (groups stripped) — used by allowedPages() and similar.
function flatNav(): array {
    $flat = [];
    foreach (buildNav() as $g) {
        foreach ($g['items'] ?? [] as $it) {
            if (!empty($it['separator'])) continue;
            $flat[] = $it;
        }
    }
    return $flat;
}

function allowedPages(): array {
    $pages = [];
    foreach (flatNav() as $n) { $pages[] = $n['page']; }
    // Add sub-pages accessible based on transactions
    if (isSuperadmin()) {
        $pages = array_merge($pages, ['create','edit','add_location','edit_location',
            'create_issue','view_issue','edit_issue','export_attendance','export_issues',
            'export_checklist_report','change_password','punch_request','download_pr_attachment','download_issue_attachment',
            'export_attendance_report','export_employees_csv',
            'delete_issues']);
    }
    if (hasTxn('employees')) {
        $pages = array_merge($pages, ['create','edit','export_employees_csv']);
    }
    if (hasTxn('locations')) {
        $pages = array_merge($pages, ['add_location','edit_location']);
    }
    if (hasTxn('attendance')) {
        $pages = array_merge($pages, ['export_attendance','export_attendance_report']);
    }
    if (hasTxn('approve_punches')) {
        $pages = array_merge($pages, ['punch_request','download_pr_attachment']);
    }
    if (hasTxn('issues')) {
        $pages = array_merge($pages, ['view_issue','export_issues','download_issue_attachment']);
    }
    if (hasTxn('create_issue')) {
        $pages[] = 'create_issue';
    }
    if (hasTxn('edit_issue')) {
        $pages[] = 'edit_issue';
    }
    if (hasTxn('ticket_scheduler')) {
        $pages = array_merge($pages, ['ticket_schedules','ticket_schedule_new','ticket_schedule_edit']);
    }
    if (hasTxn('checklist_report')) {
        $pages[] = 'export_checklist_report';
    }
    if (hasTxn('time_report')) {
        $pages[] = 'export_time_report';
    }
    if (hasTxn('offer')) {
        $pages[] = 'generate_coupons';
    }
    if (hasTxn('issue_summary')) {
        $pages[] = 'issue_summary';
    }
    if (hasTxn('issue_comments')) {
        $pages[] = 'issue_comments';
    }
    if (hasTxn('issues') || hasTxn('issue_summary')) {
        $pages[] = 'issue_overview';
    }
    // Always available sub-pages for all employees
    if (!isSuperadmin()) {
        $pages = array_merge($pages, ['create_issue','view_issue','export_mypunches','export_issues',
            'download_pr_attachment','download_issue_attachment','profile']);
    }
    // Checklist attachments — anyone who can see the checklist can open
    // its files. Permission re-checked server-side in the handler.
    $pages[] = 'download_checklist_attachment';
    if (isSuperadmin() || hasTxn('dependencies')) {
        $pages[] = 'download_dependency';
    }
    if (isSuperadmin() || hasTxn('store_hours')) {
        $pages[] = 'export_store_hours';
    }
    if (isSuperadmin() || hasTxn('price_tags')) {
        $pages[] = 'price_tags_app';
    }
    // Audit sub-pages — visibility enforced inside each page/handler.
    // Any employee reaches audit_list / audit_view / export_audit_register /
    // download_audit_attachment — server-side scope filter restricts them to
    // their own location when they lack any txn_audit_* flag.
    // audit_manager_review is open to any logged-in user too — the page itself
    // restricts access to the assigned Store Manager on each audit.
    $pages = array_merge($pages, [
        'audit_list', 'audit_view', 'export_audit_register', 'download_audit_attachment',
        'audit_param_history', 'audit_manager_review', 'audit_manual',
        // Audit attachment annotations — gated inside the page handler by
        // auditCanViewRow / auditCanAnnotate, so expose the page names
        // broadly.
        'audit_annotation_image', 'audit_annotation_serve', 'audit_annotation_thread',
    ]);
    if (isSuperadmin() || hasTxn('audit_create')) {
        $pages = array_merge($pages, ['audit_new', 'audit_edit']);
    }
    if (isSuperadmin() || hasTxn('audit_approve')) {
        $pages[] = 'audit_approve';
    }
    if (isSuperadmin() || hasTxn('audit_operation')) {
        $pages[] = 'audit_operation_review';
    }
    if (isSuperadmin() || hasTxn('audit_management')) {
        $pages[] = 'audit_management_review';
    }
    if (isSuperadmin() || hasTxn('audit_summary')) {
        $pages = array_merge($pages, ['audit_summary', 'export_audit_summary']);
    }
    if (isSuperadmin() || hasTxn('audit_admin')) {
        $pages = array_merge($pages, ['audit_templates', 'audit_categories', 'audit_parameters', 'export_audit_templates']);
    }
    // Violations sub-pages — server-side scope filter restricts data inside each page.
    $pages = array_merge($pages, ['violations', 'violation_view', 'download_violation_attachment']);
    if (isSuperadmin() || hasTxn('record_violation')) {
        $pages[] = 'violation_record';
    }
    if (isSuperadmin() || hasTxn('reset_violation_counter')) {
        $pages[] = 'violation_counter_reset';
    }
    if (isSuperadmin() || hasTxn('violation_admin')) {
        $pages[] = 'violation_categories';
    }
    // Price variations — list/detail/attachments are open to admins too so
    // they can browse before deciding; only the "+ New Variation" form is
    // restricted to creators (txn_price_variation).
    if (isSuperadmin() || hasTxn('price_variation') || hasTxn('price_variation_admin')) {
        $pages[] = 'price_variation_detail';
        $pages[] = 'download_pv_attachment';
        $pages[] = 'export_price_variations';
    }
    if (isSuperadmin() || hasTxn('price_variation')) {
        $pages[] = 'price_variation_new';
        $pages[] = 'price_variation_edit';
    }
    if (isSuperadmin() || hasTxn('price_variation_admin')) {
        $pages[] = 'price_list_export';
    }
    // Transactions — uploaders + privileged users may download attachments.
    $pages[] = 'download_txn_attachment';
    if (isSuperadmin() || hasTxn('transactions_report')) {
        $pages[] = 'export_transactions_report';
    }
    $pages[] = 'sl_image';
    if (isSuperadmin() || hasTxn('shelf_life_upload')) {
        $pages[] = 'sl_export';
    }
    $pages[] = 'profile';
    $pages[] = 'my_pending'; // dashboard sub-page; available to every logged-in user
    // Policy pages — every logged-in user can read, view PDF, send heartbeat, see consent history
    $pages = array_merge($pages, ['policies','policy_view','policy_pdf','policy_heartbeat','policy_consent_history']);
    if (isSuperadmin() || hasTxn('policy_admin')) {
        $pages = array_merge($pages, ['policy_publish','policy_admin_list','policy_admin_versions']);
    }
    if (isSuperadmin() || hasTxn('policy_admin') || hasTxn('policy_dashboard')) {
        $pages[] = 'policy_consent_dashboard';
    }
    return array_unique($pages);
}

function renderShell(string $page, ?array $flash): void {
    // Enforce allowed pages
    if (!in_array($page, allowedPages())) {
        $page = defaultPage();
    }
    $nav = buildNav();
    // Decide layout: short lists render flat (no group headings, current
    // look). Long lists that would force scrolling render with collapsible
    // group headings — the active group is auto-expanded, others start
    // collapsed. Threshold ≈ how many items fit comfortably in a 720px
    // viewport without overflow.
    $totalItems = 0;
    foreach ($nav as $g) {
        foreach ($g['items'] ?? [] as $it) {
            if (empty($it['separator'])) $totalItems++;
        }
    }
    $useGroups = $totalItems > 14;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Work Pulse</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg?v=2">
<link rel="shortcut icon" href="assets/favicon.svg?v=2">
<?php renderStyles(); ?>
</head>
<body>
<div class="sidebar-backdrop"></div>
<aside class="sidebar">
    <div class="sidebar-brand"><span>⬡</span> Work Pulse</div>
    <div class="sidebar-role"><?= roleLabel() ?></div>
    <nav class="sidebar-nav <?= $useGroups ? 'is-grouped' : 'is-flat' ?>">
        <?php if ($useGroups): ?>
            <?php foreach ($nav as $gi => $g):
                if (empty($g['items'])) continue;
                $groupKey = preg_replace('/[^a-z0-9]+/i', '-', strtolower($g['group'] ?? ('g' . $gi)));
                $hasActive = false;
                foreach ($g['items'] as $it) { if (!empty($it['separator'])) continue; if ($it['page'] === $page) { $hasActive = true; break; } }
                // Personal-style ungrouped sections (no label) just render flat — no header chevron.
            ?>
            <div class="nav-group <?= $hasActive ? 'open' : '' ?>" data-group="<?= h($groupKey) ?>">
                <button type="button" class="nav-group-head" aria-expanded="<?= $hasActive ? 'true' : 'false' ?>">
                    <span class="nav-group-label"><?= h($g['group']) ?></span>
                    <span class="nav-group-chevron" aria-hidden="true">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </span>
                </button>
                <div class="nav-group-items">
                    <?php foreach ($g['items'] as $n): ?>
                        <?php if (!empty($n['separator'])): ?>
                            <hr class="nav-sep">
                        <?php else: ?>
                            <a href="?page=<?= $n['page'] ?>" class="nav-item <?= $page === $n['page'] ? 'active' : '' ?>">
                                <?= $n['icon'] ?> <?= $n['label'] ?>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <?php $first = true; foreach ($nav as $g):
                if (empty($g['items'])) continue;
                if (!$first) echo '<hr class="nav-sep">';
                $first = false;
                foreach ($g['items'] as $n):
                    if (!empty($n['separator'])) { echo '<hr class="nav-sep">'; continue; } ?>
                <a href="?page=<?= $n['page'] ?>" class="nav-item <?= $page === $n['page'] ? 'active' : '' ?>">
                    <?= $n['icon'] ?> <?= $n['label'] ?>
                </a>
            <?php endforeach;
            endforeach; ?>
        <?php endif; ?>
    </nav>
    <?php if (myDeptName()): ?>
    <div class="sidebar-emp"><?= h(myDeptName()) ?></div>
    <?php endif; ?>
    <form method="POST" class="sidebar-footer">
        <input type="hidden" name="action" value="logout">
        <button type="submit" class="btn-logout"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg> Logout</button>
    </form>
    <div class="sidebar-version">
        PHP <?= PHP_VERSION ?> · EOL <?= phpEolDate(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) ?><br>
        PHPMailer <?= \PHPMailer\PHPMailer\PHPMailer::VERSION ?>
    </div>
</aside>
<div class="main">
    <button type="button" class="nav-toggle" aria-label="Toggle menu">☰ Menu</button>
    <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>">
        <?= h($flash['msg']) ?>
    </div>
    <?php endif; ?>
    <?php dispatchPage($page); ?>
</div>
<script>
// Sidebar toggle:
//   • Desktop (>768px): toggles body.sidebar-collapsed, persisted in
//     localStorage so the choice survives navigation/reload.
//   • Mobile (≤768px): toggles body.sidebar-open (drawer overlay).
// Backdrop tap and any nav-item click always close the mobile drawer.
(function () {
    var LS_KEY = 'wp-sidebar-collapsed';
    var isDesktop = function () { return window.innerWidth > 768; };

    // Restore collapsed state on desktop.
    try {
        if (isDesktop() && localStorage.getItem(LS_KEY) === '1') {
            document.body.classList.add('sidebar-collapsed');
        }
    } catch (e) {}

    var toggle = document.querySelector('.nav-toggle');
    if (toggle) {
        toggle.addEventListener('click', function () {
            if (isDesktop()) {
                document.body.classList.toggle('sidebar-collapsed');
                try {
                    localStorage.setItem(LS_KEY,
                        document.body.classList.contains('sidebar-collapsed') ? '1' : '0');
                } catch (e) {}
            } else {
                document.body.classList.toggle('sidebar-open');
            }
        });
    }

    var bp = document.querySelector('.sidebar-backdrop');
    if (bp) bp.addEventListener('click', function () { document.body.classList.remove('sidebar-open'); });
    document.querySelectorAll('.sidebar-nav .nav-item').forEach(function (a) {
        a.addEventListener('click', function () { document.body.classList.remove('sidebar-open'); });
    });

    // If the viewport crosses the breakpoint, drop the now-irrelevant class
    // so we don't end up with both states stuck on at once.
    window.addEventListener('resize', function () {
        if (isDesktop()) document.body.classList.remove('sidebar-open');
        else document.body.classList.remove('sidebar-collapsed');
    });
})();
// Grouped nav: accordion — only one section is open at a time.
// On load only the active section (server-rendered with .open, i.e. the
// one containing the current page) is expanded; every other group stays
// collapsed. This means a fresh login shows a tidy, collapsed sidebar
// instead of restoring whatever the user had expanded before. Clicking a
// heading opens that section and closes the others; clicking the section
// that's already open collapses it.
(function () {
    var nav = document.querySelector('.sidebar-nav.is-grouped');
    if (!nav) return;
    var groups = nav.querySelectorAll('.nav-group');

    function setOpen(group, open) {
        group.classList.toggle('open', !!open);
        var head = group.querySelector('.nav-group-head');
        if (head) head.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    groups.forEach(function (group) {
        var head = group.querySelector('.nav-group-head');
        if (!head) return;
        head.addEventListener('click', function () {
            var willOpen = !group.classList.contains('open');
            // Collapse every group, then open the clicked one — unless it
            // was already open, in which case the click just collapses it.
            groups.forEach(function (g) { setOpen(g, false); });
            if (willOpen) setOpen(group, true);
        });
    });
})();
// Auto-label helper: for any table inside a `.table-wrap[data-stack]`, copy
// each <th>'s text into data-label on the matching <td> so the card-stack
// mobile CSS can render the cell's column name. Skips <td> that already
// have data-label (manually set). Runs once after DOM ready.
(function () {
    function autoLabel() {
        document.querySelectorAll('.table-wrap[data-stack] table').forEach(function (tbl) {
            var ths = tbl.querySelectorAll('thead th');
            if (!ths.length) return;
            var labels = Array.prototype.map.call(ths, function (th) { return th.textContent.trim(); });
            tbl.querySelectorAll('tbody tr').forEach(function (tr) {
                tr.querySelectorAll('td').forEach(function (td, i) {
                    if (!td.hasAttribute('data-label') && labels[i]) td.setAttribute('data-label', labels[i]);
                });
            });
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', autoLabel);
    else autoLabel();
})();
// Idle auto-logout: after IDLE_TIMEOUT seconds without user activity,
// reload the page. The server then sees an expired session and bounces
// to login with "Session expired" message.
(function () {
    var IDLE_MS = <?= IDLE_TIMEOUT_SECONDS * 1000 ?>;
    var timer;
    function reset() {
        clearTimeout(timer);
        timer = setTimeout(function () { window.location.reload(); }, IDLE_MS + 1000);
    }
    ['mousemove','keydown','click','scroll','touchstart'].forEach(function (ev) {
        document.addEventListener(ev, reset, { passive: true });
    });
    reset();
})();
// Double-submit guard — disables submit buttons on first POST and
// blocks a second submit on the same form until the page navigates
// (or 30s elapses, in case the form errored out and stayed put).
// Add data-no-disable to a <form> to opt out (e.g., AJAX forms).
(function () {
    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (!form || form.tagName !== 'FORM') return;
        if (form.hasAttribute('data-no-disable')) return;
        // Skip if an inline onsubmit (e.g., return confirm(...)) cancelled it.
        if (ev.defaultPrevented) return;
        if (form.dataset.submitting === '1') {
            ev.preventDefault();
            return false;
        }
        form.dataset.submitting = '1';
        // Disable submit buttons via setTimeout (a *task*, not a
        // microtask) so the button's name=value pair stays in the POST.
        // Microtasks run BEFORE the browser collects form data, which
        // would strip flags like `submit_after_save=1` on multi-action
        // forms (Save & Submit, Save & Forward, decision=approve, etc.).
        // setTimeout queues a task, which runs after form data has been
        // serialised and the request is in flight.
        setTimeout(function () {
            form.querySelectorAll('button[type=submit], input[type=submit]').forEach(function (b) {
                b.disabled = true;
                b.dataset.wasSubmit = '1';
            });
        }, 0);
        // Safety net: re-enable after 30s if the page didn't navigate.
        setTimeout(function () {
            form.dataset.submitting = '';
            form.querySelectorAll('[data-was-submit="1"]').forEach(function (b) {
                b.disabled = false;
            });
        }, 30000);
    }, false);
})();
// 24-hour time picker — combines two <select> values into the
// hidden HH:MM field on change. Always 24h regardless of OS locale.
(function () {
    function init(span) {
        var hh = span.querySelector('.t24-hh');
        var mm = span.querySelector('.t24-mm');
        var hidden = span.querySelector('input[type=hidden]');
        if (!hh || !mm || !hidden) return;
        var required = span.dataset.required === '1';
        var colorHours = span.dataset.colorHours === '1';
        var ampm = span.querySelector('.t24-ampm');
        function sync() {
            var H = hh.value, M = mm.value;
            hidden.value = (H && M) ? (H + ':' + M) : '';
            if (required) {
                hidden.setCustomValidity(hidden.value ? '' : 'Please pick hour and minute');
            }
            // Show the picked time in 12-hour AM/PM beside the selects.
            if (ampm) {
                if (H && M) {
                    var h = parseInt(H, 10), suffix = h < 12 ? 'AM' : 'PM';
                    var h12 = h % 12; if (h12 === 0) h12 = 12;
                    var hp = (h12 < 10 ? '0' : '') + h12;
                    // Night = 9PM–6AM (hour >= 21 or < 6), else Day.
                    var part = (h >= 21 || h < 6) ? 'Night' : 'Day';
                    ampm.textContent = hp + ':' + M + ' ' + suffix + ' (' + part + ')';
                    // Match the hour-option colours: 1–7 blue, 0 + 8–23 yellow.
                    if (colorHours) ampm.style.color = (h >= 1 && h <= 7) ? '#1a8fe3' : '#d4a800';
                } else {
                    ampm.textContent = '';
                }
            }
        }
        hh.addEventListener('change', sync);
        mm.addEventListener('change', sync);
        sync();
    }
    function bootstrap() {
        document.querySelectorAll('.time24').forEach(init);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bootstrap);
    else bootstrap();
})();

// Generic inline clear (×) button for filter inputs / selects.
// Markup: <span class="input-clear-wrap"><input ...><button class="input-clear-btn" type="button">×</button></span>
// On click: empties the input/select, fires change+input events, and submits
// the enclosing GET form so the URL drops the filter immediately.
(function () {
    function syncWrap(wrap, field) {
        var v = field.value || '';
        if (field.tagName === 'SELECT') {
            // Treat the empty string AND a "0" sentinel as "no filter".
            if (v === '' || v === '0') wrap.classList.remove('has-value');
            else wrap.classList.add('has-value');
        } else {
            if (v.trim() === '') wrap.classList.remove('has-value');
            else wrap.classList.add('has-value');
        }
    }
    function initWrap(wrap) {
        var field = wrap.querySelector('input,select');
        var btn   = wrap.querySelector('.input-clear-btn');
        if (!field || !btn) return;
        // data-clear-skip-sync: page manages .has-value manually — needed when the
        // input's "empty" state has non-empty text (e.g. "All Locations").
        if (!wrap.hasAttribute('data-clear-skip-sync')) {
            syncWrap(wrap, field);
            field.addEventListener('input',  function () { syncWrap(wrap, field); });
            field.addEventListener('change', function () { syncWrap(wrap, field); });
        }
        // data-no-auto opts out of the default clear-and-submit click handler.
        // Pages that own the clear lifecycle (e.g. autocomplete pickers) keep
        // their custom click handler — we only do the visibility sync above.
        if (btn.hasAttribute('data-no-auto')) return;
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            if (field.tagName === 'SELECT') {
                // Prefer the explicit empty-value option, else the "0" option, else first.
                var opts = field.options, idx = -1;
                for (var i = 0; i < opts.length; i++) { if (opts[i].value === '')  { idx = i; break; } }
                if (idx < 0) for (var j = 0; j < opts.length; j++) { if (opts[j].value === '0') { idx = j; break; } }
                if (idx < 0) idx = 0;
                field.selectedIndex = idx;
            } else {
                field.value = '';
            }
            syncWrap(wrap, field);
            field.dispatchEvent(new Event('input',  { bubbles: true }));
            field.dispatchEvent(new Event('change', { bubbles: true }));
            // Auto-submit when inside a form so the URL drops the filter.
            var form = wrap.closest('form');
            if (form) form.submit();
            else field.focus();
        });
    }
    function bootstrap() {
        document.querySelectorAll('.input-clear-wrap').forEach(initWrap);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bootstrap);
    else bootstrap();
})();
</script>
</body>
</html>
<?php
}

function dispatchPage(string $page): void {
    switch ($page) {
        // Biometric core
        case 'dashboard':       pageDashboard();  break;
        case 'my_pending':      if (function_exists('pageMyPending')) pageMyPending(); break;
        case 'employees':       pageEmployees();  break;
        case 'create':          pageEmpForm(null); break;
        case 'edit':            pageEmpForm(getEmployee((int)($_GET['id'] ?? 0))); break;
        case 'attendance':      pageAttendance(); break;
        case 'mypunches':       pageMyPunches();  break;
        case 'my_time':         if (function_exists('pageMyTime')) pageMyTime(); break;
        case 'time_tasks':      if (function_exists('pageTimeTasks')) pageTimeTasks(); break;
        case 'time_report':     if (function_exists('pageTimeReport')) pageTimeReport(); break;
        case 'export_time_report': if (function_exists('exportTimeReport')) exportTimeReport(); break;
        case 'my_location':     pageMyLocation(); break;
        case 'profile':         if (function_exists('pageProfile')) pageProfile(); break;
        case 'dependencies':    if (function_exists('pageDependencies')) pageDependencies(); break;
        case 'download_dependency': if (function_exists('downloadDependency')) downloadDependency(); break;
        case 'export_store_hours':  if (function_exists('exportStoreHours')) exportStoreHours(); break;
        case 'failed_punches':  pageFailedPunches(); break;
        case 'locations':       pageLocations();  break;
        case 'add_location':    pageLocationForm(null); break;
        case 'edit_location':   pageLocationForm(getLocation((int)($_GET['id'] ?? 0))); break;
        case 'departments':     pageDepartments(); break;
        case 'roles':           pageRoles(); break;
        case 'devices':         pageDevices();    break;
        case 'settings':        pageSettings();   break;
        // Issues
        case 'issues':          pageIssues();     break;
        case 'create_issue':    pageCreateIssue(); break;
        case 'view_issue':      pageViewIssue();  break;
        case 'edit_issue':      pageEditIssue();  break;
        case 'manage_categories': pageManageCategories(); break;
        case 'ticket_schedules':     if (function_exists('pageTicketSchedules')) pageTicketSchedules(); break;
        case 'ticket_schedule_new':  if (function_exists('pageTicketScheduleForm')) pageTicketScheduleForm(null); break;
        case 'ticket_schedule_edit': if (function_exists('pageTicketScheduleForm')) pageTicketScheduleForm(function_exists('getTicketSchedule') ? getTicketSchedule((int)($_GET['id'] ?? 0)) : null); break;
        case 'issue_summary':   if (function_exists('pageIssueSummary')) pageIssueSummary(); break;
        case 'issue_overview':  if (function_exists('pageIssueOverview')) pageIssueOverview(); break;
        case 'delete_issues':   if (function_exists('pageDeleteIssues')) pageDeleteIssues(); break;
        case 'issue_comments':  if (function_exists('pageIssueComments')) pageIssueComments(); break;
        // Offer
        case 'offer':           pageOffer();      break;
        case 'generate_coupons': pageGenerateCoupons(); break;
        case 'coupon_redeemed': pageCouponRedeemed(); break;
        case 'generate_vouchers': if (function_exists('pageGenerateVouchers')) pageGenerateVouchers(); break;
        // Checklist
        case 'checklist':       pageChecklist();  break;
        case 'manage_tasks':    pageManageTasks(); break;
        case 'checklist_report': pageChecklistReport(); break;
        case 'download_checklist_attachment':
            if (function_exists('downloadChecklistAttachment')) downloadChecklistAttachment(); break;
        case 'checklist_overview': if (function_exists('pageChecklistOverview')) pageChecklistOverview(); break;
        case 'checklist_audit': pageChecklistAudit(); break;
        case 'checklist_validate': if (function_exists('pageChecklistValidate')) pageChecklistValidate(); break;
        // Retail
        case 'store_hours':       if (function_exists('pageStoreHours'))    pageStoreHours();    break;
        case 'price_tags':        if (function_exists('pagePriceTags'))     pagePriceTags();     break;
        case 'price_tags_app':    if (function_exists('pagePriceTagsApp'))  pagePriceTagsApp();  break;
        case 'transactions':                if (function_exists('pageTransactions'))         pageTransactions();         break;
        case 'transactions_report':         if (function_exists('pageTransactionsReport'))   pageTransactionsReport();   break;
        case 'download_txn_attachment':     if (function_exists('doDownloadTxnAttachment'))  doDownloadTxnAttachment();  break;
        case 'export_transactions_report':  if (function_exists('exportTransactionsReport')) exportTransactionsReport(); break;
        // Exports
        case 'export_attendance': exportAttendance(); break;
        case 'export_attendance_report': if (function_exists('exportAttendanceReport')) exportAttendanceReport(); break;
        case 'export_mypunches':  exportMyPunches();  break;
        case 'export_employees_csv': if (function_exists('exportEmployeesCsv')) exportEmployeesCsv(); break;
        case 'export_issues':     exportIssues();     break;
        case 'download_issue_attachment': downloadIssueAttachment(); break;
        case 'export_checklist_report': exportChecklistReport(); break;
        // Punch requests
        case 'punch_request':     pagePunchRequest();    break;
        case 'approve_punches':   pageApprovePunches();  break;
        case 'download_pr_attachment': downloadPrAttachment(); break;
        // Password
        case 'manage_passwords':  pageManagePasswords(); break;
        case 'change_password':   pageChangePassword();  break;
        // Outlet Directory & Shelf Life
        case 'outlet_directory':  if (function_exists('pageOutletDirectory')) pageOutletDirectory(); break;
        case 'shelf_life':        if (function_exists('pageShelfLife')) pageShelfLife(); break;
        case 'sl_image':          if (function_exists('serveShelfLifeImage')) serveShelfLifeImage(); break;
        case 'sl_export':         if (function_exists('exportShelfLifeCsv')) exportShelfLifeCsv(); break;
        // Audit
        case 'audit_list':        if (function_exists('pageAuditList'))       pageAuditList();       break;
        case 'audit_manual':      if (function_exists('pageAuditManual'))     pageAuditManual();     break;
        case 'audit_summary':     if (function_exists('pageAuditSummary'))    pageAuditSummary();    break;
        case 'export_audit_summary': if (function_exists('exportAuditSummary')) exportAuditSummary(); break;
        case 'export_audit_templates': if (function_exists('exportAuditTemplates') && (isSuperadmin() || hasTxn('audit_admin'))) exportAuditTemplates(); break;
        case 'audit_new':         if (function_exists('pageAuditNew'))        pageAuditNew();        break;
        case 'audit_edit':        if (function_exists('pageAuditEdit'))       pageAuditEdit();       break;
        case 'audit_view':        if (function_exists('pageAuditView'))       pageAuditView();       break;
        case 'audit_approve':     if (function_exists('pageAuditApprove'))    pageAuditApprove();    break;
        case 'audit_manager_review':    if (function_exists('pageAuditManagerReview'))    pageAuditManagerReview();    break;
        case 'audit_operation_review':  if (function_exists('pageAuditOperationReview'))  pageAuditOperationReview();  break;
        case 'audit_management_review': if (function_exists('pageAuditManagementReview')) pageAuditManagementReview(); break;
        // Audit attachment annotations (separate module)
        case 'audit_annotation_image':  if (function_exists('pageAuditAnnotationImage'))  pageAuditAnnotationImage();  break;
        case 'audit_annotation_serve':  if (function_exists('pageAuditAnnotationServe'))  pageAuditAnnotationServe();  break;
        case 'audit_annotation_thread': if (function_exists('pageAuditAnnotationThread')) pageAuditAnnotationThread(); break;
        case 'audit_templates':   if (function_exists('pageAuditTemplates'))  pageAuditTemplates();  break;
        case 'location_managers': if (function_exists('pageLocationManagers')) pageLocationManagers(); break;
        case 'audit_categories':  if (function_exists('pageAuditCategories')) pageAuditCategories(); break;
        case 'audit_parameters':  if (function_exists('pageAuditParameters')) pageAuditParameters(); break;
        case 'audit_param_history': if (function_exists('pageAuditParamHistory')) pageAuditParamHistory(); break;
        case 'download_audit_attachment': if (function_exists('downloadAuditAttachment')) downloadAuditAttachment(); break;
        case 'export_audit_register':     if (function_exists('exportAuditRegister'))     exportAuditRegister();     break;
        // Policies (Phase 5)
        case 'policies':                       if (function_exists('pagePolicies'))               pagePolicies();               break;
        case 'policy_view':                    if (function_exists('pagePolicyView'))             pagePolicyView();             break;
        case 'policy_pdf':                     if (function_exists('pagePolicyPdf'))              pagePolicyPdf();              break;
        case 'policy_heartbeat':               if (function_exists('pagePolicyHeartbeat'))        pagePolicyHeartbeat();        break;
        case 'policy_publish':                 if (function_exists('pagePolicyPublish'))          pagePolicyPublish();          break;
        case 'policy_admin_list':              if (function_exists('pagePolicyAdminList'))        pagePolicyAdminList();        break;
        case 'policy_admin_versions':          if (function_exists('pagePolicyAdminVersions'))    pagePolicyAdminVersions();    break;
        case 'policy_consent_dashboard':       if (function_exists('pagePolicyConsentDashboard')) pagePolicyConsentDashboard(); break;
        case 'policy_consent_history':         if (function_exists('pagePolicyConsentHistory'))   pagePolicyConsentHistory();   break;
        // Violations
        case 'violations':                if (function_exists('pageViolations'))            pageViolations();            break;
        case 'violation_record':          if (function_exists('pageViolationRecord'))       pageViolationRecord();       break;
        case 'violation_view':            if (function_exists('pageViolationView'))         pageViolationView();         break;
        case 'violation_categories':      if (function_exists('pageViolationCategories'))   pageViolationCategories();   break;
        case 'violation_counter_reset':   if (function_exists('pageViolationCounterReset')) pageViolationCounterReset(); break;
        case 'download_violation_attachment': if (function_exists('downloadViolationAttachment')) downloadViolationAttachment(); break;
        // Price Variation
        case 'price_list':              if (function_exists('pagePriceList'))             pagePriceList();             break;
        case 'price_list_export':       if (function_exists('doPriceListExport'))         doPriceListExport();         break;
        case 'price_variation_new':     if (function_exists('pagePriceVariationNew'))     pagePriceVariationNew();     break;
        case 'price_variation_edit':    if (function_exists('pagePriceVariationEdit'))    pagePriceVariationEdit();    break;
        case 'price_variations':        if (function_exists('pagePriceVariationsList'))   pagePriceVariationsList();   break;
        case 'export_price_variations': if (function_exists('doPriceVariationsExport'))    doPriceVariationsExport();   break;
        case 'price_variation_detail':  if (function_exists('pagePriceVariationDetail'))  pagePriceVariationDetail();  break;
        case 'download_pv_attachment':  if (function_exists('doDownloadPvAttachment'))    doDownloadPvAttachment();    break;
        default:                pageDashboard();  break;
    }
}

function renderLogin(): void {
    $flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#1c1c24">
<title>Work Pulse — Login</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg?v=2">
<link rel="shortcut icon" href="assets/favicon.svg?v=2">
<?php renderStyles(); ?>
</head>
<body class="login-body">
<div class="login-card">
    <div class="login-logo">⬡</div>
    <h1 class="login-title">Work Pulse</h1>
    <p class="login-sub">Dangee Dums</p>
    <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= h($flash['msg']) ?></div>
    <?php endif; ?>
    <form method="POST">
        <input type="hidden" name="action" value="login">
        <div class="form-group">
            <label>Username / Employee Code</label>
            <input type="text" name="identifier" class="form-control"
                   placeholder="User ID" autofocus required>
        </div>
        <div class="form-group" style="margin-top:12px">
            <label>Password</label>
            <input type="password" name="password" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary w-full" style="margin-top:16px">Login</button>
    </form>
</div>
</body></html>
<?php
}
