-- =====================================================================
--  Student passwords  —  2026-09
-- =====================================================================
--  Students used to sign in with no password at all: registration wrote a
--  throwaway random hash nobody could type, and the lookup page let them in
--  on their School ID and last name. Registration now asks for a real
--  password and login asks for it back.
--
--  This adds the one column that makes the transition possible.
--
--  WHY A COLUMN AND NOT A CHECK ON THE HASH. The throwaway hash and a real
--  one are both bcrypt output — nothing about the stored value says whether
--  its owner knows it. The fact has to be recorded rather than inferred.
--
--  Running this is OPTIONAL: vts_ensure_password_flag() in
--  includes/functions.php creates the same column on first use, so the app
--  works on a database nobody has migrated. This file exists so the change
--  can be applied once, deliberately, by someone who would rather not have
--  the application holding ALTER privileges in production.
-- =====================================================================

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `has_password` TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '1 = the owner chose this password; 0 = pre-password account, may still use the last-name lookup';

-- Staff have always typed a real password to sign in, so they start at 1.
-- Students start at 0 and move to 1 when they register, reset via
-- forgot_password.php, or set one from their profile.
UPDATE `users` SET `has_password` = 1 WHERE `role` <> 'Student';

-- A student who registered AFTER this feature shipped already chose one.
-- Nothing to do for them here; register_process.php writes has_password = 1.

-- ---------------------------------------------------------------------
--  Where to go from here
-- ---------------------------------------------------------------------
--  While config/app.php has STUDENT_PASSWORD_REQUIRED = false, a student
--  with has_password = 0 can still sign in with School ID + last name, and
--  is prompted to set a password. Check who is left with:
--
--      SELECT student_id, fullname, email
--        FROM users
--       WHERE role = 'Student' AND has_password = 0
--       ORDER BY fullname;
--
--  When that returns nothing, set STUDENT_PASSWORD_REQUIRED to true and the
--  last-name route closes for good.
-- =====================================================================
