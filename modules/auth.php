<?php
// =========================================================
// Authentication & transaction-level access helpers
// Access is determined by role txn_* flags (employees.role_id → roles)
// =========================================================

// Idle timeout (seconds) — auto-logout after this many seconds of inactivity
const IDLE_TIMEOUT_SECONDS = 600; // 10 minutes

function isLoggedIn(): bool   { return isset($_SESSION['bio_role']); }

// ── Idle-timeout enforcement ────────────────────────────
// Call on every authenticated request. If session has been idle longer than
// IDLE_TIMEOUT_SECONDS, destroy it and bounce to login with a flash message.
function enforceIdleTimeout(): void {
    if (!isLoggedIn()) return;

    // Long-form work — don't expire while the user is on these pages or
    // submitting a save from them (drafting an audit, writing a manager
    // review justification, or attaching files to a price variation can
    // each take >10 minutes).
    $exemptPages   = ['audit_new', 'audit_edit', 'audit_manager_review', 'audit_operation_review', 'audit_management_review',
                      'audit_annotation_image'];
    $exemptActions = ['create_audit', 'save_audit_weights', 'delete_audit_attachment', 'approve_audit', 'manager_review_audit',
                      'operation_review_audit', 'management_approve_audit',
                      'create_audit_annotation', 'add_audit_annotation_comment', 'resolve_audit_annotation', 'reopen_audit_annotation',
                      'pv_add_attachment'];
    $curPage   = $_GET['page'] ?? '';
    $curAction = $_POST['action'] ?? '';
    if (in_array($curPage, $exemptPages, true) || in_array($curAction, $exemptActions, true)) {
        $_SESSION['last_activity'] = time();
        return;
    }

    $now  = time();
    $last = (int)($_SESSION['last_activity'] ?? 0);
    if ($last > 0 && ($now - $last) > IDLE_TIMEOUT_SECONDS) {
        // Wipe the session entirely, then start a fresh one just to carry the flash
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        session_start();
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Session expired after 10 minutes of inactivity. Please log in again.'];
        header('Location: index.php');
        exit;
    }
    $_SESSION['last_activity'] = $now;
}
function isSuperadmin(): bool { return ($_SESSION['bio_role'] ?? '') === 'superadmin'; }
function myCode(): string     { return $_SESSION['bio_code'] ?? ''; }
function myName(): string     { return $_SESSION['bio_name'] ?? ''; }
function myLocationId(): int  { return (int)($_SESSION['bio_location_id'] ?? 0); }
function myDeptId(): int      { return (int)($_SESSION['bio_dept_id'] ?? 0); }
function myDeptName(): string { return $_SESSION['bio_dept_name'] ?? ''; }

// ── Transaction-level access check ──────────────────────
function hasTxn(string $txn): bool {
    if (isSuperadmin()) return true;
    return (bool)($_SESSION['bio_txns']['txn_' . $txn] ?? 0);
}

// ── Permission helpers ──────────────────────────────────
function canManageEmployees(): bool { return isSuperadmin() || hasTxn('employees'); }
function canManageIssues(): bool    { return isSuperadmin() || hasTxn('edit_issue'); }
// Checklist management is split by assign_type: manage_tasks governs the
// location-assigned (Store) checklists, manage_dept_tasks the employee-assigned
// (factory department) ones. chkCanManageChecklist() in checklist.php decides
// per checklist; these are the coarse page/route gates.
function canManageTasks(): bool     { return isSuperadmin() || hasTxn('manage_tasks'); }
function canManageDeptTasks(): bool { return isSuperadmin() || hasTxn('manage_dept_tasks'); }
function canManageAnyChecklist(): bool { return canManageTasks() || canManageDeptTasks(); }
function canViewAttendance(): bool  { return isSuperadmin() || hasTxn('attendance'); }

function defaultPage(): string {
    if (isSuperadmin()) return 'dashboard';
    if (hasTxn('dashboard'))  return 'dashboard';
    if (hasTxn('employees'))  return 'employees';
    if (hasTxn('issues'))     return 'issues';
    if (hasTxn('checklist'))  return 'checklist';
    return 'mypunches';
}

function doLogin(): void {
    $id   = trim($_POST['identifier'] ?? '');
    $pass = trim($_POST['password']   ?? '');
    if ($id === '' || $pass === '') {
        flash('error', 'Enter username and password.');
        header('Location: index.php'); return;
    }
    // Superadmin keyword login
    if ($id === 'superadmin' && $pass === getSetting('SuperadminPassword', 'superadmin123')) {
        $_SESSION['bio_role'] = 'superadmin';
        $_SESSION['last_activity'] = time();
        $_SESSION['bio_txns'] = [
            'txn_dashboard' => 1, 'txn_employees' => 1, 'txn_departments' => 1,
            'txn_locations' => 1, 'txn_attendance' => 1, 'txn_approve_punches' => 1,
            'txn_failed_punches' => 1, 'txn_issues' => 1, 'txn_create_issue' => 1,
            'txn_edit_issue' => 1, 'txn_issue_summary' => 1, 'txn_issue_comments' => 1,
            'txn_checklist' => 1, 'txn_manage_tasks' => 1, 'txn_manage_dept_tasks' => 1,
            'txn_checklist_report' => 1, 'txn_checklist_audit' => 1,
            'txn_offer' => 1, 'txn_coupon_redeemed' => 1,
            'txn_devices' => 1, 'txn_manage_categories' => 1,
            'txn_generate_coupons' => 1, 'txn_manage_passwords' => 1, 'txn_settings' => 1,
            'txn_generate_vouchers' => 1,
            'txn_outlet_directory' => 1, 'txn_shelf_life' => 1,
            'txn_shelf_life_upload' => 1, 'txn_store_hours' => 1,
            'txn_price_tags' => 1,
            'txn_dependencies' => 1, 'txn_dept_roles' => 1,
            'txn_audit_create' => 1, 'txn_audit_approve' => 1,
            'txn_audit_admin' => 1, 'txn_audit_view' => 1, 'txn_audit_summary' => 1,
            'txn_violations_view' => 1, 'txn_record_violation' => 1,
            'txn_reset_violation_counter' => 1, 'txn_violation_admin' => 1,
            'txn_price_variation' => 1, 'txn_price_variation_confirm' => 1, 'txn_price_variation_admin' => 1,
            'txn_transactions_report' => 1,
            'txn_policy_admin' => 1, 'txn_policy_dashboard' => 1,
        ];
        header('Location: index.php?page=dashboard'); return;
    }
    // Biometric device enrollment login
    if ($id === 'biometric' && $pass === getSetting('AdminPassword', '111')) {
        $_SESSION['bio_role'] = 'employee';
        $_SESSION['last_activity'] = time();
        $_SESSION['bio_txns'] = [
            'txn_dashboard' => 1, 'txn_employees' => 1, 'txn_departments' => 1,
            'txn_locations' => 1, 'txn_attendance' => 1, 'txn_approve_punches' => 1,
            'txn_failed_punches' => 1, 'txn_devices' => 1,
        ];
        header('Location: index.php?page=employees'); return;
    }
    // Employee login — fetch role transaction flags (role is independent of department)
    // Use r.* so any future txn_* column on `roles` is picked up without
    // requiring this SELECT to be edited. Without the wildcard, missing
    // columns throw "Unknown column" inside the try/catch and the user
    // sees the misleading "Invalid credentials." message even when the
    // password is correct. Note: `id` is overwritten by r.id in FETCH_ASSOC,
    // but we never read $emp['id'] so it's harmless.
    try {
        $st = getDb()->prepare(
            'SELECT e.employee_code, e.full_name, e.portal_password, e.location_id, e.department_id, e.role_id,
                    d.department_name,
                    r.*
             FROM employees e
             LEFT JOIN departments d ON e.department_id = d.id
             LEFT JOIN roles       r ON e.role_id       = r.id
             WHERE e.employee_code = ? AND e.is_active = 1 AND e.portal_password IS NOT NULL');
        $st->execute([mb_strtoupper($id)]);
        $emp = $st->fetch();
        if ($emp && $emp['portal_password'] === $pass) {
            $_SESSION['bio_role'] = 'employee';
            $_SESSION['last_activity'] = time();
            $_SESSION['bio_code'] = $emp['employee_code'];
            $_SESSION['bio_name'] = $emp['full_name'];
            $_SESSION['bio_location_id'] = (int)($emp['location_id'] ?? 0);
            $_SESSION['bio_dept_id'] = (int)($emp['department_id'] ?? 0);
            $_SESSION['bio_dept_name'] = (string)($emp['department_name'] ?? '');
            $_SESSION['bio_txns'] = [
                'txn_dashboard'        => (int)($emp['txn_dashboard'] ?? 0),
                'txn_employees'        => (int)($emp['txn_employees'] ?? 0),
                'txn_departments'      => (int)($emp['txn_departments'] ?? 0),
                'txn_locations'        => (int)($emp['txn_locations'] ?? 0),
                'txn_attendance'       => (int)($emp['txn_attendance'] ?? 0),
                'txn_approve_punches'  => (int)($emp['txn_approve_punches'] ?? 0),
                'txn_failed_punches'   => (int)($emp['txn_failed_punches'] ?? 0),
                'txn_issues'           => (int)($emp['txn_issues'] ?? 0),
                'txn_create_issue'     => (int)($emp['txn_create_issue'] ?? 0),
                'txn_edit_issue'       => (int)($emp['txn_edit_issue'] ?? 0),
                'txn_checklist'        => (int)($emp['txn_checklist'] ?? 0),
                'txn_manage_tasks'     => (int)($emp['txn_manage_tasks'] ?? 0),
                'txn_manage_dept_tasks' => (int)($emp['txn_manage_dept_tasks'] ?? 0),
                'txn_checklist_report' => (int)($emp['txn_checklist_report'] ?? 0),
                'txn_checklist_audit'  => (int)($emp['txn_checklist_audit'] ?? 0),
                'txn_offer'            => (int)($emp['txn_offer'] ?? 0),
                'txn_coupon_redeemed'  => (int)($emp['txn_coupon_redeemed'] ?? 0),
                'txn_issue_summary'    => (int)($emp['txn_issue_summary'] ?? 0),
                'txn_issue_comments'   => (int)($emp['txn_issue_comments'] ?? 0),
                'txn_devices'          => (int)($emp['txn_devices'] ?? 0),
                'txn_manage_categories' => (int)($emp['txn_manage_categories'] ?? 0),
                'txn_generate_coupons' => (int)($emp['txn_generate_coupons'] ?? 0),
                'txn_manage_passwords' => (int)($emp['txn_manage_passwords'] ?? 0),
                'txn_settings'         => (int)($emp['txn_settings'] ?? 0),
                'txn_generate_vouchers' => (int)($emp['txn_generate_vouchers'] ?? 0),
                'txn_outlet_directory'  => (int)($emp['txn_outlet_directory'] ?? 0),
                'txn_shelf_life'        => (int)($emp['txn_shelf_life'] ?? 0),
                'txn_shelf_life_upload' => (int)($emp['txn_shelf_life_upload'] ?? 0),
                'txn_store_hours'       => (int)($emp['txn_store_hours'] ?? 0),
                'txn_price_tags'        => (int)($emp['txn_price_tags'] ?? 0),
                'txn_dependencies'      => (int)($emp['txn_dependencies'] ?? 0),
                'txn_dept_roles'        => (int)($emp['txn_dept_roles'] ?? 0),
                'txn_audit_create'      => (int)($emp['txn_audit_create'] ?? 0),
                'txn_audit_approve'     => (int)($emp['txn_audit_approve'] ?? 0),
                'txn_audit_admin'       => (int)($emp['txn_audit_admin'] ?? 0),
                'txn_audit_view'        => (int)($emp['txn_audit_view'] ?? 0),
                'txn_audit_summary'     => (int)($emp['txn_audit_summary'] ?? 0),
                'txn_violations_view'         => (int)($emp['txn_violations_view'] ?? 0),
                'txn_record_violation'        => (int)($emp['txn_record_violation'] ?? 0),
                'txn_reset_violation_counter' => (int)($emp['txn_reset_violation_counter'] ?? 0),
                'txn_violation_admin'         => (int)($emp['txn_violation_admin'] ?? 0),
                'txn_price_variation'         => (int)($emp['txn_price_variation'] ?? 0),
                'txn_price_variation_confirm' => (int)($emp['txn_price_variation_confirm'] ?? 0),
                'txn_price_variation_admin'   => (int)($emp['txn_price_variation_admin'] ?? 0),
                'txn_transactions_report'     => (int)($emp['txn_transactions_report'] ?? 0),
                'txn_policy_admin'            => (int)($emp['txn_policy_admin'] ?? 0),
                'txn_policy_dashboard'        => (int)($emp['txn_policy_dashboard'] ?? 0),
                'txn_audit_operation'         => (int)($emp['txn_audit_operation'] ?? 0),
                'txn_audit_management'        => (int)($emp['txn_audit_management'] ?? 0),
                'txn_audit_annotation_resolve' => (int)($emp['txn_audit_annotation_resolve'] ?? 0),
                'txn_time_report'             => (int)($emp['txn_time_report'] ?? 0),
                'txn_checklist_validate'      => (int)($emp['txn_checklist_validate'] ?? 0),
                'txn_ticket_scheduler'        => (int)($emp['txn_ticket_scheduler'] ?? 0),
            ];
            // Soft nudge: if the employee has any unaccepted in-scope policy,
            // land them on the policy view page (regardless of grace period).
            // After grace expires the per-request gate in index.php takes over
            // and forces them to stay until accepted; within grace they can
            // navigate away from this page like any other.
            if (function_exists('policyFirstUnacceptedVersion')) {
                $pending = policyFirstUnacceptedVersion($emp['employee_code']);
                if ($pending) {
                    header('Location: index.php?page=policy_view&id=' . (int)$pending['id']);
                    return;
                }
            }
            header('Location: index.php?page=' . defaultPage()); return;
        }
    } catch (Exception $e) {
        error_log('[auth] login failed for ' . $id . ': ' . $e->getMessage());
    }
    flash('error', 'Invalid credentials.');
    header('Location: index.php');
}

function doLogout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: index.php');
}
function flash(string $t, string $m): void { $_SESSION['flash'] = ['type' => $t, 'msg' => $m]; }
