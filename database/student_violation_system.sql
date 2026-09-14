-- =====================================================================
-- Student Violation Tracking and Management System (SVTMS)
-- Golden West Colleges, Inc.
-- FULL Database Schema  (rebuilt + expanded)
-- =====================================================================
-- Import this ONCE into phpMyAdmin / MySQL before running the system.
-- It creates the database, all tables, lookup data, and a few seed rows.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS student_violation_system
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE student_violation_system;

-- Clean slate (safe to re-run)
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS appeals;
DROP TABLE IF EXISTS sanctions;
DROP TABLE IF EXISTS scan_logs;
DROP TABLE IF EXISTS violations;
DROP TABLE IF EXISTS violation_types;
DROP TABLE IF EXISTS courses;
DROP TABLE IF EXISTS colleges;
DROP TABLE IF EXISTS system_settings;
DROP TABLE IF EXISTS users;

-- ---------------------------------------------------------------------
-- colleges  (the "department" each course belongs to)
-- ---------------------------------------------------------------------
CREATE TABLE colleges (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  college_name  VARCHAR(150) NOT NULL,
  short_name    VARCHAR(30)  NOT NULL,
  -- Most departments share the one general OSA inbox (OSA_REPORT_EMAIL in
  -- config/mail.php). A department can be given its own separate OSA contact
  -- here instead — e.g. Criminology has its own OSA, so Guard/Marshal reports
  -- covering a Crim student go straight to them rather than the general inbox.
  -- NULL = use the general OSA inbox (the default for every department).
  osa_email     VARCHAR(190) NULL,
  osa_name      VARCHAR(150) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO colleges (college_name, short_name) VALUES
  ('College of Information Technology', 'CIT'),
  ('College of Business Administration', 'CBA'),
  ('College of Education', 'COE'),
  ('College of Criminology', 'CCJ');

-- ---------------------------------------------------------------------
-- courses  (linked to a college, used in registration dropdowns)
-- ---------------------------------------------------------------------
CREATE TABLE courses (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  college_id   INT UNSIGNED NOT NULL,
  course_name  VARCHAR(150) NOT NULL,
  short_name   VARCHAR(30)  NOT NULL,
  CONSTRAINT fk_course_college FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO courses (college_id, course_name, short_name) VALUES
  (1, 'BS Information Technology', 'BSIT'),
  (1, 'BS Computer Science', 'BSCS'),
  (2, 'BS Business Administration', 'BSBA'),
  (2, 'BS Accountancy', 'BSA'),
  (3, 'Bachelor of Elementary Education', 'BEED'),
  (3, 'Bachelor of Secondary Education', 'BSED'),
  (4, 'BS Criminology', 'BSCRIM');

-- ---------------------------------------------------------------------
-- users  (every role: Student, Guard/Marshal, OSA Staff, OSA, Admin)
-- ---------------------------------------------------------------------
CREATE TABLE users (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id       VARCHAR(10)  NULL UNIQUE,
  firstname        VARCHAR(60)  NULL,
  middlename       VARCHAR(60)  NULL,
  lastname         VARCHAR(60)  NULL,
  suffix           VARCHAR(15)  NULL,
  fullname         VARCHAR(180) NOT NULL,
  username         VARCHAR(60)  NOT NULL UNIQUE,
  email            VARCHAR(150) NOT NULL UNIQUE,
  password         VARCHAR(255) NOT NULL,
  role             ENUM('Student','Guard','OSA Staff','OSA','Admin') NOT NULL DEFAULT 'Student', -- Guard is displayed as "Guard/Marshal" in the UI
  contact_number   VARCHAR(20)  NULL,
  gender           ENUM('Male','Female','Other') NULL,
  birthday         DATE NULL,
  college_id       INT UNSIGNED NULL,
  course           VARCHAR(150) NULL,
  year_level       VARCHAR(20)  NULL,
  section          VARCHAR(20)  NULL,
  profile_picture  VARCHAR(255) NULL,
  qr_code          VARCHAR(255) NULL,
  status           ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  email_verified   TINYINT(1) NOT NULL DEFAULT 1,
  verify_code      VARCHAR(6) NULL,
  verify_expires   DATETIME NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_college FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- violation_types  (lookup list, includes a severity tier)
-- ---------------------------------------------------------------------
CREATE TABLE violation_types (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  violation_name VARCHAR(150) NOT NULL,
  severity       ENUM('Minor','Major','Grave') NOT NULL DEFAULT 'Minor',
  max_points     INT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO violation_types (violation_name, severity, max_points) VALUES
  ('No School ID', 'Minor', 1),
  ('Improper Uniform', 'Minor', 1),
  ('Improper Haircut', 'Minor', 1),
  ('Multiple Earrings', 'Major', 2),
  ('Non-Neutral Nail Color', 'Minor', 1),
  ('Untrimmed Nails', 'Minor', 1),
  ('Late to Class', 'Minor', 1),
  ('Disrespect', 'Major', 2),
  ('Vandalism', 'Grave', 3),
  ('Others', 'Minor', 1);

-- ---------------------------------------------------------------------
-- violations  (core record table)
-- ---------------------------------------------------------------------
CREATE TABLE violations (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id     INT UNSIGNED NOT NULL,
  violation      VARCHAR(150) NOT NULL,
  description    TEXT NULL,
  severity       ENUM('Minor','Major','Grave') NOT NULL DEFAULT 'Minor',
  offense        ENUM('First Offense','Second Offense','Third Offense') NOT NULL DEFAULT 'First Offense',
  points         INT UNSIGNED NOT NULL DEFAULT 1,
  evidence       VARCHAR(255) NULL,
  status         ENUM('Recorded') NOT NULL DEFAULT 'Recorded',
  remarks        TEXT NULL,
  reported_by    INT UNSIGNED NULL,
  scanner_name   VARCHAR(100) NULL,
  date_reported  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_violations_student  FOREIGN KEY (student_id)  REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_violations_reporter FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_violations_status (status),
  INDEX idx_violations_student_status (student_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- scan_logs  (every QR scan performed by a Guard)
-- ---------------------------------------------------------------------
CREATE TABLE scan_logs (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id  INT UNSIGNED NOT NULL,
  scanned_by  INT UNSIGNED NOT NULL,
  scanner_name VARCHAR(100) NULL,
  scan_time   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_scanlogs_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_scanlogs_guard   FOREIGN KEY (scanned_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- notifications  (per-student feed shown on the dashboard)
-- ---------------------------------------------------------------------
CREATE TABLE notifications (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  title         VARCHAR(150) NOT NULL DEFAULT 'New Violation',
  message       VARCHAR(255) NOT NULL,
  violation_id  INT UNSIGNED NULL,
  is_read       TINYINT(1) NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notif_user_read (user_id, is_read),
  CONSTRAINT fk_notif_user      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_notif_violation FOREIGN KEY (violation_id) REFERENCES violations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- system_settings
-- ---------------------------------------------------------------------
CREATE TABLE system_settings (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_name    VARCHAR(255) NOT NULL DEFAULT 'Golden West Colleges, Inc.',
  school_address TEXT NULL,
  school_email   VARCHAR(255) NULL,
  contact_number VARCHAR(100) NULL,
  logo           VARCHAR(255) NULL,
  academic_year  VARCHAR(100) NULL,
  semester       VARCHAR(50)  NULL,
  max_points     INT NOT NULL DEFAULT 100
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO system_settings
  (school_name, school_address, school_email, contact_number, academic_year, semester, max_points)
VALUES
  ('Golden West Colleges, Inc.', 'Alaminos, Pangasinan', '', '', '2026-2027', '1st Semester', 100);

-- =====================================================================
-- SEED ACCOUNTS
-- ---------------------------------------------------------------------
-- The password hash below is a PLACEHOLDER. bcrypt hashes cannot be
-- written by hand reliably, so AFTER importing this file you MUST run
-- the included helper once to set real, working passwords:
--
--     http://localhost/SAD/setup_passwords.php
--
-- That sets every demo account's password to:  password123
-- (then delete setup_passwords.php). Logins will fail until you do this.
-- =====================================================================
INSERT INTO users
  (student_id, firstname, middlename, lastname, fullname, username, email, password, role, gender, college_id, course, year_level, section, status)
VALUES
  ('2024-00109', 'Veronica', 'C', 'Cerdan', 'Veronica C. Cerdan', 'veronica', 'veronica@gwc.edu.ph',
   '$2y$10$e0NRsk2Bo0sBQjF4i0nDhuPGZHQ0bUiq3Vz0mXq6Hk5p1nVx7zVe', 'Student', 'Female', 1, 'BS Information Technology', '3rd Year', 'Set D', 'Active'),
  (NULL, 'System', NULL, 'Admin', 'System Administrator', 'admin', 'admin@gwc.edu.ph',
   '$2y$10$e0NRsk2Bo0sBQjF4i0nDhuPGZHQ0bUiq3Vz0mXq6Hk5p1nVx7zVe', 'Admin', 'Male', NULL, NULL, NULL, NULL, 'Active'),
  (NULL, 'OSA', NULL, 'Officer', 'OSA Officer', 'osa', 'osa@gwc.edu.ph',
   '$2y$10$e0NRsk2Bo0sBQjF4i0nDhuPGZHQ0bUiq3Vz0mXq6Hk5p1nVx7zVe', 'OSA', 'Female', NULL, NULL, NULL, NULL, 'Active'),
  ('GUARD-01', 'James', NULL, 'Marshall', 'James Marshall', 'james', 'james@gwc.edu.ph',
   '$2y$10$e0NRsk2Bo0sBQjF4i0nDhuPGZHQ0bUiq3Vz0mXq6Hk5p1nVx7zVe', 'Guard', 'Male', NULL, NULL, NULL, NULL, 'Active'),
  -- ---- OSA Staff (lighter tier under OSA — no delete, no user/settings access) ----
  (NULL, 'Marites', NULL, 'Dizon',    'Marites Dizon',   'osa.staff',  'osa.staff@gwc.edu.ph',
  '$2y$10$/MDIHv1RbHGJOdezALQ14eZJR2.LAVXotornqoACp4AeWEjo6Onf.', 'OSA Staff', 'Female', NULL, NULL, NULL, NULL, 'Active'),
  -- ---- System account for the phone scanner app (anchors reported_by FK) ----
  ('SCAN-SYS', 'Scanner', NULL, 'App', 'Scanner App', 'scanner.app', 'scanner.app@gwc.edu.ph',
   '$2y$10$e0NRsk2Bo0sBQjF4i0nDhuPGZHQ0bUiq3Vz0mXq6Hk5p1nVx7zVe', 'Guard', NULL, NULL, NULL, NULL, NULL, 'Active'),
  -- ---- Second guard ----
  ('GUARD-02', 'Elena', NULL, 'Cruz',  'Elena Cruz',     'elena',      'elena@gwc.edu.ph',
   '$2y$10$e0NRsk2Bo0sBQjF4i0nDhuPGZHQ0bUiq3Vz0mXq6Hk5p1nVx7zVe', 'Guard', 'Female', NULL, NULL, NULL, NULL, 'Active');

INSERT INTO violations (student_id, violation, severity, offense, points, status, date_reported) VALUES
  ((SELECT id FROM users WHERE username='veronica'), 'Non-Neutral Nail Color', 'Minor', 'First Offense',  1, 'Recorded', '2026-06-06 09:00:00'),
  ((SELECT id FROM users WHERE username='veronica'), 'Multiple Earrings',      'Major', 'First Offense',  2, 'Recorded', '2026-06-07 09:00:00'),
  ((SELECT id FROM users WHERE username='veronica'), 'Untrimmed Nails',        'Minor', 'Third Offense',  3, 'Recorded', '2026-06-08 09:00:00'),
  ((SELECT id FROM users WHERE username='veronica'), 'Improper Haircut',       'Minor', 'First Offense',  1, 'Recorded', '2026-06-09 09:00:00'),
  ((SELECT id FROM users WHERE username='veronica'), 'No School ID',           'Minor', 'Second Offense', 2, 'Recorded', '2026-06-10 09:00:00'),
  ((SELECT id FROM users WHERE username='veronica'), 'No School ID',           'Minor', 'First Offense',  1, 'Recorded', '2026-06-11 09:00:00'),
  ((SELECT id FROM users WHERE username='veronica'), 'Improper Uniform',       'Minor', 'First Offense',  1, 'Recorded', '2026-07-05 09:00:00');


-- =====================================================================
-- ROLE VIEWS — browse each role like its own table in phpMyAdmin
-- (students / osa_officers / osa_staff / guards / admins)
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

-- OSA (admin-level office accounts).
CREATE OR REPLACE VIEW osa_officers AS
  SELECT u.id, u.fullname, u.username, u.email, u.status, u.created_at
  FROM users u
  WHERE u.role = 'OSA';

-- OSA Staff (lighter tier — add/edit/view only, no delete or admin access).
CREATE OR REPLACE VIEW osa_staff AS
  SELECT u.id, u.fullname, u.username, u.email, u.status, u.created_at
  FROM users u
  WHERE u.role = 'OSA Staff';

-- Guard/Marshal (gate scanning + same-day report to OSA/Admin).
CREATE OR REPLACE VIEW guards AS
  SELECT u.id, u.student_id AS guard_no, u.fullname, u.username, u.email,
         u.status, u.created_at
  FROM users u
  WHERE u.role = 'Guard';

CREATE OR REPLACE VIEW admins AS
  SELECT u.id, u.fullname, u.username, u.email, u.status, u.created_at
  FROM users u
  WHERE u.role = 'Admin';


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

SET FOREIGN_KEY_CHECKS = 1;
