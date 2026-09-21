-- =========================================================
-- WorkPulse — Employee Documents (HRMS personnel file)
-- 2026-09-21
--
-- The paperwork a hire arrives with — resume, government ID, the
-- interview sheet the panel filled in, the signed offer — lives today in
-- a folder on somebody's desk or in a mail thread. When that person
-- leaves, the record is needed MORE, not less: a background-verification
-- call from their next employer, a PF or gratuity query, an audit asking
-- who was interviewed and by whom, a re-hire enquiry two years on. That
-- is exactly when a desk folder has already been cleared out.
--
-- So: one personnel file per employee, inside HRMS, that outlives the
-- employment. Deactivating an employee ("left") does nothing to their
-- documents — the archive lists a left employee exactly as it lists a
-- serving one, and the "Left staff" filter is there because that is the
-- set HR comes looking for.
--
-- One table:
--   employee_documents — a row per uploaded file, on disk under
--                        uploads/employee_docs/{YYYY-MM}/, served only
--                        through ?page=employee_doc_file&id=N.
--
-- Why the employee's code and name are COPIED onto every row: the row
-- has to stay readable when the employees row it came from has been
-- renamed, or is gone. employee_id is the live link; the two snapshot
-- columns are what the archive can still print when that link is dead.
--
-- Deleting is a soft delete (deleted_at / deleted_by / delete_reason) —
-- a retention archive that a mis-click can empty is not an archive. The
-- file stays on disk, superadmin can see removed rows and restore them.
--
-- Additive and safe to re-run.
-- =========================================================

CREATE TABLE IF NOT EXISTS `employee_documents` (
  `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  -- Live link to employees.id. Kept even after the employee is
  -- deactivated; NULL only if the employees row itself ever goes away.
  `employee_id`   int(11)      DEFAULT NULL,
  -- Snapshots, written at upload time and never updated afterwards.
  `employee_code` varchar(50)  NOT NULL,
  `employee_name` varchar(100) NOT NULL,
  -- resume | government_id | interview_sheet | offer_letter |
  -- appointment_letter | relieving_letter | experience_letter | other.
  -- varchar, not enum, so EMPDOC_TYPES in modules/employee_docs.php can
  -- grow a category without a migration.
  `doc_type`      varchar(40)  NOT NULL DEFAULT 'other',
  -- What HR calls this document ("Aadhaar", "Panel round 2"). Blank
  -- falls back to the doc_type label on screen.
  `title`         varchar(200) DEFAULT NULL,
  -- The date ON the document (interview date, letter date) — not the
  -- upload date, which is uploaded_at.
  `doc_date`      date         DEFAULT NULL,
  `original_name` varchar(255) NOT NULL,
  `stored_name`   varchar(120) NOT NULL,
  -- Which uploads/employee_docs/{YYYY-MM}/ folder holds stored_name.
  `bucket_month`  char(7)      NOT NULL,
  `mime_type`     varchar(100) NOT NULL DEFAULT 'application/pdf',
  `file_size`     int(10) UNSIGNED NOT NULL DEFAULT 0,
  -- Lets a later integrity check tell "the file changed" from "the file
  -- was replaced by a newer scan", and spots an exact re-upload.
  `sha256`        char(64)     DEFAULT NULL,
  `notes`         text         DEFAULT NULL,
  `uploaded_by`   varchar(50)  DEFAULT NULL,
  `uploaded_at`   datetime     NOT NULL DEFAULT current_timestamp(),
  -- Soft delete. A row with deleted_at set is hidden from the archive
  -- but its file is still on disk and superadmin can restore it.
  `deleted_at`    datetime     DEFAULT NULL,
  `deleted_by`    varchar(50)  DEFAULT NULL,
  `delete_reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_empdoc_employee` (`employee_id`),
  KEY `idx_empdoc_code`     (`employee_code`),
  KEY `idx_empdoc_type`     (`doc_type`),
  KEY `idx_empdoc_live`     (`deleted_at`, `uploaded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- txn_employee_docs — open the personnel-file archive, upload into it and
-- remove a document from it. Deliberately NOT implied by txn_employees:
-- a government ID and a salary-bearing offer letter are a narrower thing
-- than the employee master, so this starts at 0 on every role and is
-- granted by hand on Administration › Roles. Restoring a removed
-- document stays superadmin-only and has no flag.
ALTER TABLE `roles`
  ADD COLUMN IF NOT EXISTS `txn_employee_docs` tinyint(1) NOT NULL DEFAULT 0;

-- Check afterwards:
--   SHOW COLUMNS FROM employee_documents;
--   SHOW COLUMNS FROM roles LIKE 'txn_employee_docs';
