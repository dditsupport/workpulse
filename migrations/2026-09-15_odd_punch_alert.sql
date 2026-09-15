-- =========================================================
-- WorkPulse — Odd Punch Report + daily 07:00 alert
-- 2026-09-15
--
-- A finished shift day always carries an EVEN number of punches: IN/OUT,
-- IN/OUT/IN/OUT, and so on (2, 4, 6, 8…). An odd count means one half of a
-- pair never happened — someone forgot to punch — and the in→out trace
-- cannot be completed. The new Odd Punch Report (HRMS › Odd Punch Report)
-- lists exactly those days, and cron/run_odd_punch_alert.php mails the
-- previous shift's list every morning at 07:00, once the shift has closed
-- at (ShiftCutoffHour - 1):59:59.
--
-- This migration only adds the settings row the alert reads. Leave it blank
-- and the alert falls back to PunchRequestNotifyHR / PunchRequestNotifyOps,
-- so the digest still reaches HR and Operations on day one.
--
-- Additive and safe to run more than once.
-- =========================================================

INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
  ('OddPunchNotifyEmails', '',
   'Comma-separated recipients for the daily 07:00 odd-punch alert. Blank falls back to PunchRequestNotifyHR / PunchRequestNotifyOps.');

-- Every attendance report now groups punches on the cutoff the punch devices
-- themselves use -- shiftCutoffHour() reads this key -- instead of the
-- hard-coded 4 in modules/helpers.php. This install already holds 6, which is
-- what closes the shift at 05:59:59 and is where the device API writes its
-- auto-close OUT; the INSERT below only covers a fresh install, and the UPDATE
-- fills in the missing hint text on the Settings page.
INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
  ('ShiftCutoffHour', '6',
   'Hour a shift day rolls over. The shift closes at (this - 1):59:59.');

UPDATE `system_settings`
   SET `description` = 'Hour a shift day rolls over. The shift closes at (this - 1):59:59.'
 WHERE `setting_key` = 'ShiftCutoffHour'
   AND (`description` IS NULL OR `description` = '');
