-- =====================================================================
-- UPGRADE SCRIPT — run this if you ALREADY have the database installed
-- and don't want to lose data. (Fresh installs: just import the main
-- student_violation_system.sql instead.)
-- =====================================================================
USE student_violation_system;

-- reject.php / approve.php track who processed the violation
ALTER TABLE violations
  ADD COLUMN approved_by INT UNSIGNED NULL AFTER reported_by,
  ADD CONSTRAINT fk_violations_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL;

-- Speed up the queries used on every page load
ALTER TABLE violations
  ADD INDEX idx_violations_status (status),
  ADD INDEX idx_violations_student_status (student_id, status);

ALTER TABLE notifications
  ADD INDEX idx_notif_user_read (user_id, is_read);

-- Align system_settings with the Settings page (admin/setting.php)
ALTER TABLE system_settings
  ADD COLUMN IF NOT EXISTS contact_number VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS logo           VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS academic_year  VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS semester       VARCHAR(50)  NULL,
  ADD COLUMN IF NOT EXISTS max_points     INT NOT NULL DEFAULT 100;

-- New role accounts (run setup_passwords.php after; password becomes password123)
INSERT INTO users (student_id, firstname, lastname, fullname, username, email, password, role, gender, college_id, status) VALUES
  (NULL, 'Ramon',   'Velasco',  'Ramon Velasco',   'dean.cit',   'dean.cit@gwc.edu.ph',   'x', 'Dean', 'Male',   1, 'Active'),
  (NULL, 'Lourdes', 'Santiago', 'Lourdes Santiago','dean.cba',   'dean.cba@gwc.edu.ph',   'x', 'Dean', 'Female', 2, 'Active'),
  (NULL, 'Teresa',  'Aquino',   'Teresa Aquino',   'dean.coe',   'dean.coe@gwc.edu.ph',   'x', 'Dean', 'Female', 3, 'Active'),
  (NULL, 'Rodrigo', 'Mateo',    'Rodrigo Mateo',   'dean.crim',  'dean.crim@gwc.edu.ph',  'x', 'Dean', 'Male',   4, 'Active'),
  (NULL, 'Marites', 'Dizon',    'Marites Dizon',   'osa.staff',  'osa.staff@gwc.edu.ph',  'x', 'OSA',  'Female', NULL, 'Active'),
  ('GUARD-02', 'Elena', 'Cruz', 'Elena Cruz',      'elena',      'elena@gwc.edu.ph',      'x', 'Guard','Female', NULL, 'Active');

-- Remove College of Hospitality Management and College of Engineering (and anything tied to them)
DELETE FROM users    WHERE username IN ('dean.chm', 'dean.coeng');
DELETE FROM courses  WHERE college_id IN (SELECT id FROM colleges WHERE short_name IN ('CHM','COENG'));
UPDATE users SET college_id = NULL WHERE college_id IN (SELECT id FROM colleges WHERE short_name IN ('CHM','COENG'));
DELETE FROM colleges WHERE short_name IN ('CHM','COENG');

-- OTP email verification
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS email_verified TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS verify_code    VARCHAR(6) NULL,
  ADD COLUMN IF NOT EXISTS verify_expires DATETIME NULL;


-- =====================================================================
-- ROLE VIEWS — browse each role like its own table in phpMyAdmin
-- (students / deans / osa_staff / guards / admins)
-- One real `users` table keeps logins + foreign keys intact; these
-- views give clean, role-specific "tables" for browsing and reports.
-- =====================================================================
CREATE OR REPLACE VIEW students AS
  SELECT u.id, u.student_id, u.fullname, u.username, u.email, u.contact_number,
         u.gender, c.college_name, u.course, u.year_level, u.section,
         u.status, u.email_verified, u.created_at
  FROM users u
  LEFT JOIN colleges c ON u.college_id = c.id
  WHERE u.role = 'Student';

CREATE OR REPLACE VIEW deans AS
  SELECT u.id, u.fullname, u.username, u.email,
         c.college_name AS assigned_college, u.status, u.created_at
  FROM users u
  LEFT JOIN colleges c ON u.college_id = c.id
  WHERE u.role = 'Dean';

CREATE OR REPLACE VIEW osa_staff AS
  SELECT u.id, u.fullname, u.username, u.email, u.status, u.created_at
  FROM users u
  WHERE u.role = 'OSA';

CREATE OR REPLACE VIEW guards AS
  SELECT u.id, u.student_id AS guard_no, u.fullname, u.username, u.email,
         u.status, u.created_at
  FROM users u
  WHERE u.role = 'Guard';

CREATE OR REPLACE VIEW admins AS
  SELECT u.id, u.fullname, u.username, u.email, u.status, u.created_at
  FROM users u
  WHERE u.role = 'Admin';

-- Physical scanner name (phone can be handed to another guard on duty)
ALTER TABLE violations ADD COLUMN IF NOT EXISTS scanner_name VARCHAR(100) NULL AFTER reported_by;
ALTER TABLE scan_logs  ADD COLUMN IF NOT EXISTS scanner_name VARCHAR(100) NULL AFTER scanned_by;

-- Suffix support + phone-scanner system account
ALTER TABLE users ADD COLUMN IF NOT EXISTS suffix VARCHAR(15) NULL AFTER lastname;
INSERT INTO users (student_id, firstname, lastname, fullname, username, email, password, role, status)
SELECT 'SCAN-SYS','Scanner','App','Scanner App','scanner.app','scanner.app@gwc.edu.ph','x','Guard','Active'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username='scanner.app');

-- Sanctions & Appeals feature removed
DROP TABLE IF EXISTS appeals;
DROP TABLE IF EXISTS sanctions;
ALTER TABLE violations DROP COLUMN IF EXISTS sanction;

-- Direct-recording flow: violations are final records, no approve/reject
UPDATE violations SET status = 'Recorded';
ALTER TABLE violations
  MODIFY status ENUM('Recorded') NOT NULL DEFAULT 'Recorded',
  DROP FOREIGN KEY fk_violations_approver,
  DROP COLUMN approved_by,
  DROP COLUMN approved_at;


-- =====================================================================
-- PER-COURSE STUDENT VIEWS (7) — browse each program like its own table
-- e.g. click `bscrim_students` in phpMyAdmin to see only Criminology
-- students, complete with their violation count and total points.
-- Total database objects: 8 base tables + 5 role views + 7 course views = 20
-- =====================================================================
CREATE OR REPLACE VIEW bsit_students AS
  SELECT u.id, u.student_id, u.fullname, u.year_level, u.section,
         u.email, u.contact_number, u.status,
         COUNT(v.id)              AS violation_count,
         COALESCE(SUM(v.points),0) AS total_points
  FROM users u
  LEFT JOIN violations v ON v.student_id = u.id
  WHERE u.role = 'Student' AND u.course = 'BS Information Technology'
  GROUP BY u.id;

CREATE OR REPLACE VIEW bscs_students AS
  SELECT u.id, u.student_id, u.fullname, u.year_level, u.section,
         u.email, u.contact_number, u.status,
         COUNT(v.id)              AS violation_count,
         COALESCE(SUM(v.points),0) AS total_points
  FROM users u
  LEFT JOIN violations v ON v.student_id = u.id
  WHERE u.role = 'Student' AND u.course = 'BS Computer Science'
  GROUP BY u.id;

CREATE OR REPLACE VIEW bsba_students AS
  SELECT u.id, u.student_id, u.fullname, u.year_level, u.section,
         u.email, u.contact_number, u.status,
         COUNT(v.id)              AS violation_count,
         COALESCE(SUM(v.points),0) AS total_points
  FROM users u
  LEFT JOIN violations v ON v.student_id = u.id
  WHERE u.role = 'Student' AND u.course = 'BS Business Administration'
  GROUP BY u.id;

CREATE OR REPLACE VIEW bsa_students AS
  SELECT u.id, u.student_id, u.fullname, u.year_level, u.section,
         u.email, u.contact_number, u.status,
         COUNT(v.id)              AS violation_count,
         COALESCE(SUM(v.points),0) AS total_points
  FROM users u
  LEFT JOIN violations v ON v.student_id = u.id
  WHERE u.role = 'Student' AND u.course = 'BS Accountancy'
  GROUP BY u.id;

CREATE OR REPLACE VIEW beed_students AS
  SELECT u.id, u.student_id, u.fullname, u.year_level, u.section,
         u.email, u.contact_number, u.status,
         COUNT(v.id)              AS violation_count,
         COALESCE(SUM(v.points),0) AS total_points
  FROM users u
  LEFT JOIN violations v ON v.student_id = u.id
  WHERE u.role = 'Student' AND u.course = 'Bachelor of Elementary Education'
  GROUP BY u.id;

CREATE OR REPLACE VIEW bsed_students AS
  SELECT u.id, u.student_id, u.fullname, u.year_level, u.section,
         u.email, u.contact_number, u.status,
         COUNT(v.id)              AS violation_count,
         COALESCE(SUM(v.points),0) AS total_points
  FROM users u
  LEFT JOIN violations v ON v.student_id = u.id
  WHERE u.role = 'Student' AND u.course = 'Bachelor of Secondary Education'
  GROUP BY u.id;

CREATE OR REPLACE VIEW bscrim_students AS
  SELECT u.id, u.student_id, u.fullname, u.year_level, u.section,
         u.email, u.contact_number, u.status,
         COUNT(v.id)              AS violation_count,
         COALESCE(SUM(v.points),0) AS total_points
  FROM users u
  LEFT JOIN violations v ON v.student_id = u.id
  WHERE u.role = 'Student' AND u.course = 'BS Criminology'
  GROUP BY u.id;

