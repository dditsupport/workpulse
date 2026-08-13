-- =========================================================
-- WorkPulse — Manager Mapping gets its own permission
-- 2026-08-17
--
-- Editing the Manager Mapping page was gated on txn_audit_operation,
-- borrowed from the audit workflow because the page used to live under
-- Audits. The page has since moved to Store Operations and is readable by
-- everyone, so who may change it is a Store Operations decision and needs
-- its own checkbox — granting someone the mapping should not hand them
-- the audit Operation Review queue as well.
--
-- The seed below keeps every role that can edit the page today able to
-- edit it tomorrow. Note it is a ONE-TIME seed, not part of the schema:
-- re-running this whole file re-grants the permission to every Operation
-- Review role. If you have since revoked it from one of them, run only
-- the ALTER and skip the UPDATE.
--
-- Anyone already signed in keeps their old session flags until they log
-- out and back in — the session copies txn_* columns at login.
--
-- Additive and safe to run at any time (see the seed caveat above). Until
-- it runs, roleTxnColumns() hides the new permission on the Roles page and
-- nobody but the superadmin can edit the mapping.
-- =========================================================

ALTER TABLE `roles`
  ADD COLUMN IF NOT EXISTS `txn_manager_mapping` tinyint(1) NOT NULL DEFAULT 0 AFTER `txn_price_tags`;

-- One-time seed: carry over whoever could already edit the page.
UPDATE `roles` SET `txn_manager_mapping` = 1 WHERE `txn_audit_operation` = 1;
