<?php
/* OSA Staff: form + handler to add a new student record (mirrors admin/add_student.php). */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (($_SESSION['role'] ?? '') !== "OSA Staff") {
    vts_deny_access();
}

/* ---- Ensure the roster table exists (same shape as students.php / edit_roster.php) ---- */
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS student_roster (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        school_id VARCHAR(10) NOT NULL UNIQUE,
        lastname VARCHAR(60) NOT NULL,
        firstname VARCHAR(60) NULL,
        middlename VARCHAR(60) NULL,
        course VARCHAR(150) NULL,
        year_level VARCHAR(20) NULL,
        is_used TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_roster_lastname (lastname)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}
// Older installs created the table without middlename — add it once, ignore if there.
try { $conn->exec("ALTER TABLE student_roster ADD COLUMN middlename VARCHAR(60) NULL AFTER firstname"); }
catch (Throwable $e) {}

$error = "";

// College + course pickers (course list is filtered by the chosen college).
$colleges = $conn->query("SELECT id, college_name FROM colleges ORDER BY college_name")->fetchAll(PDO::FETCH_ASSOC);
// Courses are shown (and stored) by their short code — BSIT, BSBA, BSED, BSCRIM…
$courseList = $conn->query(
    "SELECT c.id, c.course_name, c.short_name, c.college_id
       FROM courses c ORDER BY c.short_name, c.course_name")->fetchAll(PDO::FETCH_ASSOC);

/* "Add Student" only proves a School ID belongs to an actual enrolled student —
   it adds JUST the ID to the enrollment roster, the SAME record register.php
   checks against. It never creates a login account: students only get one by
   registering themselves (auth/register_process.php), where they fill in
   their own name and details. Need to attach a name/course now? Use Edit
   on the roster entry, or Import Student List for a full roster upload. */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_verify()) { vts_csrf_fail('add that student'); }
    // School IDs are numeric; names are stored the way they're typed (UPPERCASE).
    $school_id  = trim($_POST['school_id'] ?? '');
    $lastname   = mb_strtoupper(trim($_POST['lastname'] ?? ''));
    $firstname  = mb_strtoupper(trim($_POST['firstname'] ?? ''));
    $middlename = mb_strtoupper(trim($_POST['middlename'] ?? ''));
    $suffix     = trim($_POST['suffix'] ?? '');
    $year       = trim($_POST['year_level'] ?? '');
    $course_id  = trim($_POST['course_id'] ?? '');
    if ($suffix !== '' && !in_array($suffix, ['Jr.','Sr.','II','III','IV','V'], true)) $suffix = '';

    // Course carries the college with it (the College picker just filters the list).
    $course = ''; $college_id = null;
    if (ctype_digit($course_id)) {
        $cq = $conn->prepare("SELECT course_name, short_name, college_id FROM courses WHERE id = :id");
        $cq->execute([':id' => (int)$course_id]);
        if ($cRow = $cq->fetch(PDO::FETCH_ASSOC)) {
            // Store the short code (BSIT) — that's what the lists and exports show.
            $course     = $cRow['short_name'] !== '' ? $cRow['short_name'] : $cRow['course_name'];
            $college_id = $cRow['college_id'] !== null ? (int)$cRow['college_id'] : null;
        }
    }

    if ($school_id === '') {
        $error = "School ID is required.";
    } elseif (!ctype_digit($school_id)) {
        $error = "School ID must be numbers only.";
    } elseif (strlen($school_id) > 10) {
        $error = "School ID must be 10 digits or fewer.";
    } else {
        $u = $conn->prepare("SELECT id FROM users WHERE student_id = :v");
        $u->execute([':v' => $school_id]);
        if ($u->fetch()) {
            $error = "School ID \"{$school_id}\" already has a student account.";
        } else {
            try {
                // Upsert the enrollment roster row (so it shows on the enrolled list).
                $rc = $conn->prepare("SELECT id FROM student_roster WHERE school_id = :v");
                $rc->execute([':v' => $school_id]);
                if ($rid = $rc->fetchColumn()) {
                    $conn->prepare("UPDATE student_roster SET lastname=:ln, firstname=:fn, middlename=:mn, course=:c, year_level=:y WHERE id=:id")
                         ->execute([':ln'=>$lastname, ':fn'=>($firstname ?: null), ':mn'=>($middlename ?: null), ':c'=>($course ?: null), ':y'=>($year ?: null), ':id'=>$rid]);
                } else {
                    $conn->prepare("INSERT INTO student_roster (school_id, lastname, firstname, middlename, course, year_level) VALUES (:s,:ln,:fn,:mn,:c,:y)")
                         ->execute([':s'=>$school_id, ':ln'=>$lastname, ':fn'=>($firstname ?: null), ':mn'=>($middlename ?: null), ':c'=>($course ?: null), ':y'=>($year ?: null)]);
                }

                // Auto-provision the scannable account + QR NOW (no registration needed).
                $prov = vts_provision_student_account($conn, $school_id, [
                    'lastname' => $lastname, 'firstname' => $firstname,
                    'middlename' => $middlename, 'suffix' => $suffix, 'year_level' => $year,
                    'course' => $course, 'college_id' => $college_id,
                ]);
                audit_log($conn, "Enroll Student", "users", $prov['id'], $school_id . ' — account + QR provisioned');
                header("Location: students.php?success=" . urlencode("Student enrolled — account created and QR generated. They can be scanned right away, no registration needed."));
                exit();
            } catch (PDOException $e) { error_log('OSA add student failed: ' . $e->getMessage()); $error = 'The student could not be saved. Please check the details and try again.'; }
        }
    }
}

$assetBase = "../";
include "../includes/header.php";
include "../includes/navbar.php";
include "../includes/sidebar.php";
?>
<main class="vts-main">
<div class="vts-main-inner">

<div class="admin-welcome-row">
  <div><h1>Enroll Student</h1><p>Creates the account + QR instantly — scannable right away, no registration.</p></div>
  <a href="students.php" class="btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<?php if($error): ?>
  <div class="alert alert-error u-mb-14"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="recent-table-wrap table-responsive" style="max-width:640px;">
  <form method="POST">
<?php echo csrf_field(); ?>
    <fieldset class="vts-fieldset">
      <legend>Who the student is</legend>
      <p class="vts-fieldset-hint">The School ID is what the gate scanner matches on, so it has to be right.</p>
    <div class="form-grid">
      <div class="field"><label for="school_id">School ID <span class="req">*</span></label>
        <input id="school_id" type="text" name="school_id" class="vts-input js-digits" maxlength="10" required autofocus
               inputmode="numeric" pattern="[0-9]{1,10}" placeholder="Numbers only, up to 10"
               title="Numbers only, up to 10 digits" value="<?php echo htmlspecialchars($_POST['school_id'] ?? ''); ?>" data-allow="digits"></div>
      <div class="field"><label for="lastname">Last Name</label>
        <input id="lastname" type="text" name="lastname" class="vts-input js-upper u-upper" maxlength="60" value="<?php echo htmlspecialchars($_POST['lastname'] ?? ''); ?>" data-allow="letters" pattern="[A-Za-zÀ-ɏÑñ .'\-]{2,60}" title="Letters, spaces, hyphens, apostrophes and periods only."></div>
      <div class="field"><label for="firstname">First Name</label>
        <input id="firstname" type="text" name="firstname" class="vts-input js-upper u-upper" maxlength="60" value="<?php echo htmlspecialchars($_POST['firstname'] ?? ''); ?>" data-allow="letters" pattern="[A-Za-zÀ-ɏÑñ .'\-]{2,60}" title="Letters, spaces, hyphens, apostrophes and periods only."></div>
      <div class="field"><label for="middlename">Middle Name</label>
        <input id="middlename" type="text" name="middlename" class="vts-input js-upper u-upper" maxlength="60" placeholder="Optional" value="<?php echo htmlspecialchars($_POST['middlename'] ?? ''); ?>" data-allow="letters" pattern="[A-Za-zÀ-ɏÑñ .'\-]{2,60}" title="Letters, spaces, hyphens, apostrophes and periods only."></div>
      <div class="field"><label for="suffix">Suffix</label>
        <select id="suffix" name="suffix" class="vts-select">
          <option value="">Suffix (optional)</option>
          <?php foreach (['Jr.','Sr.','II','III','IV','V'] as $sf): ?>
            <option value="<?php echo $sf; ?>" <?php if (($_POST['suffix'] ?? '') === $sf) echo 'selected'; ?>><?php echo $sf; ?></option>
          <?php endforeach; ?>
        </select></div>
    </div>
    </fieldset>

    <fieldset class="vts-fieldset">
      <legend>Where they are enrolled</legend>
      <p class="vts-fieldset-hint">Pick the college first — it narrows the course list below.</p>
    <div class="form-grid">
      <div class="field"><label for="collegeSelect">College</label>
        <select id="collegeSelect" class="vts-select">
          <option value="">All colleges</option>
          <?php foreach ($colleges as $col): ?>
            <option value="<?php echo (int)$col['id']; ?>" <?php if ((string)($_POST['college_filter'] ?? '') === (string)$col['id']) echo 'selected'; ?>><?php echo htmlspecialchars($col['college_name']); ?></option>
          <?php endforeach; ?>
        </select>
        <input type="hidden" name="college_filter" id="collegeFilterValue" value="<?php echo htmlspecialchars($_POST['college_filter'] ?? ''); ?>"></div>
      <div class="field"><label for="courseSelect">Course</label>
        <select name="course_id" id="courseSelect" class="vts-select">
          <option value="">Select course</option>
          <?php foreach ($courseList as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>" data-college="<?php echo (int)$c['college_id']; ?>"
              title="<?php echo htmlspecialchars($c['course_name']); ?>"
              <?php if ((string)($_POST['course_id'] ?? '') === (string)$c['id']) echo 'selected'; ?>><?php echo htmlspecialchars($c['short_name'] !== '' ? $c['short_name'] : $c['course_name']); ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label for="year_level">Year Level</label>
        <select id="year_level" name="year_level" class="vts-select">
          <option value="">Select year level</option>
          <?php foreach (['1st Year','2nd Year','3rd Year','4th Year','5th Year'] as $yl): ?>
            <option <?php if (($_POST['year_level'] ?? '') === $yl) echo 'selected'; ?>><?php echo $yl; ?></option>
          <?php endforeach; ?>
        </select></div>
    </div>
    </fieldset>
    <script>
    /* Choosing a college narrows the course list; the course itself is what's
       saved (it carries its own college). */
    (function(){
      var col = document.getElementById('collegeSelect'),
          crs = document.getElementById('courseSelect'),
          keep = document.getElementById('collegeFilterValue');
      function paint(){
        var want = col.value;
        keep.value = want;
        Array.prototype.forEach.call(crs.options, function(o){
          if (!o.value) return;
          var show = !want || o.getAttribute('data-college') === want;
          o.hidden = !show; o.disabled = !show;
        });
        if (crs.selectedOptions[0] && crs.selectedOptions[0].disabled) crs.value = '';
      }
      col.addEventListener('change', paint); paint();
    })();
    </script>
    <p style="color:var(--text-muted);font-size:.85rem;margin-top:10px;">
      Only the School ID is required. Students sign in with their name + School ID — <b>no password</b>.
    </p>
    <div class="u-actions u-mt-14">
      <button type="submit" class="btn-primary"><i class="fas fa-user-check"></i> Enroll &amp; Generate QR</button>
      <a href="students.php" class="btn-outline">Cancel</a>
    </div>
  </form>
</div>

<?php /* The app shell (.vts-main-inner + <main>) is closed by
         includes/footer.php below -- closing it here as well emitted a
         stray </div></main> on every one of these pages. */ ?>
<?php include "../includes/footer.php"; ?>
