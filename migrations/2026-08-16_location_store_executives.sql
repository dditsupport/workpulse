-- =========================================================
-- WorkPulse — store executives under each store manager
-- 2026-08-16
--
-- location_managers holds one store manager and one operation manager per
-- location. The store executives reporting to that manager are a list, not
-- a single name, so they get their own table instead of exec_1 … exec_8
-- columns: the page renders them as a tree under the store manager.
--
-- Up to 8 per location. The cap is enforced in PHP (LOCMGR_MAX_EXECS) —
-- MySQL cannot express "at most N rows per group" as a constraint — and the
-- composite primary key stops the same employee being listed twice on one
-- store.
--
-- sort_order keeps the tree in the order it was entered; ties fall back to
-- employee_code so the list is at least stable.
--
-- Additive and safe to run at any time. Until it is run the Manager Mapping
-- page hides the executive tree and its pickers (see locMgrHasExecTable()),
-- so PHP may deploy before the SQL.
-- =========================================================

CREATE TABLE IF NOT EXISTS `location_executives` (
  `location_id` int(11) NOT NULL,
  `employee_code` varchar(20) NOT NULL,
  `sort_order` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `updated_by` varchar(20) DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`location_id`,`employee_code`),
  KEY `idx_loc_exec_order` (`location_id`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
