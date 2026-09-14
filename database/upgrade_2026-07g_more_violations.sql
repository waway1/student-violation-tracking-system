-- =====================================================================
-- VTS UPGRADE — 2026-07g  (additional handbook violations)
-- Adds the remaining Major/Grave violation types named in the July 22 review
-- that weren't in the catalog yet. Safe to re-run (added only if missing).
-- =====================================================================
DROP PROCEDURE IF EXISTS vts_add_type_2026g;
DELIMITER //
CREATE PROCEDURE vts_add_type_2026g(IN vn VARCHAR(150), IN sev VARCHAR(10), IN pts INT)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM violation_types WHERE violation_name = vn) THEN
    INSERT INTO violation_types (violation_name, severity, max_points) VALUES (vn, sev, pts);
  END IF;
END //
DELIMITER ;

CALL vts_add_type_2026g('Psychological Injury / Emotional Abuse',        'Grave', 3);
CALL vts_add_type_2026g('Possession of Prohibited / Bad Articles',       'Major', 2);
CALL vts_add_type_2026g('Pornographic / Obscene Material',               'Grave', 3);
CALL vts_add_type_2026g('Cross-dressing / Improper Gender Attire',       'Major', 2);
CALL vts_add_type_2026g('Inappropriate Relationship with Staff/Faculty', 'Grave', 3);
CALL vts_add_type_2026g('Using Cellphone During Examination',            'Grave', 3);
CALL vts_add_type_2026g('Online Bullying',                               'Grave', 3);

DROP PROCEDURE IF EXISTS vts_add_type_2026g;
