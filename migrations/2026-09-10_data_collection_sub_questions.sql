-- =========================================================
-- WorkPulse — Data Collection: sub-questions, one answer box each
-- 2026-09-10
--
-- The first AC task asked everything in a single sentence and outlets got
-- confused about what was actually wanted. A task can now carry numbered
-- sub-questions under its main one, each with its own answer box, so the
-- outlet works through them and the report reads as columns rather than a
-- paragraph.
--
-- Only needed on a database that already ran
-- 2026-09-07_data_collection.sql — that file has since been updated, so a
-- fresh install already has these tables and the statements below are
-- no-ops. Safe to re-run either way.
-- =========================================================

-- One long question ("photo of every AC, and the sub-zero meters from a
-- distance, no meter reading, and where every meter is zero send the old
-- AC photos too") is read once and half-answered. Broken into numbered
-- sub-questions, each with its own box, an outlet answers them one at a
-- time and the report gets a column per question instead of a paragraph
-- to re-read.
--
-- dc_requests.question stays as the task's main ask, with its own box;
-- these are the extra ones under it. A task with none behaves exactly as
-- it did before.
CREATE TABLE IF NOT EXISTS `dc_questions` (
  `id`            int(11)      NOT NULL AUTO_INCREMENT,
  `request_id`    int(11)      NOT NULL,
  `question_text` varchar(255) NOT NULL,
  `sort_order`    int(11)      NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_dc_questions_request` (`request_id`,`sort_order`),
  CONSTRAINT `dc_questions_ibfk_1`
    FOREIGN KEY (`request_id`) REFERENCES `dc_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- One outlet's answer to one sub-question. question_id already implies the
-- task, so there is no request_id to keep in step; deleting the task
-- cascades through dc_questions to here.
CREATE TABLE IF NOT EXISTS `dc_answers` (
  `question_id`  int(11)     NOT NULL,
  `location_id`  int(11)     NOT NULL,
  `answer_text`  text        DEFAULT NULL,
  `updated_by`   varchar(20) DEFAULT NULL,
  `updated_at`   datetime    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`question_id`,`location_id`),
  KEY `ix_dc_answers_location` (`location_id`),
  CONSTRAINT `dc_answers_ibfk_1`
    FOREIGN KEY (`question_id`) REFERENCES `dc_questions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dc_answers_ibfk_2`
    FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Check afterwards:
--   SHOW TABLES LIKE 'dc_questions';
--   SHOW TABLES LIKE 'dc_answers';
