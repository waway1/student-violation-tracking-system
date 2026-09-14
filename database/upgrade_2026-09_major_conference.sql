-- =====================================================================
--  Major violations — talk to the student first  —  2026-09
-- =====================================================================
--  WHAT THIS ADDS
--
--  A Major (and a Grave, which this system folds in with Major everywhere
--  else) cannot be rejected on proof review or deleted until the office
--  has recorded that it was DISCUSSED with the student in person.
--
--  (This originally gated a third route, the student's appeal. Appeals
--  were removed in upgrade_2026-09_remove_appeals.sql; the gate itself is
--  unchanged and still guards the two routes that remain.)
--
--  WHY A GATE AND NOT A WARNING
--
--  Those actions are the only ways a Major stops counting, and a student
--  carries at most one Major ever — so making one disappear is the most
--  consequential thing anyone can do to a record in this system. Both
--  previously ran on a form submit, with nobody obliged to have spoken to
--  the person whose record it is.
--
--  WHY A RECORD AND NOT A CHECKBOX
--
--  The conference is what justifies the removal, so it is stored like
--  evidence: who held it, when, and what was said. An audit that can see a
--  Major was overturned but not that anyone ever met the student is not
--  much of an audit. discussion_note is required (10 characters minimum,
--  enforced in vts_record_discussion()) so the unlock cannot be a click
--  that says nothing.
--
--  WHAT IS NOT GATED
--
--  Recording a Major, APPROVING its proof, or clearing a proof decision.
--  Nothing here can stop a violation being filed and nothing here delays
--  the student being told about it — the gate is only on the routes that
--  REMOVE a Major.
--
--  Running this is OPTIONAL: vts_ensure_discussion_columns() in
--  includes/functions.php adds the same columns on first use. This file
--  exists so a DBA can apply it once, deliberately, rather than relying on
--  the application holding ALTER privileges in production.
-- =====================================================================

ALTER TABLE `violations`
  ADD COLUMN IF NOT EXISTS `discussed_at` DATETIME NULL
      COMMENT 'when the conference with the student was held'
      AFTER `proof_note`,
  ADD COLUMN IF NOT EXISTS `discussed_by` INT UNSIGNED NULL
      COMMENT 'users.id of the Admin/OSA who held it'
      AFTER `discussed_at`,
  ADD COLUMN IF NOT EXISTS `discussion_note` TEXT NULL
      COMMENT 'what was said, and the student''s account of it'
      AFTER `discussed_by`;

-- Existing rows are NULL, which reads correctly: no conference has been
-- recorded for them. Any Major already on file therefore needs one before
-- it can be removed — which is the intended effect, not a migration gap.
-- No backfill.

-- ---------------------------------------------------------------------
--  WHERE IT IS ENFORCED  (includes/functions.php)
--
--    vts_discussion_required()  the rule itself — Major/Grave + no
--                               discussed_at => blocked, with the reason
--
--  and its callers:
--
--    vts_decide_proof()         'reject' only; approve and clear are free
--    admin/delete_violation.php before the DELETE, and before the audit row
--
--  Every one of them is server-side. The disabled Reject button on
--  admin/proof.php is a courtesy to the reader, not the control.
-- =====================================================================
