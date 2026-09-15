-- =====================================================================
--  Remove the proof-review note  --  2026-09
-- =====================================================================
--  WHAT THIS REMOVES
--
--  violations.proof_note, and with it the free-text box that used to sit
--  under Approve / Reject on admin/proof.php.
--
--  WHY
--
--  That page asks one question -- does the photo show what the record
--  claims -- and Approve/Reject is the entire answer to it. The note was a
--  second output nobody asked for: written commentary ON A STUDENT, by
--  someone whose job on that screen is to read a photograph, and it was
--  then posted to the student inside their notification. Reviewing proof
--  is not the place to write about a person.
--
--  WHAT IS NOT REMOVED
--
--  violations.discussion_note stays, and so does the box that writes it.
--  That one is not an opinion, it is the record of a meeting that actually
--  happened, and it is what unlocks removing a Major. Different fact,
--  different field -- see vts_record_discussion() in includes/functions.php.
--
--  The DECISION is untouched: proof_status, proof_reviewed_by and
--  proof_reviewed_at all stay. Who decided, when, and what they decided is
--  still on the record and still in the audit log.
-- =====================================================================

ALTER TABLE violations
  DROP COLUMN IF EXISTS proof_note;

-- Verify. Should return zero rows.
-- SELECT COLUMN_NAME FROM information_schema.COLUMNS
--  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'violations'
--    AND COLUMN_NAME = 'proof_note';
