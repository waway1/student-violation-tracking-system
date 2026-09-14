<?php
/* Admin / OSA: delete a violation record.

   WHO MAY DELETE WHAT
   Both roles reach this file; they do not reach the same records through
   it. A Major is normally locked until the conference with the student is
   on record, and for OSA it still is. Admin is not gated: Admin holds full
   CRUD over this system — student records, staff accounts, the audit log —
   and a violation is not the one thing carved out of that.

   The rule itself lives in vts_can_delete_violation(), next to the gate it
   overrides, so the two cannot drift apart. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

// Admin / OSA only
if (!in_array($_SESSION['role'] ?? '', ['Admin', 'OSA'], true)) {
    vts_deny_access();
}
$vtsRole = (string)($_SESSION['role'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    vts_redirect_back('violations.php', 'error', 'Session expired. Please try again.');
}

// Validate ID
if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
    vts_redirect_back('violations.php', 'error', 'Invalid violation ID.');
}

$id = (int)$_POST['id'];

try {

    // Check if violation exists
    $check = $conn->prepare("
        SELECT id, student_id, severity, discussed_at, evidence
        FROM violations
        WHERE id = :id
    ");

    $check->execute([
        ':id' => $id
    ]);

    $violation = $check->fetch(PDO::FETCH_ASSOC);

    if (!$violation) {
        vts_redirect_back('violations.php', 'error', 'Violation not found.');
    }

    /* A Major cannot be deleted until the conference with the student is on
       record — unless an Admin is asking. Deleting is the most complete way
       to make an offense vanish: unlike a proof rejection it leaves nothing
       behind at all, so for OSA it is the last place that should take
       anyone's word for it. Record the conference on Violation Proof.
       Admin has full CRUD and is not held here. */
    [$mayDelete, $whyNot] = vts_can_delete_violation($conn, $id, $vtsRole);
    if (!$mayDelete) {
        vts_redirect_back('violations.php', 'error', $whyNot);
    }

    /* An Admin deleting an undiscussed Major is exactly the case the gate
       exists to catch, so it is named in the audit row rather than logged as
       an ordinary delete. The power is real; it should not be quiet. */
    $sev     = (string)($violation['severity'] ?? 'Minor');
    $bypass  = ($sev === 'Major' || $sev === 'Grave') && empty($violation['discussed_at']);
    $details = $bypass
        ? 'Admin deleted a ' . $sev . ' offense with no conference on record'
        : null;

    /* Logged here, not before the existence check: the old placement wrote a
       "Delete Violation" entry for an id that was never found and, now, for
       one the rule above refuses — an audit trail claiming deletions that
       never happened is worse than none. */
    audit_log($conn, "Delete Violation", "violations", $id, $details);

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

    /* The proof photo goes with the record.

       Deleting is the action that leaves nothing behind, and a photo of a
       student committing an offense that officially never happened is the
       worst thing to leave lying in a public uploads folder. Every other
       place that replaces a stored file here unlinks the old one; this was
       the one that did not, and the Violation Proof page — which now offers
       this delete — is where that shows.

       Three guards, because this deletes a file from a name that came out of
       the database:
         - basename(), so a stored "../../config/db_credentials.php" can only
           ever resolve inside the evidence folder
         - realpath() checked to still be under that folder afterwards
         - no other violation row pointing at the same file (an import can
           land two records on one photo; one of them going must not blind
           the other)
       @ on the unlink: a file already gone, or held open by another process,
       must not turn a completed delete into an error page. */
    $evidence = trim((string)($violation['evidence'] ?? ''));
    if ($evidence !== '') {
        $still = $conn->prepare("SELECT COUNT(*) FROM violations WHERE evidence = :e");
        $still->execute([':e' => $evidence]);
        if ((int)$still->fetchColumn() === 0) {
            $dir  = realpath(__DIR__ . '/../uploads/evidence');
            $file = $dir ? realpath($dir . DIRECTORY_SEPARATOR . basename($evidence)) : false;
            if ($file && str_starts_with($file, $dir . DIRECTORY_SEPARATOR) && is_file($file)) {
                @unlink($file);
            }
        }
    }

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
        ':message' => 'One of your violation records has been removed by the administrator.'
    ]);

    // Back to the exact page the delete was fired from — the Records &
    // Actions tab with its filters intact, not the default Official Sheet.
    vts_redirect_back('violations.php', 'success', 'Violation deleted successfully.');

} catch (PDOException $e) {
    error_log('Admin delete violation failed: ' . $e->getMessage());
    vts_redirect_back('violations.php', 'error', 'The violation could not be deleted. Please try again.');

}
?>
