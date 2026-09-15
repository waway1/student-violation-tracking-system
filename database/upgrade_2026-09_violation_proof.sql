-- =====================================================================
--  Proof review  —  2026-09
-- =====================================================================
--  WHAT THIS ADDS
--
--  A violation carries a reason and, where one was taken, a photo. An
--  Admin/OSA reads one against the other on admin/proof.php and says
--  whether they agree. This stores that decision.
--
--  THIS IS NOT THE OLD approve/reject COLUMN COMING BACK
--
--  upgrade_2026-07.sql dropped `approved_by` / `approved_at` and collapsed
--  `status` to a single value, with the note "Direct-recording flow:
--  violations are final records, no approve/reject". That decision stands
--  and this does not reverse it.
--
--  The difference is what Pending means. In the old flow a violation did
--  not count until somebody approved it, which is unworkable here: the
--  scanner is offline-first, and a shift's scans can reach the server days
--  later on a USB. Every one of those would have sat uncounted, invisible
--  to the office and to the student, until a reviewer got to it.
--
--  So Pending is not a holding pen. An unreviewed violation counts exactly
--  as it always did. A review can only ever SUBTRACT: Rejected means an
--  Admin looked at the proof and found it does not support the record.
--
--  WHY NOT REUSE cleared_at
--
--  "Served the penalty" (cleared_at) and "the proof did not support this"
--  (proof_status) are two separate claims about a record. They happen to
--  have the same effect on the offense ladder; they are not the same fact,
--  and a disciplinary record that cannot say which one happened is worth
--  less than one that can. cleared_at also only ever applies to a Minor
--  (violation_is_clearable() refuses a Major), and a proof review has to
--  be able to strike a Major just as readily.
--
--  Running this is OPTIONAL: vts_ensure_proof_columns() in
--  includes/functions.php adds the same columns on first use. This file
--  exists so a DBA can apply it once, deliberately, rather than relying on
--  the application holding ALTER privileges in production.
-- =====================================================================

ALTER TABLE `violations`
  ADD COLUMN IF NOT EXISTS `proof_status`
      ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending'
      COMMENT 'proof review outcome; Pending counts normally'
      AFTER `evidence`,
  ADD COLUMN IF NOT EXISTS `proof_reviewed_by` INT UNSIGNED NULL
      COMMENT 'users.id of the Admin/OSA who decided it'
      AFTER `proof_status`,
  ADD COLUMN IF NOT EXISTS `proof_reviewed_at` DATETIME NULL
      AFTER `proof_reviewed_by`,
  ADD COLUMN IF NOT EXISTS `proof_note` TEXT NULL
      COMMENT 'why, in the reviewer''s words; shown to the student on a rejection'
      AFTER `proof_reviewed_at`;

-- Existing rows are Pending by the column default, which is the correct
-- reading of them: nobody has reviewed their proof, and they have been
-- counting all along. No backfill.

-- ---------------------------------------------------------------------
--  THE OFFENSE LADDER
--
--  The same two places in includes/functions.php that skip a cleared
--  Minor now also skip a rejected row, at any severity:
--
--    active_violation_count()   adds  AND proof_status <> 'Rejected'
--    vts_renumber_offenses()    a rejected row never advances the ladder
--
--  vts_decide_proof() calls vts_renumber_offenses() whenever a decision
--  moves a row INTO or OUT OF Rejected, because every offense number after
--  it shifts. A decision is reversible for exactly that reason — an Admin
--  who rejects the wrong row must be able to put it back.
-- =====================================================================
