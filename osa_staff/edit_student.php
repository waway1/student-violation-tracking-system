<?php
/* OSA Staff: edit an existing student record. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (($_SESSION['role'] ?? '') !== "OSA Staff") {
    vts_deny_access();
}

/* OSA Staff view student records; they do not edit them. This used to
   hand straight over to the edit form. */
header('Location: ../admin/view_student.php?id=' . urlencode((string)($_GET['id'] ?? '')));
exit();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: students.php?error=Invalid student ID.");
    exit();
}
$id = (int)$_GET['id'];

$stmt = $conn->prepare("SELECT * FROM users WHERE id = :id AND role='Student'");
$stmt->execute([':id'=>$id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$student) { header("Location: students.php?error=Student not found."); exit(); }

$assetBase = "../";
include "../includes/header.php";
include "../includes/navbar.php";
include "../includes/sidebar.php";
?>
<main class="vts-main">
<div class="vts-main-inner">

<div class="admin-welcome-row">
  <div><h1>Student Details</h1><p>View-only — student accounts are managed by the student themselves.</p></div>
  <a href="students.php" class="btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<div class="recent-table-wrap table-responsive" style="max-width:640px;">
  <div class="student-strip u-mb-20">
    <div class="avatar-circle" style="overflow:hidden;">
      <?php if (!empty($student['profile_picture'])): ?>
        <img alt="" src="../uploads/profile/<?php echo htmlspecialchars($student['profile_picture']); ?>"
style="width:100%;height:100%;object-fit:cover;">
      <?php else: ?><i class="fas fa-user-graduate"></i><?php endif; ?>
    </div>
    <div>
      <div class="cell-title" style="font-size:1.05rem;"><?php echo htmlspecialchars($student['fullname']); ?></div>
      <div class="cell-sub"><?php echo htmlspecialchars($student['student_id'] ?? '-'); ?> · <?php echo htmlspecialchars($student['username']); ?></div>
    </div>
  </div>

  <dl class="detail-list">
    <div><dt>Full Name</dt><dd><?php echo htmlspecialchars($student['fullname']); ?></dd></div>
    <div><dt>Student ID</dt><dd><?php echo htmlspecialchars($student['student_id'] ?? '-'); ?></dd></div>
    <div><dt>Username</dt><dd><?php echo htmlspecialchars($student['username']); ?></dd></div>
    <div><dt>Email</dt><dd><?php echo htmlspecialchars($student['email']); ?></dd></div>
    <div><dt>Contact Number</dt><dd><?php echo htmlspecialchars($student['contact_number'] ?? '-'); ?></dd></div>
    <div><dt>Course</dt><dd><?php echo htmlspecialchars($student['course'] ?? '-'); ?></dd></div>
    <div><dt>Year Level</dt><dd><?php echo htmlspecialchars($student['year_level'] ?? '-'); ?></dd></div>
    <div><dt>Section</dt><dd><?php echo htmlspecialchars($student['section'] ?? '-'); ?></dd></div>
    <div><dt>Status</dt><dd><span class="pill <?php echo ($student['status']==='Active'?'resolved':'atrisk'); ?>"><?php echo htmlspecialchars($student['status']); ?></span></dd></div>
    <div><dt>Registered On</dt><dd><?php echo !empty($student['created_at']) ? vts_date($student['created_at']) : '-'; ?></dd></div>
  </dl>

  <div style="display:flex;gap:10px;margin-top:22px;">
    <a href="students.php" class="btn-outline">Back to Students</a>
  </div>
</div>

<?php /* The app shell (.vts-main-inner + <main>) is closed by
         includes/footer.php below -- closing it here as well emitted a
         stray </div></main> on every one of these pages. */ ?>
<?php include "../includes/footer.php"; ?>
