-- =====================================================================
-- FIX: rebuild `users` as a REAL table
-- ---------------------------------------------------------------------
-- Your live database currently has `users` split across five physical
-- tables (admins, guards, osa_officers, osa_staff, students), with
-- `users` left behind as a read-only VIEW that UNIONs them together.
--
-- That's the reason nothing could be added/edited/deleted/updated: the
-- whole app (account.php, admin/*.php, osa_staff/*.php, login, etc.)
-- runs INSERT/UPDATE/DELETE against `users` -- and a UNION view can
-- never be written to in MySQL. It always fails ("users is not
-- updatable" / "not insertable-into"), which is the "check database" /
-- "can't do that" behavior you were seeing.
--
-- This script:
--   1. Rebuilds `users` as one real, writable table (merging all the
--      accounts back in, ids preserved so violations/scan_logs/
--      notifications/audit history all still point at the right person)
--   2. Renames the "Head Marshal" account to the OSA Staff role, as
--      requested
--   3. Turns students/guards/osa_staff/osa_officers/admins back into
--      clean READ-ONLY views over `users` -- so you still get a
--      tidy, role-filtered "table" to browse in phpMyAdmin for each
--      role (this is the "better organization for easier lookup"),
--      but all writes go through the one real table underneath
--   4. Adds the foreign keys the app's own schema always called for,
--      now that `users` is a real table again
--
-- HOW TO RUN
--   1. BACK UP first: phpMyAdmin > your database > Export > Go.
--   2. Open your database in phpMyAdmin > SQL tab.
--   3. Paste this ENTIRE file and click Go. Run it once.
--   4. Check the counts at the very bottom match what's printed there.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ---- 1. Drop the old read-only `users` view ----
DROP VIEW IF EXISTS `users`;

-- ---- 2. Create the REAL `users` table ----
CREATE TABLE `users` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `student_id`       VARCHAR(10)  NULL UNIQUE,
  `firstname`        VARCHAR(60)  NULL,
  `middlename`       VARCHAR(60)  NULL,
  `lastname`         VARCHAR(60)  NULL,
  `suffix`           VARCHAR(15)  NULL,
  `fullname`         VARCHAR(180) NOT NULL,
  `username`         VARCHAR(60)  NOT NULL UNIQUE,
  `email`            VARCHAR(150) NOT NULL UNIQUE,
  `password`         VARCHAR(255) NOT NULL,
  `role`             ENUM('Student','Guard','OSA Staff','OSA','Admin') NOT NULL DEFAULT 'Student',
  `contact_number`   VARCHAR(20)  NULL,
  `gender`           ENUM('Male','Female','Other') NULL,
  `birthday`         DATE NULL,
  `college_id`       INT UNSIGNED NULL,
  `course`           VARCHAR(150) NULL,
  `year_level`       VARCHAR(20)  NULL,
  `section`          VARCHAR(20)  NULL,
  `profile_picture`  VARCHAR(255) NULL,
  `qr_code`          VARCHAR(255) NULL,
  `status`           ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  `email_verified`   TINYINT(1) NOT NULL DEFAULT 1,
  `verify_code`      VARCHAR(6) NULL,
  `verify_expires`   DATETIME NULL,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_user_college` FOREIGN KEY (`college_id`) REFERENCES `colleges`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- 3. Pour every account back in, keeping the same ids ----
INSERT INTO `users`
  (id, student_id, firstname, middlename, lastname, suffix, fullname, username, email, password,
   role, contact_number, gender, birthday, college_id, course, year_level, section,
   profile_picture, qr_code, status, email_verified, verify_code, verify_expires, created_at)
SELECT id, student_id, firstname, middlename, lastname, suffix, fullname, username, email, password,
       'Student', contact_number, gender, birthday, college_id, course, year_level, section,
       profile_picture, qr_code, status, email_verified, verify_code, verify_expires, created_at
FROM `students`;

INSERT INTO `users`
  (id, firstname, middlename, lastname, suffix, fullname, username, email, password,
   role, contact_number, gender, birthday, status, email_verified, verify_code, verify_expires, created_at)
SELECT id, firstname, middlename, lastname, suffix, fullname, username, email, password,
       -- The "Head Marshal" account (id 13) becomes OSA Staff, as requested.
       -- Everyone else who was in the guards table stays Guard.
       CASE WHEN id = 13 THEN 'OSA Staff' ELSE 'Guard' END,
       contact_number, gender, birthday, status, email_verified, verify_code, verify_expires, created_at
FROM `guards`;

INSERT INTO `users`
  (id, firstname, middlename, lastname, suffix, fullname, username, email, password,
   role, contact_number, gender, birthday, status, email_verified, verify_code, verify_expires, created_at)
SELECT id, firstname, middlename, lastname, suffix, fullname, username, email, password,
       'OSA Staff', contact_number, gender, birthday, status, email_verified, verify_code, verify_expires, created_at
FROM `osa_staff`;

INSERT INTO `users`
  (id, firstname, middlename, lastname, suffix, fullname, username, email, password,
   role, contact_number, gender, birthday, status, email_verified, verify_code, verify_expires, created_at)
SELECT id, firstname, middlename, lastname, suffix, fullname, username, email, password,
       'OSA', contact_number, gender, birthday, status, email_verified, verify_code, verify_expires, created_at
FROM `osa_officers`;

INSERT INTO `users`
  (id, firstname, middlename, lastname, suffix, fullname, username, email, password,
   role, contact_number, gender, birthday, status, email_verified, verify_code, verify_expires, created_at)
SELECT id, firstname, middlename, lastname, suffix, fullname, username, email, password,
       'Admin', contact_number, gender, birthday, status, email_verified, verify_code, verify_expires, created_at
FROM `admins`;

-- Keep future auto-generated ids clear of the ones just restored.
ALTER TABLE `users` AUTO_INCREMENT = 100;

-- ---- 4. Drop the old per-role physical tables and their id anchor ----
DROP TABLE IF EXISTS `students`;
DROP TABLE IF EXISTS `guards`;
DROP TABLE IF EXISTS `osa_staff`;
DROP TABLE IF EXISTS `osa_officers`;
DROP TABLE IF EXISTS `admins`;
DROP TABLE IF EXISTS `people_registry`;

-- ---- 5. Recreate them as READ-ONLY views for easy browsing/lookup ----
-- (course-breakdown views like bsit_students / bscs_students / etc. already
-- exist and simply query the `students` view below by name -- nothing else
-- to change there, they pick up the fix automatically.)
CREATE OR REPLACE VIEW `students` AS
  SELECT u.id, u.student_id, u.fullname, u.username, u.email, u.contact_number,
         u.gender, c.college_name, u.course, u.year_level, u.section,
         u.status, u.email_verified, u.created_at
  FROM `users` u
  LEFT JOIN `colleges` c ON u.college_id = c.id
  WHERE u.role = 'Student';

CREATE OR REPLACE VIEW `osa_officers` AS
  SELECT u.id, u.fullname, u.username, u.email, u.status, u.created_at
  FROM `users` u WHERE u.role = 'OSA';

CREATE OR REPLACE VIEW `osa_staff` AS
  SELECT u.id, u.fullname, u.username, u.email, u.status, u.created_at
  FROM `users` u WHERE u.role = 'OSA Staff';

CREATE OR REPLACE VIEW `guards` AS
  SELECT u.id, u.fullname, u.username, u.email, u.status, u.created_at
  FROM `users` u WHERE u.role = 'Guard';

CREATE OR REPLACE VIEW `admins` AS
  SELECT u.id, u.fullname, u.username, u.email, u.status, u.created_at
  FROM `users` u WHERE u.role = 'Admin';

-- ---- 6. Add the foreign keys the app's own schema always called for
--         (checked beforehand: no orphaned rows exist in violations /
--         scan_logs / notifications, so these are safe to add) ----
ALTER TABLE `violations`
  ADD CONSTRAINT `fk_violations_student`  FOREIGN KEY (`student_id`)  REFERENCES `users`(`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_violations_reporter` FOREIGN KEY (`reported_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;

ALTER TABLE `scan_logs`
  ADD CONSTRAINT `fk_scanlogs_student` FOREIGN KEY (`student_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_scanlogs_guard`   FOREIGN KEY (`scanned_by`) REFERENCES `users`(`id`) ON DELETE CASCADE;

ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- 7. Sanity check -- run this next and compare against the totals below
-- ---------------------------------------------------------------------
SELECT role, COUNT(*) AS total FROM `users` GROUP BY role;
-- Expected from your current data: Student 15, Guard 2, OSA Staff 1 (this
-- now includes the renamed Head Marshal account), OSA 1, Admin 1 = 20 total.

-- Confirm the rename specifically:
SELECT id, username, fullname, role FROM `users` WHERE username = 'headmarshal';
-- Expected: role = 'OSA Staff'
