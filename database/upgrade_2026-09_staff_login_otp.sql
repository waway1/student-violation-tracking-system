-- =====================================================================
--  Staff two-step sign-in (login OTP)  —  2026-09
-- =====================================================================
--  Adds the two columns that hold a staff member's pending sign-in code.
--
--  Running this is OPTIONAL: vts_ensure_login_otp_columns() in
--  includes/functions.php adds the same columns on first use, so the
--  feature works on a database nobody has migrated. This file exists for
--  the usual reason the others do — so a DBA can apply the change once,
--  deliberately, instead of relying on the app holding ALTER privileges.
--
--  WHY NOT REUSE verify_code. That column is the *registration* code, and
--  forgot_password.php overwrites it as well. Sharing one column would mean
--  a password reset started in one tab silently invalidates a login code in
--  another, and a login code would satisfy verify.php.
--
--  WHY A HASH, WHEN verify_code IS PLAINTEXT. Anyone who can read the users
--  table — a backup, an export, an injection anywhere else in the system —
--  can read a plaintext code while it is still live. Only the hash is
--  stored here, so a leaked table hands over nothing that still works.
--  VARCHAR(255) because that is what password_hash() asks for: bcrypt is 60
--  characters today, but the column has to survive PHP's default algorithm
--  changing under it.
-- =====================================================================

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `login_otp_hash`    VARCHAR(255) NULL AFTER `verify_expires`,
  ADD COLUMN IF NOT EXISTS `login_otp_expires` DATETIME     NULL AFTER `login_otp_hash`;

-- Codes are single-use and short-lived, so nothing outstanding at upgrade
-- time should survive it.
UPDATE `users` SET `login_otp_hash` = NULL, `login_otp_expires` = NULL;
