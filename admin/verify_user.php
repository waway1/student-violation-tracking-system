<?php
/* Manually mark a staff account's email as verified — admin fallback for when
   the account's email couldn't be confirmed another way. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (!in_array($_SESSION['role'] ?? '', ['Admin', 'OSA'], true)) {
    vts_deny_access();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    header("Location: users.php?error=" . urlencode("Session expired. Please try again."));
    exit();
}

$id = (int)($_POST['id'] ?? 0);
if ($id > 0) {
    $stmt = $conn->prepare("UPDATE users
                            SET email_verified = 1, verify_code = NULL, verify_expires = NULL
                            WHERE id = :id AND role <> 'Student'");
    $stmt->execute([':id' => $id]);
    header("Location: users.php?success=" . urlencode("Account marked as verified."));
} else {
    header("Location: users.php?error=" . urlencode("Invalid user."));
}
exit();
