<?php
/* Admin: download the SECURE students-list file (.vtsl) the offline scanner imports.
   It's AES-GCM encrypted (see vts_export_scanner_file) so it can't be opened in Excel
   by a Marshall — it carries active students + Guard/OSA/Admin accounts (bcrypt hashes)
   so the scanner can verify logins and unlock offline. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (!in_array($_SESSION['role'] ?? '', ['Admin', 'OSA'], true)) {
    vts_deny_access();
}

vts_export_scanner_file($conn, ($_SESSION['fullname'] ?? 'Admin') . ' (Admin)');
