<?php
/* OSA Staff: view a single violation's details. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (($_SESSION['role'] ?? '') !== "OSA Staff") {
    vts_deny_access();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    vts_redirect_back('violations.php', 'error', 'Invalid violation ID.');
    exit();
}
$id = (int)$_GET['id'];

$stmt = $conn->prepare("
    SELECT v.*, u.student_id AS sid, u.fullname, u.email, u.course, u.year_level, u.section,
           r.fullname AS reporter_name, r.role AS reporter_role
    FROM violations v
    JOIN users u ON v.student_id = u.id
    LEFT JOIN users r ON v.reported_by = r.id
    WHERE v.id = :id
");
$stmt->execute([':id' => $id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) {
    vts_redirect_back('violations.php', 'error', 'Violation not found.');
    exit();
}

$assetBase = "../";
include "../includes/header.php";
include "../includes/navbar.php";
include "../includes/sidebar.php";
?>
<main class="vts-main">
<div class="vts-main-inner">

  <div class="page-head with-back">
    <a href="javascript:history.back()" class="back-btn"><i class="fas fa-chevron-left"></i></a>
    <div>
      <h1>Violation Details</h1>
      <p>Record #<?php echo $data['id']; ?></p>
    </div>
  </div>

  <div class="detail-grid">
    <div class="vts-card">
      <h3 class="card-label">Student</h3>
      <dl class="detail-list">
        <div><dt>Name</dt><dd><?php echo htmlspecialchars($data['fullname']); ?></dd></div>
        <div><dt>Student ID</dt><dd><?php echo htmlspecialchars($data['sid']); ?></dd></div>
        <div><dt>Email</dt><dd><?php echo htmlspecialchars($data['email']); ?></dd></div>
        <div><dt>Course</dt><dd><?php echo htmlspecialchars($data['course'] ?? '—'); ?></dd></div>
        <div><dt>Year / Section</dt><dd><?php echo htmlspecialchars(($data['year_level'] ?? '—') . ' / ' . ($data['section'] ?? '—')); ?></dd></div>
      </dl>
    </div>

    <div class="vts-card">
      <h3 class="card-label">Violation</h3>
      <dl class="detail-list">
        <div><dt>Type</dt><dd><?php echo htmlspecialchars($data['violation']); ?></dd></div>
        <div><dt>Violation Count</dt><dd><?php echo htmlspecialchars(offense_display($data['offense'])); ?></dd></div>
        <?php /* Recorder is OSA/Admin-only; OSA Staff reviews the violation, not who filed it. */
              if (vts_can_see_recorder()): ?>
        <div><dt>Recorded by</dt><dd><?php echo htmlspecialchars(violation_recorder($data)); ?></dd></div>
        <?php endif; ?>
        <div><dt>Date reported</dt><dd><?php echo vts_datetime($data['date_reported']); ?></dd></div>
      </dl>
      <?php if (!empty($data['description'])): ?>
        <h3 class="card-label u-mt-16">Description</h3>
        <p class="detail-text"><?php echo nl2br(htmlspecialchars($data['description'])); ?></p>
      <?php endif; ?>
      <?php if (!empty($data['remarks'])): ?>
        <h3 class="card-label u-mt-16">Remarks / Action Taken</h3>
        <p class="detail-text"><?php echo nl2br(htmlspecialchars($data['remarks'])); ?></p>
      <?php endif; ?>
      <?php if (!empty($data['evidence'])): ?>
        <h3 class="card-label u-mt-16">Evidence</h3>
        <a class="btn-ghost btn-sm" target="_blank"
           href="<?php echo $assetBase; ?>uploads/evidence/<?php echo htmlspecialchars($data['evidence']); ?>">
           <i class="fas fa-paperclip"></i> View attachment
        </a>
      <?php endif; ?>
    </div>
  </div>


<?php /* The app shell (.vts-main-inner + <main>) is closed by
         includes/footer.php below -- closing it here as well emitted a
         stray </div></main> on every one of these pages. */ ?>
<?php include "../includes/footer.php"; ?>
