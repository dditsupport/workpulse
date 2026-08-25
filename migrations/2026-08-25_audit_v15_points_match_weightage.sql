-- =========================================================
-- WorkPulse — v15 templates: option points now match question weightage
-- 2026-08-25
--
-- The Audit 2026 v15 sheet carries separate "Weight (/100)" and "Points"
-- columns per monitor, and on many rows they don't match (e.g. "Is the
-- store music on...": Hygiene Weight 15, Hygiene Points 12). Confirmed
-- with the user this was a sheet error, not intentional partial credit —
-- the Weight column is the one that's final. Every option in both v15
-- templates was already either the question's full max_value (a positive
-- condition) or 0 (a negative one) — never a value in between — so
-- re-pointing "full marks" at score_weightage instead of the old,
-- mismatched max_value is a safe, mechanical rescale: verified by
-- scanning all 218 options in migrations/2026-08-25_audit_templates_v15.sql
-- before writing this file.
--
-- What this does, for every parameter (and its options) under the
-- "Store Hygiene" and "Audit" templates only:
--   1. audit_parameters.max_value  := score_weightage
--   2. audit_parameter_options.points := score_weightage, for every
--      option that currently scores > 0 (the "positive" ones — a 0
--      option stays 0, nothing to rescale).
--
-- After this, the points badge shown next to each condition on the audit
-- form always equals the question's Weightage column, and
-- points/max_value*100 still simplifies to the same 0 or 100 obtain-score
-- it always did — the ceiling and the full-marks value just move
-- together, so no audit's scoring formula changes, only the numbers now
-- agree instead of looking like two different, unrelated figures.
--
-- Doesn't touch audit_responses: confirmed with the user that no real
-- audit has been filed against either v15 template yet — only test
-- drafts, which they'll delete and refile against the corrected
-- template rather than have their in-flight snapshot data patched.
-- =========================================================

START TRANSACTION;

UPDATE `audit_parameters` p
  JOIN `audit_categories` c ON c.`id` = p.`category_id`
  JOIN `audit_templates`  t ON t.`id` = c.`template_id`
   SET p.`max_value` = p.`score_weightage`
 WHERE t.`name` IN ('Store Hygiene', 'Audit');

UPDATE `audit_parameter_options` o
  JOIN `audit_parameters` p ON p.`id` = o.`parameter_id`
  JOIN `audit_categories` c ON c.`id` = p.`category_id`
  JOIN `audit_templates`  t ON t.`id` = c.`template_id`
   SET o.`points` = p.`score_weightage`
 WHERE t.`name` IN ('Store Hygiene', 'Audit')
   AND o.`points` > 0;

COMMIT;
