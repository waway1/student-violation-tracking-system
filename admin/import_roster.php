<?php
/* Legacy roster import/export -- now redirects to the consolidated students.php. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (!in_array($_SESSION['role'] ?? '', ['Admin', 'OSA'], true)) {
    vts_deny_access();
}

// Redirect to the consolidated students page with roster tab
header("Location: students.php?tab=roster");
exit();
?>