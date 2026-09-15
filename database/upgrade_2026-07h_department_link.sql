-- =====================================================================
-- VTS UPGRADE — 2026-07h  (Department ↔ Course linkage)
-- Safe to re-run.
--
-- FIXES: students stored the course SHORT name ("BSIT") while courses held
-- the FULL name ("BS Information Technology"), so nothing joined and the
-- Department column came out blank even for courses full of students.
--   1. Colleges are renamed to "Department of ..." (what the school calls them).
--   2. Every student's college_id is back-filled by matching their stored
--      course against EITHER the short name or the full name.
--   3. users.course is normalised to the SHORT name (BSIT, BSCRIM, ...) so
--      one consistent value is used everywhere.
-- =====================================================================

-- 1) Departments (was "College of ...")
UPDATE colleges SET college_name = 'Department of Information Technology' WHERE short_name = 'CIT';
UPDATE colleges SET college_name = 'Department of Business Administration' WHERE short_name = 'CBA';
UPDATE colleges SET college_name = 'Department of Education'               WHERE short_name = 'COE';
UPDATE colleges SET college_name = 'Department of Criminology'             WHERE short_name = 'CCJ';

-- 2) Back-fill college_id from whatever the student has in `course`
UPDATE users u
JOIN courses c
  ON  u.course IS NOT NULL AND u.course <> ''
  AND (u.course = c.short_name OR u.course = c.course_name)
SET u.college_id = c.college_id
WHERE u.role = 'Student' AND (u.college_id IS NULL OR u.college_id = 0);

-- 3) Normalise course to the SHORT name so joins are consistent everywhere
UPDATE users u
JOIN courses c ON u.course = c.course_name
SET u.course = c.short_name
WHERE u.role = 'Student';

-- 4) Same treatment for the enrolment roster
UPDATE student_roster r
JOIN courses c ON (r.course = c.short_name OR r.course = c.course_name)
SET r.course = c.short_name
WHERE r.course IS NOT NULL AND r.course <> '';
