-- =====================================================================
-- VTS UPGRADE — 2026-07i  (severity = Minor / Major only)
-- Safe to re-run.
--
-- The handbook only recognises MINOR and MAJOR offences. "Grave" was an
-- extra tier the system invented; every Grave record becomes Major so
-- nothing is lost and the official sheet has just two columns.
-- =====================================================================

-- 1) Fold existing Grave rows into Major (data first, while the enum still allows it)
UPDATE violations      SET severity = 'Major' WHERE severity = 'Grave';
UPDATE violation_types SET severity = 'Major' WHERE severity = 'Grave';

-- 2) Narrow the columns so 'Grave' can never be written again
ALTER TABLE violations
  MODIFY COLUMN severity ENUM('Minor','Major') NOT NULL DEFAULT 'Minor';
ALTER TABLE violation_types
  MODIFY COLUMN severity ENUM('Minor','Major') NOT NULL DEFAULT 'Minor';

-- 3) Points: Minor = 1, Major = 2 (no third tier)
UPDATE violation_types SET max_points = 2 WHERE severity = 'Major' AND max_points > 2;
UPDATE violation_types SET max_points = 1 WHERE severity = 'Minor';
