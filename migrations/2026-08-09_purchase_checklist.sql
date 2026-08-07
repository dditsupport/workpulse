-- =========================================================
-- WorkPulse — Purchase checklist
-- Generated 2026-08-09 from the department spreadsheet.
--
-- Last of the nine department sheets. Companion to
-- 2026-08-07_ho_checklists.sql and 2026-08-08_operations_checklists.sql.
--
-- Every task on this sheet is daily, so it makes a single checklist.
-- The sheet's rows 11-15 are numbered but carry no task and no cycle —
-- unfilled placeholders, so they import as nothing.
--
-- Inserts only. No existing row is touched. The id comes from
-- AUTO_INCREMENT and travels in a session variable, never hardcoded.
-- =========================================================

START TRANSACTION;

-- ── Purchase Department — daily (10 tasks) ──
INSERT INTO `chk_checklists`
  (`name`, `assign_type`, `frequency`, `time_gated`, `rollover_min`, `sort_order`, `is_active`)
  VALUES ('Purchase Department', 'employee', 'daily', 0, 0, 20, 1);
SET @cl_purchase = LAST_INSERT_ID();
INSERT INTO `chk_items`
  (`checklist_id`, `task_description`, `section_name`, `section_id`, `input_type`, `est_minutes`, `is_active`)
VALUES
  (@cl_purchase, 'Download Pending PTO Report', NULL, NULL, 'yes_no', 0, 1),
  (@cl_purchase, 'Check Material Requirement Report', NULL, NULL, 'yes_no', 0, 1),
  (@cl_purchase, 'Meeting with Supplier', NULL, NULL, 'yes_no', 0, 1),
  (@cl_purchase, 'Material Follow up with Vendor', NULL, NULL, 'yes_no', 0, 1),
  (@cl_purchase, 'Payment Discussion/ Follow up with Accounts Team', NULL, NULL, 'yes_no', 0, 1),
  (@cl_purchase, 'PO Prepartion, Discussion & Share to Vendor', NULL, NULL, 'yes_no', 0, 1),
  (@cl_purchase, 'Update & Follow up of Task', NULL, NULL, 'yes_no', 0, 1),
  (@cl_purchase, 'Work Pulse Ticket', NULL, NULL, 'yes_no', 0, 1),
  (@cl_purchase, 'Supplier Payment Followup', NULL, NULL, 'yes_no', 0, 1),
  (@cl_purchase, 'Replacement Expiry Stock and Credit note follow up', NULL, NULL, 'yes_no', 0, 1);

COMMIT;

-- 1 checklist, 10 tasks.
-- Employee-assigned: add the designated filler under
-- Manage Checklists → Designated fillers before it appears for them.
