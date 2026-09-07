-- =========================================================
-- WorkPulse — Store Performance: Audit Score keeps its decimals
-- 2026-09-07
--
-- 04Audit Score was typed 'number', which the review grid renders as a
-- grouped whole number. Audit scores are graded, not counted: 88.75 is a
-- different result from 88, and the historical import carries figures
-- like 99.1 that were being shown as 99. This adds a 'decimal' display
-- type — two decimal places, trailing zeros trimmed — and puts Audit
-- Score on it.
--
-- Display only. No stored value changes: perf_values.value_num has always
-- been decimal(18,4), so the precision was there and only the rendering
-- was dropping it.
--
-- Run after migrations/2026-09-02_store_performance.sql. Safe to re-run.
-- (A database created from that file today already has both, since it was
-- updated to match; this file is for one migrated before that.)
-- =========================================================

ALTER TABLE `perf_parameters`
  MODIFY COLUMN `value_type` enum('amount','number','percent','decimal') NOT NULL DEFAULT 'number';

UPDATE `perf_parameters` SET `value_type` = 'decimal' WHERE `param_code` = '04';

-- Should list 04 as 'decimal', the other 17 unchanged.
SELECT `param_code`, `param_name`, `value_type` FROM `perf_parameters` ORDER BY `sort_order`;
