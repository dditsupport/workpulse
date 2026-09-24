-- =========================================================
-- WorkPulse — Move the Customer Complaint tickets into Negative Feedback
-- 2026-09-25
--
-- DESTRUCTIVE: copies every ticket under Service Type › Customer
-- Complaint into Negative Feedback, then DELETES those tickets.
-- Take a database backup first.
--
-- Run after 2026-09-24_negative_feedback.sql and
-- 2026-09-25_feedback_ticket_import.sql. MariaDB 10.5+ (REGEXP_SUBSTR).
-- Everything is one transaction: an error anywhere leaves both Tickets and
-- Negative Feedback as they were. Safe to re-run — a ticket already
-- brought in (legacy_ticket_id) is not copied again.
--
-- WP-582 and WP-583 are filed under Customer Complaint but are an
-- employee's own request, not complaints. They are neither copied nor
-- deleted; move them to the right category by hand.
--
-- What each ticket becomes:
--   assigned / waiting / in progress → Open, marked already escalated so
--                                      the module does not mail a batch of
--                                      overdue alerts the first time the
--                                      list is opened
--   resolved → Resolution submitted, by whoever resolved the ticket
--              (issue_status_logs), waiting for a closer to verify
--   closed   → Closed, "Closed in Tickets" (close_reason 'migrated'),
--              closed by whoever closed the ticket; the resolver's
--              resolution is shown as approved by them
--   summary + description      → feedback text
--   reporter / created time    → logged by / logged and received time
--   comments                   → fb_notes (Ticket History on the page)
--   attachments                → fb_files, kind 'ticket'. Files are NOT
--                                moved: they stay under uploads/issues/
--                                and the module serves them from there.
-- Read from the text, never guessed: Swiggy / Zomato (named, or by the
-- order-ID shape — 15 digits Swiggy, 10 digits Zomato), the order ID, a
-- labelled customer name and a phone number. Anything unclear is Other.
--
-- Finally the Customer Complaint category is switched off (not deleted —
-- WP-582/583 still point at it) so new complaints go through Negative
-- Feedback.
-- =========================================================

START TRANSACTION;

SET @cat := (SELECT `id` FROM `issue_categories`
              WHERE `category_group` = 'service_type' AND `category_name` = 'Customer Complaint');
SET @now := NOW();

-- ── 1. The tickets to move, with what their text says ───
DROP TEMPORARY TABLE IF EXISTS `fb_tmp_tickets`;
CREATE TEMPORARY TABLE `fb_tmp_tickets` (
  `tid`         int(10) unsigned NOT NULL PRIMARY KEY,
  `location_id` int(11)          NOT NULL,
  `reporter`    varchar(20)      NOT NULL,
  `tstatus`     varchar(40)      NOT NULL,
  `created_at`  datetime         DEFAULT NULL,
  `updated_at`  datetime         DEFAULT NULL,
  `resolved_at` datetime         DEFAULT NULL,
  `summary`     text             NOT NULL,
  `descr`       text             NOT NULL,
  `raw`         text             NOT NULL,
  `ref`         varchar(30)      DEFAULT NULL,
  `source`      varchar(10)      DEFAULT NULL,
  `phone`       varchar(20)      DEFAULT NULL,
  `cname`       varchar(100)     DEFAULT NULL,
  `new_status`  varchar(10)      DEFAULT NULL,
  `res_by`      varchar(20)      DEFAULT NULL,
  `res_at`      datetime         DEFAULT NULL,
  `closed_by`   varchar(20)      DEFAULT NULL,
  `closed_at`   datetime         DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `fb_tmp_tickets` (`tid`, `location_id`, `reporter`, `tstatus`, `created_at`, `updated_at`,
                              `resolved_at`, `summary`, `descr`, `raw`)
SELECT i.`id`, i.`location_id`, i.`reporter_code`, i.`status`, i.`created_at`, i.`updated_at`, i.`resolved_at`,
       TRIM(REPLACE(i.`summary`, '\r', '')),
       TRIM(REPLACE(COALESCE(i.`description`, ''), '\r', '')),
       CONCAT(i.`summary`, '\n', COALESCE(i.`description`, ''))
  FROM `issues` i
 WHERE i.`category_id` = @cat
   AND i.`id` NOT IN (582, 583)
   AND NOT EXISTS (SELECT 1 FROM `fb_feedback` f WHERE f.`legacy_ticket_id` = i.`id`);

-- Order ID: after "order id" (and the misspellings stores type, like
-- "oreder id") or "zomato id"; otherwise any 12–20 digit number.
UPDATE `fb_tmp_tickets`
   SET `ref` = NULLIF(REGEXP_SUBSTR(
                 REGEXP_SUBSTR(`raw`, '(?i)(?:\\bo\\w{2,5}r\\s*id|zomato\\s*id)\\s*[:\\-]?\\s*\\d{8,20}\\b'),
                 '\\d{8,20}$'), '');
UPDATE `fb_tmp_tickets`
   SET `ref` = NULLIF(REGEXP_SUBSTR(`raw`, '(?<!\\d)\\d{12,20}(?!\\d)'), '')
 WHERE `ref` IS NULL;

UPDATE `fb_tmp_tickets`
   SET `source` = CASE
         WHEN `raw` REGEXP '(?i)swiggy'           THEN 'swiggy'
         WHEN `raw` REGEXP '(?i)zomato|zomto'     THEN 'zomato'
         WHEN CHAR_LENGTH(`ref`) >= 12            THEN 'swiggy'
         WHEN CHAR_LENGTH(`ref`) = 10             THEN 'zomato'
         ELSE 'other' END;

-- Phone: a number labelled as the contact wins; otherwise the first
-- mobile-shaped number once the order ID is taken out (a 10-digit Zomato
-- ID looks exactly like a phone number).
UPDATE `fb_tmp_tickets`
   SET `phone` = NULLIF(RIGHT(REGEXP_SUBSTR(`raw`,
         '(?i)(?:contact(?:\\s*(?:number|no))?|mob(?:ile)?(?:\\s*no)?|\\bmo|phone|\\bph)\\s*[:=.\\-]?\\s*(?:\\+?91[\\s-]?)?[6-9]\\d{9}(?!\\d)'), 10), '');
UPDATE `fb_tmp_tickets`
   SET `phone` = NULLIF(RIGHT(REGEXP_SUBSTR(REPLACE(`raw`, COALESCE(`ref`, CHAR(0)), ''),
         '(?<!\\d)(?:\\+?91[\\s-]?)?[6-9]\\d{9}(?!\\d)'), 10), '')
 WHERE `phone` IS NULL;

-- Name: "name : Avani", "CUS: Shruti Patil" — but not "Item Name: …".
UPDATE `fb_tmp_tickets`
   SET `cname` = NULLIF(TRIM(REGEXP_REPLACE(REGEXP_REPLACE(
         REGEXP_SUBSTR(`raw`, '(?i)(?<!item\\s)(?<!item)\\b(?:name|cus)\\s*[:=]\\s*[a-z][a-z .]{1,59}'),
         '^[^:=]*[:=]\\s*', ''), '\\s+', ' ')), '');

-- Who resolved / closed it — the last such change in its status log.
UPDATE `fb_tmp_tickets` t
   SET t.`new_status` = CASE t.`tstatus` WHEN 'closed' THEN 'closed' WHEN 'resolved' THEN 'submitted' ELSE 'open' END,
       t.`res_by`    = (SELECT l.`changed_by` FROM `issue_status_logs` l WHERE l.`issue_id` = t.`tid` AND l.`new_status` = 'resolved' ORDER BY l.`id` DESC LIMIT 1),
       t.`res_at`    = (SELECT l.`changed_at` FROM `issue_status_logs` l WHERE l.`issue_id` = t.`tid` AND l.`new_status` = 'resolved' ORDER BY l.`id` DESC LIMIT 1),
       t.`closed_by` = (SELECT l.`changed_by` FROM `issue_status_logs` l WHERE l.`issue_id` = t.`tid` AND l.`new_status` = 'closed'   ORDER BY l.`id` DESC LIMIT 1),
       t.`closed_at` = (SELECT l.`changed_at` FROM `issue_status_logs` l WHERE l.`issue_id` = t.`tid` AND l.`new_status` = 'closed'   ORDER BY l.`id` DESC LIMIT 1);

-- The same order ID twice (a complaint filed twice, or already typed into
-- the module): keep it on the earliest one only. It stays in the text.
UPDATE `fb_tmp_tickets` t
   SET t.`ref` = NULL
 WHERE t.`ref` IS NOT NULL
   AND EXISTS (SELECT 1 FROM `fb_feedback` f WHERE f.`source` = t.`source` AND f.`source_ref` = t.`ref`);
DROP TEMPORARY TABLE IF EXISTS `fb_tmp_dups`;
CREATE TEMPORARY TABLE `fb_tmp_dups` AS
  SELECT `source`, `ref`, MIN(`tid`) AS `keep_tid` FROM `fb_tmp_tickets`
   WHERE `ref` IS NOT NULL GROUP BY `source`, `ref` HAVING COUNT(*) > 1;
UPDATE `fb_tmp_tickets` t
  JOIN `fb_tmp_dups` d ON d.`source` = t.`source` AND d.`ref` = t.`ref` AND t.`tid` <> d.`keep_tid`
   SET t.`ref` = NULL;

-- ── 2. The complaints ───────────────────────────────────
INSERT INTO `fb_feedback`
  (`source`, `location_id`, `customer_name`, `customer_phone`, `rating`, `feedback_text`, `received_at`,
   `source_ref`, `status`, `created_by`, `created_at`, `open_since`, `escalated_at`,
   `closed_by`, `closed_at`, `close_reason`, `close_note`, `legacy_ticket_id`)
SELECT t.`source`, t.`location_id`, t.`cname`, t.`phone`, NULL,
       -- Summary, then the description unless it only repeats it. Tabs
       -- came from sheets pasted into the box; they read as separators.
       REPLACE(IF(t.`descr` = '' OR t.`descr` = t.`summary`, t.`summary`,
                  CONCAT(t.`summary`, '\n\n', t.`descr`)), '\t', ' · '),
       COALESCE(t.`created_at`, @now), t.`ref`, t.`new_status`, t.`reporter`,
       COALESCE(t.`created_at`, @now), COALESCE(t.`created_at`, @now),
       IF(t.`new_status` = 'open', @now, NULL),
       IF(t.`new_status` = 'closed', t.`closed_by`, NULL),
       IF(t.`new_status` = 'closed', COALESCE(t.`closed_at`, t.`resolved_at`, t.`updated_at`, t.`created_at`, @now), NULL),
       IF(t.`new_status` = 'closed', 'migrated', NULL),
       IF(t.`new_status` = 'closed', CONCAT('Closed in Tickets as WP-', t.`tid`, '.'), NULL),
       t.`tid`
  FROM `fb_tmp_tickets` t
 ORDER BY t.`tid`;

-- ── 3. Who resolved it ──────────────────────────────────
INSERT INTO `fb_resolutions`
  (`feedback_id`, `remark`, `submitted_by`, `submitted_at`, `decision`, `decided_by`, `decided_at`)
SELECT f.`id`,
       CONCAT('Marked resolved in Tickets (WP-', t.`tid`, '). See Ticket History below.'),
       COALESCE(t.`res_by`, t.`reporter`),
       COALESCE(t.`res_at`, t.`resolved_at`, t.`created_at`, @now),
       IF(t.`new_status` = 'closed', 'approved', 'pending'),
       IF(t.`new_status` = 'closed', f.`closed_by`, NULL),
       IF(t.`new_status` = 'closed', f.`closed_at`, NULL)
  FROM `fb_tmp_tickets` t
  JOIN `fb_feedback` f ON f.`legacy_ticket_id` = t.`tid`
 WHERE t.`new_status` = 'submitted'
    OR (t.`new_status` = 'closed' AND t.`res_by` IS NOT NULL);

-- ── 4. The comment thread ───────────────────────────────
INSERT INTO `fb_notes` (`feedback_id`, `author_code`, `body`, `created_at`, `legacy_comment_id`)
SELECT f.`id`, c.`author_code`, c.`body`, COALESCE(c.`created_at`, f.`created_at`), c.`id`
  FROM `issue_comments` c
  JOIN `fb_tmp_tickets` t ON t.`tid` = c.`issue_id`
  JOIN `fb_feedback` f    ON f.`legacy_ticket_id` = t.`tid`
 ORDER BY c.`issue_id`, c.`created_at`, c.`id`;

-- ── 5. The attachments (rows only — files stay on disk) ─
INSERT INTO `fb_files`
  (`resolution_id`, `feedback_id`, `note_id`, `kind`, `original_name`, `stored_name`, `legacy_path`,
   `mime_type`, `size_bytes`, `uploaded_by`, `uploaded_at`)
SELECT NULL, f.`id`, n.`id`, 'ticket', LEFT(a.`filename`, 255), LEFT(a.`stored_name`, 120),
       CONCAT(a.`issue_id`, '/', IF(a.`comment_id` IS NULL, '', CONCAT('comments/', a.`comment_id`, '/')), a.`stored_name`),
       a.`mime_type`, a.`file_size`, a.`uploaded_by`, COALESCE(a.`created_at`, f.`created_at`)
  FROM `issue_attachments` a
  JOIN `fb_tmp_tickets` t ON t.`tid` = a.`issue_id`
  JOIN `fb_feedback` f    ON f.`legacy_ticket_id` = t.`tid`
  LEFT JOIN `fb_notes` n  ON n.`legacy_comment_id` = a.`comment_id`
 ORDER BY a.`id`;

-- ── 6. Delete the tickets that were brought in ──────────
-- Only tickets that now have a Negative Feedback row. issue_participants
-- has no cascade; deleting the issue cascades to its comments, attachment
-- rows and status logs. The attachment FILES are not touched — Negative
-- Feedback still serves them.
DELETE p FROM `issue_participants` p
  JOIN `fb_feedback` f ON f.`legacy_ticket_id` = p.`issue_id`;
DELETE i FROM `issues` i
  JOIN `fb_feedback` f ON f.`legacy_ticket_id` = i.`id`
 WHERE i.`category_id` = @cat;

-- ── 7. No new complaints as tickets ─────────────────────
UPDATE `issue_categories` SET `is_active` = 0 WHERE `id` = @cat;

DROP TEMPORARY TABLE IF EXISTS `fb_tmp_dups`;
DROP TEMPORARY TABLE IF EXISTS `fb_tmp_tickets`;

COMMIT;

-- Check afterwards — expect only WP-582 and WP-583 left in the category:
--   SELECT id, status FROM issues WHERE category_id = 8;
--   SELECT status, close_reason, COUNT(*) FROM fb_feedback
--    WHERE legacy_ticket_id IS NOT NULL GROUP BY status, close_reason;
--   SELECT source, COUNT(*) FROM fb_feedback WHERE legacy_ticket_id IS NOT NULL GROUP BY source;
