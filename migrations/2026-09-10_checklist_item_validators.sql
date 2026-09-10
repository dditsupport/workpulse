-- =========================================================
-- WorkPulse — per-task checklist validators
-- 2026-09-10
--
-- A validator was designated per checklist (chk_validators holds one row
-- per checklist + employee), so every validator of a department checklist
-- signed off on all of its tasks. On the department work-lists the tasks
-- belong to different owners — the Operation sheet's banking rows are the
-- accounts team's, the GR rows are purchase's — so validation has to be
-- designated per task.
--
-- item_id names the chk_items row a validator signs off on; 0 keeps the
-- old meaning, "every task on this checklist", which is what the existing
-- rows become, so nothing changes for a checklist until its validators are
-- re-assigned task by task.
--
-- uq_validator has to widen to include item_id, otherwise one person could
-- hold only a single task per checklist.
--
-- Additive and safe to run at any time. Until it is run the validator box
-- has no task picker and every validator keeps the whole checklist.
-- =========================================================

ALTER TABLE `chk_validators`
  ADD COLUMN IF NOT EXISTS `item_id` int(10) UNSIGNED NOT NULL DEFAULT 0 AFTER `checklist_id`;

ALTER TABLE `chk_validators` DROP INDEX IF EXISTS `uq_validator`;
ALTER TABLE `chk_validators`
  ADD UNIQUE KEY IF NOT EXISTS `uq_validator` (`checklist_id`,`employee_code`,`item_id`);
ALTER TABLE `chk_validators`
  ADD KEY IF NOT EXISTS `idx_validator_item` (`item_id`);
