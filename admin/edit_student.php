<?php
/* Admin: edit an existing student record. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";
vts_ensure_role_enum($conn);

/* CHANGING A STUDENT IS AN ADMIN ACTION.

   OSA and OSA Staff both used to reach this form -- OSA Staff by way of a
   redirect from their own folder, and the listing offered it behind an EYE
   icon, so the control that looked like "look at this record" was in fact
   the one that rewrites it. Both roles look students up all day; neither
   is responsible for the contents of the roster.

   They get admin/view_student.php instead, which has no form on it. */
if (($_SESSION['role'] ?? '') !== 'Admin') {
    $sid = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($sid > 0) {
        header('Location: view_student.php?id=' . $sid);
        exit();
    }
    vts_deny_access("Only an Admin can change a student record. You can view it instead.");
}
$studentsPage = ($_SESSION['role'] ?? '') === 'OSA Staff' ? '../osa_staff/students.php' : 'students.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: {$studentsPage}?error=Invalid student ID.");
    exit();
}
$id = (int)$_GET['id'];

$stmt = $conn->prepare("SELECT * FROM users WHERE id = :id AND role='Student'");
$stmt->execute([':id'=>$id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$student) { header("Location: {$studentsPage}?error=Student not found."); exit(); }

$error = '';
$courseList = $conn->query("SELECT id, course_name, short_name, college_id FROM courses ORDER BY short_name, course_name")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify()) {
    $error = 'Session expired. Please try again.';
  } else {
    $studentId = trim((string)($_POST['student_id'] ?? ''));
    $fullname = name_case($_POST['fullname'] ?? '');
    $email = trim((string)($_POST['email'] ?? ''));
    $contact = trim((string)($_POST['contact_number'] ?? ''));
    $year = trim((string)($_POST['year_level'] ?? ''));
    $section = strtoupper(trim((string)($_POST['section'] ?? '')));
    $status = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
    $courseId = trim((string)($_POST['course_id'] ?? ''));
    $course = null;
    $collegeId = null;

    if ($courseId !== '' && ctype_digit($courseId)) {
      $courseStmt = $conn->prepare("SELECT short_name, course_name, college_id FROM courses WHERE id = :id");
      $courseStmt->execute([':id' => (int)$courseId]);
      if ($courseRow = $courseStmt->fetch(PDO::FETCH_ASSOC)) {
        $course = $courseRow['short_name'] !== '' ? $courseRow['short_name'] : $courseRow['course_name'];
        $collegeId = (int)$courseRow['college_id'];
      } else {
        $error = 'Please select a valid course.';
      }
    }

    if ($error === '' && $studentId === '') $error = 'Student ID is required.';
    elseif ($error === '' && !ctype_digit($studentId)) $error = 'Student ID must contain numbers only.';
    elseif ($error === '' && strlen($studentId) > 10) $error = 'Student ID must be 10 digits or fewer.';
    elseif ($error === '' && $fullname === '') $error = 'Full name is required.';
    elseif ($error === '' && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Please enter a valid email address.';
    elseif ($error === '' && $section !== '' && !preg_match('/^[A-Z]$/', $section)) $error = 'Section must be one capital letter (A-Z).';

    if ($error === '') {
      try {
        $dupSql = "SELECT id FROM users WHERE id <> :id AND student_id = :sid";
        $dupParams = [':id' => $id, ':sid' => $studentId];
        if ($email !== '') {
          $dupSql .= " OR (id <> :email_id AND email = :email)";
          $dupParams[':email_id'] = $id;
          $dupParams[':email'] = $email;
        }
        $dup = $conn->prepare($dupSql);
        $dup->execute($dupParams);
        if ($dup->fetch()) {
          $error = 'The student ID or email is already used by another account.';
        } else {
          $up = $conn->prepare("UPDATE users SET student_id=:sid, fullname=:fullname, email=:email,
            contact_number=:contact, college_id=:college, course=:course, year_level=:year,
            section=:section, status=:status WHERE id=:id AND role='Student'");
          $up->execute([
            ':sid' => $studentId, ':fullname' => $fullname, ':email' => $email,
            ':contact' => $contact !== '' ? $contact : null, ':college' => $collegeId,
            ':course' => $course, ':year' => $year !== '' ? $year : null,
            ':section' => $section !== '' ? $section : null, ':status' => $status, ':id' => $id,
          ]);
          audit_log($conn, 'Edit Student', 'users', $id, 'Updated student account');
                    header('Location: ' . $studentsPage . '?success=' . urlencode('Student updated successfully.'));
          exit();
        }
      } catch (Throwable $e) {
        error_log('Admin edit student failed: ' . $e->getMessage());
        $error = 'The student could not be updated. Please check the details and try again.';
      }
    }
    $student = array_merge($student, [
      'student_id' => $studentId, 'fullname' => $fullname, 'email' => $email,
      'contact_number' => $contact, 'course' => $course, 'year_level' => $year,
      'section' => $section, 'status' => $status,
    ]);
  }
}

$adminActive = 'students';
include "../includes/admin_header.php";
?>

<div class="admin-welcome-row">
  <div><h1>Edit Student</h1><p>Update the student account and enrollment details.</p></div>
  <a href="<?php echo htmlspecialchars($studentsPage); ?>" class="btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<?php if ($error !== ''): ?>
  <div class="alert alert-error u-mb-14"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="recent-table-wrap table-responsive" style="max-width:720px;">
  <form method="POST">
    <?php echo csrf_field(); ?>
    <fieldset class="vts-fieldset">
      <legend>Who the student is</legend>
    <div class="form-grid">
      <div class="field"><label for="student_id">Student ID <span class="req">*</span></label>
        <input id="student_id" name="student_id" class="vts-input js-digits" maxlength="10" required value="<?php echo htmlspecialchars($student['student_id'] ?? ''); ?>" data-allow="digits" pattern="[0-9]{10}" title="A School ID is exactly 10 digits." inputmode="numeric"></div>
      <div class="field"><label for="fullname">Full Name <span class="req">*</span></label>
        <input id="fullname" name="fullname" class="vts-input" required value="<?php echo htmlspecialchars($student['fullname'] ?? ''); ?>" data-allow="letters" pattern="[A-Za-zÀ-ɏÑñ .'\-]{2,60}" title="Letters, spaces, hyphens, apostrophes and periods only." maxlength="80"></div>
    </div>
    </fieldset>

    <fieldset class="vts-fieldset">
      <legend>How to reach them</legend>
      <p class="vts-fieldset-hint">Without an email address the student can only see violations in the app.</p>
    <div class="form-grid">
      <div class="field"><label for="email">Email</label>
        <input id="email" type="email" name="email" class="vts-input" value="<?php echo htmlspecialchars($student['email'] ?? ''); ?>"></div>
      <div class="field"><label for="contact_number">Contact Number</label>
        <input id="contact_number" type="tel" name="contact_number" class="vts-input"
               inputmode="tel" maxlength="20" pattern="[0-9 ()+-]{7,20}"
               title="Digits, spaces, brackets, + and - only" placeholder="09XX XXX XXXX"
               value="<?php echo htmlspecialchars($student['contact_number'] ?? ''); ?>"></div>
    </div>
    </fieldset>

    <fieldset class="vts-fieldset">
      <legend>Enrolment</legend>
    <div class="form-grid">
      <div class="field"><label for="course_id">Course</label>
        <select id="course_id" name="course_id" class="vts-select">
          <option value="">No course</option>
          <?php foreach ($courseList as $courseOption): $courseValue = $courseOption['short_name'] ?: $courseOption['course_name']; ?>
            <option value="<?php echo (int)$courseOption['id']; ?>" <?php echo (($student['course'] ?? '') === $courseValue) ? 'selected' : ''; ?>><?php echo htmlspecialchars($courseValue); ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label for="year_level">Year Level</label>
        <select id="year_level" name="year_level" class="vts-select">
          <option value="">Select year level</option>
          <?php foreach (['1st Year','2nd Year','3rd Year','4th Year','5th Year'] as $yearOption): ?>
            <option value="<?php echo $yearOption; ?>" <?php echo (($student['year_level'] ?? '') === $yearOption) ? 'selected' : ''; ?>><?php echo $yearOption; ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label for="section">Section</label>
        <input id="section" name="section" class="vts-input js-upper u-upper" maxlength="1" pattern="[A-Za-z]" inputmode="text"
               title="Enter one capital letter from A to Z."
               value="<?php echo htmlspecialchars($student['section'] ?? ''); ?>"></div>
      <div class="field"><label for="status">Status</label>
        <select id="status" name="status" class="vts-select">
          <option value="Active" <?php echo (($student['status'] ?? '') === 'Active') ? 'selected' : ''; ?>>Active</option>
          <option value="Inactive" <?php echo (($student['status'] ?? '') === 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
        </select></div>
    </div>
    </fieldset>
    <div class="u-actions u-mt-14">
      <button type="submit" class="btn-primary"><i class="fas fa-floppy-disk"></i> Save Student</button>
      <a href="<?php echo htmlspecialchars($studentsPage); ?>" class="btn-outline">Cancel</a>
    </div>
  </form>
</div>

<?php include "../includes/admin_footer.php"; ?>
