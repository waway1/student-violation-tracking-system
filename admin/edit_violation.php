<?php
/* Admin: edit an existing violation record. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (!in_array($_SESSION['role'] ?? '', ['Admin', 'OSA'], true)) {
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
    $remarks    = trim($_POST['remarks'] ?? '');
    $offense    = $_POST['offense'] ?? 'First Offense';
    $status = 'Recorded';

    if ($student_id==='' || $vname==='') {
        $error = "Please select a student and enter a violation.";
    } else {
        /* ONE-MAJOR RULE — the edit form can move a record to a different
           student, and a Major must not land on someone who already has one.
           Only worth checking when the student is actually changing: left
           where it is, the row the rule would find IS this row. */
        $movingTo = (int)$student_id;
        [$majorBlocked, $majorWhy] = ($movingTo !== (int)$violation['student_id'])
            ? major_violation_blocked($conn, $movingTo, $violation['severity'] ?? 'Minor')
            : [false, ''];
        if ($majorBlocked) { $error = $majorWhy; }
    }

    if (!$error && $student_id!=='' && $vname!=='') {
        try {
            vts_ensure_violation_columns($conn);
            // Keep the existing evidence unless a new file is uploaded.
            $evidenceName = vts_save_evidence_upload($_FILES['evidence'] ?? null) ?: ($violation['evidence'] ?? null);
            $up = $conn->prepare("UPDATE violations SET student_id=:s, violation=:v, description=:d,
                remarks=:rem, evidence=:evi, offense=:o, status=:st WHERE id=:id");
            $up->execute([':s'=>$student_id, ':v'=>$vname, ':d'=>$desc,
                ':rem'=>($remarks !== '' ? $remarks : null), ':evi'=>$evidenceName,
                ':o'=>$offense, ':st'=>$status, ':id'=>$id]);
            // Straight back to the tab/filters the edit was opened from.
            vts_redirect_back('violations.php', 'success', 'Violation updated successfully.');
        } catch (PDOException $e) { error_log('Admin edit violation failed: ' . $e->getMessage()); $error = 'The violation could not be updated. Please try again.'; }
    }
    $violation = array_merge($violation, $_POST);
}

$adminActive = 'violations';
include "../includes/admin_header.php";
?>

<div class="admin-welcome-row">
  <div><h1>Edit Violation</h1><p>Update this violation record.</p></div>
  <a href="<?php echo htmlspecialchars(vts_return_url('violations.php')); ?>" class="btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<?php if($error): ?>
  <div class="alert alert-error u-mb-14"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="recent-table-wrap table-responsive" style="max-width:760px;">
  <form method="POST" enctype="multipart/form-data">
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
      <div class="field full"><label for="remarks">Remarks / Action Taken</label>
        <textarea id="remarks" name="remarks" class="vts-input" rows="2" placeholder="e.g. Warning given, parent informed, ID confiscated"><?php echo htmlspecialchars($violation['remarks'] ?? ''); ?></textarea></div>
      <div class="field full"><label for="evidence">Evidence</label>
        <?php if (!empty($violation['evidence'])): ?>
          <div style="margin-bottom:6px;">
            <a class="btn-ghost btn-sm" target="_blank" href="../uploads/evidence/<?php echo htmlspecialchars($violation['evidence']); ?>"><i class="fas fa-paperclip"></i> View current attachment</a>
          </div>
        <?php endif; ?>
        <input id="evidence" type="file" name="evidence" class="vts-input" accept="image/*,.pdf">
        <small class="field-hint">Upload a new file to replace the current one (image/PDF, max 5 MB).</small></div>
    </div>
    <div class="u-actions u-mt-14">
      <button type="submit" class="btn-primary"><i class="fas fa-floppy-disk"></i> Update Violation</button>
      <a href="<?php echo htmlspecialchars(vts_return_url('violations.php')); ?>" class="btn-outline">Cancel</a>
    </div>
  </form>
</div>

<?php include "../includes/admin_footer.php"; ?>
