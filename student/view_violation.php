<?php
/* Student: view a single violation's details.

   Read-only. For confidentiality the student is shown the KIND (Major/Minor),
   their running count, the date and the status — never the specific violation
   type, the description, the evidence or the staff member who recorded it.
   Anyone who needs the full details of a record is pointed at the office. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (($_SESSION['role'] ?? '') !== "Student") {
    vts_deny_access();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: violations.php");
    exit();
}
$vid    = (int)$_GET['id'];
$userID = (int)$_SESSION['user_id'];

// Students can ONLY open their own records
$stmt = $conn->prepare("
    SELECT v.*, r.fullname AS reporter_name
    FROM violations v
    LEFT JOIN users r ON v.reported_by = r.id
    WHERE v.id = :id AND v.student_id = :me
");
$stmt->execute([':id' => $vid, ':me' => $userID]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) {
    header("Location: violations.php?error=" . urlencode("Record not found."));
    exit();
}

$assetBase = "../";
include "../includes/header.php";
include "../includes/navbar.php";
include "../includes/sidebar.php";
?>
<main class="vts-main">
<div class="vts-main-inner narrow">

  <div class="page-head with-back">
    <a href="violations.php" class="back-btn"><i class="fas fa-chevron-left"></i></a>
    <div><h1>Violation Details</h1><p>Record #<?php echo $data['id']; ?></p></div>
  </div>

  <div class="vts-card">
    <h3 class="card-label">Violation Information</h3>
    <dl class="detail-list">
      <?php /* Confidentiality: the student sees KIND (Major/Minor), count,
               status and WHEN — never the specific violation type or the staff
               who recorded it. */
        $sev = ($data['severity'] ?? 'Minor') === 'Major' ? 'Major' : 'Minor'; ?>
      <div><dt>Kind</dt><dd><span class="kind-chip <?php echo strtolower($sev); ?>"><?php echo strtoupper($sev); ?></span></dd></div>
      <div><dt>Violation Count</dt><dd><?php echo (int)offense_number($data['offense']); ?></dd></div>
      <div><dt>Date &amp; time</dt><dd><?php echo vts_datetime($data['date_reported']); ?></dd></div>
      <?php
        /* A record that no longer counts stays visible — it is still part of
           the history — but it must not read as something still held against
           the student. That happens when a proof review finds the record
           unsupported: see admin/proof.php. */
        $offCount = (($data['proof_status'] ?? 'Pending') === 'Rejected') ? 'REMOVED AFTER REVIEW' : '';
        if ($offCount !== ''):
      ?>
      <div><dt>Status</dt><dd><span class="kind-chip cleared"><?php echo $offCount; ?></span></dd></div>
      <?php endif; ?>
    </dl>
    <?php /* Description, remarks and evidence are withheld from the student —
             they would reveal the specific violation. Visit the OSA if you
             need the full details of a record. */ ?>
    <p class="detail-text" style="margin-top:16px;color:var(--text-soft);">
      For the full details of this record, please see the Office of Student Affairs.
    </p>
  </div>

<?php /* The app shell (.vts-main-inner + <main>) is closed by
         includes/footer.php below -- closing it here as well emitted a
         stray </div></main> on every one of these pages. */ ?>
<?php include "../includes/footer.php"; ?>
