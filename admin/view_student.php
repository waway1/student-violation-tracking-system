<?php
/* Read-only student record.

   WHY THIS PAGE EXISTS
   Looking at a student and changing one were the same page. The list linked
   an eye icon straight at edit_student.php, which is a form: every field
   live, a Save button at the bottom, and a Delete a row above. Anyone who
   only wanted to check a section or a contact number was put in front of
   the controls that alter the record, and the icon told them it was safe.

   So the two are separated. This page shows the record and cannot change
   it -- there is no form on it at all, which is the only version of
   read-only worth having. Editing is a deliberate second step, and only
   for the role that is allowed it.

   WHO CAN OPEN IT
   Admin, OSA and OSA Staff. All three need to look students up; only Admin
   may change or remove one (see edit_student.php / delete_student.php). */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (!in_array($_SESSION['role'] ?? '', ['Admin', 'OSA', 'OSA Staff'], true)) {
    vts_deny_access();
}

/* Where "Back" goes, and where the Edit button returns to. OSA Staff browse
   their own listing, so sending them to the admin one would bounce them off
   a page they cannot open. */
$isStaff   = ($_SESSION['role'] ?? '') === 'OSA Staff';
$listPage  = $isStaff ? '../osa_staff/students.php' : 'students.php';
$canEdit   = ($_SESSION['role'] ?? '') === 'Admin';

$id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: ' . $listPage . '?error=' . urlencode('No student was chosen.'));
    exit();
}

$stmt = $conn->prepare("SELECT * FROM users WHERE id = :id AND role = 'Student'");
$stmt->execute([':id' => $id]);
$s = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$s) {
    header('Location: ' . $listPage . '?error=' . urlencode('That student record no longer exists.'));
    exit();
}

/* The course name, resolved the same way the listing resolves it. */
$courseName = trim((string)($s['course'] ?? ''));
if ($courseName === '' && !empty($s['course_id'])) {
    try {
        $c = $conn->prepare("SELECT course_name, short_name FROM courses WHERE id = :id");
        $c->execute([':id' => (int)$s['course_id']]);
        $row = $c->fetch(PDO::FETCH_ASSOC);
        if ($row) $courseName = $row['short_name'] ?: $row['course_name'];
    } catch (Throwable $e) { /* a course table that is not there must not blank the page */ }
}

/* The record this student actually has. Counted, not listed: this page is
   about who they are — their violations have their own page, linked below. */
$vCount = 0; $vLatest = null;
try {
    $v = $conn->prepare("SELECT COUNT(*) FROM violations WHERE student_id = :id");
    $v->execute([':id' => $id]);
    $vCount = (int)$v->fetchColumn();

    $l = $conn->prepare("SELECT violation, severity, date_reported FROM violations
                         WHERE student_id = :id ORDER BY date_reported DESC LIMIT 1");
    $l->execute([':id' => $id]);
    $vLatest = $l->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) { /* counts are a nicety, never a reason to fail */ }

$pic  = trim((string)($s['profile_picture'] ?? ''));
$name = (string)($s['fullname'] ?? '');

$assetBase = "../";
include "../includes/header.php";
include "../includes/navbar.php";
include "../includes/sidebar.php";
?>
<main class="vts-main">
<div class="vts-main-inner">

  <div class="admin-welcome-row">
    <div>
      <h1><i class="fas fa-user-graduate"></i> Student Record</h1>
      <p>Everything on file for this student. This page does not change anything.</p>
    </div>
    <div class="u-nowrap">
      <a href="<?php echo htmlspecialchars($listPage); ?>" class="btn-outline btn-sm">
        <i class="fas fa-chevron-left"></i> Back to students
      </a>
      <?php if ($canEdit): ?>
        <a href="edit_student.php?id=<?php echo $id; ?>&amp;return=<?php echo vts_return_param(); ?>"
           class="btn-primary btn-sm"><i class="fas fa-pen"></i> Edit</a>
      <?php endif; ?>
    </div>
  </div>

  <?php /* Said once, plainly, to whoever cannot change anything here — so a
           missing Edit button reads as a rule rather than a page that has
           failed to finish loading. */ ?>
  <?php if (!$canEdit): ?>
    <div class="alert alert-info alert-static u-mb-14" role="note">
      <i class="fas fa-circle-info"></i>
      You can view student records. Changing or removing one is an Admin action.
    </div>
  <?php endif; ?>

  <div class="vs-card">
    <div class="vs-head">
      <?php if ($pic !== ''): ?>
           <img class="vs-avatar" src="../uploads/profile/<?php echo htmlspecialchars($pic); ?>"
             alt="Profile photo of <?php echo htmlspecialchars($name !== '' ? $name : 'student'); ?>">
      <?php else: ?>
        <span class="vs-avatar vs-avatar-none"><i class="fas fa-user-graduate"></i></span>
      <?php endif; ?>
      <div class="vs-head-t">
        <h2><?php echo htmlspecialchars($name !== '' ? $name : 'Unnamed student'); ?></h2>
        <p><?php echo htmlspecialchars($s['student_id'] ?: 'No School ID on file'); ?></p>
      </div>
      <span class="pill <?php echo (($s['status'] ?? '') === 'Active' ? 'resolved' : 'atrisk'); ?>">
        <?php echo htmlspecialchars($s['status'] ?? '—'); ?>
      </span>
    </div>

    <dl class="vs-grid">
      <div><dt>School ID</dt><dd><?php echo htmlspecialchars($s['student_id'] ?: '—'); ?></dd></div>
      <div><dt>Course</dt><dd><?php echo htmlspecialchars($courseName !== '' ? $courseName : '—'); ?></dd></div>
      <div><dt>Year level</dt><dd><?php echo htmlspecialchars($s['year_level'] ?: '—'); ?></dd></div>
      <div><dt>Section</dt><dd><?php echo htmlspecialchars($s['section'] ?: '—'); ?></dd></div>
      <div><dt>Email</dt><dd>
        <?php echo htmlspecialchars($s['email'] ?: '—'); ?>
        <?php if (isset($s['email_verified'])): ?>
          <span class="vs-flag <?php echo ((int)$s['email_verified'] === 1 ? 'ok' : 'warn'); ?>">
            <?php echo ((int)$s['email_verified'] === 1 ? 'verified' : 'not verified'); ?>
          </span>
        <?php endif; ?>
      </dd></div>
      <div><dt>Contact number</dt><dd><?php echo htmlspecialchars($s['contact_number'] ?: '—'); ?></dd></div>
      <div><dt>Registered</dt><dd><?php echo htmlspecialchars(!empty($s['created_at']) ? vts_date($s['created_at']) : '—'); ?></dd></div>
      <div><dt>Violations on record</dt><dd>
        <?php echo (int)$vCount; ?>
        <?php if ($vLatest): ?>
          <span class="vs-sub">latest: <?php echo htmlspecialchars($vLatest['violation']); ?>
            &middot; <?php echo htmlspecialchars(vts_date($vLatest['date_reported'])); ?></span>
        <?php endif; ?>
      </dd></div>
    </dl>

    <div class="vs-foot">
      <a class="btn-outline btn-sm"
         href="violations.php?view=records&amp;search=<?php echo urlencode((string)$s['student_id']); ?>">
        <i class="fas fa-list"></i> See this student's violations
      </a>
    </div>
  </div>

</div>
<?php include "../includes/footer.php"; ?>
