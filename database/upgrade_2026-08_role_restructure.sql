-- =====================================================================
-- VTS UPGRADE — 2026-08  (Role restructure)
-- Run in phpMyAdmin AFTER every earlier upgrade script. Safe to re-run.
--
-- WHAT THIS DOES
--   • Removes the "Head Marshal" role — its duties (scan + send the day's
--     report) are absorbed straight into Guard/Marshal. There is no more
--     consolidation hop; Guard/Marshal sends directly to OSA/Admin.
--   • Removes the "Dean" role entirely — no Dean function in the system
--     anymore. Any existing Dean accounts are DEACTIVATED (not deleted) so
--     the person + their audit history are preserved; re-assign them to a
--     new role manually in Admin > Manage Users if they still need access.
--   • Adds "OSA Staff" — a lighter tier under OSA: can add/edit/view
--     students & violations and run reports/exports, but cannot delete
--     records and has no access to user management, settings, backup, or
--     the audit log.
--   • OSA becomes admin-level: OSA accounts now use the same pages as
--     Admin (minus creating/editing/deleting Admin-role accounts).
--
-- FINAL ROLE LIST: Student, Guard (displayed as "Guard/Marshal"),
--                   OSA Staff, OSA, Admin
-- =====================================================================

-- 1) Move existing Head Marshal accounts onto Guard/Marshal — they keep
--    signing in with the same username/password, just under the merged role.
UPDATE users SET role = 'Guard' WHERE role = 'Head Marshal';

-- 2) Deactivate existing Dean accounts (no Dean function remains). Data and
--    login history are kept; the account just can't sign in until an Admin
--    or OSA reassigns it to a real role and reactivates it.
UPDATE users SET status = 'Inactive' WHERE role = 'Dean';

-- 3) Widen the enum first so step 4's UPDATE has somewhere to put 'Dean'
--    rows before they're renamed off it, then apply the final role list.
ALTER TABLE users
  MODIFY COLUMN role ENUM('Student','Guard','Head Marshal','OSA Staff','OSA','Dean','Admin')
  NOT NULL DEFAULT 'Student';

-- 4) Any leftover Dean rows (safety net — should be none after step 2's
--    UPDATE, but this keeps the final ALTER from truncating anything odd)
--    get parked on OSA Staff, deactivated, so they're never silently lost.
UPDATE users SET role = 'OSA Staff', status = 'Inactive' WHERE role = 'Dean';
UPDATE users SET role = 'Guard' WHERE role = 'Head Marshal';

-- 5) Final enum — Head Marshal and Dean are gone for good.
ALTER TABLE users
  MODIFY COLUMN role ENUM('Student','Guard','OSA Staff','OSA','Admin')
  NOT NULL DEFAULT 'Student';

-- 6) marshal_reports (the old consolidation ledger) is no longer written to
--    — Guard/Marshal now sends straight to OSA/Admin — but we keep the
--    table so past history isn't lost. Safe to drop yourself later if you
--    don't need it:
--      DROP TABLE IF EXISTS marshal_reports;

-- 7) (Optional) rename the old demo Head Marshal account instead of losing
--    it, so it still works as a Guard/Marshal login after this runs.
UPDATE users
   SET fullname = 'Guard/Marshal', username = 'guardmarshal'
 WHERE username = 'headmarshal' AND role = 'Guard';
