-- =========================================================
-- WorkPulse — Data Collection: Operations confirms, not the outlet
-- 2026-09-08
--
-- The first cut gave a location two buttons: "Save draft" and "Confirm
-- submission". That is one button too many, and the confirm was in the
-- wrong hands. A location has one job — send the file and the answer —
-- and it should be able to correct what it sent until someone tells it
-- otherwise. Accepting a submission, and thereby locking that outlet out
-- of it, is Operations' call.
--
-- So the two states are renamed to what they now mean:
--   'draft'     → 'submitted'   the outlet has sent something and may
--                               still change it
--   'submitted' → 'confirmed'   Operations accepted it; the outlet can no
--                               longer add, remove or edit anything
--
-- Only needed on a database that already ran
-- 2026-09-07_data_collection.sql — that file has since been updated, so a
-- fresh install gets the right enum in one go and this becomes a no-op.
--
-- Re-runnable: the remap only fires while the column still carries the
-- old 'draft' value, so a second run changes nothing.
-- =========================================================

START TRANSACTION;

-- Is this an old-shaped column? Decided once, before anything is touched.
SET @dc_old := (SELECT LOCATE('draft', COLUMN_TYPE)
                  FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'dc_submissions'
                   AND COLUMN_NAME  = 'status');

-- ── 1. Widen the enum so both namings can coexist for a moment ──
SET @sql := IF(@dc_old > 0,
    "ALTER TABLE `dc_submissions`
       MODIFY COLUMN `status` enum('draft','submitted','confirmed') NOT NULL DEFAULT 'draft'",
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 2. Remap, old top value first so the two never collide ──
SET @sql := IF(@dc_old > 0,
    "UPDATE `dc_submissions` SET `status` = 'confirmed' WHERE `status` = 'submitted'",
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF(@dc_old > 0,
    "UPDATE `dc_submissions` SET `status` = 'submitted' WHERE `status` = 'draft'",
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 3. Narrow it to the two states that now exist ──
SET @sql := IF(@dc_old > 0,
    "ALTER TABLE `dc_submissions`
       MODIFY COLUMN `status` enum('submitted','confirmed') NOT NULL DEFAULT 'submitted'",
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

COMMIT;

-- Check afterwards — expect enum('submitted','confirmed') and no 'draft':
--   SHOW COLUMNS FROM dc_submissions LIKE 'status';
--   SELECT status, COUNT(*) FROM dc_submissions GROUP BY status;
