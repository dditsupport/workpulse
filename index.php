<?php
// =========================================================
// WORK PULSE  ·  Unified App Router
// =========================================================
session_start();
date_default_timezone_set('Asia/Kolkata');
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/modules/auth.php';
require_once __DIR__ . '/modules/helpers.php';
require_once __DIR__ . '/modules/styles.php';
require_once __DIR__ . '/modules/nav.php';
require_once __DIR__ . '/modules/employees.php';
require_once __DIR__ . '/modules/locations.php';
require_once __DIR__ . '/modules/departments.php';
require_once __DIR__ . '/modules/roles.php';
require_once __DIR__ . '/modules/devices.php';
require_once __DIR__ . '/modules/attendance.php';
require_once __DIR__ . '/modules/settings.php';

// Conditionally load modules (graceful if not yet created)
foreach (['dashboard','issues','issue_user','issue_edit','offer','checklist','checklist_reports','passwords','punch_requests','outlet_directory','shelf_life','store_hours','dependencies','audit','price_tags','violations','price_variations','transactions','transactions_report','policies','time_tracking','ticket_scheduler','location_managers'] as $mod) {
    $f = __DIR__ . '/modules/' . $mod . '.php';
    if (file_exists($f)) require_once $f;
}

// ── POST routing ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'login')  { doLogin();  exit; }
    if ($action === 'logout') { doLogout(); exit; }
    enforceIdleTimeout();   // may redirect + exit
    if (!isLoggedIn()) { header('Location: index.php'); exit; }
    routePost($action);
    exit;
}

// ── GET routing ───────────────────────────────────────────
enforceIdleTimeout();       // may redirect + exit
if (!isLoggedIn()) { renderLogin(); exit; }

$page  = $_GET['page'] ?? defaultPage();

// CSV exports must run BEFORE any HTML output
if (in_array($page, ['export_attendance','export_mypunches','export_issues','export_checklist_report','download_pr_attachment','download_issue_attachment','download_checklist_attachment','sl_image','sl_export','export_attendance_report','export_employees_csv','export_store_hours','download_dependency','export_audit_register','download_audit_attachment','price_tags_app','audit_param_history','price_list_export','export_price_variations','download_pv_attachment','export_transactions_report','download_txn_attachment','export_audit_summary','export_audit_templates','policy_pdf','policy_heartbeat','audit_annotation_serve','audit_annotation_thread','export_time_report'])) {
    if (in_array($page, allowedPages())) {
        dispatchPage($page);
    }
    exit;
}

// Policy consent gate — runs after login but before page render.
// Redirects to the consent page if the user has any blocking policy.
// The policy view itself + heartbeat + pdf stream are exempt to avoid
// a redirect loop. So is logout (a POST handled earlier).
if (function_exists('policyFirstBlockingVersion')
    && !in_array($page, ['policy_view','policy_pdf','policy_heartbeat','my_pending','audit_annotation_serve','audit_annotation_thread'], true)) {
    $blocking = policyFirstBlockingVersion(myCode());
    if ($blocking) {
        $_SESSION['flash'] = ['type' => 'error',
            'msg' => 'You must accept the outstanding policy before continuing: "' . $blocking['title'] . '".'];
        header('Location: index.php?page=policy_view&id=' . (int)$blocking['id']);
        exit;
    }
}

// Ticket scheduler — lazy fallback: create any due tickets at most once
// per day (covers deployments without a server cron). Self-throttled.
if (function_exists('ticketSchedLazyRun')) ticketSchedLazyRun();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

renderShell($page, $flash);

// ── POST action dispatcher ────────────────────────────────
function routePost(string $a): void {
    switch ($a) {
        // Employee management (hr, superadmin)
        case 'create_employee': if (canManageEmployees()) doCreateEmployee(); break;
        case 'update_employee': if (canManageEmployees()) doUpdateEmployee(); break;
        case 'toggle_active':   if (canManageEmployees()) doToggleActive();   break;
        // Locations (superadmin or locations txn)
        case 'save_location':   if (isSuperadmin() || hasTxn('locations')) doSaveLocation();   break;
        case 'del_location':    if (isSuperadmin() || hasTxn('locations')) doDelLocation();    break;
        // Departments — org data only (hr, superadmin, departments txn)
        case 'save_department': if (canManageEmployees() || hasTxn('departments')) doSaveDepartment(); break;
        case 'del_department':  if (canManageEmployees() || hasTxn('departments')) doDelDepartment();  break;
        // Roles — permission profiles (superadmin or dept_roles txn)
        case 'save_role':       if (isSuperadmin() || hasTxn('dept_roles')) doSaveRole(); break;
        case 'del_role':        if (isSuperadmin() || hasTxn('dept_roles')) doDelRole();  break;
        // Devices
        case 'save_device':     if (hasTxn('devices')) doSaveDevice();     break;
        case 'toggle_device':   if (hasTxn('devices')) doToggleDevice();   break;
        // Settings
        case 'save_settings':   if (hasTxn('settings')) doSaveSettings();   break;
        case 'test_smtp':       if (hasTxn('settings')) doTestSmtp();       break;
        // Issues (loaded conditionally)
        case 'create_issue':    if (function_exists('doCreateIssue') && hasTxn('create_issue'))    doCreateIssue();    break;
        case 'update_issue':    if (function_exists('doUpdateIssue') && hasTxn('edit_issue')) doUpdateIssue(); break;
        case 'transition_issue': if (function_exists('doTransitionIssue')) doTransitionIssue(); break;
        case 'add_comment':     if (function_exists('doAddComment'))     doAddComment();     break;
        case 'save_category':   if (function_exists('doSaveCategory') && hasTxn('manage_categories')) doSaveCategory(); break;
        case 'del_category':    if (function_exists('doDelCategory') && hasTxn('manage_categories')) doDelCategory(); break;
        case 'delete_issues_bulk': if (function_exists('doDeleteIssuesBulk') && isSuperadmin()) doDeleteIssuesBulk(); break;
        // Ticket scheduler
        case 'save_ticket_schedule':   if (function_exists('doSaveTicketSchedule'))   doSaveTicketSchedule();   break;
        case 'delete_ticket_schedule': if (function_exists('doDeleteTicketSchedule')) doDeleteTicketSchedule(); break;
        case 'run_ticket_schedules':   if (function_exists('doRunTicketSchedulesNow')) doRunTicketSchedulesNow(); break;
        // Store manager mapping
        case 'save_location_manager':   if (function_exists('doSaveLocationManager'))   doSaveLocationManager();   break;
        case 'delete_location_manager': if (function_exists('doDeleteLocationManager')) doDeleteLocationManager(); break;
        // Time tracking
        case 'save_time_entry':   if (function_exists('doSaveTimeEntry'))   doSaveTimeEntry();   break;
        case 'delete_time_entry': if (function_exists('doDeleteTimeEntry')) doDeleteTimeEntry(); break;
        case 'save_time_task':    if (function_exists('doSaveTimeTask'))    doSaveTimeTask();    break;
        case 'delete_time_task':  if (function_exists('doDeleteTimeTask'))  doDeleteTimeTask();  break;
        case 'time_search_refs':  if (function_exists('doSearchTimeRefs'))  doSearchTimeRefs();  break;
        // Offer
        case 'submit_offer':    if (function_exists('doSubmitOffer'))    doSubmitOffer();    break;
        case 'resend_offer':    if (function_exists('doResendOffer'))    doResendOffer();    break;
        case 'generate_coupons': if (function_exists('doGenerateCoupons') && hasTxn('generate_coupons')) doGenerateCoupons(); break;
        case 'generate_vouchers': if (function_exists('doGenerateVouchers') && hasTxn('generate_vouchers')) doGenerateVouchers(); break;
        // Checklist
        case 'save_checklist':  if (function_exists('doSaveChecklist'))  doSaveChecklist();  break;
        case 'delete_checklist_attachment':
            if (function_exists('doDeleteChecklistAttachment')) doDeleteChecklistAttachment(); break;
        case 'save_task':       if (function_exists('doSaveTask') && canManageTasks()) doSaveTask(); break;
        case 'toggle_task':     if (function_exists('doToggleTask') && canManageTasks()) doToggleTask(); break;
        case 'del_task':        if (function_exists('doDelTask') && canManageTasks()) doDelTask(); break;
        case 'delete_checklist_month': if (function_exists('doDeleteChecklistMonth') && isSuperadmin()) doDeleteChecklistMonth(); break;
        case 'save_checklist_validation': if (function_exists('doSaveChecklistValidation')) doSaveChecklistValidation(); break;
        // Punch requests
        case 'submit_punch_request': if (function_exists('doSubmitPunchRequest')) doSubmitPunchRequest(); break;
        case 'review_punch_request': if (function_exists('doReviewPunchRequest') && canManageEmployees()) doReviewPunchRequest(); break;
        case 'delete_all_failed_punches': if (function_exists('doDeleteAllFailedPunches') && isSuperadmin()) doDeleteAllFailedPunches(); break;
        // Passwords
        case 'reset_password':  if (function_exists('doResetPassword') && hasTxn('manage_passwords')) doResetPassword(); break;
        case 'change_password': if (function_exists('doChangePassword')) doChangePassword(); break;
        // Shelf life
        case 'sl_upload':       if (function_exists('doShelfLifeUpload') && (isSuperadmin() || hasTxn('shelf_life_upload'))) doShelfLifeUpload(); break;
        case 'sl_update':       if (function_exists('doShelfLifeUpdate') && (isSuperadmin() || hasTxn('shelf_life_upload'))) doShelfLifeUpdate(); break;
        case 'sl_import':       if (function_exists('doShelfLifeImport') && (isSuperadmin() || hasTxn('shelf_life_upload'))) doShelfLifeImport(); break;
        case 'sl_add':          if (function_exists('doShelfLifeAdd')    && (isSuperadmin() || hasTxn('shelf_life_upload'))) doShelfLifeAdd();    break;
        // Price tags
        case 'price_tag_save_item': if (function_exists('doSaveTagItem') && (isSuperadmin() || hasTxn('price_tags'))) doSaveTagItem(); break;
        // Location transfer (OTP)
        case 'request_location_otp':  if (function_exists('doRequestLocationOtp')) doRequestLocationOtp(); break;
        case 'verify_location_otp':   if (function_exists('doVerifyLocationOtp')) doVerifyLocationOtp(); break;
        case 'cancel_location_otp':   if (function_exists('doCancelLocationOtp')) doCancelLocationOtp(); break;
        // Audit
        case 'create_audit':              if (function_exists('doCreateAudit'))            doCreateAudit();            break;
        case 'save_audit_weights':        if (function_exists('doSaveAuditWeights'))       doSaveAuditWeights();       break;
        case 'submit_audit':              if (function_exists('doSubmitAudit'))            doSubmitAudit();            break;
        case 'manager_review_audit':      if (function_exists('doManagerReviewAudit'))     doManagerReviewAudit();     break;
        case 'operation_review_audit':    if (function_exists('doOperationReviewAudit'))   doOperationReviewAudit();   break;
        case 'approve_audit':             if (function_exists('doApproveAudit'))           doApproveAudit();           break;
        case 'management_approve_audit':  if (function_exists('doManagementApproveAudit')) doManagementApproveAudit(); break;
        // Audit attachment annotations
        case 'create_audit_annotation':       if (function_exists('doCreateAuditAnnotation'))     doCreateAuditAnnotation();     break;
        case 'add_audit_annotation_comment':  if (function_exists('doAddAuditAnnotationComment')) doAddAuditAnnotationComment(); break;
        case 'resolve_audit_annotation':      if (function_exists('doResolveAuditAnnotation'))    doResolveAuditAnnotation();    break;
        case 'reopen_audit_annotation':       if (function_exists('doReopenAuditAnnotation'))     doReopenAuditAnnotation();     break;
        case 'delete_audit_attachment':   if (function_exists('doDeleteAuditAttachment'))  doDeleteAuditAttachment();  break;
        case 'delete_audit':              if (function_exists('doDeleteAudit'))            doDeleteAudit();            break;
        case 'save_audit_template':       if (function_exists('doSaveAuditTemplate'))      doSaveAuditTemplate();      break;
        case 'del_audit_template':        if (function_exists('doDelAuditTemplate'))       doDelAuditTemplate();       break;
        case 'save_audit_category':       if (function_exists('doSaveAuditCategory'))      doSaveAuditCategory();      break;
        case 'del_audit_category':        if (function_exists('doDelAuditCategory'))       doDelAuditCategory();       break;
        case 'save_audit_parameter':      if (function_exists('doSaveAuditParameter'))     doSaveAuditParameter();     break;
        case 'del_audit_parameter':       if (function_exists('doDelAuditParameter'))      doDelAuditParameter();      break;
        // Violations
        case 'create_violation':          if (function_exists('doCreateViolation'))         doCreateViolation();         break;
        case 'add_violation_remark':      if (function_exists('doAddViolationRemark'))      doAddViolationRemark();      break;
        case 'delete_violation':          if (function_exists('doDeleteViolation'))         doDeleteViolation();         break;
        case 'reset_violation_counter':   if (function_exists('doResetViolationCounter'))   doResetViolationCounter();   break;
        case 'save_violation_category':   if (function_exists('doSaveViolationCategory'))   doSaveViolationCategory();   break;
        // Price Variations
        case 'pl_import':              if (function_exists('doPriceListImport'))        doPriceListImport();        break;
        case 'pl_save_item':           if (function_exists('doSavePriceListItem'))      doSavePriceListItem();      break;
        case 'pl_add_item':            if (function_exists('doAddPriceListItem'))       doAddPriceListItem();       break;
        case 'pl_save_slot_activity':  if (function_exists('doSaveSlotActivity'))       doSaveSlotActivity();       break;
        case 'pv_submit':         if (function_exists('doSubmitPriceVariation'))   doSubmitPriceVariation();   break;
        case 'pv_update':         if (function_exists('doUpdatePriceVariation'))   doUpdatePriceVariation();   break;
        case 'pv_decide':         if (function_exists('doDecidePriceVariation'))   doDecidePriceVariation();   break;
        case 'pv_edit_decision_remarks': if (function_exists('doEditDecisionRemarks')) doEditDecisionRemarks(); break;
        case 'pv_edit_confirm_remarks':  if (function_exists('doEditConfirmRemarks'))  doEditConfirmRemarks();  break;
        case 'pv_confirm':        if (function_exists('doConfirmPriceVariation'))  doConfirmPriceVariation();  break;
        case 'pv_delete':         if (function_exists('doDeletePriceVariation'))    doDeletePriceVariation();   break;
        case 'pv_add_attachment': if (function_exists('doPvAddAttachment'))        doPvAddAttachment();        break;
        case 'pv_search_items':   if (function_exists('doSearchPriceListItems'))   doSearchPriceListItems();   break;
        case 'delete_price_variations_by_month': if (function_exists('doDeletePriceVariationsByMonth') && isSuperadmin()) doDeletePriceVariationsByMonth(); break;
        // Transactions
        case 'save_transaction':
            if (function_exists('doSaveTransaction') && canUploadTransaction()) doSaveTransaction(); break;
        case 'validate_transaction':
            if (function_exists('doValidateTransaction')) doValidateTransaction(); break;
        case 'invalidate_transaction':
            if (function_exists('doInvalidateTransaction')) doInvalidateTransaction(); break;
        case 'delete_transactions_by_month':
            if (function_exists('doDeleteTransactionsByMonth') && isSuperadmin()) doDeleteTransactionsByMonth(); break;
        // Policies
        case 'publish_policy_version':
            if (function_exists('doPublishPolicyVersion') && (isSuperadmin() || hasTxn('policy_admin'))) doPublishPolicyVersion(); break;
        case 'consent_policy':
            if (function_exists('doConsentPolicy')) doConsentPolicy(); break;
        case 'request_policy_otp':
            if (function_exists('doRequestPolicyOtp')) doRequestPolicyOtp(); break;
        case 'cancel_policy_otp':
            if (function_exists('doCancelPolicyOtp')) doCancelPolicyOtp(); break;
    }
}
