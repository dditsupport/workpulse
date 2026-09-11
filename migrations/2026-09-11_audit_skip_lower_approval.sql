-- =========================================================
-- WorkPulse — Audit: higher approval stands in for the levels below
-- 2026-09-11
--
-- After the Store Manager justifies, an audit walks three review desks in
-- order: Operation Team → Approver → Management. Each one had to act
-- before the next could see it, so an audit could sit in the Operation
-- queue for a week while the Management approval that would have settled
-- it was a click away.
--
-- A reviewer may now act on their own stage or on any stage beneath it,
-- and that decision carries the ones it passed over. The bypassed desks
-- are stamped with the person who superseded them and the moment they
-- did it — so `approved_at` and the actor columns stay populated and
-- every existing report keeps working — and `skipped_stages` records
-- which of those stamps came from a skip rather than a real review, so
-- no screen has to pretend the Operation Team looked at it.
--
-- Empty for every audit approved the long way round, which is all of them
-- before today.
--
-- Additive and safe to re-run. Run after
-- migrations/2026-09-11_audit_sm_verification_photos.sql.
-- =========================================================

ALTER TABLE `audits`
  ADD COLUMN IF NOT EXISTS `skipped_stages` varchar(120) DEFAULT NULL AFTER `status`;

-- Audits whose approval skipped a desk. Empty on a fresh migration.
SELECT `audit_number`, `status`, `skipped_stages`
FROM `audits`
WHERE COALESCE(`skipped_stages`, '') <> ''
ORDER BY `audit_date` DESC;
