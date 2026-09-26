-- =========================================================
-- WorkPulse — Uniform Management (HRMS)
-- 2026-09-26
--
-- Staff uniforms (jeans, T-shirts) are tracked today in a spreadsheet:
-- one sheet with every employee's size, one with who was sent what, and
-- a stock block of Receive / Dispatch / Available per size that is kept
-- right by hand. This moves all three into HRMS.
--
-- Four tables:
--   uniform_items       — what is handed out (Jeans Pant, T-Shirt), the
--                         sizes it comes in, and how many pieces make one
--                         issue (2 — every person gets a pair).
--   uniform_staff_sizes — the size register: one row per employee per
--                         item.
--   uniform_moves       — the stock ledger. Every piece that comes in or
--                         goes out is a row: receive, issue, return,
--                         adjust. Stock on hand is never stored; it is
--                         SUM(qty) over the live rows, so it cannot drift
--                         from the ledger the way a typed total does.
--   uniform_requests    — a size somebody still needs ("4XL REQ") that
--                         could not be issued yet — what the stock sheet
--                         kept under its Available row.
--
-- qty in uniform_moves is SIGNED: receive and return are positive, issue
-- is negative, adjust is either. Available for a size is then simply the
-- sum.
--
-- A mistaken entry is voided (voided_at / voided_by / void_reason), never
-- deleted, so the ledger can always be read back.
--
-- Additive and safe to re-run.
-- =========================================================

CREATE TABLE IF NOT EXISTS `uniform_items` (
  `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          varchar(100) NOT NULL,
  -- Comma-separated, in the order they are shown: "28,30,32" or "S,M,L".
  `sizes`         varchar(500) NOT NULL,
  -- Pieces one issue hands over — the default on the Issue form.
  `qty_per_issue` int(10) UNSIGNED NOT NULL DEFAULT 2,
  `sort_order`    int(11)      NOT NULL DEFAULT 0,
  `is_active`     tinyint(1)   NOT NULL DEFAULT 1,
  `created_at`    datetime     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_uniform_item_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `uniform_staff_sizes` (
  `employee_id` int(11)          NOT NULL,
  `item_id`     int(10) UNSIGNED NOT NULL,
  `size`        varchar(20)      NOT NULL,
  `updated_by`  varchar(50)      DEFAULT NULL,
  `updated_at`  datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`employee_id`, `item_id`),
  KEY `idx_uss_item` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `uniform_moves` (
  `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `item_id`       int(10) UNSIGNED NOT NULL,
  `size`          varchar(20)  NOT NULL,
  `move_type`     enum('receive','issue','return','adjust') NOT NULL,
  -- Signed: + into stock (receive, return), - out of it (issue).
  `qty`           int(11)      NOT NULL,
  -- Who it was issued to / returned by. NULL for receive and adjust.
  `employee_id`   int(11)      DEFAULT NULL,
  -- Snapshots, so the ledger still reads if the employee is renamed or
  -- moves outlet.
  `employee_code` varchar(50)  DEFAULT NULL,
  `employee_name` varchar(100) DEFAULT NULL,
  `location_id`   int(11)      DEFAULT NULL,
  `move_date`     date         NOT NULL,
  -- Supplier / challan / bill no. on a receipt; free text otherwise.
  `reference`     varchar(100) DEFAULT NULL,
  `notes`         varchar(255) DEFAULT NULL,
  -- The request this issue fulfilled, if any.
  `request_id`    int(10) UNSIGNED DEFAULT NULL,
  `created_by`    varchar(50)  DEFAULT NULL,
  `created_at`    datetime     NOT NULL DEFAULT current_timestamp(),
  `voided_at`     datetime     DEFAULT NULL,
  `voided_by`     varchar(50)  DEFAULT NULL,
  `void_reason`   varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_um_stock`    (`item_id`, `size`, `voided_at`),
  KEY `idx_um_employee` (`employee_id`),
  KEY `idx_um_date`     (`move_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `uniform_requests` (
  `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id`   int(11)      NOT NULL,
  `employee_code` varchar(50)  DEFAULT NULL,
  `employee_name` varchar(100) DEFAULT NULL,
  `item_id`       int(10) UNSIGNED NOT NULL,
  `size`          varchar(20)  NOT NULL,
  `qty`           int(10) UNSIGNED NOT NULL DEFAULT 2,
  `notes`         varchar(255) DEFAULT NULL,
  `status`        enum('open','fulfilled','cancelled') NOT NULL DEFAULT 'open',
  `created_by`    varchar(50)  DEFAULT NULL,
  `created_at`    datetime     NOT NULL DEFAULT current_timestamp(),
  `closed_by`     varchar(50)  DEFAULT NULL,
  `closed_at`     datetime     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ur_status` (`status`, `item_id`, `size`),
  KEY `idx_ur_employee` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The two items the staff sheet carries today. INSERT IGNORE on the
-- unique name, so re-running never duplicates them or undoes an edit.
INSERT IGNORE INTO `uniform_items` (`name`, `sizes`, `qty_per_issue`, `sort_order`) VALUES
  ('Jeans Pant', '28,30,32,34,36,38,40,42,44,46',  2, 1),
  ('T-Shirt',    'S,M,L,XL,XXL,3XL,4XL,5XL',        2, 2);

-- txn_uniforms — open Uniforms, keep the size register, and record stock
-- in and out. Starts at 0 on every role; grant it on Administration › Roles.
ALTER TABLE `roles`
  ADD COLUMN IF NOT EXISTS `txn_uniforms` tinyint(1) NOT NULL DEFAULT 0;

-- Check afterwards:
--   SELECT * FROM uniform_items;
--   SHOW COLUMNS FROM roles LIKE 'txn_uniforms';
