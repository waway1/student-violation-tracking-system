<?php
/* Admin: system settings page. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

/* ADMIN ONLY. Was Admin + OSA. Everything on this page configures the system
   rather than operating it — the school's own details, the export folders, and
   the Scanner & duty card that turns the gate on and off and posts its hours.
   OSA work the gate; they do not set it up. */
if (($_SESSION['role'] ?? '') !== 'Admin') {
    vts_deny_access();
}

$error = "";
$success = "";

// Ensure settings table exists (also defined in schema; harmless if present)
$conn->exec("
CREATE TABLE IF NOT EXISTS system_settings(
    id INT PRIMARY KEY AUTO_INCREMENT,
    school_name VARCHAR(255),
    school_address TEXT,
    school_email VARCHAR(255),
    contact_number VARCHAR(100),
    logo VARCHAR(255),
    academic_year VARCHAR(100),
    semester VARCHAR(50),
    max_points INT DEFAULT 100
)");
/* Google Drive folders the exported files get filed in — the school gave one
   folder per kind of export. Stored as settings so the links can be changed
   without touching code (shared helper, also used by the Drive buttons). */
vts_ensure_drive_settings($conn);
vts_ensure_college_osa_columns($conn);

$count = $conn->query("SELECT COUNT(*) FROM system_settings")->fetchColumn();
if ($count == 0) {
    $conn->exec("INSERT INTO system_settings
        (school_name, school_address, school_email, contact_number, academic_year, semester, max_points)
        VALUES ('Golden West Colleges, Inc.', 'Alaminos, Pangasinan', '', '', '2026-2027', '1st Semester', 100)");
}

if ($_SERVER['REQUEST_METHOD'] === "POST") {
    if (!csrf_verify()) { vts_csrf_fail('save those settings'); }
    try {
        $update = $conn->prepare("
            UPDATE system_settings SET
                school_name=:school, school_address=:address, school_email=:email,
                contact_number=:contact, academic_year=:year, semester=:semester, max_points=:points,
                reports_drive_url=:dreports, violations_drive_url=:dviol, students_drive_url=:dstud,
                marshal_drive_url=:dmarshal
            WHERE id=1");
        $update->execute([
            ':school'   => trim($_POST['school_name'] ?? ''),
            ':address'  => trim($_POST['school_address'] ?? ''),
            ':email'    => trim($_POST['school_email'] ?? ''),
            ':contact'  => trim($_POST['contact_number'] ?? ''),
            ':year'     => trim($_POST['academic_year'] ?? ''),
            ':semester' => $_POST['semester'] ?? '1st Semester',
            ':points'   => (int)($_POST['max_points'] ?? 100),
            ':dreports' => trim($_POST['reports_drive_url'] ?? ''),
            ':dviol'    => trim($_POST['violations_drive_url'] ?? ''),
            ':dstud'    => trim($_POST['students_drive_url'] ?? ''),
            ':dmarshal' => trim($_POST['marshal_drive_url'] ?? ''),
        ]);
        $success = "Settings updated successfully.";
    } catch (PDOException $e) {
      error_log('Admin settings update failed: ' . $e->getMessage());
      $error = 'Settings could not be saved. Please try again.';
    }
}

/* ---- Department OSA contacts: REMOVED ----

   This form set colleges.osa_email / osa_name, and told the reader that the
   Guard/Marshal daily report would send that department's violations to the
   address instead of the general OSA inbox.

   Nothing in the codebase ever did that. The two columns were written only by
   this form and read by nothing at all — no mailer, no report, no scan path —
   so the control promised routing the system does not perform. All four
   departments were still blank, so no one had relied on it.

   The COLUMNS are deliberately left in place (vts_ensure_college_osa_columns()
   still provisions them). Dropping them is a separate decision, and keeping
   them means the per-department routing can be built later without a
   migration. What is gone is the screen that claimed it already worked. */

$settings = $conn->query("SELECT * FROM system_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
/* The colleges query that used to sit here fed the Department OSA Contacts
   panel and nothing else, so it went with it. */

$adminActive = 'setting';
include "../includes/admin_header.php";
?>

<div class="admin-welcome-row">
  <div><h1>System Settings</h1><p>Configure institutional details and violation thresholds.</p></div>
</div>

<?php if($success): ?>
  <div class="alert alert-success u-mb-14"><i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if($error): ?>
  <div class="alert alert-error u-mb-14"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php /* The duty controls below POST away to toggle_scanning.php /
         save_duty_hours.php / sign_off_duty.php, which redirect back here with
         the outcome in the query string — so the page has to be able to say
         what happened, not only report on its own form. */ ?>
<?php if(isset($_GET['success'])): ?>
  <div class="alert alert-success u-mb-14" role="status"><i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($_GET['success']); ?></div>
<?php endif; ?>
<?php if(isset($_GET['error'])): ?>
  <div class="alert alert-error u-mb-14" role="alert"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($_GET['error']); ?></div>
<?php endif; ?>

<?php /* Scanning on/off, the duty hours and the live slot roster — moved off
         the sidebar, where it was repeated on every page for a thing that is
         changed a few times a term. */ ?>
<?php include "../includes/on_duty_panel.php"; ?>

<?php /* No max-width here any more. It used to be capped at 760px, so
         collapsing the sidebar just grew the empty gutter to the right of the
         card instead of the card — every other admin page's card fills the
         content column, and now this one does too. `is-panel` rather than
         `table-responsive` because there is no table in here to scroll. */ ?>
<div class="recent-table-wrap is-panel">
  <form method="POST" class="settings-form">
<?php echo csrf_field(); ?>
    <fieldset class="vts-fieldset">
      <legend>Institution</legend>
      <p class="vts-fieldset-hint">These details head every printed slip, notice and export.</p>
    <div class="form-grid">
      <div class="field">
        <label for="school_name">School Name <span class="req">*</span></label>
        <input id="school_name" type="text" name="school_name" class="vts-input" required value="<?php echo htmlspecialchars($settings['school_name'] ?? ''); ?>">
      </div>
      <div class="field">
        <label for="school_email">School Email</label>
        <input id="school_email" type="email" name="school_email" class="vts-input" value="<?php echo htmlspecialchars($settings['school_email'] ?? ''); ?>">
      </div>
      <div class="field">
        <label for="contact_number">Contact Number</label>
        <input id="contact_number" type="tel" name="contact_number" class="vts-input"
               inputmode="tel" maxlength="20" pattern="[0-9 ()+-]{7,20}"
               title="Digits, spaces, brackets, + and - only" placeholder="(075) 000 0000"
               value="<?php echo htmlspecialchars($settings['contact_number'] ?? ''); ?>">
      </div>
      <div class="field full">
        <label for="school_address">School Address</label>
        <textarea id="school_address" name="school_address" class="vts-input" rows="2"><?php echo htmlspecialchars($settings['school_address'] ?? ''); ?></textarea>
      </div>
    </div>
    </fieldset>

    <fieldset class="vts-fieldset">
      <legend>Current term</legend>
      <p class="vts-fieldset-hint">New violation records are stamped with the term set here.</p>
    <div class="form-grid">
      <div class="field">
        <label for="academic_year">Academic Year <span class="req">*</span></label>
        <input id="academic_year" type="text" name="academic_year" class="vts-input" required value="<?php echo htmlspecialchars($settings['academic_year'] ?? ''); ?>">
      </div>
      <div class="field">
        <label for="semester">Semester</label>
        <select id="semester" name="semester" class="vts-select">
          <?php foreach(['1st Semester','2nd Semester','Summer'] as $sem): ?>
            <option value="<?php echo $sem; ?>" <?php echo ($settings['semester']??'')===$sem?'selected':''; ?>><?php echo $sem; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    </fieldset>

    <?php /* This group used to be a bordered <div> with a bold label standing in
             for a heading. It is a real <fieldset> now, so the grouping is in
             the markup rather than only in the styling. */ ?>
    <fieldset class="vts-fieldset">
      <legend>Google Drive folders</legend>
      <p class="vts-fieldset-hint">
        Where each exported Excel file gets filed. The buttons on Reports, Violations and Students
        open these folders in a new tab so you can drop the downloaded file straight in.
      </p>
    <div class="form-grid">
      <div class="field">
        <label for="reports_drive_url">Reports folder</label>
        <input id="reports_drive_url" type="url" name="reports_drive_url" class="vts-input" placeholder="https://drive.google.com/drive/folders/…"
               value="<?php echo htmlspecialchars($settings['reports_drive_url'] ?? ''); ?>">
      </div>
      <div class="field">
        <label for="violations_drive_url">Violations folder</label>
        <input id="violations_drive_url" type="url" name="violations_drive_url" class="vts-input" placeholder="https://drive.google.com/drive/folders/…"
               value="<?php echo htmlspecialchars($settings['violations_drive_url'] ?? ''); ?>">
      </div>
      <div class="field">
        <label for="students_drive_url">Student lists folder (per course)</label>
        <input id="students_drive_url" type="url" name="students_drive_url" class="vts-input" placeholder="https://drive.google.com/drive/folders/…"
               value="<?php echo htmlspecialchars($settings['students_drive_url'] ?? ''); ?>">
      </div>
      <div class="field">
        <label for="marshal_drive_url">Guard/Marshal daily reports folder</label>
        <input id="marshal_drive_url" type="url" name="marshal_drive_url" class="vts-input" placeholder="https://drive.google.com/drive/folders/…"
               value="<?php echo htmlspecialchars($settings['marshal_drive_url'] ?? ''); ?>">
      </div>
    </div>
    </fieldset>

    <div class="u-actions u-mt-14">
      <button type="submit" class="btn-primary"><i class="fas fa-floppy-disk"></i> Save Settings</button>
      <a href="dashboard.php" class="btn-outline">Back</a>
    </div>
  </form>
</div>


<?php include "../includes/admin_footer.php"; ?>
