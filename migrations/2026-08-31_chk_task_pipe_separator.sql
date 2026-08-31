-- =========================================================
-- WorkPulse — pipe separator in checklist task descriptions
-- 2026-08-31
--
-- A task description is written as "Task name | what it actually means".
-- The app shows the first half at full size and the second half on a
-- smaller, muted line underneath, the way an audit option carries its
-- action hint (chkSplitTaskText(), modules/checklist.php).
--
-- The tasks already in the database use a dash for that break:
--
--   Check & Receive Logistics Materials – Check and receive logistics …
--   Purchase Follow-Up – Follow up with the Purchase Department.
--
-- The app splits on the pipe and nothing else — a dash is ordinary
-- punctuation, and "Purchase Follow-Up" and "SF-2" carry their own — so
-- until this runs those descriptions render as one line, exactly as they
-- did before the clarification line existed. This converts them.
--
-- Only the FIRST dash becomes the separator; any later one stays part of
-- the explanation. En dash, em dash and a spaced hyphen are each handled,
-- in that order, and every statement skips a row that already holds a pipe
-- — so a row converted by an earlier statement is not touched again, and a
-- second run of the whole file matches nothing. The spacing requirement is
-- what leaves "Follow-Up" and "SF-2" alone.
--
-- Look before you leap:
--   SELECT id, task_description FROM chk_items
--    WHERE task_description LIKE '%|%';           -- already converted
--   SELECT id, task_description FROM chk_items
--    WHERE task_description NOT LIKE '%|%'
--      AND (task_description LIKE '% – %'
--        OR task_description LIKE '% — %'
--        OR task_description LIKE '% - %');       -- what this will rewrite
-- =========================================================

START TRANSACTION;

-- En dash — how the current tasks are written.
UPDATE `chk_items`
   SET `task_description` = CONCAT(
         TRIM(SUBSTRING(`task_description`, 1, LOCATE(' – ', `task_description`) - 1)),
         ' | ',
         TRIM(SUBSTRING(`task_description`, LOCATE(' – ', `task_description`) + 3)))
 WHERE `task_description` NOT LIKE '%|%'
   AND `task_description` LIKE '% – %'
   AND TRIM(SUBSTRING(`task_description`, LOCATE(' – ', `task_description`) + 3)) <> ''
   AND LOCATE(' – ', `task_description`) > 1;

-- Em dash.
UPDATE `chk_items`
   SET `task_description` = CONCAT(
         TRIM(SUBSTRING(`task_description`, 1, LOCATE(' — ', `task_description`) - 1)),
         ' | ',
         TRIM(SUBSTRING(`task_description`, LOCATE(' — ', `task_description`) + 3)))
 WHERE `task_description` NOT LIKE '%|%'
   AND `task_description` LIKE '% — %'
   AND TRIM(SUBSTRING(`task_description`, LOCATE(' — ', `task_description`) + 3)) <> ''
   AND LOCATE(' — ', `task_description`) > 1;

-- Hyphen, spaced on both sides. A hyphen inside a word is not a separator,
-- which is why the spaces are part of the match.
UPDATE `chk_items`
   SET `task_description` = CONCAT(
         TRIM(SUBSTRING(`task_description`, 1, LOCATE(' - ', `task_description`) - 1)),
         ' | ',
         TRIM(SUBSTRING(`task_description`, LOCATE(' - ', `task_description`) + 3)))
 WHERE `task_description` NOT LIKE '%|%'
   AND `task_description` LIKE '% - %'
   AND TRIM(SUBSTRING(`task_description`, LOCATE(' - ', `task_description`) + 3)) <> ''
   AND LOCATE(' - ', `task_description`) > 1;

COMMIT;

-- Check afterwards — every task, and where its clarification now starts:
--   SELECT id, section_name, task_description FROM chk_items
--    ORDER BY checklist_id, section_id, id;
