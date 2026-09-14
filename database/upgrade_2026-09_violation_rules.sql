-- =====================================================================
--  Automated violation rules  —  2026-09
-- =====================================================================
--  WHAT THIS ADDS
--
--  violation_types already carried `severity` and `max_points` per type,
--  and the recording paths already read them — so severity and points
--  were, in effect, already automated. Two things were missing.
--
--  1. NOBODY COULD CHANGE THEM. There was no screen for violation_types
--     anywhere in the app. The only way to alter what an offense was
--     worth was an UPDATE run by hand against the database.
--     admin/violation_rules.php is that screen.
--
--  2. NO TYPE COULD ESCALATE ON REPETITION. A type was Minor or Major
--     forever. There was no way to express "the third time someone is
--     caught without an ID, treat it as Major" — the exact rule an office
--     actually wants. `escalate_after` is that number.
--
--        0  never escalate (the default, and what every existing row
--           gets, so applying this changes no behaviour on its own)
--        N  the Nth offense OF THIS TYPE is filed as Major
--
--     The count is per type and ignores records that were cleared or
--     struck on proof review — a record that no longer counts toward the
--     offense ladder must not push the next one to Major either.
--
--  WHERE IT IS APPLIED
--
--  In record_violation() (includes/functions.php), not in the callers.
--  There are five routes in — manual entry, OSA Staff, the gate scanner,
--  the offline import, and the API — and putting the rule in the one
--  function they all pass through is what makes the same offense worth
--  the same thing however it was filed. It runs before the one-Major
--  backstop, so an escalated Major is subject to that rule exactly like a
--  configured one.
--
--  A BUG FIXED ALONGSIDE IT
--
--  admin/add_violation.php looked up `severity` but not `max_points`, so
--  record_violation() fell back to its default of 1. Every other route
--  passed it. The same violation therefore scored different points
--  depending on who filed it. That page now reads both.
--
--  Running this is OPTIONAL: vts_ensure_rule_columns() adds the same
--  column on first use. This file exists so a DBA can apply it once,
--  deliberately, rather than relying on the application holding ALTER
--  privileges in production.
-- =====================================================================

ALTER TABLE `violation_types`
  ADD COLUMN IF NOT EXISTS `escalate_after` INT UNSIGNED NOT NULL DEFAULT 0
    COMMENT '0 = never; N = the Nth offense of this type is filed as Major'
    AFTER `max_points`;

-- Existing rows keep the current behaviour explicitly.
UPDATE `violation_types` SET `escalate_after` = 0 WHERE `escalate_after` IS NULL;

-- A type that is already Major cannot also escalate TO Major. The admin
-- screen enforces this on save; this keeps any hand-edited data honest.
UPDATE `violation_types` SET `escalate_after` = 0 WHERE `severity` = 'Major';
