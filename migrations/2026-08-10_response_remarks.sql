-- =========================================================
-- WorkPulse — per-task remarks on the checklist
-- 2026-08-10
--
-- A free-text note the person filling the checklist writes beside each
-- task, so a reading, a fault or a follow-up has somewhere to live next
-- to the Yes/No. The cycle's remarks are then joined into the notes of
-- that person's My Time entry for the cycle.
--
-- varchar(500) matches chk_validations.remarks, which is the validator's
-- equivalent field, so both cap the same way.
--
-- Additive and safe to run at any time. Until it is run the remarks box
-- does not render and My Time notes keep their current labels.
-- =========================================================

ALTER TABLE `chk_daily_responses`
  ADD COLUMN IF NOT EXISTS `remarks` varchar(500) DEFAULT NULL AFTER `time_minutes`;
