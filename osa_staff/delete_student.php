<?php
/* OSA Staff: delete a student record. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

// OSA Staff cannot delete records — that ability is reserved for OSA/Admin.
// (kept as a hard block here in case a stale bookmark/old link is used.)
if (true) {
    header("Location: students.php?error=" . urlencode("OSA Staff accounts can't delete records. Ask OSA or Admin."));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    header("Location: students.php?error=" . urlencode("Session expired. Please try again."));
    exit();
}

// Validate ID
if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
    header("Location: students.php?error=Invalid student ID.");
    exit();
}

$id = (int) $_POST['id'];

// Same permanent purge as Admin (account + violations + roster) so a deleted
// student can no longer be searched or logged in. See vts_purge_student().
[$ok, $msg] = vts_purge_student($conn, $id);
header("Location: students.php?" . ($ok ? "success=" : "error=") . urlencode($msg));
exit();
?>
