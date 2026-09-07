-- =========================================================
-- WorkPulse — Store Performance: per-outlet goals
-- 2026-09-07
--
-- "Wastage under 2%" is not the same instruction at every outlet — a
-- high-footfall store may be held to 2% and a small one to 5%. So each
-- parameter carries a goal, set per outlet, with a company-wide default
-- to fall back on:
--
--   perf_parameters.default_target  the number that applies unless an
--                                   outlet says otherwise (NULL = no goal)
--   perf_targets                    the outlet's own number, overriding it
--
-- Which side of the goal is good news is already known: perf_parameters
-- .better. 'down' makes the goal a ceiling (wastage at or under it is
-- met), 'up' makes it a floor (visibility at or over it is met), and
-- 'none' means the figure is not judged against a goal at all.
--
-- Nothing is seeded. Until Operations sets a goal, the review grid reads
-- exactly as it does today — a goal nobody agreed to is not a standard.
--
-- Run after migrations/2026-09-02_store_performance.sql. Additive and
-- safe to re-run.
-- =========================================================

ALTER TABLE `perf_parameters`
  ADD COLUMN IF NOT EXISTS `default_target` decimal(18,4) DEFAULT NULL AFTER `better`;

CREATE TABLE IF NOT EXISTS `perf_targets` (
  `id`           int(11)       NOT NULL AUTO_INCREMENT,
  `location_id`  int(11)       NOT NULL,
  `param_code`   varchar(4)    NOT NULL,
  -- NULL is a deliberate "this outlet is not held to a goal here", which
  -- is different from having no row (fall back to the default).
  `target_value` decimal(18,4) DEFAULT NULL,
  `updated_by`   varchar(20)   DEFAULT NULL,
  `updated_at`   datetime      NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_perf_target` (`location_id`,`param_code`),
  KEY `ix_perf_target_param` (`param_code`),
  CONSTRAINT `perf_targets_ibfk_1`
    FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Goals in force, outlet by outlet. Empty until Operations sets some.
SELECT l.`location_name`, p.`param_code`, p.`param_name`,
       COALESCE(t.`target_value`, p.`default_target`) AS goal,
       CASE WHEN t.`target_value` IS NOT NULL THEN 'outlet' ELSE 'default' END AS source,
       p.`better`
FROM `perf_parameters` p
CROSS JOIN `locations` l
LEFT JOIN `perf_targets` t ON t.`location_id` = l.`location_id` AND t.`param_code` = p.`param_code`
WHERE p.`is_active` = 1
  AND COALESCE(t.`target_value`, p.`default_target`) IS NOT NULL
ORDER BY l.`location_name`, p.`sort_order`;
