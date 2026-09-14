-- 2026-09: ensure the OSA Staff login exists.
-- Safe to run more than once in phpMyAdmin.
-- Demo password hash matches the existing seeded account password.

ALTER TABLE users
  MODIFY COLUMN role ENUM('Student','Guard','Head Marshal','OSA Staff','OSA','Dean','Admin')
  NOT NULL DEFAULT 'Student';

INSERT INTO users
  (firstname, lastname, fullname, username, email, password, role, gender, status, email_verified)
SELECT
  'OSA', 'Staff', 'OSA Staff', 'osa.staff', 'osa.staff@gwc.edu.ph',
  '$2y$10$/MDIHv1RbHGJOdezALQ14eZJR2.LAVXotornqoACp4AeWEjo6Onf.',
  'OSA Staff', 'Other', 'Active', 1
WHERE NOT EXISTS (
  SELECT 1 FROM users WHERE username = 'osa.staff'
);

UPDATE users
SET role = 'OSA Staff', status = 'Active', email_verified = 1
WHERE username = 'osa.staff';

UPDATE users SET role = 'Guard' WHERE role = 'Head Marshal';
UPDATE users SET role = 'OSA Staff', status = 'Inactive' WHERE role = 'Dean';

UPDATE users
SET firstname = 'Denzel', lastname = 'Valdez', fullname = 'Mr. Denzel Valdez'
WHERE username = 'admin'
  AND LOWER(fullname) LIKE '%alma%'
  AND LOWER(fullname) LIKE '%viray%';

ALTER TABLE users
  MODIFY COLUMN role ENUM('Student','Guard','OSA Staff','OSA','Admin')
  NOT NULL DEFAULT 'Student';
