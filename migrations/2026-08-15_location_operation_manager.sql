-- =========================================================
-- WorkPulse — an operation manager per location
-- 2026-08-15
--
-- location_managers already answers "who is the store manager of this
-- store?". Operations wanted the same answer for the operation manager
-- who owns the store, so the mapping page now carries both names on one
-- row instead of living in a spreadsheet somewhere.
--
-- Nullable on purpose: existing rows keep working with the store manager
-- alone, and a location can be mapped one name at a time.
--
-- Additive and safe to run at any time. Until it is run the Store Manager
-- Mapping page hides the operation manager column and its picker
-- (see locMgrHasOpsColumn()), so PHP may deploy before the SQL.
-- =========================================================

ALTER TABLE `location_managers`
  ADD COLUMN IF NOT EXISTS `operation_manager_code` varchar(20) DEFAULT NULL AFTER `store_manager_code`;
