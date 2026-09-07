-- =========================================================
-- WorkPulse — Data Collection (ad-hoc collection drives)
-- 2026-09-07
--
-- Operations asks every outlet for the same thing at unpredictable times:
-- P&P closing stock, a sales sheet, a photo of a device, "how many boxes
-- are left in the deep freezer". Today that runs on WhatsApp — 40-odd
-- outlets drop a file into a group, someone scrolls the thread to work out
-- who has not sent theirs, and the files then live in that group forever.
--
-- This is the same drive inside the app: one task, a target list of
-- outlets, a question each of them answers in a text box, and a board of
-- who has filed. When the report is done the task is DISCARDED — files off
-- disk, rows out of the database, task row deleted. Nothing is kept, by
-- design; the download is the record.
--
-- Five tables:
--   dc_requests          — one collection task. Several run at once and
--                          none knows about the others.
--   dc_request_locations — which outlets this task is asking.
--   dc_files             — the uploaded files, on disk under
--                          uploads/data_collection/{request_id}/.
--   dc_samples           — the blank format the task hands out, for the
--                          outlets to fill in and send back.
--   dc_submissions       — one row per outlet: its answer text AND its
--                          lock state. An outlet submits and can keep
--                          changing what it sent; Operations confirms,
--                          and that is what locks the outlet out.
--
-- Additive and safe to re-run: every statement is IF NOT EXISTS.
-- modules/data_collection.php feature-detects the tables (dcSchemaReady())
-- and shows a "run the migration" notice instead of a fatal error until
-- this has been applied.
-- =========================================================

CREATE TABLE IF NOT EXISTS `dc_requests` (
  `id`            int(11)      NOT NULL AUTO_INCREMENT,
  `title`         varchar(150) NOT NULL,
  `instructions`  text         DEFAULT NULL,
  -- Printed as the label directly above the outlet's answer box. Blank
  -- means the task wants a free remark rather than an answer to anything,
  -- and the page falls back to "Answer / remark".
  `question`      varchar(255) DEFAULT NULL,
  -- 1 = an outlet cannot confirm without attaching at least one file.
  -- 0 = the question alone is the ask; a text answer is a full submission.
  `requires_file` tinyint(1)   NOT NULL DEFAULT 1,
  `due_date`      date         DEFAULT NULL,
  -- A closed task takes no more submissions but can still be downloaded.
  -- There is no 'purged' state: discarding deletes this row outright.
  `status`        enum('open','closed') NOT NULL DEFAULT 'open',
  `created_by`    varchar(20)  DEFAULT NULL,
  `created_at`    datetime     NOT NULL DEFAULT current_timestamp(),
  `closed_by`     varchar(20)  DEFAULT NULL,
  `closed_at`     datetime     DEFAULT NULL,
  -- Last full download. The discard dialog warns in red while this is
  -- NULL, because discard is the one step that cannot be undone.
  `downloaded_by` varchar(20)  DEFAULT NULL,
  `downloaded_at` datetime     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_dc_requests_status` (`status`,`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `dc_request_locations` (
  `request_id`  int(11) NOT NULL,
  `location_id` int(11) NOT NULL,
  PRIMARY KEY (`request_id`,`location_id`),
  KEY `ix_dc_req_loc_location` (`location_id`),
  CONSTRAINT `dc_request_locations_ibfk_1`
    FOREIGN KEY (`request_id`) REFERENCES `dc_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dc_request_locations_ibfk_2`
    FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- original_name is what the outlet called its file and what the ZIP puts
-- back; stored_name is the unguessable name on disk. Files are only ever
-- served through index.php?page=dc_file, never by direct URL.
CREATE TABLE IF NOT EXISTS `dc_files` (
  `id`            int(11)      NOT NULL AUTO_INCREMENT,
  `request_id`    int(11)      NOT NULL,
  `location_id`   int(11)      NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `stored_name`   varchar(120) NOT NULL,
  `mime_type`     varchar(100) DEFAULT NULL,
  `size_bytes`    int(11)      NOT NULL DEFAULT 0,
  `uploaded_by`   varchar(20)  DEFAULT NULL,
  `uploaded_at`   datetime     NOT NULL DEFAULT current_timestamp(),
  -- 1 = filed by Operations for an outlet that sent it another way, so a
  -- store's own submission is never confused with one typed in for them.
  `on_behalf`     tinyint(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_dc_files_request` (`request_id`,`location_id`),
  CONSTRAINT `dc_files_ibfk_1`
    FOREIGN KEY (`request_id`) REFERENCES `dc_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dc_files_ibfk_2`
    FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- One row per outlet per task, written the first time that outlet sends
-- anything. The answer lives here rather than on dc_files so it stands on
-- its own when the task asks for no file, and does not multiply across an
-- outlet's five attachments.
--
-- status is the lock, and only Operations moves it: while it is
-- 'submitted' the outlet may still add files, remove them and rewrite its
-- answer; a txn_data_collect holder confirming freezes the lot, and only
-- they can reopen it.
CREATE TABLE IF NOT EXISTS `dc_submissions` (
  `request_id`   int(11)     NOT NULL,
  `location_id`  int(11)     NOT NULL,
  `answer_text`  text        DEFAULT NULL,
  `status`       enum('submitted','confirmed') NOT NULL DEFAULT 'submitted',
  `updated_by`   varchar(20) DEFAULT NULL,
  `updated_at`   datetime    NOT NULL DEFAULT current_timestamp(),
  -- Who in Operations accepted it, and when. The outlet cannot set these.
  `confirmed_by` varchar(20) DEFAULT NULL,
  `confirmed_at` datetime    DEFAULT NULL,
  `reopened_by`  varchar(20) DEFAULT NULL,
  `reopened_at`  datetime    DEFAULT NULL,
  `on_behalf`    tinyint(1)  NOT NULL DEFAULT 0,
  PRIMARY KEY (`request_id`,`location_id`),
  KEY `ix_dc_submissions_status` (`request_id`,`status`),
  CONSTRAINT `dc_submissions_ibfk_1`
    FOREIGN KEY (`request_id`) REFERENCES `dc_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dc_submissions_ibfk_2`
    FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

-- ── Permission ──────────────────────────────────────────
-- txn_data_collect — start a task, edit it, close it, file on behalf of
--                    any targeted outlet, CONFIRM an outlet's submission
--                    (which locks that outlet out of it) and reopen one,
--                    download everything and discard the task.
-- Submitting needs no flag: an employee reaches the task through the
-- outlet on their profile (employees.location_id) or through Manager
-- Mapping naming them Store Manager or Operation Manager of one.
ALTER TABLE `roles`
  ADD COLUMN IF NOT EXISTS `txn_data_collect` tinyint(1) NOT NULL DEFAULT 0;

-- Check afterwards:
--   SHOW TABLES LIKE 'dc\_%';
--   SHOW COLUMNS FROM roles LIKE 'txn_data_collect';
