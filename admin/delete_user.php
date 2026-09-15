<?php
/* Admin: delete a user account. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";
vts_ensure_role_enum($conn);

// Admin Only
if (!in_array($_SESSION['role'] ?? '', ['Admin', 'OSA'], true)) {
    vts_deny_access();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    header("Location: users.php?error=" . urlencode("Session expired. Please try again."));
    exit();
}

// Validate ID
if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
    header("Location: users.php?error=Invalid user ID.");
    exit();
}

$id = (int)$_POST['id'];

// Prevent deleting yourself
if ($id == $_SESSION['user_id']) {
    header("Location: users.php?error=You cannot delete your own account.");
    exit();
}

try {

    // Check if account exists
    $check = $conn->prepare("
        SELECT id, fullname, role
        FROM users
        WHERE id = :id
    ");

    $check->execute([
        ':id' => $id
    ]);
    $user = $check->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        header("Location: users.php?error=User not found.");
        exit();
    }

    // Prevent deleting student accounts here
    if ($user['role'] == "Student") {
        header("Location: users.php?error=Student accounts must be deleted in Student Management.");
        exit();
    }

    // OSA is admin-level but can't delete Admin accounts.
    if ($user['role'] === 'Admin' && ($_SESSION['role'] ?? '') === 'OSA') {
        header("Location: users.php?error=" . urlencode("OSA accounts can't delete Admin accounts. Ask an Admin."));
        exit();
    }

    // Clean dependent records explicitly for older databases whose foreign keys
    // may not have been imported with the current ON DELETE actions.
    $conn->beginTransaction();
    $tableExists = static function ($table) use ($conn) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table");
        $stmt->execute([':table' => $table]);
        return (bool)$stmt->fetchColumn();
    };
    $columnExists = static function ($table, $column) use ($conn) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column");
        $stmt->execute([':table' => $table, ':column' => $column]);
        return (bool)$stmt->fetchColumn();
    };
    if ($tableExists('notifications')) {
        $conn->prepare("DELETE FROM notifications WHERE user_id = :id")->execute([':id' => $id]);
    }
    if ($tableExists('violations') && $columnExists('violations', 'reported_by')) {
        $conn->prepare("UPDATE violations SET reported_by = NULL WHERE reported_by = :id")->execute([':id' => $id]);
    }
    if ($tableExists('scan_logs')) {
        $conn->prepare("DELETE FROM scan_logs WHERE scanned_by = :id")->execute([':id' => $id]);
    }
    if ($tableExists('marshal_reports')) {
        $conn->prepare("DELETE FROM marshal_reports WHERE guard_id = :id OR sent_by = :id2")
             ->execute([':id' => $id, ':id2' => $id]);
    }

    $delete = $conn->prepare("
        DELETE FROM users
        WHERE id = :id
    ");

    $delete->execute([
        ':id' => $id
    ]);

    if ($delete->rowCount() !== 1) {
        throw new RuntimeException('User was not deleted.');
    }
    $conn->commit();
    audit_log($conn, "Delete User", "users", $id, "Deleted {$user['role']} account");

    header("Location: users.php?success=User deleted successfully.");
    exit();

} catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log('Admin delete user failed: ' . $e->getMessage());
    header("Location: users.php?error=" . urlencode('The account could not be deleted. Please try again.'));
    exit();

}
?>
