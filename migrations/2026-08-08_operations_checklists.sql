-- =========================================================
-- WorkPulse — Operations checklists (five owners)
-- Generated 2026-08-08 from the department spreadsheets.
--
-- Companion to 2026-08-07_ho_checklists.sql, same shape: a checklist
-- carries one cycle, so an owner whose sheet mixes cycles becomes more
-- than one checklist. Frequency is taken from each sheet's own column.
--
-- Paresh's two weekly tasks are the first weekly work in the system —
-- they close Saturday midnight.
--
-- Inserts only. No existing row is touched. Ids come from AUTO_INCREMENT
-- and travel in session variables, never hardcoded.
-- =========================================================

START TRANSACTION;

-- ── Operation - Paresh — daily (18 tasks) ──
INSERT INTO `chk_checklists`
  (`name`, `assign_type`, `frequency`, `time_gated`, `rollover_min`, `sort_order`, `is_active`)
  VALUES ('Operation - Paresh', 'employee', 'daily', 0, 0, 12, 1);
SET @cl_op_paresh = LAST_INSERT_ID();
INSERT INTO `chk_items`
  (`checklist_id`, `task_description`, `section_name`, `section_id`, `input_type`, `est_minutes`, `is_active`)
VALUES
  (@cl_op_paresh, 'Corporate client Requirement & Invoice', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_paresh, 'Advance order & Bill Cancellation Approval', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_paresh, 'Pending GR report', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_paresh, 'Day Open ( Cash register )', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_paresh, 'Operation Report', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_paresh, 'Banking Data', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_paresh, 'Advance order', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_paresh, 'Staff Management', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_paresh, 'Store Hygin & Audit Replay Followup', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_paresh, 'Electricity bill & Maintenance bill', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_paresh, 'Franchise store Payment follow up', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_paresh, 'Target Vs. Achievement Report', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_paresh, 'Wastage Report', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_paresh, 'MSL UPDATE', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_paresh, 'Zoom Meeting', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_paresh, 'Store Hygiene Visit', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_paresh, 'Meetings', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_paresh, 'Other Tasks', NULL, NULL, 'text', 0, 1);

-- ── Operation - Paresh - Weekly — weekly (2 tasks) ──
INSERT INTO `chk_checklists`
  (`name`, `assign_type`, `frequency`, `time_gated`, `rollover_min`, `sort_order`, `is_active`)
  VALUES ('Operation - Paresh - Weekly', 'employee', 'weekly', 0, 0, 13, 1);
SET @cl_op_paresh_weekly = LAST_INSERT_ID();
INSERT INTO `chk_items`
  (`checklist_id`, `task_description`, `section_name`, `section_id`, `input_type`, `est_minutes`, `is_active`)
VALUES
  (@cl_op_paresh_weekly, 'Back end distribution & Requirement', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_paresh_weekly, 'Price Tag & other printing (Shyamal Tag)', NULL, NULL, 'yes_no', 0, 1);

-- ── Operation - Paresh - Monthly — monthly (9 tasks) ──
INSERT INTO `chk_checklists`
  (`name`, `assign_type`, `frequency`, `time_gated`, `rollover_min`, `sort_order`, `is_active`)
  VALUES ('Operation - Paresh - Monthly', 'employee', 'monthly', 0, 0, 14, 1);
SET @cl_op_paresh_monthly = LAST_INSERT_ID();
INSERT INTO `chk_items`
  (`checklist_id`, `task_description`, `section_name`, `section_id`, `input_type`, `est_minutes`, `is_active`)
VALUES
  (@cl_op_paresh_monthly, 'Coupon Code for MRP Variance', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_paresh_monthly, 'Over time & Week off`s Paid', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_paresh_monthly, 'Month end P&P closing sheet', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_paresh_monthly, 'Discount Scheme Extend mail', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_paresh_monthly, 'Deduction', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_paresh_monthly, 'Extra paid and w off management data prerper', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_paresh_monthly, 'Monthly Review Meeting', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_paresh_monthly, 'Store Closing Process', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_paresh_monthly, 'Store Opening Process', NULL, NULL, 'yes_no', 0, 1);

-- ── Operation - Soheb — daily (16 tasks) ──
INSERT INTO `chk_checklists`
  (`name`, `assign_type`, `frequency`, `time_gated`, `rollover_min`, `sort_order`, `is_active`)
  VALUES ('Operation - Soheb', 'employee', 'daily', 0, 0, 15, 1);
SET @cl_op_soheb = LAST_INSERT_ID();
INSERT INTO `chk_items`
  (`checklist_id`, `task_description`, `section_name`, `section_id`, `input_type`, `est_minutes`, `is_active`)
VALUES
  (@cl_op_soheb, 'store wise day open', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_soheb, 'work pulse ticket review', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_soheb, 'work pulse checklist review', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_soheb, 'banking following up', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_soheb, 'click up task following up', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_soheb, 'msl update storewise', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_soheb, 'audit reply', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_soheb, 'timesheet', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_soheb, 'store wise voucher submitation', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_soheb, 'maintainance following up releted store ticket', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_soheb, 'store wise querie resolvation', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_soheb, 'store wise packaging material pto', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_soheb, 'store hygiene visit store wise', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_soheb, 'Monthly Review Meeting', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_soheb, 'Meetings', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_soheb, 'Work Pulse Tickets Report', NULL, NULL, 'yes_no', 0, 1);

-- ── Operation - Nilesh — daily (17 tasks) ──
INSERT INTO `chk_checklists`
  (`name`, `assign_type`, `frequency`, `time_gated`, `rollover_min`, `sort_order`, `is_active`)
  VALUES ('Operation - Nilesh', 'employee', 'daily', 0, 0, 16, 1);
SET @cl_op_nilesh = LAST_INSERT_ID();
INSERT INTO `chk_items`
  (`checklist_id`, `task_description`, `section_name`, `section_id`, `input_type`, `est_minutes`, `is_active`)
VALUES
  (@cl_op_nilesh, 'Update & Follow up of tasks', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_nilesh, 'Voucher Approval', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_nilesh, 'Voice of Customer', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_nilesh, 'Daily Target & Achievement', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_nilesh, 'Sales Report', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_nilesh, 'Calling Sheet', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_nilesh, 'Interview Sheet', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_nilesh, 'Meeting', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_nilesh, 'Store Hygiene Visit', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_nilesh, 'Operator Invoice', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_nilesh, 'Monthly Review', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_nilesh, 'Monthly Review Data', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_nilesh, 'Surat New Store', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_nilesh, 'Ahmedabad New Store', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_nilesh, 'Brand Guideline', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_nilesh, 'Ambience/Theme', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_nilesh, 'Staff Uniform', NULL, NULL, 'yes_no', 0, 1);

-- ── Operation - Kirit — daily (10 tasks) ──
INSERT INTO `chk_checklists`
  (`name`, `assign_type`, `frequency`, `time_gated`, `rollover_min`, `sort_order`, `is_active`)
  VALUES ('Operation - Kirit', 'employee', 'daily', 0, 0, 17, 1);
SET @cl_op_kirit = LAST_INSERT_ID();
INSERT INTO `chk_items`
  (`checklist_id`, `task_description`, `section_name`, `section_id`, `input_type`, `est_minutes`, `is_active`)
VALUES
  (@cl_op_kirit, 'Audit Followup', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_kirit, 'Check, Update, Follow Up for Reports', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_kirit, 'Check store hygiene tasks and update status', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_kirit, 'Sub zero meter for New AC', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_kirit, 'Store Closure', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_kirit, 'Monthly Review Meeting', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_kirit, 'Generate Time Sheets', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_kirit, 'Other extra work', NULL, NULL, 'text', 0, 1),
  (@cl_op_kirit, 'Panjrapole Meeting', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_kirit, 'Store Hygiene Visit', NULL, NULL, 'yes_no', 0, 1);

-- ── Operation - Pradhyuman — daily (27 tasks) ──
INSERT INTO `chk_checklists`
  (`name`, `assign_type`, `frequency`, `time_gated`, `rollover_min`, `sort_order`, `is_active`)
  VALUES ('Operation - Pradhyuman', 'employee', 'daily', 0, 0, 18, 1);
SET @cl_op_pradhyuman = LAST_INSERT_ID();
INSERT INTO `chk_items`
  (`checklist_id`, `task_description`, `section_name`, `section_id`, `input_type`, `est_minutes`, `is_active`)
VALUES
  (@cl_op_pradhyuman, 'Maitenance Tickets', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_pradhyuman, 'Update and Follow up of tasks', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_pradhyuman, 'Interview', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_pradhyuman, 'Short banking report', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_pradhyuman, 'Store Hygiene reports', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_pradhyuman, 'audit report', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_pradhyuman, 'Daily store time track', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_pradhyuman, 'Sales Report', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_pradhyuman, 'Wastsge report', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_pradhyuman, 'Advance Order Bill cancellation', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_pradhyuman, 'Overtime Report', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_pradhyuman, 'Deduction Data', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_pradhyuman, 'Making Reports', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_pradhyuman, 'Updating MSL', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_pradhyuman, 'Operational Plan', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_pradhyuman, 'Monthly Review', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_pradhyuman, 'Panjrapole Training', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_pradhyuman, 'Zoom Meeting', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_pradhyuman, 'Store Hygiene Visit', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_pradhyuman, 'Corporate Client Visit', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_pradhyuman, 'Event Handling', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_pradhyuman, 'New Outlet Opening Setup', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_pradhyuman, 'Outlet Closing Process', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_pradhyuman, 'Customer Complain Handling', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_pradhyuman, 'Logistics & Production Issue handling', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_pradhyuman, 'Meeting', NULL, NULL, 'yes_no', 0, 1),
  (@cl_op_pradhyuman, 'Trading Stock Followup with Store and Purchase', NULL, NULL, 'yes_no', 0, 1);

-- ── Operation - Pradhyuman - Monthly — monthly (1 task) ──
INSERT INTO `chk_checklists`
  (`name`, `assign_type`, `frequency`, `time_gated`, `rollover_min`, `sort_order`, `is_active`)
  VALUES ('Operation - Pradhyuman - Monthly', 'employee', 'monthly', 0, 0, 19, 1);
SET @cl_op_pradhyuman_monthly = LAST_INSERT_ID();
INSERT INTO `chk_items`
  (`checklist_id`, `task_description`, `section_name`, `section_id`, `input_type`, `est_minutes`, `is_active`)
VALUES
  (@cl_op_pradhyuman_monthly, 'Special Day Planning & PTO', NULL, NULL, 'yes_no', 0, 1);

COMMIT;

-- 8 checklists, 100 tasks.
-- Each is employee-assigned: add the designated filler under
-- Manage Checklists → Designated fillers before it appears for them.
