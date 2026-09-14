<?php
/* Manually mark a student's email as verified — admin fallback for when the
   OTP email couldn't be delivered (e.g. Brevo not configured yet). */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (!in_array($_SESSION['role'] ?? '', ['Admin', 'OSA'], true)) {
    vts_deny_access();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    header("Location: students.php?error=" . urlencode("Session expired. Please try again."));
    exit();
}

$id = (int)($_POST['id'] ?? 0);
if ($id > 0) {
    $stmt = $conn->prepare("UPDATE users
                            SET email_verified = 1, verify_code = NULL, verify_expires = NULL
                            WHERE id = :id AND role = 'Student'");
    $stmt->execute([':id' => $id]);
    header("Location: students.php?success=" . urlencode("Student email marked as verified. They can now log in."));
} else {
    header("Location: students.php?error=" . urlencode("Invalid student."));
}
exit();
