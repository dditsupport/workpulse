-- =========================================================
-- WorkPulse — the Google Rating check runs once a month, not every day
-- 2026-09-03
--
-- "Check Google Rating between (1st to 15th) with screenshot." sat in the
-- Store Checklist's Afternoon section, whose cycle is Daily. So the filler
-- asked for it every single day and Checklist Overview could never reach
-- 31/31 — every cell read 30/31 in yellow because of this one task.
--
-- A checklist has been able to mix cycles since 2026-08-12_merge_cycle_sections
-- .sql: a task takes its cycle from its section (chkItemFreq()). Giving the
-- Store Checklist a Monthly section and moving this one task into it is the
-- whole fix. Its answers then log against the month's anchor — the 1st — via
-- chkItemLogDate(), and the counting side is handled in code by
-- chkDoneByLocationDay(), which credits a monthly answer to every day of the
-- month it covers.
--
-- start_min/end_min are left at the column defaults: a non-daily section
-- ignores them outright (checklistSectionState() returns 'open' for the whole
-- cycle) and Manage Checklists prints "whole cycle" in place of a window.
--
-- Existing chk_daily_responses rows are left alone. They carry past daily
-- dates, and chkDoneByLocationDay() normalises any log_date to its cycle
-- anchor before counting, so no answer is lost or double-counted.
--
-- Matched on task_description rather than id, so it behaves the same on any
-- database that took the earlier migrations, and the checklist is taken from
-- the task itself rather than assumed. Idempotent: a second run finds the
-- section already there and the task already moved.
-- =========================================================

START TRANSACTION;

-- ── 1. A Monthly section on whichever checklist holds the task ──
INSERT INTO `chk_sections` (`checklist_id`, `name`, `frequency`, `start_min`, `end_min`, `sort_order`)
SELECT DISTINCT i.`checklist_id`, 'Monthly', 'monthly', 0, 1440, 4
  FROM `chk_items` i
 WHERE i.`is_active` = 1
   AND i.`task_description` LIKE 'Check Google Rating%'
   AND i.`checklist_id` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `chk_sections` s
                    WHERE s.`checklist_id` = i.`checklist_id`
                      AND s.`name` = 'Monthly' AND s.`frequency` = 'monthly');

-- ── 2. Move the task into it ─────────────────────────────
-- section_name is a denormalised copy of the section's name and is what the
-- Overview's Section filter matches on, so it moves with section_id.
UPDATE `chk_items` i
  JOIN `chk_sections` s
    ON s.`checklist_id` = i.`checklist_id`
   AND s.`name` = 'Monthly' AND s.`frequency` = 'monthly'
   SET i.`section_id` = s.`id`, i.`section_name` = s.`name`
 WHERE i.`is_active` = 1
   AND i.`task_description` LIKE 'Check Google Rating%';

COMMIT;

-- Check afterwards:
--   SELECT s.name AS section, s.frequency, s.sort_order,
--          i.task_description, i.section_name
--     FROM chk_items i
--     JOIN chk_sections s ON s.id = i.section_id
--    WHERE i.task_description LIKE 'Check Google Rating%';
