<?php
/* OSA Staff: edit a single roster/student entry. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (($_SESSION['role'] ?? '') !== "OSA Staff") {
    vts_deny_access();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: students.php?tab=roster&error=Invalid roster entry.");
    exit();
}
$id = (int)$_GET['id'];

$stmt = $conn->prepare("SELECT * FROM student_roster WHERE id = :id");
$stmt->execute([':id' => $id]);
$entry = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$entry) { header("Location: students.php?tab=roster&error=Roster entry not found."); exit(); }

$error = "";

/* Only the School ID is editable here. Correcting it also updates the
   registered student account that used the old ID (users.student_id),
   so the change truly lands in the database everywhere — violations,
   scans, and reports keep working because they join on users.id. */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_verify()) { vts_csrf_fail('save that roster change'); }
    $oldId     = $entry['school_id'];
    $school_id = strtoupper(trim($_POST['school_id'] ?? ''));

    if ($school_id === '') {
        $error = "School ID is required.";
    } elseif (strlen($school_id) > 10) {
        $error = "School ID must be 10 characters or fewer.";
    } elseif ($school_id === $oldId) {
        header("Location: students.php?tab=roster&success=" . urlencode("No change — the School ID is the same."));
        exit();
    } else {
        $c = $conn->prepare("SELECT id FROM student_roster WHERE school_id = :v AND id != :id");
        $c->execute([':v' => $school_id, ':id' => $id]);
        $u = $conn->prepare("SELECT id FROM users WHERE student_id = :v");
        $u->execute([':v' => $school_id]);
        if ($c->fetch()) {
            $error = "School ID \"{$school_id}\" is already used by another roster entry.";
        } elseif ($u->fetch()) {
            $error = "School ID \"{$school_id}\" already belongs to a different registered account.";
        } else {
            try {
                $conn->beginTransaction();
                $conn->prepare("UPDATE student_roster SET school_id = :s WHERE id = :id")
                     ->execute([':s' => $school_id, ':id' => $id]);
                // Cascade to the registered student account (if the ID was already used)
                $cu = $conn->prepare("UPDATE users SET student_id = :new WHERE student_id = :old AND role = 'Student'");
                $cu->execute([':new' => $school_id, ':old' => $oldId]);
                $conn->commit();

                // Old QR encodes the old ID — remove it so a fresh one is
                // generated with the new ID the next time it's needed.
                require_once "../includes/qr_helper.php";
                @unlink(qr_file_path($oldId));

                audit_log($conn, "Edit Roster School ID", "student_roster", $id,
                    "{$oldId} -> {$school_id}" . ($cu->rowCount() ? " (registered account updated too)" : ""));
                header("Location: students.php?tab=roster&success=" . urlencode(
                    "School ID updated to {$school_id}." . ($cu->rowCount() ? " The registered student account was updated as well." : "")));
                exit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) $conn->rollBack();
              error_log('OSA edit roster failed: ' . $e->getMessage());
              $error = "Could not update the School ID. Please try again.";
            }
        }
    }
    $entry['school_id'] = $school_id;
}

$assetBase = "../";
include "../includes/header.php";
include "../includes/navbar.php";
include "../includes/sidebar.php";
?>
<main class="vts-main">
<div class="vts-main-inner">

<div class="admin-welcome-row">
  <div><h1>Edit Student ID</h1><p>Only the School ID is kept on the roster — students fill in their own details when they register.</p></div>
  <a href="students.php?tab=roster" class="btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<?php if ($error): ?>
  <div class="alert alert-error u-mb-14"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="recent-table-wrap table-responsive" style="max-width:640px;">
  <form method="POST">
    <?php echo csrf_field(); ?>
    <div class="form-grid">
      <div class="field"><label for="school_id">School ID <span class="req">*</span></label>
        <input id="school_id" type="text" name="school_id" class="vts-input js-upper" maxlength="10" required autofocus
               value="<?php echo htmlspecialchars($entry['school_id'] ?? ''); ?>">
        <small class="field-hint">Changing this also updates the student's registered account, if they already registered with the old ID.</small>
      </div>
    </div>
    <div class="u-actions u-mt-14">
      <button type="submit" class="btn-primary"><i class="fas fa-floppy-disk"></i> Update Student ID</button>
      <a href="students.php?tab=roster" class="btn-outline">Cancel</a>
    </div>
  </form>
</div>

<?php /* The app shell (.vts-main-inner + <main>) is closed by
         includes/footer.php below -- closing it here as well emitted a
         stray </div></main> on every one of these pages. */ ?>
<?php include "../includes/footer.php"; ?>
