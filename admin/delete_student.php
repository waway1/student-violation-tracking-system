<?php
/* Admin: delete a student record. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

// Allow Admin only
/* REMOVING A STUDENT IS AN ADMIN ACTION.

   It was Admin or OSA. Deleting a student takes their violations with it
   (fk_violations_student cascades), so it is the most destructive control
   in the app, and it now sits with the one role answerable for the roster.
   OSA and OSA Staff view; they do not remove. */
if (($_SESSION['role'] ?? '') !== 'Admin') {
    vts_deny_access("Only an Admin can remove a student record.");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    header("Location: " . basename($_SERVER['HTTP_REFERER'] ?? 'dashboard.php'));
    exit();
}

// Validate ID
if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
    header("Location: students.php?error=Invalid student ID.");
    exit();
}

$id = (int) $_POST['id'];

// Purge the student EVERYWHERE (account + violations + roster) so they can no
// longer be searched or logged in. See vts_purge_student().
[$ok, $msg] = vts_purge_student($conn, $id);
header("Location: students.php?" . ($ok ? "success=" : "error=") . urlencode($msg));
exit();
?>
