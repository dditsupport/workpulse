-- =========================================================
-- WorkPulse — Data Collection: a task can hand out a sample file
-- 2026-09-09
--
-- Asking 41 outlets for "closing stock" gets 41 different layouts back,
-- and the person building the report re-types them all. A task can now
-- carry the blank format itself — the sheet with the right columns, an
-- example photo, a one-page instruction PDF. Every location sees it above
-- its own upload box, downloads it, fills it in and sends it back.
--
-- Only needed on a database that already ran
-- 2026-09-07_data_collection.sql — that file has since been updated, so a
-- fresh install already has this table and the statement below is a
-- no-op. Safe to re-run either way.
-- =========================================================

-- The blank format a task hands out: the sheet with the right columns and
-- headings, an example photo, a one-page instruction PDF. Locations
-- download it, fill it in and upload it back as their submission, so 41
-- outlets return 41 files with the same shape instead of 41 layouts.
-- Belongs to the task, not to any location. Lives in the task's own folder
-- under a dcs_ prefix, so discarding the task takes it with everything else.
CREATE TABLE IF NOT EXISTS `dc_samples` (
  `id`            int(11)      NOT NULL AUTO_INCREMENT,
  `request_id`    int(11)      NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `stored_name`   varchar(120) NOT NULL,
  `mime_type`     varchar(100) DEFAULT NULL,
  `size_bytes`    int(11)      NOT NULL DEFAULT 0,
  `uploaded_by`   varchar(20)  DEFAULT NULL,
  `uploaded_at`   datetime     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_dc_samples_request` (`request_id`),
  CONSTRAINT `dc_samples_ibfk_1`
    FOREIGN KEY (`request_id`) REFERENCES `dc_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Check afterwards:
--   SHOW TABLES LIKE 'dc_samples';
