<?php
/* OSA Staff: edit an existing violation record. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (($_SESSION['role'] ?? '') !== "OSA Staff") {
    vts_deny_access();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    vts_redirect_back('violations.php', 'error', 'Invalid violation ID.');
}
$id = (int)$_GET['id'];

$stmt = $conn->prepare("SELECT * FROM violations WHERE id = :id");
$stmt->execute([':id'=>$id]);
$violation = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$violation) { vts_redirect_back('violations.php', 'error', 'Violation not found.'); }

$students = $conn->query("SELECT id, student_id, fullname FROM users WHERE role='Student' ORDER BY fullname")
                 ->fetchAll(PDO::FETCH_ASSOC);

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_verify()) { vts_csrf_fail('save that violation'); }
    $student_id = $_POST['student_id'] ?? '';
    $vname      = trim($_POST['violation'] ?? '');
    $desc       = trim($_POST['description'] ?? '');
    $offense    = $_POST['offense'] ?? 'First Offense';
    $status = 'Recorded';

    if ($student_id==='' || $vname==='') {
        $error = "Please select a student and enter a violation.";
    } else {
        try {
            $up = $conn->prepare("UPDATE violations SET student_id=:s, violation=:v, description=:d,
                offense=:o, status=:st WHERE id=:id");
            $up->execute([':s'=>$student_id, ':v'=>$vname, ':d'=>$desc,
                ':o'=>$offense, ':st'=>$status, ':id'=>$id]);
            // Straight back to the tab/filters the edit was opened from.
            vts_redirect_back('violations.php', 'success', 'Violation updated successfully.');
        } catch (PDOException $e) { error_log('OSA edit violation failed: ' . $e->getMessage()); $error = 'The violation could not be updated. Please try again.'; }
    }
    $violation = array_merge($violation, $_POST);
}

$assetBase = "../";
include "../includes/header.php";
include "../includes/navbar.php";
include "../includes/sidebar.php";
?>
<main class="vts-main">
<div class="vts-main-inner">

<div class="admin-welcome-row">
  <div><h1>Edit Violation</h1><p>Update this violation record.</p></div>
  <a href="<?php echo htmlspecialchars(vts_return_url('violations.php')); ?>" class="btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<?php if($error): ?>
  <div class="alert alert-error u-mb-14"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="recent-table-wrap table-responsive" style="max-width:760px;">
  <form method="POST">
<?php echo csrf_field(); ?>
<?php echo '<input type="hidden" name="return" value="' . htmlspecialchars(vts_return_url('violations.php'), ENT_QUOTES, 'UTF-8') . '">'; ?>
    <div class="form-grid">
      <div class="field full"><label for="student_id">Student <span class="req">*</span></label>
        <select id="student_id" name="student_id" class="vts-select" required>
          <option value="">Select Student</option>
          <?php foreach($students as $s): ?>
            <option value="<?php echo $s['id']; ?>" <?php echo ($violation['student_id']==$s['id'])?'selected':''; ?>>
              <?php echo htmlspecialchars(($s['student_id'] ?? 'N/A')." - ".$s['fullname']); ?>
            </option>
          <?php endforeach; ?>
        </select></div>
      <div class="field full"><label for="violation">Violation <span class="req">*</span></label>
        <input id="violation" type="text" name="violation" class="vts-input" required value="<?php echo htmlspecialchars($violation['violation'] ?? ''); ?>"></div>
      <div class="field"><label for="offense">Offense</label>
        <select id="offense" name="offense" class="vts-select">
          <?php
            $curOff  = $violation['offense'] ?? 'First Offense';
            $offOpts = ['First Offense','Second Offense','Third Offense'];
            // Keep an auto-computed 4th/5th/… offense as a real option so editing
            // another field never silently resets it back to "First Offense".
            if (!in_array($curOff, $offOpts, true)) $offOpts[] = $curOff;
          ?>
          <?php foreach($offOpts as $o): ?>
            <option <?php echo ($curOff===$o)?'selected':''; ?>><?php echo htmlspecialchars($o); ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field full"><label for="description">Description</label>
        <textarea id="description" name="description" class="vts-input" rows="3"><?php echo htmlspecialchars($violation['description'] ?? ''); ?></textarea></div>
    </div>
    <div class="u-actions u-mt-14">
      <button type="submit" class="btn-primary"><i class="fas fa-floppy-disk"></i> Update Violation</button>
      <a href="<?php echo htmlspecialchars(vts_return_url('violations.php')); ?>" class="btn-outline">Cancel</a>
    </div>
  </form>
</div>

<?php /* The app shell (.vts-main-inner + <main>) is closed by
         includes/footer.php below -- closing it here as well emitted a
         stray </div></main> on every one of these pages. */ ?>
<?php include "../includes/footer.php"; ?>
