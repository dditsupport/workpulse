-- =========================================================
-- WorkPulse — a checklist task's minutes belong to a day, not to a cycle
-- 2026-08-15
--
-- A weekly or monthly task has exactly one chk_daily_responses row per
-- employee per cycle — a monthly task's answers all live on the 1st. That
-- row carried a single time_minutes and a single worked_on, so the minutes
-- were a property of the whole month:
--
--   * every day of August pre-filled the duration box with them,
--   * saving any later day re-stamped worked_on and moved the timesheet
--     entry off the day the work was actually done,
--   * blanking the box on a later day cleared the only copy, so the
--     original day's entry disappeared as well.
--
-- Minutes now live here, one row per task per day worked, which is the
-- shape the timesheet has always needed: 1h on the 12th and 30m on the
-- 15th are two rows, and clearing one leaves the other alone.
--
-- chk_daily_responses.time_minutes stays, holding the cycle total, so
-- every existing report and done-count reads exactly as it did.
--
-- log_date is the cycle anchor the task's answer sits under (the same
-- value chk_daily_responses uses), kept so a cycle's days can be summed
-- without re-deriving the anchor from worked_on.
--
-- Additive and safe to run at any time. Until it is run, chkHasTaskTimeDay()
-- reads false and the module keeps its current single-row behaviour.
-- =========================================================

CREATE TABLE IF NOT EXISTS `chk_task_time` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `checklist_id` int(10) UNSIGNED NOT NULL,
  `location_id` int(11) NOT NULL DEFAULT 0,
  `item_id` int(10) UNSIGNED NOT NULL,
  `employee_code` varchar(20) NOT NULL,
  `log_date` date NOT NULL COMMENT 'cycle anchor the answer sits under',
  `worked_on` date NOT NULL COMMENT 'calendar day the minutes were spent',
  `minutes` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chk_task_day` (`checklist_id`,`location_id`,`item_id`,`employee_code`,`worked_on`),
  KEY `idx_chk_emp_day` (`checklist_id`,`employee_code`,`worked_on`),
  KEY `idx_chk_item_cycle` (`checklist_id`,`item_id`,`log_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Backfill: every response that already carries minutes becomes one day's
-- row, dated by worked_on where that column recorded it and by the cycle
-- anchor where it did not. Re-runnable — the unique key absorbs a second go.
INSERT IGNORE INTO `chk_task_time`
    (`checklist_id`, `location_id`, `item_id`, `employee_code`, `log_date`, `worked_on`, `minutes`)
SELECT r.checklist_id, r.location_id, r.item_id, r.employee_code,
       r.log_date, COALESCE(r.worked_on, r.log_date), r.time_minutes
FROM `chk_daily_responses` r
WHERE r.time_minutes IS NOT NULL AND r.time_minutes > 0;
