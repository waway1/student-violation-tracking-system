<?php
/* Admin: Form + Handler to record a new student violation */

require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (!in_array($_SESSION['role'] ?? '', ['Admin', 'OSA'], true)) {
    vts_deny_access();
}

$error = "";
// What the user already typed, so a failed save re-renders the form
// with their work still in it instead of a blank one.
$old = fn($k) => (string)($_POST[$k] ?? '');

// Get students
$students = $conn->query("
    SELECT id, student_id, fullname
    FROM users
    WHERE role = 'Student'
    ORDER BY fullname
")->fetchAll(PDO::FETCH_ASSOC);

// Get violation types
$types = $conn->query("
    SELECT violation_name
    FROM violation_types
    ORDER BY violation_name
")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!csrf_verify()) {
        vts_csrf_fail('record that violation');
    }

    $student      = (int)($_POST['student_id'] ?? 0);
    $violation    = trim($_POST['violation'] ?? '');
    $description  = trim($_POST['description'] ?? '');
    $remarks      = trim($_POST['remarks'] ?? '');

    $status        = "Recorded";
    $reporter      = (int)($_SESSION['user_id'] ?? 0);
    $reporterName  = $_SESSION['fullname'] ?? "Admin";

    if ($student <= 0 || empty($violation)) {
        $error = ($student <= 0 && empty($violation))
            ? "Choose a student and a violation type."
            : ($student <= 0 ? "Choose a student." : "Choose a violation type.");
    } else {

        try {

            // Get the rule for this violation type. This comes FIRST now: the
            // one-Major rule below depends on it, and a refused record must
            // not leave an uploaded evidence file orphaned in uploads/.
            //
            // Only the severity is read now. max_points went with the points
            // system (removed 2026-09): a type is Minor or Major, and nothing
            // multiplies it by anything.
            $stmt = $conn->prepare("
                SELECT severity
                FROM violation_types
                WHERE violation_name = ?
                LIMIT 1
            ");

            $stmt->execute([$violation]);

            $type = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['severity' => 'Minor'];

            $severity = $type['severity'] ?? 'Minor';

            // ONE-MAJOR RULE — a student carries at most one Major offense,
            // ever. If they already have one, refuse the second here and name
            // the record they already have.
            [$majorBlocked, $majorWhy] = major_violation_blocked($conn, $student, $severity);

            if ($majorBlocked) {

                $error = $majorWhy;

            } else {

                /* PROOF IS REQUIRED on this form. A violation recorded here
                   is recorded by a person sitting at a desk who can attach
                   what they are looking at, and admin/proof.php exists to
                   read that photo against the reason — which it cannot do
                   for a record that never had one.

                   Checked on the server, not only with the `required`
                   attribute: that attribute is a convenience for honest
                   users and is gone the moment anyone posts this form from
                   anywhere else. vts_save_evidence_upload() returns null for
                   a missing file AND for one that fails its type/size/content
                   checks, so this catches a rejected upload too — which is
                   the case that would otherwise save silently without proof.

                   The SCANNER path is deliberately not held to this: it has
                   no camera capture yet, so requiring proof there would stop
                   gate scanning entirely. */
                $evidenceName = vts_save_evidence_upload($_FILES['evidence'] ?? null);
                if ($evidenceName === null) {
                    throw new Exception(
                        (($_FILES['evidence']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)
                          ? "Attach a photo as proof — a violation cannot be recorded without one."
                          : "That file could not be accepted as proof. Use a JPG, PNG, GIF, WEBP or PDF under 5MB."
                    );
                }

                // Record violation
                $violationID = record_violation(
                    $conn,
                    $student,
                    $violation,
                    $severity,
                    $reporter,
                    $reporterName,
                    [
                        'description' => $description,
                        'remarks'     => $remarks,
                        'evidence'    => $evidenceName,
                        'status'      => $status,
                    ]
                );

                if (!$violationID) {
                    throw new Exception(vts_last_violation_error() ?: "Failed to record violation.");
                }

                // Auto backup
                vts_auto_backup($conn, 'add');

                header("Location: violations.php?success=Violation added successfully.");
                exit();

            }

        } catch (Throwable $e) {
            error_log('Admin add violation failed: ' . $e->getMessage());
            $error = 'The violation could not be recorded. Please check the details and try again.';
        }

    }

}

$adminActive = "violations";

include "../includes/admin_header.php";
?>

<div class="admin-welcome-row">
    <div>
        <h1>Add Violation</h1>
        <p>Record a new student violation.</p>
    </div>

    <a href="violations.php" class="btn-outline">
        <i class="fas fa-arrow-left"></i>
        Back
    </a>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-error" style="margin-bottom:15px;">
    <i class="fas fa-circle-exclamation"></i>
    <?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<div class="recent-table-wrap table-responsive" style="max-width:760px;">

<form method="POST" enctype="multipart/form-data">

<?php echo csrf_field(); ?>

<div class="form-grid">

    <div class="field full">
        <label for="student_id">
            Student
            <span class="req">*</span>
        </label>

        <select id="student_id" name="student_id" class="vts-select" required>

            <option value="">Select Student</option>

            <?php foreach ($students as $student): ?>

                <option value="<?= $student['id']; ?>" <?= $old('student_id') === (string)$student['id'] ? 'selected' : ''; ?>>
                    <?= htmlspecialchars($student['student_id'] . " - " . $student['fullname']); ?>
                </option>

            <?php endforeach; ?>

        </select>

    </div>

    <div class="field full">

        <label for="violation">
            Violation Type
            <span class="req">*</span>
        </label>

        <select id="violation"
            name="violation"
            class="vts-select"
            required
        >

            <option value="">Select Violation</option>

            <?php foreach ($types as $type): ?>

                <option value="<?= htmlspecialchars($type['violation_name']); ?>" <?= $old('violation') === $type['violation_name'] ? 'selected' : ''; ?>>
                    <?= htmlspecialchars($type['violation_name']); ?>
                </option>

            <?php endforeach; ?>

        </select>

        <small class="field-hint">
            Offense number is determined automatically based on the student's previous violations.
        </small>

    </div>

    <div class="field full">

        <label for="description">Description</label>

        <textarea id="description"
            name="description"
            rows="4"
            class="vts-input"
            placeholder="Enter additional details (optional)"
        ><?= htmlspecialchars($old('description')); ?></textarea>

    </div>

    <div class="field full">

        <label for="remarks">Remarks / Action Taken</label>

        <textarea id="remarks"
            name="remarks"
            rows="3"
            class="vts-input"
            placeholder="Warning issued, Parent informed, etc."
        ><?= htmlspecialchars($old('remarks')); ?></textarea>

    </div>

    <div class="field full">

        <label for="evidence">Proof <span class="req">*</span></label>

        <input id="evidence"
            type="file"
            name="evidence"
            class="vts-input"
            accept="image/*,.pdf"
            required
        >

        <small class="field-hint">
            Required. A photo of the violation, checked against your reason on
            the Violation Proof page. Images or PDF, maximum 5MB.
        </small>

    </div>

</div>

<div style="display:flex;gap:10px;margin-top:20px;">

    <button type="submit" class="btn-primary">
        <i class="fas fa-save"></i>
        Save Violation
    </button>

    <a href="violations.php" class="btn-outline">
        Cancel
    </a>

</div>

</form>

</div>

<?php include "../includes/admin_footer.php"; ?>