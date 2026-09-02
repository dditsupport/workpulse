-- =========================================================
-- WorkPulse — Store Performance (monthly MIS) review
-- 2026-09-02
--
-- The Operations team tracks 18 numbers per outlet per month
-- (target, achievement, wastage, aggregator basics, phone capture,
-- feedback …). Until now that lived in one "Target vs Achievement"
-- pivot workbook. This moves it into the app so that:
--
--   1. the Operations Manager uploads one CSV per month,
--   2. the Store Manager writes a remark against each parameter
--      while the month is reviewed, and
--   3. the Operations Manager closes the month with a conclusion.
--
-- Four tables:
--   perf_parameters — the 18 rows, in the sheet's own order. The
--                     numeric prefix (01…18) is the sort key and the
--                     stable code the CSV is matched on; renaming the
--                     label later does not orphan history.
--   perf_values     — one number per (outlet, month, parameter).
--   perf_reviews    — one review header per (outlet, month): status
--                     plus the Operations Manager's conclusion.
--   perf_remarks    — the Store Manager's per-parameter remark,
--                     hanging off the review header.
--
-- Additive and safe to re-run: every statement is IF NOT EXISTS or an
-- upsert. modules/store_performance.php feature-detects the tables
-- (perfSchemaReady()) and shows a "run the migration" notice instead
-- of a fatal error until this has been applied.
-- =========================================================

CREATE TABLE IF NOT EXISTS `perf_parameters` (
  `id`         int(11)      NOT NULL AUTO_INCREMENT,
  `param_code` varchar(4)   NOT NULL,
  `param_name` varchar(100) NOT NULL,
  -- Drives display only: amount → ₹ grouped, percent → 1 decimal + %,
  -- number → grouped integer.
  `value_type` enum('amount','number','percent') NOT NULL DEFAULT 'number',
  -- Which way is good news, for the month-on-month delta arrow.
  -- 'none' = neither (a target is a target, not an achievement).
  `better`     enum('up','down','none') NOT NULL DEFAULT 'none',
  `sort_order` int(11)      NOT NULL DEFAULT 0,
  `is_active`  tinyint(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_perf_param_code` (`param_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- period_month is always the 1st of the month; the app normalises
-- every write, so the UNIQUE key really is "one value per parameter
-- per outlet per month" and a re-upload overwrites rather than doubles.
CREATE TABLE IF NOT EXISTS `perf_values` (
  `id`           int(11)       NOT NULL AUTO_INCREMENT,
  `location_id`  int(11)       NOT NULL,
  `period_month` date          NOT NULL,
  `param_code`   varchar(4)    NOT NULL,
  `value_num`    decimal(18,4) DEFAULT NULL,
  -- Non-numeric cells the source sheet carries ("No Audit", "#VALUE!").
  -- Kept verbatim rather than dropped so a blank cell and a known
  -- "no audit this month" stay distinguishable in the review grid.
  `value_text`   varchar(60)   DEFAULT NULL,
  `uploaded_by`  varchar(20)   DEFAULT NULL,
  `uploaded_at`  datetime      NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_perf_value` (`location_id`,`period_month`,`param_code`),
  KEY `ix_perf_value_month` (`period_month`),
  CONSTRAINT `perf_values_ibfk_1`
    FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `perf_reviews` (
  `id`           int(11)     NOT NULL AUTO_INCREMENT,
  `location_id`  int(11)     NOT NULL,
  `period_month` date        NOT NULL,
  -- pending   → data uploaded, Store Manager has not remarked yet
  -- remarked  → Store Manager submitted their parameter remarks
  -- concluded → Operations Manager wrote the closing conclusion
  `status`       enum('pending','remarked','concluded') NOT NULL DEFAULT 'pending',
  `remarked_by`  varchar(20) DEFAULT NULL,
  `remarked_at`  datetime    DEFAULT NULL,
  `conclusion`   text        DEFAULT NULL,
  `concluded_by` varchar(20) DEFAULT NULL,
  `concluded_at` datetime    DEFAULT NULL,
  `created_at`   datetime    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_perf_review` (`location_id`,`period_month`),
  KEY `ix_perf_review_month` (`period_month`,`status`),
  CONSTRAINT `perf_reviews_ibfk_1`
    FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `perf_remarks` (
  `id`         int(11)     NOT NULL AUTO_INCREMENT,
  `review_id`  int(11)     NOT NULL,
  `param_code` varchar(4)  NOT NULL,
  `remark`     text        NOT NULL,
  `updated_by` varchar(20) DEFAULT NULL,
  `updated_at` datetime    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_perf_remark` (`review_id`,`param_code`),
  CONSTRAINT `perf_remarks_ibfk_1`
    FOREIGN KEY (`review_id`) REFERENCES `perf_reviews` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── Permissions ─────────────────────────────────────────
-- txn_perf_admin — Operations Manager: upload the monthly CSV, see every
--                  outlet, write the conclusion, reopen a concluded month.
-- txn_perf_view  — read-only across every outlet (management / HO).
-- Neither is needed by a Store Manager: an employee with
-- employees.location_id set reaches their own outlet's review and writes
-- the per-parameter remarks with no txn flag at all.
ALTER TABLE `roles`
  ADD COLUMN IF NOT EXISTS `txn_perf_admin` tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `txn_perf_view`  tinyint(1) NOT NULL DEFAULT 0;

-- ── The 18 parameters ───────────────────────────────────
-- Labels and order are the source workbook's, prefix included, so an
-- export pasted straight out of the pivot still matches on import.
-- Re-running refreshes the label/type but never the id, so remarks and
-- values already keyed on param_code survive.
INSERT INTO `perf_parameters` (`param_code`,`param_name`,`value_type`,`better`,`sort_order`) VALUES
  ('01','Target',              'amount', 'none',  1),
  ('02','Achivement',          'amount', 'up',    2),
  ('03','Target %',            'percent','up',    3),
  ('04','Audit Score',         'number', 'up',    4),
  ('05','Wastage %',           'percent','down',  5),
  ('06','Swiggy Basic',        'amount', 'up',    6),
  ('07','Zomato Basic',        'amount', 'up',    7),
  ('08','Online Rejection %',  'percent','down',  8),
  ('09','Swiggy Visibility %', 'percent','up',    9),
  ('10','Zomato Visibility %', 'percent','up',   10),
  ('11','Total orders',        'number', 'up',   11),
  ('12','Valid phone',         'number', 'up',   12),
  ('13','Valid phone %',       'percent','up',   13),
  ('14','Online Sales',        'number', 'up',   14),
  ('15','Online Sales %',      'percent','up',   15),
  ('16','Invalid phone',       'number', 'down', 16),
  ('17','Invalid phone %',     'percent','down', 17),
  ('18','Negative Feedback',   'number', 'down', 18)
ON DUPLICATE KEY UPDATE
  `param_name` = VALUES(`param_name`),
  `value_type` = VALUES(`value_type`),
  `better`     = VALUES(`better`),
  `sort_order` = VALUES(`sort_order`);
