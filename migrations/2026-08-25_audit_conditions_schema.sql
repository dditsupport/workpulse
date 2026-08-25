-- =========================================================
-- WorkPulse — schema for condition-based audit questions
-- 2026-08-25
--
-- The Audit 2026 v10/v15 checklists answer most questions by picking one
-- (or, for a few, several) pre-written condition instead of typing a
-- rating or a number. This adds the table and columns that support:
--   audit_parameter_options  — the conditions a question offers, the
--                              action each one calls for, and its points.
--   audit_parameters.option_mode — 'radio' (pick one, default) or
--                              'checkbox' (tick any number, points add up).
--   audit_responses.option_id/option_text — snapshot of the single
--                              condition an auditor picked (radio), kept
--                              even if the master option is edited later.
--   audit_responses.option_ids — comma-separated ids for a checkbox
--                              question where more than one was ticked.
--
-- Additive and safe to run at any time. modules/audit.php already
-- feature-detects every one of these (auditGetParameterOptions(),
-- auditHasOptionModeCol(), auditHasResponseOptionCols(),
-- auditHasResponseOptionIdsCol()) and falls back to plain rating/value/
-- boolean questions with no conditions until this has run.
-- =========================================================

CREATE TABLE IF NOT EXISTS `audit_parameter_options` (
  `id`           int(11)      NOT NULL AUTO_INCREMENT,
  `parameter_id` int(11)      NOT NULL,
  `option_text`  varchar(1000) NOT NULL,
  `action_hint`  varchar(500) DEFAULT NULL,
  `points`       decimal(10,2) NOT NULL DEFAULT 0.00,
  `sort_order`   int(11)      NOT NULL DEFAULT 0,
  `is_active`    tinyint(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `ix_apo_param` (`parameter_id`,`sort_order`),
  CONSTRAINT `audit_parameter_options_ibfk_1`
    FOREIGN KEY (`parameter_id`) REFERENCES `audit_parameters` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `audit_parameters`
  ADD COLUMN IF NOT EXISTS `option_mode` enum('radio','checkbox') NOT NULL DEFAULT 'radio' AFTER `type`;

-- No FK on option_id on purpose — the response has to survive the master
-- option being edited or removed after the fact.
ALTER TABLE `audit_responses`
  ADD COLUMN IF NOT EXISTS `option_id`   int(11)       DEFAULT NULL AFTER `value_entered`,
  ADD COLUMN IF NOT EXISTS `option_text` varchar(1000) DEFAULT NULL AFTER `option_id`,
  ADD COLUMN IF NOT EXISTS `option_ids`  varchar(500)  DEFAULT NULL AFTER `option_text`;
