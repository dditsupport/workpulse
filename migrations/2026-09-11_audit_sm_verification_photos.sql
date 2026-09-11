-- =========================================================
-- WorkPulse — Audit: Store Manager verification photos
-- 2026-09-11
--
-- Until now every file on an audit response was the auditor's: evidence
-- of what was found on the floor, uploaded while the audit was still in
-- draft. The Store Manager, who answers that evidence on the "Pending SM
-- Justify" screen, could only type.
--
-- A typed justification is often "we fixed it" — and the proof of that is
-- a photo, not a sentence. `uploaded_stage` records which side of the
-- conversation a file belongs to so the two never blur together:
--
--   auditor        — the finding (what the auditor saw)
--   store_manager  — the verified work (what the store did about it)
--
-- Existing rows default to 'auditor', which is what every file uploaded
-- before today actually is.
--
-- Additive and safe to re-run.
-- =========================================================

ALTER TABLE `audit_response_attachments`
  ADD COLUMN IF NOT EXISTS `uploaded_stage` varchar(20) NOT NULL DEFAULT 'auditor' AFTER `uploaded_by`;

-- Files per stage. On a fresh migration every row reads as 'auditor'.
SELECT `uploaded_stage`, COUNT(*) AS files
FROM `audit_response_attachments`
GROUP BY `uploaded_stage`;
