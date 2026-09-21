-- =========================================================
-- WorkPulse — Past Employees (a master of its own)
-- 2026-09-21
--
-- Follows migrations/2026-09-21_employee_documents.sql, and exists
-- because that one assumed too much: it filed every document against an
-- `employees` row. The paperwork HR actually needs to archive is older
-- than this system. Someone who joined in 2018 and left in 2021 was
-- never enrolled here — no biometric template, no portal login, no
-- attendance to report on — so they have no `employees` row and never
-- should: putting them there would put a dead name in every employee
-- dropdown, headcount and export in the app.
--
-- Hence a second, deliberately separate master:
--
--   past_employees — the people the archive holds paperwork for who are
--                    not in `employees`. Plain reference data: code,
--                    name, and whatever of department / designation /
--                    location / dates the old record still carries, all
--                    free text because the 2018 departments and outlets
--                    may not exist as rows any more either.
--
-- It is touched by nothing but the document archive. No login, no
-- attendance, no payroll, no nav anywhere else.
--
-- A document now hangs off exactly ONE of the two masters:
--   employee_documents.employee_id      → employees.id       (serving or
--                                         since deactivated here)
--   employee_documents.past_employee_id → past_employees.id  (pre-dates
--                                         this system)
-- Both NULL-able, never both set. employee_code / employee_name stay
-- copied onto the document row either way, so the archive still reads
-- if the master row is edited or removed.
--
-- employee_code is NOT unique here. Old registers repeat and skip codes,
-- a 2018 code may since have been reissued to somebody serving, and some
-- records arrive with no code at all. The CSV import keys on the code
-- when there is one (same code = update that row, not a second copy),
-- which is what makes re-running an import safe.
--
-- Additive and safe to re-run.
-- =========================================================

CREATE TABLE IF NOT EXISTS `past_employees` (
  `id`              int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  -- Their code in the old register. Blank is allowed — plenty of 2018
  -- records have only a name.
  `employee_code`   varchar(50)  DEFAULT NULL,
  `full_name`       varchar(100) NOT NULL,
  -- Free text, not ids: the department or outlet they worked in may not
  -- exist as a row in this system, and renaming one today must not
  -- silently rewrite what an old record says.
  `department_name` varchar(100) DEFAULT NULL,
  `designation`     varchar(100) DEFAULT NULL,
  `location_name`   varchar(100) DEFAULT NULL,
  `phone`           varchar(20)  DEFAULT NULL,
  `email`           varchar(100) DEFAULT NULL,
  `join_date`       date         DEFAULT NULL,
  `leave_date`      date         DEFAULT NULL,
  `leave_reason`    varchar(255) DEFAULT NULL,
  `notes`           text         DEFAULT NULL,
  `created_by`      varchar(50)  DEFAULT NULL,
  `created_at`      datetime     NOT NULL DEFAULT current_timestamp(),
  `updated_by`      varchar(50)  DEFAULT NULL,
  `updated_at`      datetime     DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_past_emp_code` (`employee_code`),
  KEY `idx_past_emp_name` (`full_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The document's other possible owner. NULL on every existing row, which
-- is correct: everything filed so far was filed against `employees`.
ALTER TABLE `employee_documents`
  ADD COLUMN IF NOT EXISTS `past_employee_id` int(10) UNSIGNED DEFAULT NULL AFTER `employee_id`;

ALTER TABLE `employee_documents`
  ADD KEY IF NOT EXISTS `idx_empdoc_past` (`past_employee_id`);

-- Check afterwards:
--   SHOW COLUMNS FROM past_employees;
--   SHOW COLUMNS FROM employee_documents LIKE 'past_employee_id';
