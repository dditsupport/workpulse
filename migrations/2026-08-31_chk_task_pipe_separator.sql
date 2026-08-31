-- =========================================================
-- WorkPulse — pipe separator in checklist task descriptions
-- 2026-08-31
--
-- A task description is written as "Task name | what it actually means".
-- The app shows the first half at full size and the second half on a
-- smaller, muted line underneath, the way an audit option carries its
-- action hint (chkSplitTaskText(), modules/checklist.php).
--
-- The tasks in these five checklists were written with an en dash for
-- that break. The app splits on the pipe and nothing else — a dash is
-- ordinary punctuation, and "Purchase Follow-Up", "Next-Day Cake Order"
-- and "Month-End Stock Counting" carry their own — so until this runs
-- they render as one line. This converts them:
--
--     Expired Trade Items – Check expired trade items in the system …
--  -> Expired Trade Items | Check expired trade items in the system …
--
-- Scope, by checklist_id, and what each holds as of the 31 Aug dump:
--
--   2  RM Store     18 of 19 rows convert (id 32 is already a pipe)
--   3  Production   24 of 24
--   4  Fancy        27 of 27
--   5  Logistic     18 of 18
--   6  HR-Factory   11 of 24 (the 13 ALL-CAPS rows carry no dash)
--
--   98 rows in total. Checklist 7 (Maintenance) has 25 more rows in the
--   same style; it is NOT in this migration because it was not on the
--   list — add 7 to the IN () below to include it.
--
-- Every separator in these five is the en dash, so that is all the
-- pattern statement looks for: no em dash and no spaced hyphen occurs
-- here, and leaving them out means nothing else can be caught by
-- surprise. Two rows do not fit the pattern and are set by hand first,
-- which also puts a pipe in them so the pattern statement skips them:
--
--   62   the dash has no space before it ("G.R.– Complete")
--   100  two dashes; the name is "Production JV – All Stores", so the
--        break is the second one, and the name keeps its own dash
--
-- Every statement skips a row that already holds a pipe, so a second run
-- of this file matches nothing. ' – ' and ' | ' are both 3 characters,
-- so no row grows and varchar(500) is safe.
--
-- Look before you leap:
--   SELECT id, checklist_id, task_description FROM chk_items
--    WHERE checklist_id IN (2,3,4,5,6)
--      AND task_description NOT LIKE '%|%'
--      AND task_description LIKE '% – %';      -- the 96 pattern rows
--   SELECT id, task_description FROM chk_items WHERE id IN (62, 100);
-- =========================================================

START TRANSACTION;

-- The two rows the pattern cannot read. Setting them here also marks them
-- with a pipe, which is what keeps the statement below off them.
UPDATE `chk_items`
   SET `task_description` = 'Internal G.I. & G.R. | Complete internal G.I. and G.R. for all stores.'
 WHERE `id` = 62
   AND `task_description` = 'Internal G.I. & G.R.– Complete internal G.I. and G.R. for all stores.';

UPDATE `chk_items`
   SET `task_description` = 'Production JV – All Stores | Complete production JV entries for all stores.'
 WHERE `id` = 100
   AND `task_description` = 'Production JV – All Stores – Complete production JV entries for all stores.';

-- Everything else: the first en dash becomes the separator. Any later
-- dash stays where it is, inside the explanation.
UPDATE `chk_items`
   SET `task_description` = CONCAT(
         TRIM(SUBSTRING(`task_description`, 1, LOCATE(' – ', `task_description`) - 1)),
         ' | ',
         TRIM(SUBSTRING(`task_description`, LOCATE(' – ', `task_description`) + 3)))
 WHERE `checklist_id` IN (2, 3, 4, 5, 6)
   AND `task_description` NOT LIKE '%|%'
   AND `task_description` LIKE '% – %'
   AND LOCATE(' – ', `task_description`) > 1
   AND TRIM(SUBSTRING(`task_description`, LOCATE(' – ', `task_description`) + 3)) <> '';

COMMIT;

-- Check afterwards — 111 of the 112 rows in these five checklists should
-- hold a pipe (id 145-157 in checklist 6 carry no clarification, so 98
-- of them are new and 13 stay whole):
--   SELECT checklist_id,
--          SUM(task_description LIKE '%|%')  AS with_hint,
--          COUNT(*)                          AS rows
--     FROM chk_items WHERE checklist_id IN (2,3,4,5,6)
--    GROUP BY checklist_id ORDER BY checklist_id;
--   SELECT id, checklist_id, task_description FROM chk_items
--    WHERE checklist_id IN (2,3,4,5,6) ORDER BY checklist_id, section_id, id;
