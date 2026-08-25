-- =========================================================
-- WorkPulse — deactivate the pre-existing "Audit 2026" / "Store Hygine"
-- templates now that the v15 pair is live
-- 2026-08-25
--
-- Run after 2026-08-25_audit_templates_v15.sql.
--
-- 2026-08-25_audit_templates_v15.sql assumed the sheet's names ("Store
-- Hygiene" / "Audit") were free to seed fresh under. They were — but two
-- other, older templates were already live and active under different
-- names: "Audit 2026" and "Store Hygine" (note the typo — no 'e'). Neither
-- has any rows in audit_parameter_options; their categories and questions
-- don't match the v10/v15 sheet at all, so they were never in scope for
-- the conditions work. Until now that left FOUR overlapping active
-- templates on the New Audit dropdown — easy to pick the old one by habit
-- and see plain value boxes with no conditions, which is what happened in
-- testing.
--
-- Matches by exact name, not id — ids differ across environments, and a
-- database that doesn't have a template under one of these two names
-- (or already has it inactive) is simply left untouched by that row.
--
-- Nothing is deleted and no audit history moves: this only flips
-- is_active to 0. Every audit already filed against either template keeps
-- rendering exactly as it does today — auditGetTree() reads each
-- response's own snapshot, not the template's current active flag. An
-- admin can re-enable either one at any time from the Audit Templates
-- screen (Edit → Active → Save) exactly like the "(v10)" pair, matching
-- "mostly new template will be active" from the original ask without
-- losing the ability to fall back.
-- =========================================================

UPDATE `audit_templates`
   SET `is_active` = 0
 WHERE `name` IN ('Audit 2026', 'Store Hygine')
   AND `is_active` = 1;
