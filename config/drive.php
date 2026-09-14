<?php
/* =====================================================================
   GOOGLE DRIVE — the folders exported files belong in.

   The app does NOT upload to Drive. It used to try, signing in as a
   service account, but Google gives service accounts no Drive storage of
   their own: one can read a folder shared with it and never own a file
   inside it, so every upload came back "Service Accounts do not have
   storage quota". Getting past that needs a paid Google Workspace plan
   (for a Shared Drive, which owns its own files) or an OAuth sign-in as
   the folder's owner. Neither is in place, so the upload was removed
   rather than left as a button that always failed.

   What these ids are for now: the Export buttons open the right folder in
   a new tab so the downloaded file can be dragged in. That needs no
   account, no key and no subscription.

   They are still worth keeping accurate — if a Workspace plan is ever
   bought, the uploader can come back and these mappings are what it used.
   ===================================================================== */

define('DRIVE_ENABLED', true);                       // master switch

/* Folder IDs (the part of the folder URL after /folders/). */
define('DRIVE_FOLDER_REPORTS',    '1-0hsBstQAv15YuqAnerYDUO3uAh9Pkdx');  // violation report workbooks
define('DRIVE_FOLDER_VIOLATIONS', '1G_wpi4dK-sxtjsUpE2KzGD6EnCBVJfCh');  // violation records
define('DRIVE_FOLDER_STUDENTS',   '1cN8_fnVxZeGYzmnXzSY3mObf33glHkuf');  // student lists, per course
define('DRIVE_FOLDER_MARSHAL',    '1NHC3CLmWvunJ0M1dsEjcrOud20djGIHG');  // Guard/Marshal daily reports
// Where the database/Excel BACKUPS are filed. Separate from Reports on
// purpose: reports are re-exportable any time, a backup is the only copy of
// a moment in time, so it does not get mixed in with routine paperwork.
define('DRIVE_FOLDER_BACKUPS',    '158lpcUl7FbuP9UP_rpYV-9VsaoG5czfE');  // Excel / .sql backups

/* ---------------------------------------------------------------------
   ONE DRIVE FOLDER PER DEPARTMENT.

   When an export covers a single department, it is filed in that
   department's own folder instead of the shared one, so each department
   can be given access to its own records and nothing else.

   The keys accept BOTH the college id and any course code belonging to
   that department, because different exports know different things: the
   violations page filters by college_id, the student export loops by
   course. Courses without a folder of their own (BSCS, BSA, BEED) go to
   their department's folder -- a department has one drive, not a course.

   Inside each department folder the files still separate themselves into
   "Students" and "Violations" sub-folders, so the two kinds never mix. */
define('DRIVE_DEPT_FOLDERS', [
    // Department of Information Technology
    'college:1' => '1XUv90ukRaFz2WlRF70Socbv_1C8evCPG',
    'BSIT'      => '1XUv90ukRaFz2WlRF70Socbv_1C8evCPG',
    'BSCS'      => '1XUv90ukRaFz2WlRF70Socbv_1C8evCPG',

    // Department of Business Administration
    'college:2' => '1WlB4nQeE-bPhhm6Zcl2c8k5qvsF_huIF',
    'BSBA'      => '1WlB4nQeE-bPhhm6Zcl2c8k5qvsF_huIF',
    'BSA'       => '1WlB4nQeE-bPhhm6Zcl2c8k5qvsF_huIF',

    // Department of Education
    'college:3' => '1gr-VGyCQGEuua8V52_uzFBH0bW2pz_v7',
    'BSED'      => '1gr-VGyCQGEuua8V52_uzFBH0bW2pz_v7',
    'BEED'      => '1gr-VGyCQGEuua8V52_uzFBH0bW2pz_v7',

    // Department of Criminology
    'college:4' => '1VJQuCppWMUjWDkHxjpy7DVFPndfR4Dui',
    'BSCRIM'    => '1VJQuCppWMUjWDkHxjpy7DVFPndfR4Dui',
]);

/* Put each file in a sub-folder named after the course it covers
   (BSIT / BSCRIM / … , or "All courses" when no course filter is set). */
define('DRIVE_SPLIT_BY_COURSE', true);
