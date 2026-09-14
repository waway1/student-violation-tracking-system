-- =====================================================================
--  Point violations.student_id at `users`  —  2026-09
-- =====================================================================
--  THE BUG THIS FIXES
--
--  violations.student_id holds a `users`.id. Every query in the app says
--  so — admin/violations.php, osa_staff/violations.php, the reports and
--  the exports all read:
--
--      INNER JOIN users u ON v.student_id = u.id
--
--  But the foreign key pointed at `students`, a table from the
--  pre-consolidation schema, back when each role had its own table. The
--  application has not written to `students` in a long time; nothing in
--  the codebase contains an INSERT or UPDATE against it.
--
--  So the constraint only permitted a violation against a student who
--  happened to predate the consolidation. Measured on the live database
--  before this was applied:
--
--      active Students in `users`            17
--      rows in legacy `students`             15
--      students that could NOT be cited       3
--
--  Recording a violation for one of those three failed outright:
--
--      Cannot add or update a child row: a foreign key constraint fails
--      (`svts`.`violations`, CONSTRAINT `fk_violations_student`
--       FOREIGN KEY (`student_id`) REFERENCES `students` (`id`))
--
--  This is not the silent kind of failure that the notifications key had
--  (upgrade_2026-09_fk_users.sql) — the scan or the manual entry stops
--  with an error and the violation is simply not recorded. For the newest
--  students on the roster, the core function of the system did not work.
--
--  vts_ensure_person_registered() does NOT cover this. It back-fills
--  `people_registry`, which is what the OTHER legacy keys referenced; it
--  has never touched `students`.
--
--  SAFE TO RUN: verified beforehand that no violations row references a
--  student_id missing from `users` (0 orphans), so nothing is orphaned by
--  the switch.
--
--  ON DELETE CASCADE IS PRESERVED, exactly as it was. Note what that has
--  always meant and still means: deleting a student account deletes their
--  violation history with it. Whether disciplinary records should instead
--  outlive the account is a policy question, not a schema bug, and it is
--  deliberately not changed here.
--
--  `students` and the other legacy per-role tables are left alone.
--  Nothing reads or writes them; dropping them is a separate decision
--  from fixing what the live tables point at.
-- =====================================================================

ALTER TABLE `violations` DROP FOREIGN KEY `fk_violations_student`;

ALTER TABLE `violations`
  ADD CONSTRAINT `fk_violations_student`
  FOREIGN KEY (`student_id`) REFERENCES `users` (`id`)
  ON DELETE CASCADE;
