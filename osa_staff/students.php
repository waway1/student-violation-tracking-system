<?php
/* OSA Staff: student list with search, CRUD and import/export. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (($_SESSION['role'] ?? '') !== "OSA Staff") {
    vts_deny_access();
}

/* ---- Ensure the roster table exists ---- */
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS student_roster (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        school_id VARCHAR(10) NOT NULL UNIQUE,
        lastname VARCHAR(60) NOT NULL,
        firstname VARCHAR(60) NULL,
        course VARCHAR(150) NULL,
        year_level VARCHAR(20) NULL,
        is_used TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_roster_lastname (lastname)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

/* ---- Roster actions: import (.xlsx/CSV/pasted rows), delete, unregister, clear all ---- */
$rosterNotice  = '';
$rosterErrNote = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['roster_do'])) {
    if (!csrf_verify()) {
        $rosterErrNote = "Session expired. Please try again.";
    } else {
        $rdo = $_POST['roster_do'];

        // ---- Import (.xlsx file, CSV file, OR pasted rows) ----
        if ($rdo === 'import') {
            $dataRows = null;
            $uploadName = strtolower($_FILES['roster_file']['name'] ?? '');
            if (!empty($_FILES['roster_file']['tmp_name']) && is_uploaded_file($_FILES['roster_file']['tmp_name'])
                && substr($uploadName, -5) === '.xlsx') {
                $dataRows = vts_xlsx_read_rows($_FILES['roster_file']['tmp_name']);
            }
            if ($dataRows === null) {
                $raw = trim($_POST['roster_rows'] ?? '');
                if (!empty($_FILES['roster_file']['tmp_name']) && is_uploaded_file($_FILES['roster_file']['tmp_name'])) {
                    $raw = file_get_contents($_FILES['roster_file']['tmp_name']);
                }
                $raw = str_replace(["\r\n", "\r"], "\n", (string)$raw);
                $lines = array_filter(array_map('trim', explode("\n", $raw)), fn($l) => $l !== '');
                $dataRows = array_map(fn($l) => array_map('trim', preg_split('/[\t,]/', $l)), $lines);
            }

            $ins = $conn->prepare("INSERT INTO student_roster (school_id, lastname, firstname, course, year_level)
                                   VALUES (:sid, :ln, :fn, :co, :yr)
                                   ON DUPLICATE KEY UPDATE lastname=VALUES(lastname),
                                       firstname=VALUES(firstname), course=VALUES(course), year_level=VALUES(year_level)");
            $userChk = $conn->prepare("SELECT id FROM users WHERE student_id = :sid");
            $added = 0; $skipped = 0; $dupUsers = 0;
            foreach ($dataRows as $i => $c) {
                $c = array_map('trim', array_map('strval', $c));
                $sid = $c[0] ?? ''; $ln = $c[1] ?? '';
                if ($i === 0 && (stripos($sid, 'school') !== false || stripos($ln, 'last') !== false)) { continue; }
                if ($sid === '' || $ln === '') { $skipped++; continue; }
                if (strlen($sid) > 10) { $skipped++; continue; }
                $userChk->execute([':sid' => $sid]);
                if ($userChk->fetch()) { $dupUsers++; continue; } // already a real registered student — don't touch the roster
                try {
                    $ins->execute([
                        ':sid' => $sid, ':ln' => $ln,
                        ':fn' => ($c[2] ?? '') !== '' ? $c[2] : null,
                        ':co' => ($c[3] ?? '') !== '' ? $c[3] : null,
                        ':yr' => ($c[4] ?? '') !== '' ? $c[4] : null,
                    ]);
                    $added++;
                } catch (Throwable $e) { $skipped++; }
            }
            $rosterNotice = "Imported $added student" . ($added === 1 ? '' : 's') .
                      ($skipped ? " · skipped $skipped invalid/blank row" . ($skipped === 1 ? '' : 's') : '') .
                      ($dupUsers ? " · skipped $dupUsers already-registered School ID" . ($dupUsers === 1 ? '' : 's') : '') . ".";
            audit_log($conn, "Import Roster", "student_roster", null, "added $added, skipped $skipped, already-registered $dupUsers");
        }

        // ---- Delete one ----
        if ($rdo === 'delete' && !empty($_POST['id'])) {
            $conn->prepare("DELETE FROM student_roster WHERE id = :id")->execute([':id' => (int)$_POST['id']]);
            $rosterNotice = "Removed from the enrolled list.";
        }

        // ---- Unregister (reset the claim so the School ID can register again) ----
        if ($rdo === 'unregister' && !empty($_POST['id'])) {
            $conn->prepare("UPDATE student_roster SET is_used = 0 WHERE id = :id")->execute([':id' => (int)$_POST['id']]);
            $rosterNotice = "Marked as not yet registered — this School ID can be used to register again.";
        }

        // ---- Clear all ----
        if ($rdo === 'clear_all') {
            $conn->exec("DELETE FROM student_roster");
            $rosterNotice = "Enrolled list cleared. (Registration validation is now OFF until you upload a list again.)";
        }
    }
}

$search = isset($_GET['search']) ? trim($_GET['search']) : "";
$limit  = 10;
$page   = (isset($_GET['page']) && is_numeric($_GET['page'])) ? max(1,(int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

try {
    if ($search !== "") {
        $countStmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE role='Student'
            AND (student_id LIKE :s OR fullname LIKE :s OR course LIKE :s)");
        $countStmt->execute([':s' => "%$search%"]);
        $totalRows = $countStmt->fetchColumn();

        $stmt = $conn->prepare("SELECT * FROM users WHERE role='Student'
            AND (student_id LIKE :s OR fullname LIKE :s OR course LIKE :s)
            ORDER BY course ASC, year_level ASC, fullname ASC LIMIT :o, :l");
        $stmt->bindValue(':s', "%$search%", PDO::PARAM_STR);
        $stmt->bindValue(':o', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
        $stmt->execute();
    } else {
        $totalRows = $conn->query("SELECT COUNT(*) FROM users WHERE role='Student'")->fetchColumn();
        $stmt = $conn->prepare("SELECT * FROM users WHERE role='Student' ORDER BY course ASC, year_level ASC, fullname ASC LIMIT :o, :l");
        $stmt->bindValue(':o', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
        $stmt->execute();
    }
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  error_log('OSA students query failed: ' . $e->getMessage());
  die("The student list is temporarily unavailable. Please try again later.");
}
$totalPages = ceil($totalRows / $limit);
/* The on-duty count used to be read here and printed in the subtitle below,
   pointing at a sidebar panel that no longer exists — the roster lives on the
   Settings page now. A student page does not need to report on the gate, so
   the query went with the sentence. */

/* The Drive folder the student lists are filed into, for the "open the
   folder" item in the Export menu. Loaded here rather than inline so the
   menu stays markup. */
require_once "../config/drive.php";
require_once "../includes/drive.php";
$studentsDriveUrl = vts_drive_folder_url('students');

$assetBase = "../";
include "../includes/header.php";
include "../includes/navbar.php";
include "../includes/sidebar.php";
?>
<main class="vts-main">
<div class="vts-main-inner">

<div class="admin-welcome-row">
  <div><h1>Student Management</h1><p>Add, edit and export the student roster.</p></div>
  <div class="u-actions">
    <a href="add_student.php" class="btn-primary"><i class="fas fa-plus"></i> Add Student</a>
    <!-- One Export button. These were two outline buttons side by side whose
         labels gave no clue which one a given task wanted. -->
    <div class="vts-menu" id="studentExportMenu">
      <button type="button" class="btn-outline" onclick="vtsMenu(event,'studentExportMenu')"
              aria-haspopup="true" aria-expanded="false">
        <i class="fas fa-file-export"></i> Export <i class="fas fa-chevron-down vm-caret"></i>
      </button>
      <div class="vts-menu-list" role="menu">
        <a href="export_students.php" role="menuitem" data-busy>
          <i class="fas fa-file-shield"></i>
          <span class="vm-label">For the phone scanner
            <span class="vm-sub">Encrypted .vtsl &mdash; copy by USB, then import on the phone</span></span>
        </a>
        <a href="export_students_excel.php" role="menuitem" data-busy>
          <i class="fas fa-file-excel"></i>
          <span class="vm-label">Excel copy
            <span class="vm-sub">Plain spreadsheet saved to this computer</span></span>
        </a>
        <?php /* Uploading straight to Drive needs a Google Workspace account (a
                 service account gets no Drive storage of its own), so that option
                 was removed rather than left as a button that always failed.
                 Download the file, then drop it in the folder below. */ ?>
        <a href="<?php echo htmlspecialchars($studentsDriveUrl); ?>" target="_blank" rel="noopener" role="menuitem">
          <i class="fas fa-folder-open"></i>
          <span class="vm-label">Open the Drive folder
            <span class="vm-sub">See what has been filed there</span></span>
        </a>
      </div>
    </div>
    <form method="POST" action="students.php?tab=roster" enctype="multipart/form-data" id="rosterImportForm" style="display:inline-flex;margin:0;">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="roster_do" value="import">
      <input type="file" name="roster_file" id="roster_file" class="sr-only"
             accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,.csv,text/csv,text/plain"
             required>
      <label for="roster_file" class="btn-outline u-mb-0 u-pointer" title="Accepts Excel (.xlsx) or CSV"><i class="fas fa-file-import"></i> Import Student List</label>
      <!-- Real submit control. The change handler below sends the form as soon
           as a file is picked and then hides this, so it is only ever seen when
           scripting is unavailable — without it the form could not be sent at all. -->
      <button type="submit" id="rosterImportGo" class="btn-primary u-mb-0" style="margin-left:8px;"><i class="fas fa-upload"></i> Upload</button>
    </form>
  </div>
</div>
<script>
(function(){
  var input = document.getElementById('roster_file');
  var form  = document.getElementById('rosterImportForm');
  var go    = document.getElementById('rosterImportGo');
  var label = document.querySelector('label[for="roster_file"]');
  if (!input || !form) return;

  // Scripting is on, so picking a file is enough — the explicit Upload button
  // is only the no-JS fallback and would just be a second step here.
  if (go) go.style.display = 'none';

  function markBusy(){
    if (label){
      // The .is-busy rule hides this <i> and draws a spinner in its place,
      // so the icon slot becomes the spinner without shifting the layout.
      label.classList.add('is-busy');
      label.innerHTML = '<i class="fas fa-file-import"></i> Importing student list…';
    }
  }
  input.addEventListener('change', function(){
    if (this.files && this.files.length){ markBusy(); form.submit(); }
  });
  // Covers the no-JS button being used before this script ran, and gives the
  // same "working on it" feedback for a large roster upload.
  form.addEventListener('submit', markBusy);
})();
</script>

<?php if(isset($_GET['success'])): ?>
  <div class="alert alert-success u-mb-14"><i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($_GET['success']); ?></div>
<?php endif; ?>
<?php if(isset($_GET['error'])): ?>
  <div class="alert alert-error u-mb-14"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($_GET['error']); ?></div>
<?php endif; ?>

<?php if ($rosterNotice): ?>
  <div class="alert alert-success u-mb-14"><i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($rosterNotice); ?></div>
<?php endif; ?>
<?php if ($rosterErrNote): ?>
  <div class="alert alert-error u-mb-14"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($rosterErrNote); ?></div>
<?php endif; ?>

<div id="registered-section">

<div class="recent-table-wrap table-responsive">
  <form method="GET" class="filter-bar wrap">
    <div class="filter-field grow">
      <label for="search">Search</label>
      <input id="search" type="text" name="search" class="vts-input" placeholder="Search by ID, name, or course" value="<?php echo htmlspecialchars($search); ?>">
    </div>
    <div class="filter-actions">
      <button class="btn-primary btn-sm" type="submit"><i class="fas fa-magnifying-glass"></i> Search</button>
      <a href="students.php" class="btn-outline btn-sm"><i class="fas fa-rotate"></i> Reset</a>
    </div>
  </form>

  <div class="table-scroll table-responsive">
  <table class="data-table">
    <thead><tr><th>ID</th><th>Student ID</th><th>Full Name</th><th>Course</th><th>Year</th><th>Section</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>
    <?php if(count($students) > 0): foreach($students as $row): ?>
      <tr>
        <td><?php echo $row['id']; ?></td>
        <td><?php echo htmlspecialchars($row['student_id'] ?? '-'); ?></td>
        <td>
          <?php $sp = trim($row['profile_picture'] ?? ''); ?>
          <span style="display:inline-flex;align-items:center;gap:10px;">
            <?php if ($sp !== ''): ?>
              <img alt="" src="../uploads/profile/<?php echo htmlspecialchars($sp); ?>"
style="width:34px;height:34px;border-radius:50%;object-fit:cover;flex-shrink:0;border:1.5px solid var(--border-soft);">
            <?php else: ?>
              <span style="width:34px;height:34px;border-radius:50%;flex-shrink:0;display:inline-flex;
                     align-items:center;justify-content:center;background:var(--surface-tint);color:var(--navy);font-size:.95rem;">
                <i class="fas fa-user-graduate"></i></span>
            <?php endif; ?>
            <span><?php echo htmlspecialchars($row['fullname']); ?></span>
          </span>
        </td>
        <td><?php echo htmlspecialchars($row['course'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($row['year_level'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($row['section'] ?? '-'); ?></td>
        <td><span class="pill <?php echo ($row['status']==='Active'?'resolved':'atrisk'); ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
        <td class="u-nowrap">
          <?php /* Points at the read-only record now. The icon said "view"
                   and opened the edit form, which is the kind of mismatch
                   nobody notices until something has been changed. */ ?>
          <a href="../admin/view_student.php?id=<?php echo $row['id']; ?>" class="btn-outline btn-sm" title="View this student's record"><i class="fas fa-eye"></i></a>
          <?php if (isset($row['email_verified']) && (int)$row['email_verified'] === 0): ?>
          <form method="POST" action="verify_student.php" class="inline-form" onsubmit="return confirm('Manually mark this student\'s email as verified? Use this only if the OTP email could not be delivered.');"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo $row['id']; ?>"><button type="submit" class="btn-sm" style="background:#e8a400;color:#fff;" title="Email not verified — click to verify manually"><i class="fas fa-envelope-circle-check"></i></button></form>
          <?php endif; ?>
          <!-- OSA Staff can't delete students — ask OSA or Admin. -->
        </td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="8" class="table-empty">No students found.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>

  <?php /* Was a link per page, which stops being usable somewhere past a
           few hundred students. vts_pager() shows a window around the
           current page and keeps the ends reachable. */ ?>
  <?php echo vts_pager($page, $totalPages, $_GET, 'student'); ?>
</div>
</div><!-- End registered-section -->


<?php /* The app shell (.vts-main-inner + <main>) is closed by
         includes/footer.php below -- closing it here as well emitted a
         stray </div></main> on every one of these pages. */ ?>
<?php include "../includes/footer.php"; ?>
