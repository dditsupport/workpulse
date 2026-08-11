-- =========================================================
-- WorkPulse — a timesheet entry can name the checklist task it came from
-- 2026-08-13
--
-- A day's checklist work collapsed into one row, "Checklist — IT Department",
-- with every task's remark joined into a single notes string. The timesheet
-- said which department was worked on, not what was done. Each task carrying
-- time now gets its own entry, and this column is how it says which task.
--
-- The task is referenced rather than copied into task_label, because
-- doSaveTimeEntry() nulls that column whenever an entry is edited — a name
-- stored there would vanish the first time someone corrected a duration.
--
-- Additive and safe to run at any time. Entries written before it keep a NULL
-- here and go on grouping per checklist, exactly as they do now; nothing
-- rewrites history.
-- =========================================================

ALTER TABLE `time_entries`
  ADD COLUMN IF NOT EXISTS `chk_item_id` int(10) UNSIGNED DEFAULT NULL AFTER `checklist_id`;
