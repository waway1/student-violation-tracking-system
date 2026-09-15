-- =====================================================================
--  Password-reset codes get their own columns  —  2026-09
-- =====================================================================
--  THE BUG THIS FIXES
--
--  forgot_password.php wrote users.verify_code. So does verify.php, which
--  uses it for something entirely different: confirming the email address on
--  a NEW registration. One column, two unrelated jobs, and nothing recording
--  which of the two put a value there.
--
--  The result was silent and confusing. Ask for a password reset while an
--  email verification is outstanding and the verification code is destroyed;
--  verify an email while a reset is outstanding and the reset code is
--  destroyed. Whichever was requested last worked, the other simply stopped
--  matching, and the person was told their code was "incorrect" when it was
--  the one they had been sent.
--
--  Different questions get different columns.
--
--  WHY HASHED. verify_code is stored in the clear. Anyone able to read the
--  users table — a backup, an export, an injection anywhere else in the app —
--  could read live reset codes and take over any account. Only the hash is
--  kept here, so a leaked table hands over nothing that still works.
--  VARCHAR(255) is what password_hash() asks for: bcrypt is 60 characters
--  today, but the column has to survive PHP's default algorithm changing.
--
--  Running this is OPTIONAL: vts_ensure_reset_columns() in
--  includes/functions.php adds the same columns on first use. This file is
--  here so a DBA can apply it once, deliberately, rather than relying on the
--  application holding ALTER privileges in production.
-- =====================================================================

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `reset_code_hash` VARCHAR(255) NULL
    COMMENT 'bcrypt of the live password-reset code; NULL when none is outstanding'
    AFTER `verify_expires`,
  ADD COLUMN IF NOT EXISTS `reset_expires`   DATETIME NULL
    COMMENT 'when that reset code stops being accepted'
    AFTER `reset_code_hash`;

-- Reset codes are short-lived and single-use, so nothing outstanding at
-- upgrade time needs to survive it.
UPDATE `users` SET `reset_code_hash` = NULL, `reset_expires` = NULL;

-- ---------------------------------------------------------------------
--  verify_code / verify_expires are deliberately LEFT ALONE. They still
--  belong to verify.php (new-registration email confirmation) and are now
--  used by nothing else, which is the entire point of this change.
-- =====================================================================
