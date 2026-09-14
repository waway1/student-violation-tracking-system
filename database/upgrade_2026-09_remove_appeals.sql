-- =====================================================================
--  Remove violation appeals  —  2026-09
-- =====================================================================
--  WHAT THIS REMOVES
--
--  The whole appeal feature: the student's "contest this record" form,
--  the OSA review queue (admin/appeals.php), the sidebar item and its
--  badge, and the dashboard panel. The application code is already gone;
--  this is the schema catching up.
--
--  Contesting a record now goes through the office in person. The system
--  keeps one route that makes a violation stop counting without deleting
--  it: PROOF REVIEW (admin/proof.php), where an Admin reads the photo
--  against the reason and rejects it if the two do not agree.
--
--  WHY voided_at GOES TOO
--
--  voided_at / voided_by existed for exactly one event: an appeal being
--  upheld. Nothing else ever wrote them. With appeals gone the columns
--  can only ever be NULL, and a column that can only be NULL is a trap —
--  the next person to read the schema will assume something still sets it.
--
--  THE ROWS THAT WERE ALREADY VOIDED
--
--  Step 1 below is the important one and MUST run before the DROP. A
--  violation with voided_at set is currently excluded from the student's
--  offense ladder. Drop the column with no conversion and that row starts
--  counting again — a student's 2nd offense silently becomes their 3rd.
--
--    Minor  -> cleared_at. Same effect, same meaning in this schema
--              ("this one no longer counts"), and it is the state a
--              Minor would have been put in anyway.
--    Major  -> proof_status = 'Rejected'. A Major cannot be cleared
--              (violation_is_clearable() refuses it), so the only state
--              left that stops a Major counting is a rejected proof
--              review. The note says where it came from.
--
--  ORDER MATTERS. Run this top to bottom, in one go.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. Preserve what voided_at meant, BEFORE the column disappears.
-- ---------------------------------------------------------------------
UPDATE violations
   SET cleared_at = voided_at,
       cleared_by = voided_by
 WHERE voided_at IS NOT NULL
   AND cleared_at IS NULL
   AND severity = 'Minor';

UPDATE violations
   SET proof_status      = 'Rejected',
       proof_reviewed_at = voided_at,
       proof_reviewed_by = voided_by,
       proof_note        = CONCAT_WS(' ',
                             proof_note,
                             '[Carried over from an appeal that was upheld before the appeals feature was removed.]')
 WHERE voided_at IS NOT NULL
   AND severity <> 'Minor'
   AND proof_status <> 'Rejected';

-- ---------------------------------------------------------------------
-- 2. Now the columns and the table can go.
-- ---------------------------------------------------------------------
ALTER TABLE violations
  DROP COLUMN IF EXISTS voided_at,
  DROP COLUMN IF EXISTS voided_by;

DROP TABLE IF EXISTS violation_appeals;

-- ---------------------------------------------------------------------
-- 3. Verify. Both should return zero rows.
-- ---------------------------------------------------------------------
-- SELECT COLUMN_NAME FROM information_schema.COLUMNS
--  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'violations'
--    AND COLUMN_NAME IN ('voided_at','voided_by');
-- SELECT TABLE_NAME FROM information_schema.TABLES
--  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'violation_appeals';
