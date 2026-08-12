-- =========================================================
-- WorkPulse — time tasks become a managed, shared list
-- 2026-08-14
--
-- Anyone could create a task on the Time Tracking page, and the task was
-- theirs alone — two people doing the same work created two tasks with two
-- spellings, and the cross-employee report could not add them up.
--
-- Creating a task is now a permission (txn_time_task) and the tasks a
-- holder creates are visible to every employee when logging time, so the
-- same work is logged against the same task company-wide.
--
-- time_tasks.employee_code keeps its meaning for rows written before this
-- (the owner); from here on it records who created the task. No data is
-- rewritten — existing personal tasks simply become visible to everyone.
--
-- Additive and safe to run at any time. Until it is run the Roles page
-- hides the new permission (see roleTxnColumns()) and nobody but the
-- superadmin can create tasks.
-- =========================================================

ALTER TABLE `roles`
  ADD COLUMN IF NOT EXISTS `txn_time_task` tinyint(1) NOT NULL DEFAULT 0 AFTER `txn_time_report`;
