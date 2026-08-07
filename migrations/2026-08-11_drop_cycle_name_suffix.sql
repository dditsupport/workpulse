-- =========================================================
-- WorkPulse — drop the cycle suffix from checklist names
-- 2026-08-11
--
-- The checklist hub groups its cards under Daily, Weekly and Monthly
-- headings, and the fill page prints a frequency badge beside the title, so
-- a checklist called "Operation - Paresh - Monthly" says its cycle twice.
-- The suffix comes off; the frequency column already carries that fact.
--
-- Rows affected (5), by name rather than id so this runs the same against
-- any database that took the earlier migrations:
--
--   Admin - HO - Monthly              -> Admin - HO
--   IT Department - Monthly           -> IT Department
--   Operation - Paresh - Weekly       -> Operation - Paresh
--   Operation - Paresh - Monthly      -> Operation - Paresh
--   Operation - Pradhyuman - Monthly  -> Operation - Pradhyuman
--
-- Two cycles of one department now share a name, which is why the report
-- dropdowns and the My Time labels append the cycle themselves — see
-- chkReportOptionLabel() and timeEntryLabel().
--
-- Only a suffix naming the row's OWN cycle is removed, so a daily checklist
-- called "Monthly Review Prep" is untouched. The LIKE guard needs something
-- before the suffix, so a checklist named simply "Monthly" also survives.
-- Idempotent: a second run matches nothing.
-- =========================================================

START TRANSACTION;

UPDATE `chk_checklists`
   SET `name` = TRIM(TRAILING ' - Monthly' FROM `name`)
 WHERE `frequency` = 'monthly' AND `name` LIKE '% - Monthly';

UPDATE `chk_checklists`
   SET `name` = TRIM(TRAILING ' - Weekly' FROM `name`)
 WHERE `frequency` = 'weekly' AND `name` LIKE '% - Weekly';

COMMIT;

-- Check afterwards:
--   SELECT id, name, frequency FROM chk_checklists ORDER BY sort_order;
