-- =====================================================================
-- VTS UPGRADE — 2026-07f  (offense counter fix + robust violation columns)
-- Run in phpMyAdmin AFTER the earlier upgrades. Safe to re-run.
--
-- FIXES:
--  · Bug 40 — the `offense` column was ENUM('First Offense','Second Offense',
--    'Third Offense'), so a student's 4th (or later) violation could not be
--    stored: MySQL wrote '' and the offense read back as blank/NULL. Widening
--    it to VARCHAR lets the counter keep going (Fourth, Fifth, … Nth Offense).
--  · Bug 38 — makes sure severity / points / evidence / remarks exist so
--    recording and backups never fail with "column doesn't exist".
-- =====================================================================

-- 1) Widen offense so it can hold ANY offense number (keeps existing text).
ALTER TABLE violations
  MODIFY COLUMN offense VARCHAR(30) NOT NULL DEFAULT 'First Offense';

-- 2) Ensure the other violation columns exist (idempotent via a helper proc).
DROP PROCEDURE IF EXISTS vts_add_col_2026f;
DELIMITER //
CREATE PROCEDURE vts_add_col_2026f(IN col VARCHAR(64), IN ddl VARCHAR(255))
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='violations' AND COLUMN_NAME=col) THEN
    SET @s = CONCAT('ALTER TABLE `violations` ADD COLUMN ', ddl);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END //
DELIMITER ;
CALL vts_add_col_2026f('severity', "`severity` ENUM('Minor','Major','Grave') NOT NULL DEFAULT 'Minor'");
CALL vts_add_col_2026f('points',   "`points` INT UNSIGNED NOT NULL DEFAULT 1");
CALL vts_add_col_2026f('evidence', "`evidence` VARCHAR(255) NULL");
CALL vts_add_col_2026f('remarks',  "`remarks` TEXT NULL");
DROP PROCEDURE IF EXISTS vts_add_col_2026f;

-- 3) Repair any rows whose offense was blanked out by the old ENUM limit —
--    recompute each student's offense number from chronological order.
UPDATE violations v
SET v.offense = (
  SELECT CASE cnt
    WHEN 1 THEN 'First Offense'  WHEN 2 THEN 'Second Offense' WHEN 3 THEN 'Third Offense'
    WHEN 4 THEN 'Fourth Offense' WHEN 5 THEN 'Fifth Offense'  WHEN 6 THEN 'Sixth Offense'
    WHEN 7 THEN 'Seventh Offense' WHEN 8 THEN 'Eighth Offense' WHEN 9 THEN 'Ninth Offense'
    WHEN 10 THEN 'Tenth Offense' ELSE CONCAT(cnt, 'th Offense') END
  FROM (
    SELECT COUNT(*) AS cnt FROM violations v2
    WHERE v2.student_id = v.student_id AND v2.date_reported <= v.date_reported
      AND (v2.date_reported < v.date_reported OR v2.id <= v.id)
  ) x
)
WHERE v.offense = '' OR v.offense IS NULL;
