-- =========================================================
-- WorkPulse — Negative Feedback: room for the old complaint tickets
-- 2026-09-25
--
-- Customer complaints were raised as tickets under Service Type ›
-- Customer Complaint. Superadmin moves them into Negative Feedback from
-- index.php?page=feedback_import, which copies each ticket (text,
-- comments, attachments, who resolved and closed it) and then deletes it.
-- This file makes the room for what a ticket carries that a complaint
-- logged in the module itself does not:
--
--   fb_feedback.legacy_ticket_id — the WP- number it came from. Unique,
--                                  so a ticket can never be imported twice
--                                  and a half-finished run resumes cleanly.
--   fb_feedback.close_reason     — 'migrated': closed in Tickets before the
--                                  module existed, not approved here.
--   fb_notes                     — the ticket's comment thread. The module
--                                  has no comments of its own; this keeps
--                                  the history the ticket is deleted with.
--   fb_files                     — 'ticket' files: what was attached to the
--                                  ticket or one of its comments. They
--                                  belong to no resolution, so
--                                  resolution_id may now be NULL.
--
-- Needs 2026-09-24_negative_feedback.sql first. Additive and safe to
-- re-run.
-- =========================================================

ALTER TABLE `fb_feedback`
  ADD COLUMN IF NOT EXISTS `legacy_ticket_id` int(10) unsigned DEFAULT NULL AFTER `close_note`,
  ADD UNIQUE INDEX IF NOT EXISTS `ux_fb_feedback_ticket` (`legacy_ticket_id`),
  MODIFY COLUMN `close_reason` enum('approved','duplicate','spam','not_actionable','migrated') DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `fb_notes` (
  `id`          int(11)     NOT NULL AUTO_INCREMENT,
  `feedback_id` int(11)     NOT NULL,
  `author_code` varchar(20) DEFAULT NULL,
  `body`        text        NOT NULL,
  `created_at`  datetime    NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_fb_notes_feedback` (`feedback_id`,`created_at`),
  CONSTRAINT `fb_notes_ibfk_1`
    FOREIGN KEY (`feedback_id`) REFERENCES `fb_feedback` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `fb_files`
  MODIFY COLUMN `resolution_id` int(11) DEFAULT NULL,
  MODIFY COLUMN `kind` enum('recording','receipt','ticket') NOT NULL,
  ADD COLUMN IF NOT EXISTS `note_id` int(11) DEFAULT NULL AFTER `feedback_id`,
  ADD INDEX IF NOT EXISTS `ix_fb_files_note` (`note_id`);

-- Check afterwards:
--   SHOW COLUMNS FROM fb_feedback LIKE 'legacy_ticket_id';
--   SHOW COLUMNS FROM fb_files LIKE 'kind';
--   SHOW TABLES LIKE 'fb_notes';
