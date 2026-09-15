<?php
/* Student: view and download personal QR code card. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (($_SESSION['role'] ?? '') !== "Student") {
    vts_deny_access();
}

$assetBase = "../";
include "../includes/header.php";
include "../includes/navbar.php";
include "../includes/sidebar.php";

$userID = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT student_id, fullname, course, year_level FROM users WHERE id=:id");
$stmt->execute([':id'=>$userID]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$student) die("Student not found.");

require_once "../includes/qr_helper.php";
$qrPayload = $student['student_id'] . "|" . $student['fullname'] . "|" . $student['year_level'] . "|" . $student['course'];
$qrSrc  = get_student_qr($student['student_id'], "../", $qrPayload);
$qrFile = qr_file_path($student['student_id']);
$qrName = preg_replace('/[^A-Za-z0-9]+/', '', (string)$student['fullname']);
if ($qrName === '') $qrName = 'Student';
?>

<main class="vts-main">
<div class="vts-main-inner">

  <div class="page-head">
    <h1><i class="fas fa-qrcode"></i> QR Code</h1>
    <p>Your personal student QR code.</p>
  </div>

  <div class="vts-card" style="max-width:500px;margin:0 auto;">
    <div class="qr-container">
      <div class="qr-title">YOUR QR CODE</div>
      <?php if($qrSrc): ?>
        <div class="qr-img-wrap">
          <img alt="QR Code" src="<?php echo $qrSrc; ?>" style="width:240px;height:240px;display:block;">
        </div>
      <?php else: ?>
        <div style="width:240px;height:240px;background:#f0f4fa;border-radius:12px;display:flex;align-items:center;justify-content:center;color:#a0aec0;font-size:5rem;margin-bottom:20px;">
          <i class="fas fa-qrcode"></i>
        </div>
      <?php endif; ?>
      <div style="font-size:1rem;font-weight:700;color:var(--text);margin-bottom:4px;">
        <?php echo htmlspecialchars($student['fullname']); ?>
      </div>
      <div style="font-size:0.85rem;color:var(--text-muted);margin-bottom:18px;">
        ID: <?php echo htmlspecialchars($student['student_id']); ?>
        &nbsp;|&nbsp;
        <?php echo htmlspecialchars($student['course']); ?>
        &nbsp;|&nbsp;
        Year <?php echo htmlspecialchars($student['year_level']); ?>
      </div>
      <div style="display:flex;gap:12px;">
        <a href="<?php echo htmlspecialchars($qrSrc ?: '#'); ?>" class="btn-primary qr-download-btn"
           data-src="<?php echo htmlspecialchars($qrSrc); ?>"
           data-filename="QR_<?php echo htmlspecialchars($student['student_id'] . '_' . $qrName); ?>.png"
           data-name="<?php echo htmlspecialchars($student['fullname']); ?>"
           data-id="<?php echo htmlspecialchars($student['student_id']); ?>"
           data-course="<?php echo htmlspecialchars($student['course']); ?>"
          data-logo="<?php echo htmlspecialchars($assetBase); ?>assets/images/logo-sm.jpg"
          data-direct="<?php echo $qrSrc ? '0' : '1'; ?>">
          <i class="fas fa-download"></i> Download QR
        </a>
        <button class="btn-outline" onclick="window.print();">
          <i class="fas fa-print"></i> Print QR
        </button>
      </div>
    </div>
  </div>

<?php /* The app shell (.vts-main-inner + <main>) is closed by
         includes/footer.php below -- closing it here as well emitted a
         stray </div></main> on every one of these pages. */ ?>
<?php include "../includes/footer.php"; ?>
