-- =========================================================
-- WorkPulse — the cycle moves from the checklist to the section
-- 2026-08-12
--
-- A department doing daily *and* monthly work needed two checklists. Now one
-- checklist holds Daily / Weekly / Monthly sections, so Admin, IT, Paresh and
-- Pradhyuman each collapse back to a single list.
--
-- Also adds chk_daily_responses.worked_on: the calendar day minutes were
-- actually entered, as against log_date which is the anchor of the cycle the
-- answer belongs to. Without it a monthly task filled on the 11th logged its
-- time on the 1st. Backfilled from log_date, so no existing timesheet row
-- moves.
--
-- Safe to run once. The merge matches on name and frequency rather than id, so
-- it behaves the same on any database that took the earlier migrations, and a
-- second run finds nothing left to move.
-- =========================================================

ALTER TABLE `chk_sections`
  ADD COLUMN IF NOT EXISTS `frequency` enum('daily','weekly','monthly')
  NOT NULL DEFAULT 'daily' AFTER `name`;

ALTER TABLE `chk_daily_responses`
  ADD COLUMN IF NOT EXISTS `worked_on` date DEFAULT NULL AFTER `log_date`;

START TRANSACTION;

-- Existing answers keep the day they already report in My Time.
UPDATE `chk_daily_responses` SET `worked_on` = `log_date` WHERE `worked_on` IS NULL;

-- ── 1. Every existing section is daily ───────────────────
-- The Store checklist's Morning/Afternoon/Evening bands and the factory
-- "Till 12:00" ones all describe one day. This is the column default, stated
-- outright so a re-run is unambiguous.
UPDATE `chk_sections` SET `frequency` = 'daily';

-- ── 2. Give every checklist a section per cycle it needs ──
-- Un-sectioned tasks (the head-office, Operations and Purchase imports) land
-- in a section named for their checklist's own cycle.
INSERT INTO `chk_sections` (`checklist_id`, `name`, `frequency`, `start_min`, `end_min`, `sort_order`)
SELECT c.`id`,
       CASE c.`frequency` WHEN 'weekly' THEN 'Weekly' WHEN 'monthly' THEN 'Monthly' ELSE 'Daily' END,
       c.`frequency`, 0, 1440,
       CASE c.`frequency` WHEN 'daily' THEN 1 WHEN 'weekly' THEN 2 ELSE 3 END
  FROM `chk_checklists` c
 WHERE EXISTS (SELECT 1 FROM `chk_items` i
                WHERE i.`checklist_id` = c.`id` AND i.`section_id` IS NULL)
   AND NOT EXISTS (SELECT 1 FROM `chk_sections` s
                    WHERE s.`checklist_id` = c.`id` AND s.`frequency` = c.`frequency`
                      AND s.`name` = CASE c.`frequency` WHEN 'weekly' THEN 'Weekly'
                                                        WHEN 'monthly' THEN 'Monthly' ELSE 'Daily' END);

UPDATE `chk_items` i
  JOIN `chk_checklists` c ON c.`id` = i.`checklist_id`
  JOIN `chk_sections` s   ON s.`checklist_id` = c.`id` AND s.`frequency` = c.`frequency`
                         AND s.`name` = CASE c.`frequency` WHEN 'weekly' THEN 'Weekly'
                                                           WHEN 'monthly' THEN 'Monthly' ELSE 'Daily' END
   SET i.`section_id` = s.`id`, i.`section_name` = s.`name`
 WHERE i.`section_id` IS NULL;

-- ── 3. Merge each weekly/monthly list into its daily namesake ──
-- They share a name after 2026-08-11_drop_cycle_name_suffix.sql, which is what
-- pairs them. Tasks move into the target's section for their cycle; responses
-- and timesheet rows follow. The unique key on responses is
-- (location_id, item_id, employee_code, log_date) and item_id is unchanged, so
-- re-pointing checklist_id cannot collide.
CREATE TEMPORARY TABLE `chk_merge_map` AS
SELECT src.`id` AS src_id, dst.`id` AS dst_id, src.`frequency` AS freq
  FROM `chk_checklists` src
  JOIN `chk_checklists` dst
    ON dst.`name` = src.`name` AND dst.`assign_type` = src.`assign_type`
   AND dst.`frequency` = 'daily' AND dst.`id` <> src.`id` AND dst.`is_active` = 1
 WHERE src.`frequency` <> 'daily' AND src.`is_active` = 1;

INSERT INTO `chk_sections` (`checklist_id`, `name`, `frequency`, `start_min`, `end_min`, `sort_order`)
SELECT DISTINCT m.dst_id,
       CASE m.freq WHEN 'weekly' THEN 'Weekly' ELSE 'Monthly' END,
       m.freq, 0, 1440,
       CASE m.freq WHEN 'weekly' THEN 2 ELSE 3 END
  FROM `chk_merge_map` m
 WHERE NOT EXISTS (SELECT 1 FROM `chk_sections` s
                    WHERE s.`checklist_id` = m.dst_id AND s.`frequency` = m.freq);

UPDATE `chk_items` i
  JOIN `chk_merge_map` m ON m.src_id = i.`checklist_id`
  JOIN `chk_sections` s  ON s.`checklist_id` = m.dst_id AND s.`frequency` = m.freq
   SET i.`checklist_id` = m.dst_id, i.`section_id` = s.`id`, i.`section_name` = s.`name`;

UPDATE `chk_daily_responses` r JOIN `chk_merge_map` m ON m.src_id = r.`checklist_id`
   SET r.`checklist_id` = m.dst_id;
UPDATE `chk_validations` v JOIN `chk_merge_map` m ON m.src_id = v.`checklist_id`
   SET v.`checklist_id` = m.dst_id;
UPDATE `time_entries` t JOIN `chk_merge_map` m ON m.src_id = t.`checklist_id`
   SET t.`checklist_id` = m.dst_id;
UPDATE `chk_assignees` a JOIN `chk_merge_map` m ON m.src_id = a.`checklist_id`
   SET a.`checklist_id` = m.dst_id;
UPDATE `chk_validators` v JOIN `chk_merge_map` m ON m.src_id = v.`checklist_id`
   SET v.`checklist_id` = m.dst_id;

-- Deactivated rather than deleted: nothing points at them any more, but an
-- emptied row is cheap and a dropped one is unrecoverable.
UPDATE `chk_checklists` c JOIN `chk_merge_map` m ON m.src_id = c.`id`
   SET c.`is_active` = 0, c.`name` = CONCAT(c.`name`, ' (merged)');

DROP TEMPORARY TABLE `chk_merge_map`;

-- An assignee moved from a merged list may now duplicate one already on the
-- target; keep a single row per person per checklist.
DELETE a FROM `chk_assignees` a
  JOIN `chk_assignees` b
    ON b.`checklist_id` = a.`checklist_id` AND b.`employee_code` = a.`employee_code`
   AND b.`id` < a.`id`;
DELETE v FROM `chk_validators` v
  JOIN `chk_validators` w
    ON w.`checklist_id` = v.`checklist_id` AND w.`employee_code` = v.`employee_code`
   AND w.`id` < v.`id`;

COMMIT;

-- Check afterwards:
--   SELECT c.id, c.name, c.is_active, s.name AS section, s.frequency, COUNT(i.id) AS tasks
--     FROM chk_checklists c
--     LEFT JOIN chk_sections s ON s.checklist_id = c.id
--     LEFT JOIN chk_items i ON i.section_id = s.id AND i.is_active = 1
--    GROUP BY c.id, s.id ORDER BY c.sort_order, s.sort_order;
