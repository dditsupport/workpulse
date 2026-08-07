-- =========================================================
-- WorkPulse — head-office checklists (Admin, HR, IT)
-- Generated 2026-08-07 from the department spreadsheets.
--
-- Frequency is recorded per task in the source sheets, but a
-- checklist carries one cycle, so a department whose sheet mixes
-- cycles becomes two checklists — one daily, one monthly.
--
-- Adds rows only. The single existing row it touches is the factory
-- HR checklist (id 6), renamed HR-Factory so it no longer collides
-- with the new head-office HR list.
--
-- Safe to run once against gtvpheud_workpulse. Ids are allocated by
-- AUTO_INCREMENT and carried in session variables, never hardcoded,
-- so the Operations migration can follow without renumbering.
-- =========================================================

-- The cycle column. Already present on the live database; IF NOT
-- EXISTS (MariaDB 10.2+) makes re-running this harmless.
ALTER TABLE `chk_checklists`
  ADD COLUMN IF NOT EXISTS `frequency` enum('daily','weekly','monthly')
  NOT NULL DEFAULT 'daily' AFTER `assign_type`;

START TRANSACTION;

-- ── Rename the factory HR checklist ──────────────────────
-- Guarded on the old name so a second run cannot rename anything else.
UPDATE `chk_checklists` SET `name` = 'HR-Factory' WHERE `id` = 6 AND `name` = 'HR';

-- ── Admin - HO — daily (10 tasks) ──
INSERT INTO `chk_checklists`
  (`name`, `assign_type`, `frequency`, `time_gated`, `rollover_min`, `sort_order`, `is_active`)
  VALUES ('Admin - HO', 'employee', 'daily', 0, 0, 7, 1);
SET @cl_admin_daily = LAST_INSERT_ID();
INSERT INTO `chk_items`
  (`checklist_id`, `task_description`, `section_name`, `section_id`, `input_type`, `est_minutes`, `is_active`)
VALUES
  (@cl_admin_daily, 'Email Check', NULL, NULL, 'yes_no', 0, 1),
  (@cl_admin_daily, 'Document Meeting', NULL, NULL, 'yes_no', 0, 1),
  (@cl_admin_daily, 'Meeting', NULL, NULL, 'yes_no', 0, 1),
  (@cl_admin_daily, 'Timesheet', NULL, NULL, 'yes_no', 0, 1),
  (@cl_admin_daily, 'Planner', NULL, NULL, 'yes_no', 0, 1),
  (@cl_admin_daily, 'Work Pulse Ticket', NULL, NULL, 'yes_no', 0, 1),
  (@cl_admin_daily, 'Admin Expense', NULL, NULL, 'yes_no', 0, 1),
  (@cl_admin_daily, 'Creating Documents', NULL, NULL, 'yes_no', 0, 1),
  (@cl_admin_daily, 'Other Tasks', NULL, NULL, 'text', 0, 1),
  (@cl_admin_daily, 'Follow-up on Click Up Tasks', NULL, NULL, 'yes_no', 0, 1);

-- ── Admin - HO - Monthly — monthly (7 tasks) ──
INSERT INTO `chk_checklists`
  (`name`, `assign_type`, `frequency`, `time_gated`, `rollover_min`, `sort_order`, `is_active`)
  VALUES ('Admin - HO - Monthly', 'employee', 'monthly', 0, 0, 8, 1);
SET @cl_admin_monthly = LAST_INSERT_ID();
INSERT INTO `chk_items`
  (`checklist_id`, `task_description`, `section_name`, `section_id`, `input_type`, `est_minutes`, `is_active`)
VALUES
  (@cl_admin_monthly, 'Monthly Review Meeting', NULL, NULL, 'yes_no', 0, 1),
  (@cl_admin_monthly, 'Panjrapole Meeting', NULL, NULL, 'yes_no', 0, 1),
  (@cl_admin_monthly, 'Bills Payment', NULL, NULL, 'yes_no', 0, 1),
  (@cl_admin_monthly, 'Renewal of Insurance Policies', NULL, NULL, 'yes_no', 0, 1),
  (@cl_admin_monthly, 'Fire Extinguisher Renewal', NULL, NULL, 'yes_no', 0, 1),
  (@cl_admin_monthly, 'Weight Machine Renewal', NULL, NULL, 'yes_no', 0, 1),
  (@cl_admin_monthly, 'Sim Card', NULL, NULL, 'yes_no', 0, 1);

-- ── HR - HO — daily (13 tasks) ──
INSERT INTO `chk_checklists`
  (`name`, `assign_type`, `frequency`, `time_gated`, `rollover_min`, `sort_order`, `is_active`)
  VALUES ('HR - HO', 'employee', 'daily', 0, 0, 9, 1);
SET @cl_hr_ho = LAST_INSERT_ID();
INSERT INTO `chk_items`
  (`checklist_id`, `task_description`, `section_name`, `section_id`, `input_type`, `est_minutes`, `is_active`)
VALUES
  (@cl_hr_ho, 'Work Pulse Ticket', NULL, NULL, 'yes_no', 0, 1),
  (@cl_hr_ho, 'Update Attendance', NULL, NULL, 'yes_no', 0, 1),
  (@cl_hr_ho, 'Interview', NULL, NULL, 'yes_no', 0, 1),
  (@cl_hr_ho, 'Calling', NULL, NULL, 'yes_no', 0, 1),
  (@cl_hr_ho, 'Biometric Punching Process', NULL, NULL, 'yes_no', 0, 1),
  (@cl_hr_ho, 'Letters & Certificates', NULL, NULL, 'yes_no', 0, 1),
  (@cl_hr_ho, 'Uniform Process', NULL, NULL, 'yes_no', 0, 1),
  (@cl_hr_ho, 'Joining Process', NULL, NULL, 'yes_no', 0, 1),
  (@cl_hr_ho, 'HR Work', NULL, NULL, 'yes_no', 0, 1),
  (@cl_hr_ho, 'Attendance Exception', NULL, NULL, 'yes_no', 0, 1),
  (@cl_hr_ho, 'Workindia Payment', NULL, NULL, 'yes_no', 0, 1),
  (@cl_hr_ho, 'Calling Sheet', NULL, NULL, 'yes_no', 0, 1),
  (@cl_hr_ho, 'Interview Sheet', NULL, NULL, 'yes_no', 0, 1);

-- ── IT Department — daily (18 tasks) ──
INSERT INTO `chk_checklists`
  (`name`, `assign_type`, `frequency`, `time_gated`, `rollover_min`, `sort_order`, `is_active`)
  VALUES ('IT Department', 'employee', 'daily', 0, 0, 10, 1);
SET @cl_it_daily = LAST_INSERT_ID();
INSERT INTO `chk_items`
  (`checklist_id`, `task_description`, `section_name`, `section_id`, `input_type`, `est_minutes`, `is_active`)
VALUES
  (@cl_it_daily, 'Backup Harddrive Swap', NULL, NULL, 'yes_no', 0, 1),
  (@cl_it_daily, 'Logistic pending route check at 5pm', NULL, NULL, 'yes_no', 0, 1),
  (@cl_it_daily, 'Swiggy / Zomato price variance bill punch at 2pm', NULL, NULL, 'yes_no', 0, 1),
  (@cl_it_daily, 'Item / Vendor master creation, General Ledger Creation', NULL, NULL, 'yes_no', 0, 1),
  (@cl_it_daily, 'Outlet Remote Query', NULL, NULL, 'yes_no', 0, 1),
  (@cl_it_daily, 'Accounts Query', NULL, NULL, 'yes_no', 0, 1),
  (@cl_it_daily, 'Factory Query', NULL, NULL, 'yes_no', 0, 1),
  (@cl_it_daily, 'Operation Query', NULL, NULL, 'yes_no', 0, 1),
  (@cl_it_daily, 'HO other staff Query', NULL, NULL, 'yes_no', 0, 1),
  (@cl_it_daily, 'Reelo / Msg91 balance check', NULL, NULL, 'yes_no', 0, 1),
  (@cl_it_daily, 'Server Monitoring & Health Management', NULL, NULL, 'yes_no', 0, 1),
  (@cl_it_daily, 'Network Infrastructure Monitoring (HO & Factory)', NULL, NULL, 'yes_no', 0, 1),
  (@cl_it_daily, 'Store Network Troubleshooting & ISP Coordination', NULL, NULL, 'yes_no', 0, 1),
  (@cl_it_daily, 'Vendor Coordination for IT Procurement & Repairs', NULL, NULL, 'yes_no', 0, 1),
  (@cl_it_daily, 'Outlet Technical Support Visits', NULL, NULL, 'yes_no', 0, 1),
  (@cl_it_daily, 'Ticket Status Update', NULL, NULL, 'yes_no', 0, 1),
  (@cl_it_daily, 'IT Asset Testing, Repair & Inventory Management', NULL, NULL, 'yes_no', 0, 1),
  (@cl_it_daily, 'Printer Support & Maintenance', NULL, NULL, 'yes_no', 0, 1);

-- ── IT Department - Monthly — monthly (8 tasks) ──
INSERT INTO `chk_checklists`
  (`name`, `assign_type`, `frequency`, `time_gated`, `rollover_min`, `sort_order`, `is_active`)
  VALUES ('IT Department - Monthly', 'employee', 'monthly', 0, 0, 11, 1);
SET @cl_it_monthly = LAST_INSERT_ID();
INSERT INTO `chk_items`
  (`checklist_id`, `task_description`, `section_name`, `section_id`, `input_type`, `est_minutes`, `is_active`)
VALUES
  (@cl_it_monthly, 'BOM Variance Report', NULL, NULL, 'yes_no', 0, 1),
  (@cl_it_monthly, 'Reelo Activity Report', NULL, NULL, 'yes_no', 0, 1),
  (@cl_it_monthly, 'GST Compliance report for Swiggy / Zomato', NULL, NULL, 'yes_no', 0, 1),
  (@cl_it_monthly, 'BOM Cost report', NULL, NULL, 'yes_no', 0, 1),
  (@cl_it_monthly, 'GPCB Report', NULL, NULL, 'yes_no', 0, 1),
  (@cl_it_monthly, 'GPCB Half yearly report', NULL, NULL, 'yes_no', 0, 1),
  (@cl_it_monthly, 'Power BI database update', NULL, NULL, 'yes_no', 0, 1),
  (@cl_it_monthly, 'Internet Bill Verification & Submission', NULL, NULL, 'yes_no', 0, 1);

COMMIT;

-- 5 checklists, 56 tasks.
-- Each is employee-assigned: add the designated filler under
-- Manage Checklists → Designated fillers before it appears for them.
