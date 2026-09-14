-- =====================================================================
--  VTS UPGRADE  ·  2026-07 (part B)
--  Adds:
--   1) student_roster  — master list of REAL enrolled students. Registration
--      is checked against this so nobody can sign up with a made-up School ID.
--   2) makes new self-registrations start UNVERIFIED (email_verified = 0)
--      so the email OTP is truly required before a student can log in.
--
--  HOW TO APPLY (InfinityFree / phpMyAdmin):
--    Open phpMyAdmin → your DB → "Import" → choose this file → Go.
--  Safe to run more than once.
-- =====================================================================

-- 1) MASTER ROSTER --------------------------------------------------------
--    The admin fills this with the official enrolled-student list ONCE
--    (per semester). Only School ID + Last Name are required for the check;
--    the rest are optional but handy for auditing.
CREATE TABLE IF NOT EXISTS student_roster (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id   VARCHAR(10)  NOT NULL UNIQUE,
  lastname    VARCHAR(60)  NOT NULL,
  firstname   VARCHAR(60)  NULL,
  course      VARCHAR(150) NULL,
  year_level  VARCHAR(20)  NULL,
  is_used     TINYINT(1)   NOT NULL DEFAULT 0,   -- flips to 1 once someone registers with it
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_roster_lastname (lastname)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- OPTIONAL sample rows so you can test right away.
-- Replace these with your real enrolled students (or delete them).
INSERT IGNORE INTO student_roster (school_id, lastname, firstname, course, year_level) VALUES
  ('2023-0001', 'Garce',   'Jasper',   'BSIT', '3rd Year'),
  ('2023-0002', 'Cerdan',  'Veronica', 'BSIT', '3rd Year'),
  ('2023-0003', 'Cabungan','James',    'BSIT', '3rd Year');

-- 2) VERIFICATION: new accounts must confirm their email first ------------
--    (Existing accounts keep whatever value they already have.)
ALTER TABLE users
  ALTER COLUMN email_verified SET DEFAULT 0;
