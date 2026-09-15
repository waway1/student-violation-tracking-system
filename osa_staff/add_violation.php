<?php
/* OSA Staff: form + handler to record a new student violation. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (($_SESSION['role'] ?? '') !== "OSA Staff") {
    vts_deny_access();
}

$error = "";
// What the user already typed, so a failed save re-renders the form
// with their work still in it instead of a blank one.
$old = fn($k) => (string)($_POST[$k] ?? '');

// Students for the dropdown
$students = $conn->query("SELECT id, student_id, fullname FROM users WHERE role='Student' ORDER BY fullname")
                 ->fetchAll(PDO::FETCH_ASSOC);

// Violation types for the dropdown
$types = $conn->query("SELECT violation_name FROM violation_types ORDER BY violation_name")
              ->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_verify()) { vts_csrf_fail('record that violation'); }
    $student   = $_POST['student_id'] ?? '';
    $violation = trim($_POST['violation'] ?? '');
    $status = 'Recorded';
    $desc      = trim($_POST['description'] ?? '');
    $reporter  = (int)($_SESSION['user_id'] ?? 0);
    $reporterName = $_SESSION['fullname'] ?? 'OSA';

    if ($student === '' || $violation === '') {
        // Name the field that is actually blank. "Please select a student and
        // a violation type" was shown even when only one of them was missing.
        $error = ($student === '' && $violation === '')
            ? "Choose a student and a violation type."
            : ($student === '' ? "Choose a student." : "Choose a violation type.");
    } else {
        // Set before the try so the catch below can read them even if the
        // severity lookup itself is what threw.
        $majorBlocked = false;
        $majorWhy     = '';
        try {
            // Severity/points are no longer staff-entered — looked up from the
            // violation type itself so the DB's NOT NULL columns stay filled.
            // Looked up BEFORE the upload: the one-Major rule below depends on
            // it, and a refused record must not orphan an evidence file.
            $vt = $conn->prepare("SELECT severity FROM violation_types WHERE violation_name = :n LIMIT 1");
            $vt->execute([':n' => $violation]);
            $type = $vt->fetch(PDO::FETCH_ASSOC) ?: ['severity' => 'Minor'];

            // ONE-MAJOR RULE — a student carries at most one Major offense,
            // ever. Refuse a second one here, naming the record they have.
            [$majorBlocked, $majorWhy] = major_violation_blocked($conn, (int)$student, $type['severity']);
            if ($majorBlocked) throw new Exception($majorWhy);

            /* PROOF IS REQUIRED -> uploads/evidence/
               Same rule as admin/add_violation.php, and checked on the server
               for the same reason: the `required` attribute is a convenience
               for honest users and does not survive a post from anywhere
               else. vts_save_evidence_upload() returns null both for a
               missing file and for one that fails its checks, so a rejected
               upload cannot save silently without proof. */
            $evidenceName = vts_save_evidence_upload($_FILES['evidence'] ?? null);
            if ($evidenceName === null) {
                throw new Exception(
                    (($_FILES['evidence']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)
                      ? "Attach a photo as proof — a violation cannot be recorded without one."
                      : "That file could not be accepted as proof. Use a JPG, PNG, GIF, WEBP or PDF under 5MB."
                );
            }

            // Centralized: auto-computes the offense # from ALL of this student's
            // prior violations, inserts the record, writes a scan_log, and
            // notifies the student + dean(s) — all in one place.
            $vidNew = record_violation($conn, (int)$student, $violation, $type['severity'], $reporter,
                $reporterName, [
                    'description' => $desc,
                    'evidence'    => $evidenceName,
                    'status'      => $status,
                ]);
            if ($vidNew <= 0) throw new Exception("Could not record the violation. Please try again.");

            // Autosave a snapshot so no manual backup is needed.
            vts_auto_backup($conn, 'add');

            header("Location: violations.php?success=Violation added successfully.");
            exit();
        } catch (Throwable $e) {
          error_log('OSA add violation failed: ' . $e->getMessage());
          // A rule refusal (e.g. the one-Major rule) has to reach the user in
          // its own words — a generic "check the details" tells them nothing.
          $error = $majorBlocked
                 ? $majorWhy
                 : 'The violation could not be recorded. Please check the details and try again.';
        }
    }
}

$assetBase = "../";
include "../includes/header.php";
include "../includes/navbar.php";
include "../includes/sidebar.php";
?>
<main class="vts-main">
<div class="vts-main-inner">

<div class="admin-welcome-row">
  <div><h1>Add Violation</h1><p>Record a new violation against a student.</p></div>
  <a href="violations.php" class="btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<?php if($error): ?>
  <div class="alert alert-error u-mb-14"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="recent-table-wrap table-responsive" style="max-width:760px;">
  <form method="POST" enctype="multipart/form-data">
<?php echo csrf_field(); ?>
    <div class="form-grid">
      <div class="field full">
        <label for="student_id">Student <span class="req">*</span></label>
        <select id="student_id" name="student_id" class="vts-select" required>
          <option value="">Select Student</option>
          <?php foreach($students as $s): ?>
            <option value="<?php echo $s['id']; ?>" <?php echo $old('student_id') === (string)$s['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars(($s['student_id'] ?? 'N/A')." - ".$s['fullname']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field full">
        <label for="violationSelect">Violation Type <span class="req">*</span></label>
        <select name="violation" id="violationSelect" class="vts-select" required>
          <option value="">Select Violation</option>
          <?php foreach($types as $t): ?>
            <option value="<?php echo htmlspecialchars($t['violation_name']); ?>" <?php echo $old('violation') === $t['violation_name'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($t['violation_name']); ?></option>
          <?php endforeach; ?>
        </select>
        <small class="field-hint">The offense number (1st, 2nd, 3rd…) is set automatically from this student's history.</small>
      </div>

      <div class="field full">
        <label for="description">Description</label>
        <textarea id="description" name="description" class="vts-input" rows="3" placeholder="Optional details about the violation"><?= htmlspecialchars($old('description')); ?></textarea>
      </div>

      <div class="field full">
        <label for="evidence">Proof photo <span class="req">*</span></label>
        <input id="evidence" type="file" name="evidence" class="vts-input" accept="image/*,.pdf" capture="environment" required>
        <small class="field-hint">Required. A photo or PDF showing the violation (max 5 MB), checked against your reason by the OSA.</small>
      </div>
    </div>

    <div class="u-actions u-mt-14">
      <button type="submit" class="btn-primary"><i class="fas fa-floppy-disk"></i> Save Violation</button>
      <a href="violations.php" class="btn-outline">Cancel</a>
    </div>
  </form>
</div>

<?php /* The app shell (.vts-main-inner + <main>) is closed by
         includes/footer.php below -- closing it here as well emitted a
         stray </div></main> on every one of these pages. */ ?>
<?php include "../includes/footer.php"; ?>
