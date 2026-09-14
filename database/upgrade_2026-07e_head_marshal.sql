-- =====================================================================
-- VTS UPGRADE — 2026-07e  (Head Marshal role + report handoff)
-- Run in phpMyAdmin AFTER the earlier upgrades. Safe to re-run.
--
-- Adds the 'Head Marshal' account role and a marshal_reports table that
-- records each marshal's shift-report handoff. Workflow:
--   Marshal (Guard) scans  →  submits shift report to the Head Marshal
--   Head Marshal consolidates every marshal's report and does the single
--   final send to the OSA gmail + all admins ("Sync Now"), even if no
--   admin is present.
-- =====================================================================

-- 1) Add the Head Marshal role (re-running just re-applies the same list).
ALTER TABLE users
  MODIFY COLUMN role ENUM('Student','Guard','Head Marshal','OSA','Dean','Admin')
  NOT NULL DEFAULT 'Student';

-- 2) The report handoff ledger.
CREATE TABLE IF NOT EXISTS marshal_reports (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  guard_id        INT UNSIGNED NULL,          -- marshal who submitted
  guard_name      VARCHAR(150) NULL,
  report_date     DATE NOT NULL,
  violation_count INT UNSIGNED NOT NULL DEFAULT 0,
  status          ENUM('Submitted','Sent') NOT NULL DEFAULT 'Submitted',
  submitted_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at         DATETIME NULL,
  sent_by         INT UNSIGNED NULL,           -- head marshal who forwarded
  INDEX idx_mr_date   (report_date),
  INDEX idx_mr_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) (Optional) create a demo Head Marshal account. Password = password123
--    (same bcrypt hash used by the other seeded demo accounts). Skip/change
--    on a real deployment.
INSERT INTO users (student_id, firstname, lastname, fullname, username, email, password, role, gender, status)
SELECT NULL, 'Head', 'Marshal', 'Head Marshal', 'headmarshal', 'headmarshal@gwc.edu.ph',
       '$2y$10$e0NRsk2Bo0sBQjF4i0nDhuPGZHQ0bUiq3Vz0mXq6Hk5p1nVx7zVe', 'Head Marshal', 'Male', 'Active'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'headmarshal');
