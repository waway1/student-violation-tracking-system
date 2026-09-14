<?php
/* OSA Staff: delete a violation record. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

// OSA Staff cannot delete records — that ability is reserved for OSA/Admin.
// (kept as a hard block here in case a stale bookmark/old link is used.)
if (true) {
    header("Location: violations.php?error=" . urlencode("OSA Staff accounts can't delete records. Ask OSA or Admin."));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    header("Location: violations.php?error=" . urlencode("Session expired. Please try again."));
    exit();
}

// Validate ID
if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
    header("Location: violations.php?error=Invalid violation ID.");
    exit();
}

$id = (int)$_POST['id'];

try {

    // Check if violation exists
    $check = $conn->prepare("
        SELECT id, student_id
        FROM violations
        WHERE id = :id
    ");

    $check->execute([
        ':id' => $id
    ]);
    audit_log($conn, "Delete Violation", "violations", $id ?? null);

    $violation = $check->fetch(PDO::FETCH_ASSOC);

    if (!$violation) {
        header("Location: violations.php?error=Violation not found.");
        exit();
    }

    // Delete violation
    $delete = $conn->prepare("
        DELETE FROM violations
        WHERE id = :id
    ");

    $delete->execute([
        ':id' => $id
    ]);

    // Removing a record shifts every LATER violation up the ladder, so the
    // stored offense numbers must be re-derived — otherwise the student keeps
    // a stale "Third Offense" that no longer matches their history.
    vts_renumber_offenses($conn, (int)$violation['student_id']);

    // Optional notification
    $notify = $conn->prepare("
        INSERT INTO notifications
        (
            user_id,
            message
        )
        VALUES
        (
            :user,
            :message
        )
    ");

    $notify->execute([
        ':user' => $violation['student_id'],
        ':message' => 'One of your violation records has been removed by OSA.'
    ]);

    header("Location: violations.php?success=Violation deleted successfully.");
    exit();

} catch (PDOException $e) {
    error_log('OSA delete violation failed: ' . $e->getMessage());
    header("Location: violations.php?error=" . urlencode('The violation could not be deleted. Please try again.'));
    exit();

}
?>
