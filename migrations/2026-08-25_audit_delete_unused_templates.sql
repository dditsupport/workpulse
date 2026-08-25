-- =========================================================
-- WorkPulse — delete the unused "Store Hygine", "Store Hygiene (v10)"
-- and "Audit (v10)" templates
-- 2026-08-25
--
-- Run after 2026-08-25_audit_deactivate_legacy_templates.sql (or before —
-- order doesn't matter, this only touches the three templates below).
--
-- Requested cleanup: "Store Hygine" (the pre-existing, typo'd template,
-- already deactivated by the previous migration), and the "(v10)" pair
-- seeded as the admin-toggleable old-format alternative are no longer
-- wanted at all — not just inactive, gone.
--
-- Matches by name, not id (ids differ per environment). Deletes each
-- template only if nothing depends on it, in the same order used by every
-- prior refresh in this module (questions nobody answered → categories
-- left with nothing on them → the template itself, only once its
-- categories and every audit that ever used it are both gone):
--
--   1. Draft audits on these templates (a draft is tied to the questions
--      it was started with, so it goes with them).
--   2. Parameters no audit_response references.
--   3. Categories left with no parameters, no weight snapshot and no
--      response.
--   4. The template itself, only if no category and no audit remain.
--
-- "Store Hygiene (v10)" and "Audit (v10)" were only just created by this
-- branch's own seed migration, so they should be empty and delete
-- cleanly. "Store Hygine" is older — if any real audit was ever filed
-- against it, step 4 will find that audit still pointing at it and leave
-- the template row in place rather than delete out from under real
-- history. Re-run SELECT * FROM audit_templates afterward: any of the
-- three still present means something referenced it and was protected.
-- =========================================================

SET @t_hygine := (SELECT `id` FROM `audit_templates` WHERE `name` = 'Store Hygine' LIMIT 1);
SET @t_hyg10  := (SELECT `id` FROM `audit_templates` WHERE `name` = 'Store Hygiene (v10)' LIMIT 1);
SET @t_aud10  := (SELECT `id` FROM `audit_templates` WHERE `name` = 'Audit (v10)' LIMIT 1);
SET @t_hygine := COALESCE(@t_hygine, 0);
SET @t_hyg10  := COALESCE(@t_hyg10, 0);
SET @t_aud10  := COALESCE(@t_aud10, 0);

START TRANSACTION;

DELETE FROM `audits`
 WHERE `status` = 'draft'
   AND `template_id` IN (@t_hygine, @t_hyg10, @t_aud10);

DELETE p FROM `audit_parameters` p
  JOIN `audit_categories` c ON c.`id` = p.`category_id`
  LEFT JOIN `audit_responses` r ON r.`parameter_id` = p.`id`
 WHERE c.`template_id` IN (@t_hygine, @t_hyg10, @t_aud10)
   AND r.`id` IS NULL;

DELETE c FROM `audit_categories` c
  LEFT JOIN `audit_parameters`       p ON p.`category_id` = c.`id`
  LEFT JOIN `audit_category_weights` w ON w.`category_id` = c.`id`
  LEFT JOIN `audit_responses`        r ON r.`category_id` = c.`id`
 WHERE c.`template_id` IN (@t_hygine, @t_hyg10, @t_aud10)
   AND p.`id` IS NULL
   AND w.`category_id` IS NULL
   AND r.`id` IS NULL;

DELETE t FROM `audit_templates` t
  LEFT JOIN `audit_categories` c ON c.`template_id` = t.`id`
  LEFT JOIN `audits`           a ON a.`template_id` = t.`id`
 WHERE t.`id` IN (@t_hygine, @t_hyg10, @t_aud10)
   AND c.`id` IS NULL
   AND a.`id` IS NULL;

COMMIT;
