-- 2026-07-22: add the Head Marshall staff role (guard-type: scanner + guard pages).
-- Run once on an existing database. New installs already get it from
-- student_violation_system.sql. (add_user.php also applies this
-- automatically the first time an Admin opens it.)

ALTER TABLE users
  MODIFY role ENUM('Student','Guard','Head Marshall','OSA','Dean','Admin')
  NOT NULL DEFAULT 'Student';
