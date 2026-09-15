-- =====================================================================
-- VTS UPGRADE — 2026-08b  (Per-department OSA contact)
-- Run in phpMyAdmin AFTER the 2026-08 role-restructure script.
--
-- WHAT THIS DOES
--   Adds an OPTIONAL separate OSA contact per college/department. Every
--   department defaults to the one general OSA inbox (OSA_REPORT_EMAIL in
--   config/mail.php) — this only matters for a department that has its
--   OWN OSA, like Criminology. Set it once in Admin/OSA > Settings >
--   Department OSA Contacts, and Guard/Marshal's daily report
--   automatically splits: any student from that department's violations
--   go straight to that department's OSA, everyone else still goes to
--   the general OSA inbox as before.
-- =====================================================================

ALTER TABLE colleges
  ADD COLUMN osa_email VARCHAR(190) NULL AFTER short_name,
  ADD COLUMN osa_name  VARCHAR(150) NULL AFTER osa_email;

-- Nothing is set by default — every department still uses the general OSA
-- inbox until you fill one in. Example (uncomment and edit to use):
-- UPDATE colleges SET osa_email = 'crim.osa@gwc.edu.ph', osa_name = 'Criminology OSA'
--   WHERE short_name = 'CCJ';
