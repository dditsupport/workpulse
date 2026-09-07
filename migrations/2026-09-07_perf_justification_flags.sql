-- =========================================================
-- WorkPulse — Store Performance: justification requests
-- 2026-09-07
--
-- Splits what used to be one "remark" per parameter into the two things
-- a review actually contains:
--
--   the Operations query  — this figure needs explaining, and here is
--                           what I want explained (flagged + flag_note)
--   the Store Manager's   — the answer (remark)
--   justification
--
-- They were one column, which meant whoever typed last owned the cell and
-- history could not tell a question from an answer.
--
-- `remark` becomes nullable: a row now exists the moment Operations flags
-- a parameter, before anyone has answered it. A row with flagged = 1 and
-- remark IS NULL is an open request, and that is exactly what blocks the
-- Store Manager's submit.
--
-- Run after migrations/2026-09-02_store_performance.sql. Additive and
-- safe to re-run; existing remarks keep their text and are unflagged, so
-- every month reviewed before today reads as "no justification was asked
-- for", which is true.
-- =========================================================

ALTER TABLE `perf_remarks`
  ADD COLUMN IF NOT EXISTS `flagged`    tinyint(1)    NOT NULL DEFAULT 0 AFTER `param_code`,
  ADD COLUMN IF NOT EXISTS `flag_note`  varchar(1000) DEFAULT NULL       AFTER `flagged`,
  ADD COLUMN IF NOT EXISTS `flagged_by` varchar(20)   DEFAULT NULL       AFTER `flag_note`,
  ADD COLUMN IF NOT EXISTS `flagged_at` datetime      DEFAULT NULL       AFTER `flagged_by`;

-- A flag with no answer yet is the whole point, so the answer may be NULL.
ALTER TABLE `perf_remarks`
  MODIFY COLUMN `remark` text DEFAULT NULL;

-- Open requests per outlet and month. Empty on a fresh migration.
SELECT l.`location_name`, r.`period_month`,
       SUM(m.`flagged` = 1)                                AS requested,
       SUM(m.`flagged` = 1 AND COALESCE(m.`remark`,'') = '') AS still_open
FROM `perf_remarks` m
JOIN `perf_reviews` r ON r.`id` = m.`review_id`
JOIN `locations`    l ON l.`location_id` = r.`location_id`
GROUP BY l.`location_name`, r.`period_month`
HAVING requested > 0
ORDER BY r.`period_month` DESC, l.`location_name`;
