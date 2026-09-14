<?php
/* OSA Staff: download the SECURE students-list file (.vtsl) the offline scanner imports.
   Same encrypted format as admin/export_students.php (see vts_export_scanner_file):
   students + Guard/OSA/Admin accounts, AES-GCM encrypted so a Marshall can't open it. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (($_SESSION['role'] ?? '') !== "OSA Staff") {
    vts_deny_access();
}

vts_export_scanner_file($conn, ($_SESSION['fullname'] ?? 'OSA') . ' (OSA)');
