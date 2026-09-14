-- =====================================================================
--  Point the live foreign keys at `users`  —  2026-09
-- =====================================================================
--  THE BUG THIS FIXES
--
--  notifications.user_id, violations.reported_by and scan_logs.scanned_by
--  all hold a `users`.id. Their foreign keys pointed at `people_registry`
--  instead — a table from the pre-consolidation schema, back when each role
--  had its own table (students, guards, admins, osa_officers, osa_staff) and
--  people_registry owned the shared id space.
--
--  The application has never written to people_registry. So an account
--  created through the app has a `users` row and no registry row, and any
--  INSERT naming it was rejected by the constraint:
--
--      Cannot add or update a child row: a foreign key constraint fails
--      (`svts`.`notifications`, CONSTRAINT `fk_notif_user` FOREIGN KEY
--       (`user_id`) REFERENCES `people_registry` (`id`))
--
--  For notifications that failure was silent — the insert is wrapped in a
--  try/catch so a failed alert can never break a login — which is why the
--  notifications table stayed empty and no student ever got told about a
--  violation. For violations it was already being worked around in PHP by
--  vts_ensure_person_registered(), which quietly back-fills a registry row
--  before recording. That workaround treats the symptom; this treats the
--  cause, and it can be retired once this has been applied everywhere.
--
--  SAFE TO RUN: verified beforehand that no row in any of the three tables
--  references an id that is missing from `users`, so nothing is orphaned by
--  the switch. The ON DELETE behaviour of each key is preserved exactly.
--
--  people_registry and the legacy per-role tables are left alone. Nothing in
--  the app reads or writes them any more, but dropping them is a separate
--  decision from fixing what the live tables point at.
-- =====================================================================

-- --- notifications.user_id ------------------------------------------
ALTER TABLE `notifications` DROP FOREIGN KEY `fk_notif_user`;
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notif_user`
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- --- violations.reported_by -----------------------------------------
ALTER TABLE `violations` DROP FOREIGN KEY `fk_violations_reporter`;
ALTER TABLE `violations`
  ADD CONSTRAINT `fk_violations_reporter`
  FOREIGN KEY (`reported_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- --- scan_logs.scanned_by -------------------------------------------
ALTER TABLE `scan_logs` DROP FOREIGN KEY `fk_scanlogs_guard`;
ALTER TABLE `scan_logs`
  ADD CONSTRAINT `fk_scanlogs_guard`
  FOREIGN KEY (`scanned_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- ---------------------------------------------------------------------
--  Check it took:
--
--      SELECT TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME
--        FROM information_schema.KEY_COLUMN_USAGE
--       WHERE TABLE_SCHEMA = DATABASE()
--         AND CONSTRAINT_NAME IN ('fk_notif_user','fk_violations_reporter',
--                                 'fk_scanlogs_guard');
--
--  All three should now read REFERENCED_TABLE_NAME = users.
-- =====================================================================
