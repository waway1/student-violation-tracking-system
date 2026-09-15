-- =====================================================================
-- VTS UPGRADE — 2026-07d  (Major/Minor categorization + clearing policy)
-- Run in phpMyAdmin AFTER student_violation_system.sql / seed_violation_types.sql.
-- Safe to re-run (each column is added only if missing).
--
-- Adds:
--   violation_types.critical_alert  -> 1 = fire an urgent Admin notification
--                                      the moment this violation is recorded
--                                      (e.g. deadly weapon / explosives).
--   violations.cleared_at / cleared_by -> when a MINOR offense is marked
--                                      "Served/Cleared" by the Admin. Cleared
--                                      minors stay in history but stop counting
--                                      toward the active 1st/2nd/3rd escalation.
--                                      Major/Grave are never cleared (permanent).
-- =====================================================================

-- ---- idempotent column adds (works on MySQL 5.7+/8 and MariaDB) ----
DROP PROCEDURE IF EXISTS vts_add_col_2026d;
DELIMITER //
CREATE PROCEDURE vts_add_col_2026d(IN tbl VARCHAR(64), IN col VARCHAR(64), IN ddl VARCHAR(255))
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND COLUMN_NAME = col
  ) THEN
    SET @s = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN ', ddl);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END //
DELIMITER ;

CALL vts_add_col_2026d('violation_types', 'critical_alert', '`critical_alert` TINYINT(1) NOT NULL DEFAULT 0');
CALL vts_add_col_2026d('violations',      'cleared_at',     '`cleared_at` DATETIME NULL');
CALL vts_add_col_2026d('violations',      'cleared_by',     '`cleared_by` INT UNSIGNED NULL');

DROP PROCEDURE IF EXISTS vts_add_col_2026d;

-- ---- Handbook items named in the CITE meeting that were missing ----
--  Added only if not already present (violation_types has no UNIQUE key on
--  violation_name, so this NOT EXISTS guard keeps re-runs from duplicating).
DROP PROCEDURE IF EXISTS vts_add_type_2026d;
DELIMITER //
CREATE PROCEDURE vts_add_type_2026d(IN vn VARCHAR(150), IN sev VARCHAR(10), IN pts INT)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM violation_types WHERE violation_name = vn) THEN
    INSERT INTO violation_types (violation_name, severity, max_points) VALUES (vn, sev, pts);
  END IF;
END //
DELIMITER ;

CALL vts_add_type_2026d('Hazing / Initiation',                        'Grave', 3);
CALL vts_add_type_2026d('Fraternity / Sorority Recruitment',          'Grave', 3);
CALL vts_add_type_2026d('Impersonation',                              'Grave', 3);
CALL vts_add_type_2026d('Assisting in Copying / Cheating',            'Grave', 3);
CALL vts_add_type_2026d('Unauthorized AI Use in Exams (e.g. ChatGPT)','Grave', 3);
CALL vts_add_type_2026d('Class Boycotting',                           'Major', 2);
CALL vts_add_type_2026d('Carrying Explosives',                        'Grave', 3);

DROP PROCEDURE IF EXISTS vts_add_type_2026d;

-- ---- Flag the immediate-alert (critical) violations ----
UPDATE violation_types
   SET critical_alert = 1
 WHERE violation_name IN (
   'Possession of Deadly Weapon',
   'Carrying Explosives',
   'Possession / Use of Prohibited Drugs',
   'Physical Assault / Fighting'
 );
