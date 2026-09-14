-- =========================================================
-- WorkPulse — a checklist task's remark belongs to a day, not to a cycle
-- 2026-09-14
--
-- The companion to 2026-08-15_chk_task_time.sql, which moved a task's
-- MINUTES onto the day they were worked. The remark beside them stayed on
-- chk_daily_responses, which holds one row per cycle — so on a weekly or
-- monthly task the remark was a property of the whole cycle:
--
--   * every later day of the month pre-filled the box with it (a remark
--     typed on 2 Sep was still sitting in the box on the 14th),
--   * My Time entries for every other day of the cycle carried it as
--     their note, whatever was actually done that day,
--   * typing a new one overwrote the only copy, so the first day's note
--     changed under it.
--
-- The remark now lives beside the minutes it belongs to, one row per task
-- per day worked. A remark with no minutes is a row with minutes = 0 —
-- that is a note about the day's work, and the timesheet skips it as it
-- always has.
--
-- chk_daily_responses.remarks stays, holding the cycle's roll-up (one
-- day's remark reads exactly as before; several are joined, each under its
-- date), so the reports, the audit and the validate page keep reading the
-- column they always have.
--
-- Timesheet entries already written keep the notes they were given — the
-- cycle's remark, on every day of it — until their day is saved again. Only
-- the day being filled is ever resynced, so past entries are left alone
-- rather than rewritten under the people who read them.
--
-- Additive and safe to run at any time. Until it is run, the module keeps
-- its current cycle-wide behaviour.
-- =========================================================

ALTER TABLE `chk_task_time`
  ADD COLUMN IF NOT EXISTS `remarks` varchar(500) DEFAULT NULL AFTER `minutes`;

-- Backfill. A cycle's remark is dated by the day the response was stamped
-- with (worked_on), falling back to the cycle anchor — the same rule the
-- minutes backfill used, so a remark lands on one day rather than on every
-- day of its cycle.
--
-- First the days that have no row of their own (a remark typed without
-- minutes), then the remark onto the rows that already exist.
INSERT IGNORE INTO `chk_task_time`
    (`checklist_id`, `location_id`, `item_id`, `employee_code`, `log_date`, `worked_on`, `minutes`, `remarks`)
SELECT r.checklist_id, r.location_id, r.item_id, r.employee_code,
       r.log_date, COALESCE(r.worked_on, r.log_date), 0, r.remarks
FROM `chk_daily_responses` r
WHERE r.remarks IS NOT NULL AND r.remarks <> '';

UPDATE `chk_task_time` tt
  JOIN `chk_daily_responses` r
    ON r.checklist_id = tt.checklist_id AND r.location_id = tt.location_id
   AND r.item_id      = tt.item_id      AND r.employee_code = tt.employee_code
   AND r.log_date     = tt.log_date
   SET tt.remarks = r.remarks
 WHERE tt.remarks IS NULL
   AND r.remarks IS NOT NULL AND r.remarks <> ''
   AND tt.worked_on = COALESCE(r.worked_on, r.log_date);
