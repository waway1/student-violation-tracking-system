-- =====================================================================
--  GWC Violation Tracking System — VIOLATION TYPES (curated seed)
--  Golden West Colleges · CITE Department
--  Run this in phpMyAdmin (InfinityFree) on your VTS database.
-- ---------------------------------------------------------------------
--  Safe to re-run: it clears violation_types and re-inserts the full
--  list. Past records in `violations` are NOT affected (they store the
--  violation NAME, not a foreign key to this table).
--  Points: Minor = 1, Major = 2, Grave = 3
--  (the scanner multiplies points by the offense number, capped at x3)
-- =====================================================================

DELETE FROM violation_types;
ALTER TABLE violation_types AUTO_INCREMENT = 1;

INSERT INTO violation_types (violation_name, severity, max_points) VALUES
-- ---------------- MINOR (1 pt) : dress code, grooming, decorum ----------------
  ('No School ID',                         'Minor', 1),
  ('Improper Uniform',                     'Minor', 1),
  ('Improper Haircut',                     'Minor', 1),
  ('Improper Footwear (Slippers)',         'Minor', 1),
  ('No ID Lace / Lanyard',                 'Minor', 1),
  ('Non-Neutral Nail Color',               'Minor', 1),
  ('Untrimmed Nails',                      'Minor', 1),
  ('Wearing Cap/Hat Indoors',              'Minor', 1),
  ('Late to Class / Tardiness',            'Minor', 1),
  ('Loitering During Class Hours',         'Minor', 1),
  ('Littering',                            'Minor', 1),
  ('Eating Inside the Computer Laboratory','Minor', 1),
  ('Unauthorized Use of Phone in Class',   'Minor', 1),
  ('Sleeping During Class',                'Minor', 1),

-- ---------------- MAJOR (2 pts) : conduct, discipline, IT misuse ----------------
  ('Multiple Earrings',                    'Major', 2),
  ('Prohibited Accessories / Piercings',   'Major', 2),
  ('Disrespect to Authority / Faculty',    'Major', 2),
  ('Cutting Classes',                      'Major', 2),
  ('Public Display of Affection (PDA)',    'Major', 2),
  ('Smoking / Vaping on Campus',           'Major', 2),
  ('Gambling on Campus',                   'Major', 2),
  ('Rowdy / Disruptive Behavior',          'Major', 2),
  ('Insubordination',                      'Major', 2),
  ('Unauthorized Entry to Restricted Area','Major', 2),
  ('Misuse of Computer Lab Equipment',     'Major', 2),
  ('Installing Unauthorized Software',     'Major', 2),
  ('Using Another Student''s Account',     'Major', 2),
  ('Accessing Blocked / Inappropriate Sites','Major', 2),

-- ---------------- GRAVE (3 pts) : serious offenses ----------------
  ('Vandalism',                            'Grave', 3),
  ('Cheating / Academic Dishonesty',       'Grave', 3),
  ('Plagiarism',                           'Grave', 3),
  ('Theft / Stealing',                     'Grave', 3),
  ('Physical Assault / Fighting',          'Grave', 3),
  ('Bullying / Harassment',                'Grave', 3),
  ('Cyberbullying',                        'Grave', 3),
  ('Hacking / Unauthorized System Access', 'Grave', 3),
  ('Data Theft / Privacy Violation',       'Grave', 3),
  ('Forgery / Falsification of Documents', 'Grave', 3),
  ('Possession of Alcohol / Intoxication', 'Grave', 3),
  ('Possession / Use of Prohibited Drugs', 'Grave', 3),
  ('Possession of Deadly Weapon',          'Grave', 3),
  ('Threats / Intimidation',               'Grave', 3),
  ('Gross Misconduct / Immoral Conduct',   'Grave', 3),
  ('Others',                               'Minor', 1);
