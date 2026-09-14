<?php
/* =====================================================================
   GOOGLE DRIVE — folder links only.

   This file used to upload exports into Drive by itself, signing in as a
   service account. That cannot work on this school's plan: Google gives a
   service account NO Drive storage of its own, so it may read a folder
   shared with it but can never own a file inside one, and every upload
   came back "Service Accounts do not have storage quota". Getting past
   that needs a Google Workspace subscription (for a Shared Drive, which
   owns its own files) or an OAuth sign-in as the folder owner.

   Rather than keep a button that could only ever report failure, the
   upload was removed. What is left is what always worked and costs
   nothing: the https://drive.google.com/… address of each folder, so the
   Export buttons can open the right one and the file is dragged in.

   If a Workspace subscription is ever bought, the uploader is in git
   history (and in the file's earlier revisions) — the folder ids and the
   department mapping in config/drive.php are unchanged and still correct.

   vts_drive_folder_for($kind)   folder id for reports|violations|students|marshal|backups
   vts_drive_folder_url($kind)   the browsable address of that folder
   vts_drive_dept_folder(...)    the folder belonging to ONE department
   ===================================================================== */

if (!defined('DRIVE_ENABLED')) {
    $__vtsDriveCfg = __DIR__ . '/../config/drive.php';
    if (is_file($__vtsDriveCfg)) require_once $__vtsDriveCfg;
}

/* Folder id for a kind of export: reports | violations | students | marshal | backups */
function vts_drive_folder_for($kind) {
    switch ($kind) {
        case 'reports':    return defined('DRIVE_FOLDER_REPORTS')    ? DRIVE_FOLDER_REPORTS    : '';
        case 'violations': return defined('DRIVE_FOLDER_VIOLATIONS') ? DRIVE_FOLDER_VIOLATIONS : '';
        case 'students':   return defined('DRIVE_FOLDER_STUDENTS')   ? DRIVE_FOLDER_STUDENTS   : '';
        case 'marshal':    return defined('DRIVE_FOLDER_MARSHAL')    ? DRIVE_FOLDER_MARSHAL    : '';
        case 'backups':    return defined('DRIVE_FOLDER_BACKUPS')    ? DRIVE_FOLDER_BACKUPS    : '';
    }
    return '';
}

/* The folder belonging to ONE department, or '' when we don't know of one.

   $course accepts a course code (BSIT, BSCRIM, ...) and $collegeId the
   numeric department id; either is enough. Course is tried first because
   it is the more specific of the two, then the department it belongs to. */
function vts_drive_dept_folder($course = '', $collegeId = '') {
    if (!defined('DRIVE_DEPT_FOLDERS') || !is_array(DRIVE_DEPT_FOLDERS)) return '';
    $map = DRIVE_DEPT_FOLDERS;

    $course = strtoupper(trim((string)$course));
    if ($course !== '' && isset($map[$course])) return $map[$course];

    $collegeId = trim((string)$collegeId);
    if ($collegeId !== '' && isset($map['college:' . $collegeId])) return $map['college:' . $collegeId];

    return '';
}

/* The browsable https://drive.google.com/… URL for that folder — the
   "open the folder and drag the file in" flow (no Google sign-in needed). */
function vts_drive_folder_url($kind) {
    $id = vts_drive_folder_for($kind);
    return $id !== '' ? 'https://drive.google.com/drive/folders/' . $id
                      : 'https://drive.google.com/drive/my-drive';
}
