-- =========================================================
-- WorkPulse — Negative Feedback (its own module, out of Tickets)
-- 2026-09-24
--
-- A bad Google review, a one-star Reelo bill rating, a Swiggy or Zomato
-- complaint used to be raised as an ordinary ticket. A ticket has no
-- idea what a customer, a rating or a platform reference is, and nothing
-- in it makes anyone prove the customer was actually called back. This
-- module gives the complaint its own lifecycle:
--
--   Open ──(store manager / operations submit a resolution)──▶
--   Resolution submitted ──(closer approves)──▶ Closed
--                        └─(closer sends back, reason)──▶ Open
--   Open / Resolution submitted ──(closer, duplicate/spam/…)──▶ Closed
--
-- "Closer" is by EMPLOYEE ID, not by role: the outlet's Operation Manager
-- in Manager Mapping, plus every code listed in the FeedbackCloserCodes
-- setting below. Nobody else can approve, send back or close.
--
-- Three tables:
--   fb_feedback     — one customer complaint, from intake to closure.
--   fb_resolutions  — every resolution submitted against it, with the
--                     closer's decision on each. A sent-back complaint
--                     keeps its earlier attempts; this is its history.
--   fb_files        — proof on a resolution: call recordings and
--                     receipts, on disk under uploads/feedback/{id}/.
--
-- Additive and safe to re-run: every statement is IF NOT EXISTS /
-- INSERT IGNORE. modules/feedback.php feature-detects the tables
-- (fbSchemaReady()) and shows a "run the migration" notice until this
-- has been applied.
-- =========================================================

CREATE TABLE IF NOT EXISTS `fb_feedback` (
  `id`             int(11)      NOT NULL AUTO_INCREMENT,
  -- Where the customer said it. Every source is typed in by staff for
  -- now: neither Swiggy nor Zomato offers restaurant partners a review
  -- API, so those are copied from the partner dashboards.
  `source`         enum('google','reelo','swiggy','zomato','other') NOT NULL,
  `location_id`    int(11)      NOT NULL,
  `customer_name`  varchar(100) DEFAULT NULL,
  `customer_phone` varchar(20)  DEFAULT NULL,
  -- 1–5 stars as the platform showed it; NULL when it gave none.
  `rating`         tinyint(4)   DEFAULT NULL,
  `feedback_text`  text         NOT NULL,
  -- When the customer posted it — not when it was typed in here.
  `received_at`    datetime     NOT NULL,
  -- The platform's own reference: Swiggy/Zomato order or complaint ID,
  -- Reelo bill number, Google review link. Unique per source, so the same
  -- review cannot be logged twice (NULLs do not collide).
  `source_ref`     varchar(150) DEFAULT NULL,
  `status`         enum('open','submitted','closed') NOT NULL DEFAULT 'open',
  `created_by`     varchar(20)  DEFAULT NULL,
  `created_at`     datetime     NOT NULL DEFAULT current_timestamp(),
  -- Start of the current wait for a resolution: created_at, then moved to
  -- the moment of each send-back. Escalation counts from here.
  `open_since`     datetime     NOT NULL DEFAULT current_timestamp(),
  -- Set once the wait passes FeedbackEscalateHours and the closers were
  -- told; cleared by a send-back so the next wait can escalate again.
  `escalated_at`   datetime     DEFAULT NULL,
  `closed_by`      varchar(20)  DEFAULT NULL,
  `closed_at`      datetime     DEFAULT NULL,
  -- approved = a resolution was verified; the rest are a closer shutting
  -- it without one.
  `close_reason`   enum('approved','duplicate','spam','not_actionable') DEFAULT NULL,
  `close_note`     varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_fb_feedback_ref` (`source`,`source_ref`),
  KEY `ix_fb_feedback_status` (`status`,`location_id`),
  KEY `ix_fb_feedback_received` (`received_at`),
  CONSTRAINT `fb_feedback_ibfk_1`
    FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `fb_resolutions` (
  `id`            int(11)     NOT NULL AUTO_INCREMENT,
  `feedback_id`   int(11)     NOT NULL,
  `remark`        text        NOT NULL,
  `submitted_by`  varchar(20) NOT NULL,
  `submitted_at`  datetime    NOT NULL DEFAULT current_timestamp(),
  `decision`      enum('pending','approved','sent_back') NOT NULL DEFAULT 'pending',
  `decided_by`    varchar(20) DEFAULT NULL,
  `decided_at`    datetime    DEFAULT NULL,
  -- Mandatory on a send-back; what the resolver has to fix.
  `decision_note` text        DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_fb_resolutions_feedback` (`feedback_id`,`submitted_at`),
  CONSTRAINT `fb_resolutions_ibfk_1`
    FOREIGN KEY (`feedback_id`) REFERENCES `fb_feedback` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- original_name is what the resolver called the file; stored_name is the
-- unguessable name on disk. Served only through index.php?page=feedback_file.
CREATE TABLE IF NOT EXISTS `fb_files` (
  `id`            int(11)      NOT NULL AUTO_INCREMENT,
  `resolution_id` int(11)      NOT NULL,
  `feedback_id`   int(11)      NOT NULL,
  `kind`          enum('recording','receipt') NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `stored_name`   varchar(120) NOT NULL,
  `mime_type`     varchar(100) DEFAULT NULL,
  `size_bytes`    int(11)      NOT NULL DEFAULT 0,
  `uploaded_by`   varchar(20)  DEFAULT NULL,
  `uploaded_at`   datetime     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_fb_files_resolution` (`resolution_id`),
  KEY `ix_fb_files_feedback` (`feedback_id`),
  CONSTRAINT `fb_files_ibfk_1`
    FOREIGN KEY (`resolution_id`) REFERENCES `fb_resolutions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fb_files_ibfk_2`
    FOREIGN KEY (`feedback_id`) REFERENCES `fb_feedback` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── Permissions ─────────────────────────────────────────
-- txn_feedback_entry — log a new complaint (intake).
-- txn_feedback_view  — the operations team: see every outlet's
--                      complaints and submit a resolution on any of them.
-- The outlet's Store Manager and Operation Manager (Manager Mapping) see
-- and resolve their own outlets' complaints with no flag. Approving,
-- sending back and closing are NOT a flag: see FeedbackCloserCodes.
ALTER TABLE `roles`
  ADD COLUMN IF NOT EXISTS `txn_feedback_entry` tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `txn_feedback_view`  tinyint(1) NOT NULL DEFAULT 0;

-- ── Settings ────────────────────────────────────────────
INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
  ('FeedbackCloserCodes', '',
   'Employee IDs (comma-separated) who may approve, send back or close negative feedback for every outlet. The outlet''s mapped Operation Manager always can.'),
  ('FeedbackNotifyEmails', '',
   'Operations team emails (comma-separated) told when negative feedback is logged or sent back.'),
  ('FeedbackEscalateHours', '24',
   'Hours a complaint may wait without a resolution before the closers are emailed. 0 turns escalation off.');

-- Check afterwards:
--   SHOW TABLES LIKE 'fb\_%';
--   SHOW COLUMNS FROM roles LIKE 'txn_feedback%';
--   SELECT * FROM system_settings WHERE setting_key LIKE 'Feedback%';
