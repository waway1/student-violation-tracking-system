<?php
/* OSA Staff: violation list with search and management. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (($_SESSION['role'] ?? '') !== "OSA Staff") {
    vts_deny_access();
}

$osaId = (int)($_SESSION['user_id'] ?? 0);
$scanImport = null;   // result of an offline-scan import done on this page

// Import offline scans (CSV/XLSX/JSON) right here on the Violations page.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_import_scans'])) {
    if (!csrf_verify()) {
        $scanImport = ['ok' => false, 'error' => 'Session expired. Please try again.'];
    } elseif (!isset($_FILES['scans_file']) || $_FILES['scans_file']['error'] !== UPLOAD_ERR_OK) {
        $ue = $_FILES['scans_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        $scanImport = ['ok' => false, 'error' => in_array($ue, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'That file is too large for this server to accept. Please export a smaller range or use CSV instead.'
            : 'Please choose a scanner file (.xlsx, .csv, or .json) first.'];
    } else {
        try {
            $scanImport = import_scan_file($conn, $_FILES['scans_file']['tmp_name'],
                                           $_FILES['scans_file']['name'] ?? '', $osaId);
        } catch (Throwable $e) {
            error_log('Import Scans failed: ' . $e->getMessage());
          $scanImport = ['ok' => false, 'error' => 'That file could not be imported. Check the file and try again.'];
        }
        log_import($conn, $osaId, $_FILES['scans_file']['name'] ?? '', $scanImport);
    }
}

// Clear the "Recent file imports" history (does not touch imported violations).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_clear_import_logs'])) {
    if (!csrf_verify()) {
        vts_redirect_back('violations.php', 'error', 'Session expired. Please try again.');
    } else {
        clear_import_logs($conn);
        vts_redirect_back('violations.php', 'success', 'Import history cleared.');
    }
    exit();
}

$search     = is_string($_GET['search'] ?? null) ? trim($_GET['search']) : "";
$when       = is_string($_GET['when'] ?? null) && in_array($_GET['when'], ['all', 'today', 'yesterday', '7days', 'month'], true) ? $_GET['when'] : 'all';
$from       = is_string($_GET['from'] ?? null) ? trim($_GET['from']) : '';
$to         = is_string($_GET['to'] ?? null) ? trim($_GET['to']) : '';
$yearLevel  = is_string($_GET['year_level'] ?? null) ? trim($_GET['year_level']) : '';
$course     = is_string($_GET['course'] ?? null) ? trim($_GET['course']) : '';
$vfilter    = is_string($_GET['violation'] ?? null) ? trim($_GET['violation']) : '';
$section    = is_string($_GET['section'] ?? null) ? trim($_GET['section']) : '';
$college    = is_string($_GET['college_id'] ?? null) ? trim($_GET['college_id']) : '';   // department: CITE / CRIM / COED …
// Same two views as the Admin page: the OSA's official sheet, or the working
// record list with row actions. (Reports is this page's Official Sheet view.)
$view       = (($_GET['view'] ?? 'sheet') === 'records') ? 'records' : 'sheet';

$sql = "
    SELECT v.*, u.student_id AS sid, u.fullname, u.course, u.year_level,
           r.fullname AS reporter_name, r.role AS reporter_role,
           (SELECT COUNT(*) FROM violations vc WHERE vc.student_id = v.student_id) AS student_total
    FROM violations v
    INNER JOIN users u ON v.student_id = u.id
    LEFT JOIN users r ON v.reported_by = r.id
";
$where  = [];
$params = [];
if ($search !== "") {
    $where[] = "(u.student_id LIKE :s OR u.fullname LIKE :s OR v.violation LIKE :s)";
    $params[':s'] = "%{$search}%";
}
if ($yearLevel !== "") {
    $where[] = "u.year_level = :yl";
    $params[':yl'] = $yearLevel;
}
if ($course !== "") {
    $where[] = "u.course = :crs";
    $params[':crs'] = $course;
}
if ($vfilter !== "") {
    $where[] = "v.violation = :vf";
    $params[':vf'] = $vfilter;
}
if ($section !== "") {
    $where[] = "u.section = :sec";
    $params[':sec'] = $section;
}
if ($college !== "") {
    $where[] = "u.college_id = :col";
    $params[':col'] = (int)$college;
}

/* Date filter — when it happened (today / yesterday / etc.) */
$isDate = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
if ($isDate($from) && $isDate($to)) {
    $where[] = "DATE(v.date_reported) BETWEEN :df AND :dt";
    $params[':df'] = $from; $params[':dt'] = $to;
} else {
    switch ($when) {
        case 'today':     $where[] = "DATE(v.date_reported) = CURDATE()"; break;
        case 'yesterday': $where[] = "DATE(v.date_reported) = CURDATE() - INTERVAL 1 DAY"; break;
        case '7days':     $where[] = "v.date_reported >= CURDATE() - INTERVAL 6 DAY"; break;
        case 'month':     $where[] = "YEAR(v.date_reported)=YEAR(CURDATE()) AND MONTH(v.date_reported)=MONTH(CURDATE())"; break;
    }
}
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY v.date_reported DESC";

// Keep current filters when building the export link
$exportQuery = http_build_query(array_filter([
    'search' => $search, 'when' => $when, 'from' => $from, 'to' => $to,
    'year_level' => $yearLevel, 'course' => $course, 'violation' => $vfilter,
    'section' => $section, 'college_id' => $college,
], fn($v) => $v !== '' && $v !== null));

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Official sheet view — same filters, pulled per student and pivoted into the
   OSA's own layout (identical to the Admin page). */
$officialStudents = [];
if ($view === 'sheet') {
    $oSql = "SELECT v.id, u.student_id, u.lastname, u.firstname, u.middlename,
                    u.year_level, u.section, u.course,
                    COALESCE(col.college_name, u.course, '') AS dept,
                    v.violation, v.offense, v.date_reported
             FROM violations v
             INNER JOIN users u ON v.student_id = u.id
             LEFT JOIN users r ON v.reported_by = r.id
             LEFT JOIN colleges col ON col.id = u.college_id";
    if ($where) $oSql .= " WHERE " . implode(" AND ", $where);
    $oSql .= " ORDER BY u.lastname, u.firstname, v.date_reported ASC";
    $oStmt = $conn->prepare($oSql);
    $oStmt->execute($params);
    $officialStudents = vts_official_group_students($oStmt->fetchAll(PDO::FETCH_ASSOC));
}

// Toggle links keep whatever filters are already applied.
$viewQS = $_GET; unset($viewQS['view']);
$linkSheet   = 'violations.php?' . http_build_query(array_merge($viewQS, ['view' => 'sheet']));
$linkRecords = 'violations.php?' . http_build_query(array_merge($viewQS, ['view' => 'records']));

// Options for the Year Level / Course filter dropdowns
$yearLevels = $conn->query("SELECT DISTINCT year_level FROM users WHERE role='Student' AND year_level IS NOT NULL AND year_level <> '' ORDER BY year_level")->fetchAll(PDO::FETCH_COLUMN);
$courses    = $conn->query("SELECT DISTINCT course FROM users WHERE role='Student' AND course IS NOT NULL AND course <> '' ORDER BY course")->fetchAll(PDO::FETCH_COLUMN);
$violationOptions = $conn->query("SELECT DISTINCT violation FROM violations ORDER BY violation")->fetchAll(PDO::FETCH_COLUMN);
$collegeOptions   = $conn->query("SELECT id, college_name FROM colleges ORDER BY college_name")->fetchAll(PDO::FETCH_ASSOC);
$sectionOptions   = $conn->query("SELECT DISTINCT section FROM users WHERE role='Student' AND section IS NOT NULL AND section <> '' ORDER BY section")->fetchAll(PDO::FETCH_COLUMN);

// Recent scan-import history (collapsible panel below) — covers both routes
// the scans arrive by: an offline file handed over, or a live Wi-Fi sync.
$importLogs = [];
try {
    vts_ensure_import_log_table($conn);
    $importLogs = $conn->query("
        SELECT l.filename, l.scanner_name, l.source, l.imported_count, l.skipped_count,
               l.ok, l.created_at
        FROM import_logs l
        ORDER BY l.created_at DESC LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* ignore */ }

$departmentHeading = 'All Departments';
if ($college !== '') {
  $dh = $conn->prepare("SELECT college_name FROM colleges WHERE id = :id");
  $dh->execute([':id' => (int)$college]);
  $departmentHeading = $dh->fetchColumn() ?: 'Selected Department';
}
// Icon + colour for whichever department (or course) is in view.
$deptId = vts_dept_identity($course !== '' ? $course : $departmentHeading);

/* Map a status string to a pill colour class — same mapping as admin's page */
function pillClass($status) {
    $s = strtolower(trim($status));
    if (str_contains($s, 'approve') || str_contains($s, 'resolve')) return 'resolved';
    if (str_contains($s, 'risk'))    return 'atrisk';
    if (str_contains($s, 'osa') || str_contains($s, 'review') || str_contains($s, 'pending')) return 'review';
    if (str_contains($s, 'reject'))  return 'atrisk';
    return 'warning';
}

$assetBase = "../";
include "../includes/header.php";
include "../includes/navbar.php";
include "../includes/sidebar.php";
?>
<main class="vts-main">
<div class="vts-main-inner">

<?php
  // Export target — same filters as the screen, in the official layout.
  $reportQS = http_build_query(array_filter([
      'search' => $search, 'when' => $when, 'from' => $from, 'to' => $to,
      'year_level' => $yearLevel, 'course' => $course, 'section' => $section,
      'violation' => $vfilter, 'college_id' => $college,
  ], fn($v) => $v !== '' && $v !== null));
  $exportUrl = 'export_excel.php' . ($reportQS ? '?' . $reportQS : '');

  /* Same filters, minus any success/error toast param — scans sync in from
     the gate while this page sits open, so it needs a way to catch up. */
  $refreshQS = $reportQS;
  if (($_GET['view'] ?? '') !== '') {
      $refreshQS .= ($refreshQS ? '&' : '') . 'view=' . urlencode($_GET['view']);
  }
  $refreshUrl = 'violations.php' . ($refreshQS ? '?' . $refreshQS : '');
  require_once "../config/drive.php";
  require_once "../includes/drive.php";
  $driveFolder = vts_drive_folder_url('reports');
?>
<div class="admin-welcome-row">
  <div class="dept-head">
    <span class="dept-badge" style="--chip-color:<?php echo $deptId['color']; ?>;"><i class="fas <?php echo $deptId['icon']; ?>"></i></span>
    <div>
      <h1><?php echo htmlspecialchars($departmentHeading); ?>: Violations &amp; Reports</h1>
      <!-- Course + count only. What the two tabs are is explained by the
           tabs themselves; saying it again here was noise. -->
      <div class="dept-sub">
        <?php if ($course !== ''): ?><?php echo vts_course_chip($course, true); ?><?php endif; ?>
        <span style="color:var(--text-muted);font-size:.82rem;">
          <?php echo count($rows); ?> violation record(s)
          &middot; <span id="asOf" title="When this list was loaded">as of <?php echo date('g:i A'); ?></span>
        </span>
      </div>
    </div>
  </div>

  <!-- Export used to be two buttons that both exported the same file. One
       button now; the menu names the two destinations. -->
  <div class="u-actions">
    <a href="<?php echo htmlspecialchars($refreshUrl); ?>" class="btn-outline" id="refreshBtn"
       title="Reload the list with the same filters — picks up scans synced since this page opened">
      <i class="fas fa-rotate"></i> Refresh</a>
    <a href="add_violation.php" class="btn-primary"><i class="fas fa-plus"></i> Add Violation</a>

    <div class="vts-menu" id="exportMenu">
      <button type="button" class="btn-outline" onclick="vtsMenu(event,'exportMenu')" aria-haspopup="true" aria-expanded="false">
        <i class="fas fa-file-export"></i> Export <i class="fas fa-chevron-down vm-caret"></i>
      </button>
      <div class="vts-menu-list" role="menu">
        <div class="vm-head">Uses the filters below</div>
        <a id="exportExcelBtn" href="<?php echo htmlspecialchars($exportUrl); ?>" role="menuitem" data-busy>
          <i class="fas fa-file-excel"></i>
          <span class="vm-label">Download Excel file
            <span class="vm-sub">Official Sheet layout, saved to this computer</span></span>
        </a>
        <a id="uploadDriveBtn" href="<?php echo htmlspecialchars($driveFolder); ?>" target="_blank" rel="noopener"
           data-export="<?php echo htmlspecialchars($exportUrl, ENT_QUOTES); ?>" role="menuitem">
          <i class="fab fa-google-drive"></i>
          <span class="vm-label">Open the Drive folder
            <span class="vm-sub">Download the file first, then drag it into the folder</span></span>
        </a>
      </div>
    </div>
  </div>
</div>

<?php
/* One chip per department — short names, scrolls sideways on a phone. */
$deptChips = [];
try { $deptChips = $conn->query("SELECT id, college_name FROM colleges ORDER BY college_name")->fetchAll(PDO::FETCH_ASSOC); }
catch (Throwable $e) { $deptChips = []; }
$chipBase = $_GET; unset($chipBase['success'], $chipBase['error'], $chipBase['college_id'], $chipBase['return']);
?>
<?php if ($deptChips): ?>
<div class="dept-switch">
  <?php $allQS = http_build_query($chipBase); ?>
  <a href="violations.php<?php echo $allQS ? '?' . $allQS : ''; ?>" class="<?php echo $college === '' ? 'on' : ''; ?>">
    <span class="dept-chip" style="--chip-color:var(--navy);--chip-tint:rgba(26,58,107,.10);"><i class="fas fa-building-columns"></i><span class="chip-text">All departments</span></span>
  </a>
  <?php foreach ($deptChips as $dc):
        $dcId   = vts_dept_identity($dc['college_name']);
        $dcName = preg_replace('/^(Department|College)\s+of\s+/i', '', $dc['college_name']);
        $dcQS   = http_build_query(array_merge($chipBase, ['college_id' => $dc['id']])); ?>
    <a href="violations.php?<?php echo $dcQS; ?>" class="<?php echo $college === (string)$dc['id'] ? 'on' : ''; ?>"
       title="<?php echo htmlspecialchars($dc['college_name']); ?>">
      <span class="dept-chip" style="--chip-color:<?php echo $dcId['color']; ?>;--chip-tint:<?php echo $dcId['tint']; ?>;">
        <i class="fas <?php echo $dcId['icon']; ?>"></i><span class="chip-text"><?php echo htmlspecialchars($dcName); ?></span>
      </span>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<script>
/* The scanner exports Excel, but this server has no zip extension to read an
   .xlsx — so an .xlsx pick is converted to CSV here in the browser (SheetJS,
   loaded on demand) and that CSV is what gets uploaded. .csv/.json go straight up. */
(function(){
  var input = document.getElementById('scansFileInput');
  var form  = document.getElementById('scanImportForm');
  if (!input || !form) return;   // Import Scans button removed on this page

  function withXlsx(cb){
    if (typeof XLSX !== 'undefined') return cb();
    var s = document.createElement('script');
    s.src = '../xlsx.full.min.js';
    s.onload = cb;
    s.onerror = function(){ alert('Could not load the Excel reader. Please try again.'); };
    document.head.appendChild(s);
  }

  input.addEventListener('change', function(){
    if (!this.files || !this.files.length) return;
    var file = this.files[0];
    if (!/\.xlsx$/i.test(file.name)) { form.submit(); return; }

    withXlsx(function(){
      var rd = new FileReader();
      rd.onload = function(){
        try {
          var wb  = XLSX.read(new Uint8Array(rd.result), { type: 'array' });
          var csv = XLSX.utils.sheet_to_csv(wb.Sheets[wb.SheetNames[0]]);
          var dt  = new DataTransfer();
          dt.items.add(new File(['﻿' + csv], file.name.replace(/\.xlsx$/i, '.csv'), { type: 'text/csv' }));
          input.files = dt.files;
          form.submit();
        } catch (e) {
          alert('That Excel file could not be read: ' + e.message);
        }
      };
      rd.readAsArrayBuffer(file);
    });
  });
})();
</script>
<script>
  document.addEventListener('click', function (e) {
    const btn = e.target.closest('.mask-toggle');
    if (!btn) return;
    const wrap = btn.closest('.masked-name-wrap');
    if (!wrap) return;
    const label = wrap.querySelector('.masked-name');
    if (!label) return;
    const full = label.dataset.full || '';
    const hidden = label.dataset.hidden === '1';
    label.dataset.hidden = hidden ? '0' : '1';
    label.textContent = hidden ? full : full.replace(/./g, '•');
    const icon = btn.querySelector('i');
    if (icon) icon.className = hidden ? 'fas fa-eye-slash' : 'fas fa-eye';
  });
</script>

<?php if(isset($_GET['success'])): ?>
  <div class="alert alert-success u-mb-14"><i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($_GET['success']); ?></div>
<?php endif; ?>

<?php if ($scanImport && $scanImport['ok']): $R=$scanImport['results']; ?>
  <div class="alert alert-success u-mb-14">
    <i class="fas fa-circle-check"></i> Imported <strong><?php echo (int)$R['imported']; ?></strong>,
    skipped <strong><?php echo (int)$R['skipped']; ?></strong> (scanner: <?php echo htmlspecialchars($R['scanner']); ?>).
  </div>
  <?php if (!empty($R['lines'])): ?>
    <div class="recent-table-wrap u-mb-14 table-responsive">
      <div style="max-height:170px;overflow:auto;font-size:.82rem;color:var(--text-soft);padding:10px 12px;">
        <?php foreach ($R['lines'] as $ln): ?><div><?php echo htmlspecialchars($ln); ?></div><?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
<?php elseif ($scanImport && !$scanImport['ok']): ?>
  <div class="alert alert-error u-mb-14"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($scanImport['error']); ?></div>
<?php endif; ?>

<?php if(isset($_GET['error'])): ?>
  <div class="alert alert-error u-mb-14"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($_GET['error']); ?></div>
<?php endif; ?>

<?php if (!empty($importLogs)): ?>
<details class="u-mb-16">
  <summary style="cursor:pointer;color:var(--navy);font-weight:700;padding:10px 14px;background:#fff;border-radius:10px;">
    <i class="fas fa-clock-rotate-left"></i> Recent scan imports (<?php echo count($importLogs); ?>)
  </summary>
  <div style="margin-top:8px;text-align:right;">
    <form method="POST" style="display:inline;" onsubmit="return confirm('Clear the import history? This only empties this list — imported violations are kept.');">
      <?php echo csrf_field(); ?>
      <?php echo vts_return_field(); ?>
      <input type="hidden" name="do_clear_import_logs" value="1">
      <button type="submit" class="btn-outline btn-sm"><i class="fas fa-trash"></i> Clear history</button>
    </form>
  </div>
  <div class="recent-table-wrap table-responsive" style="margin-top:8px;">
    <div class="table-scroll table-responsive">
    <!-- Columns answer the two questions the office actually asks about a
         batch of scans: HOW did it get here (a file, or a live sync), and
         WHO was holding the scanner. The device label and the account that
         clicked Import were neither, so they are gone. -->
    <table class="data-table">
      <thead><tr><th>Date</th><th>Source</th><th>Scanned By</th><th>Imported</th><th>Skipped</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($importLogs as $il): ?>
        <tr>
          <td class="u-nowrap"><?php echo htmlspecialchars(vts_datetime($il['created_at'])); ?></td>
          <td class="u-nowrap">
            <?php echo vts_import_source_pill($il['source'] ?? 'offline'); ?>
            <?php if (($il['source'] ?? '') !== 'online' && !empty($il['filename'])): ?>
              <div style="font-size:.72rem;color:var(--text-faint);margin-top:3px;"><?php echo htmlspecialchars($il['filename']); ?></div>
            <?php endif; ?>
          </td>
          <td><?php echo vts_masked_name($il['scanner_name'] ?? '', 'name of the marshal who scanned'); ?></td>
          <td><?php echo (int)$il['imported_count']; ?></td>
          <td><?php echo (int)$il['skipped_count']; ?></td>
          <td><?php if ((int)$il['ok'] === 1): ?><span class="pill resolved">OK</span><?php else: ?><span class="pill atrisk">Failed</span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</details>
<?php endif; ?>

<?php
  // Any filter beyond plain search that's currently applied — used to badge
  // the Filters toggle and auto-expand the panel so it isn't hidden silently.
  $advancedActive = $when !== 'all' || $from !== '' || $to !== '' || $yearLevel !== '' || $course !== '';
?>
<div class="recent-table-wrap is-panel"><!-- is-panel: this wrapper holds the filter bar, the tabs AND the table, so it must NOT scroll; only the inner .table-scroll does -->
  <form method="GET" class="filter-bar wrap" id="violFilterForm">
    <!-- Applying a filter used to drop the view and bounce you to the Official
         Sheet even if you were working in Records & Actions. Carry it. -->
    <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
    <div class="filter-field grow">
      <label for="search">Search</label>
      <div class="search-wrap" style="max-width:none;">
        <i class="fas fa-magnifying-glass"></i>
        <input id="search" type="text" name="search" class="vts-input" placeholder="Student name, ID, or violation" value="<?php echo htmlspecialchars($search); ?>">
      </div>
    </div>
    <div class="filter-actions">
      <button type="button" class="btn-outline btn-sm" id="filterToggleBtn" onclick="document.getElementById('filterPanel').classList.toggle('open'); this.classList.toggle('active');">
        <i class="fas fa-sliders"></i> Filters<?php echo $advancedActive ? ' <span class="filter-badge">•</span>' : ''; ?>
      </button>
      <!-- A magnifying glass sat right beside the search box and read as a
           second search. Apply confirms; a check says that. -->
      <button class="btn-primary btn-sm" type="submit"><i class="fas fa-check"></i> Apply</button>
      <a href="violations.php?view=<?php echo urlencode($view); ?>" class="btn-outline btn-sm">
        <i class="fas fa-rotate"></i> Reset
      </a>
      <button type="button" class="help-btn" id="helpBtn" aria-expanded="false" aria-controls="howtoPanel"
              onclick="vtsHelp()" title="How exporting and Drive work">
        <i class="fas fa-circle-question"></i> Help
      </button>
    </div>

    <div class="filter-panel<?php echo $advancedActive ? ' open' : ''; ?>" id="filterPanel">
      <div class="filter-field">
        <label for="when">Date Range</label>
        <select id="when" name="when" class="vts-input">
          <?php
            $opts = ['all'=>'All dates','today'=>'Today','yesterday'=>'Yesterday','7days'=>'Last 7 days','month'=>'This month'];
            foreach ($opts as $k=>$lbl) {
                $sel = ($when === $k && !($from && $to)) ? 'selected' : '';
                echo "<option value=\"$k\" $sel>$lbl</option>";
            }
          ?>
        </select>
      </div>
      <div class="filter-field">
        <label for="from">From</label>
        <input id="from" type="date" name="from" class="vts-input" value="<?php echo htmlspecialchars($from); ?>">
      </div>
      <div class="filter-field">
        <label for="to">To</label>
        <input id="to" type="date" name="to" class="vts-input" value="<?php echo htmlspecialchars($to); ?>">
      </div>
      <div class="filter-field">
        <label for="year_level">Year Level</label>
        <select id="year_level" name="year_level" class="vts-input">
          <option value="">All year levels</option>
          <?php foreach ($yearLevels as $yl): ?>
            <option value="<?php echo htmlspecialchars($yl); ?>" <?php echo $yearLevel === $yl ? 'selected' : ''; ?>><?php echo htmlspecialchars($yl); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <!-- Course lives here with the rest of the filters: what you filter to is
           what gets exported and what Drive sub-folder it's filed under. -->
      <div class="filter-field">
        <label for="course">Course</label>
        <select id="course" name="course" class="vts-input">
          <option value="">All courses</option>
          <?php foreach ($courses as $c): ?>
            <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $course === $c ? 'selected' : ''; ?>><?php echo htmlspecialchars($c); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-field">
        <label for="violation">Violation</label>
        <select id="violation" name="violation" class="vts-input">
          <option value="">All violations</option>
          <?php foreach ($violationOptions as $vo): ?>
            <option value="<?php echo htmlspecialchars($vo); ?>" <?php echo $vfilter === $vo ? 'selected' : ''; ?>><?php echo htmlspecialchars($vo); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-field">
        <label for="college_id">Department</label>
        <select id="college_id" name="college_id" class="vts-input">
          <option value="">All departments</option>
          <?php foreach ($collegeOptions as $col): ?>
            <option value="<?php echo (int)$col['id']; ?>" <?php echo $college === (string)$col['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($col['college_name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-field">
        <label for="section">Set</label>
        <select id="section" name="section" class="vts-input">
          <option value="">All sets</option>
          <?php foreach ($sectionOptions as $sc): ?>
            <option value="<?php echo htmlspecialchars($sc); ?>" <?php echo $section === $sc ? 'selected' : ''; ?>><?php echo htmlspecialchars($sc); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </form>

  <?php
    /* What you are looking at, stated once, where you would look for it —
       and each chip removable. */
    $chips = vts_violation_filter_labels([
        'college_id' => $college, 'course' => $course, 'year_level' => $yearLevel,
        'section' => $section, 'violation' => $vfilter, 'search' => $search,
        'from' => $from, 'to' => $to, 'when' => $when,
    ], $departmentHeading);
  ?>
  <?php
    /* The DEPARTMENT is scope, not a filter. It already has its own selector
       row above with the current one marked, so repeating it here as a
       removable "Showing" chip made merely opening a department look like a
       filter had been applied -- and "Clear all" then discarded the
       department and dropped you back into All departments. */
    $scopeKeys   = ['view', 'college_id'];
    $filterChips = $chips;
    unset($filterChips['college_id']);
  ?>
  <?php if ($filterChips): ?>
    <?php echo vts_filter_chips($filterChips, 'violations.php', $_GET, $scopeKeys); ?>
  <?php endif; ?>
  <div class="filter-chips" id="scopeDirty" hidden>
    <span class="fc fc-dirty"><i class="fas fa-triangle-exclamation"></i>
      <span>Not applied yet &mdash; press Apply</span></span>
  </div>

  <!-- ONE help panel, hidden until asked for. -->
  <details class="vts-howto u-mt-12" id="howtoPanel" hidden>
    <summary><i class="fas fa-circle-question"></i> Exporting and Google Drive</summary>
    <div class="howto-body">
      <div class="howto-cols">
        <div class="howto-col">
          <h4><i class="fas fa-file-excel" style="color:var(--success);"></i> Export</h4>
          <ol>
            <li>Set <b>Filters</b>, press <b>Apply</b>. What you see is what you get.</li>
            <li><b>Export &rsaquo; Download Excel file.</b> You get the Official Sheet layout, named after the filters.</li>
          </ol>
          <div class="howto-note"><i class="fas fa-lightbulb"></i>
            No department filter gives you one sheet per department. Filter to one and you get that sheet alone.</div>
        </div>
        <div class="howto-col">
          <h4><i class="fab fa-google-drive" style="color:#1a9e5c;"></i> Google Drive</h4>
          <ol>
            <li><b>Export &rsaquo; Download Excel file</b>, then <b>Open the Drive folder</b>.</li>
            <li>Drag the file into that tab. There is no Google sign-in, which is why it is a drag.</li>
          </ol>
          <div class="howto-note"><i class="fas fa-triangle-exclamation"></i>
            Drive is one copy, not a backup. Ask OSA/Admin to keep a USB copy too.</div>
        </div>
      </div>
    </div>
  </details>

  <!-- Official sheet (what the OSA submits) vs. the per-record working list -->
  <div class="tab-row u-mb-12">
    <a href="<?php echo htmlspecialchars($linkSheet); ?>" class="tab-link<?php echo $view==='sheet' ? ' active' : ''; ?>">
      <i class="fas fa-table-list"></i> Official Sheet
    </a>
    <a href="<?php echo htmlspecialchars($linkRecords); ?>" class="tab-link<?php echo $view==='records' ? ' active' : ''; ?>">
      <i class="fas fa-list"></i> Records &amp; Actions
    </a>
  </div>

  <?php if ($view === 'sheet'): ?>
  <div class="official-sheet-wrap table-responsive">
    <?php /* Name the filters that emptied it, and offer the way back. */
          echo vts_official_table_html($officialStudents,
                 vts_empty_filter_html($chips, 'violations.php', $_GET, $scopeKeys)); ?>
  </div>
  <?php else: ?>
  <div class="table-scroll table-responsive">
  <table class="data-table">
    <thead><tr>
      <th>Student ID</th><th>Name</th><th>Course</th><th>Year</th><th>Violation</th>
      <th>Violation Count</th><th>Date</th><th>Action</th>
    </tr></thead>
    <tbody>
    <?php if(count($rows) > 0): foreach($rows as $row): ?>
      <tr>
        <td><?php echo htmlspecialchars($row['sid'] ?? '-'); ?></td>
        <td class="cell-name"><?php echo htmlspecialchars($row['fullname']); ?></td>
        <!-- Course carries its department's icon + colour, so a mixed list
             sorts itself visually without reading every row. -->
        <td><?php echo vts_course_chip($row['course'] ?? ''); ?></td>
        <td><?php echo htmlspecialchars($row['year_level'] ?? '-'); ?></td>
        <td class="cell-violation"><?php echo htmlspecialchars($row['violation']); ?></td>
        <?php $offNum = (int)preg_replace('/\D/', '', offense_display($row['offense'] ?? '')); ?>
        <td><?php echo vts_heat_pill($offNum, offense_display($row['offense'] ?? '-')) ?: htmlspecialchars(offense_display($row['offense'] ?? '-')); ?></td>
        <td class="nowrap"><?php echo htmlspecialchars(vts_date($row['date_reported'])); ?></td>
        <!-- ?return= carries this exact page (tab + filters) so these come
             back here rather than to the default Official Sheet.
             OSA Staff cannot delete — that stays with OSA/Admin. -->
        <td class="nowrap">
          <div class="row-actions">
            <a class="btn-sm btn-outline" href="view_violation.php?id=<?php echo $row['id']; ?>&amp;return=<?php echo vts_return_param(); ?>"
               title="Open this record" aria-label="Open this record"><i class="fas fa-eye"></i></a>
            <a class="btn-sm btn-outline" href="edit_violation.php?id=<?php echo $row['id']; ?>&amp;return=<?php echo vts_return_param(); ?>"
               title="Edit this record" aria-label="Edit this record"><i class="fas fa-pen"></i></a>

            <div class="vts-menu to-left" id="pm<?php echo $row['id']; ?>">
              <button type="button" class="btn-sm btn-outline" onclick="vtsMenu(event,'pm<?php echo $row['id']; ?>')"
                      aria-haspopup="true" aria-expanded="false" title="Print a document for this record">
                <i class="fas fa-print"></i> <i class="fas fa-chevron-down vm-caret"></i>
              </button>
              <div class="vts-menu-list" role="menu">
                <a href="../reports/violation_slip.php?id=<?php echo $row['id']; ?>" target="_blank" role="menuitem">
                  <i class="fas fa-id-card"></i><span class="vm-label">ID Confiscation Slip<span class="vm-sub">5.5 &times; 4.5 in</span></span></a>
                <a href="../reports/student_violation_slip.php?id=<?php echo $row['id']; ?>" target="_blank" role="menuitem">
                  <i class="fas fa-file-signature"></i><span class="vm-label">Student Violation Slip<span class="vm-sub">8.5 &times; 6.9 in, editable</span></span></a>
                <a href="../reports/parent_notice.php?id=<?php echo $row['id']; ?>" target="_blank" role="menuitem">
                  <i class="fas fa-envelope"></i><span class="vm-label">Parent / Guardian Notice</span></a>
              </div>
            </div>
          </div>
        </td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="9" class="table-empty">No violation records found.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>


<script>
/* ============ Export follows the filters, always ============
   The menu links are rendered with the filters that were APPLIED. Change a
   dropdown and go straight to Export without pressing Apply and you used to
   get the previous filters, then reasonably conclude the filter did nothing.
   So: rewrite the links live from the form, and flag when the table on
   screen hasn't caught up. */
(function(){
  var form  = document.getElementById('violFilterForm');
  var xlsx  = document.getElementById('exportExcelBtn');
  var drive = document.getElementById('uploadDriveBtn');
  var dirty = document.getElementById('scopeDirty');
  if (!form) return;

  // Only these reach the export endpoint - `view` is a screen-only concern.
  var EXPORT_KEYS = ['search','when','from','to','year_level','course','violation','section','college_id'];

  function currentQuery(){
    var fd = new FormData(form), qs = [];
    EXPORT_KEYS.forEach(function(k){
      var v = (fd.get(k) || '').toString().trim();
      if (v !== '' && !(k === 'when' && v === 'all')) {
        qs.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
      }
    });
    return qs.join('&');
  }

  var applied = currentQuery();   // what the page was actually rendered with
  var userChanged = false;

  function sync(){
    var q   = currentQuery();
    var url = 'export_excel.php' + (q ? '?' + q : '');
    if (xlsx)  xlsx.href = url;
    if (drive) drive.dataset.export = url;
    if (dirty) dirty.hidden = !userChanged || q === applied;
  }

  form.addEventListener('change', function(){ userChanged = true; sync(); });
  form.addEventListener('input',  function(){ userChanged = true; sync(); });
  sync();

  /* Drive: start the download, then let the link open the folder tab. */
  if (drive) {
    drive.addEventListener('click', function(){
      var a = document.createElement('a');
      a.href = drive.dataset.export;
      document.body.appendChild(a);
      a.click();
      a.remove();
    });
  }
})();

/* HOW OLD IS THIS LIST?
   Scans sync in from the gate while this page sits open, so what is on
   screen quietly stops being current. A Refresh button on its own does not
   tell you whether pressing it is worth anything — this does, by counting
   up from load and going amber once the list is old enough to distrust. */
(function(){
  var el = document.getElementById('asOf');
  if (!el) return;
  var loadedAt = Date.now();
  var stamp = el.textContent.replace(/^as of\s*/i, '').trim();

  function tick(){
    var mins = Math.floor((Date.now() - loadedAt) / 60000);
    if (mins < 1){ el.textContent = 'as of ' + stamp; el.style.color = ''; return; }
    el.textContent = 'as of ' + stamp + ' (' + mins + ' min ago)';
    // Amber past five minutes: long enough that a shift change or a sync
    // could have added rows this page has never seen.
    el.style.color = mins >= 5 ? 'var(--warning, #e07a10)' : '';
  }
  tick();
  setInterval(tick, 30000);

  /* Coming back to the tab is exactly when someone wonders if it is current,
     so update the moment it is looked at rather than up to 30s later. */
  document.addEventListener('visibilitychange', function(){ if (!document.hidden) tick(); });
})();
</script>
<?php /* .vts-main-inner and <main> are closed by includes/footer.php;
         closing them here too emitted a stray </div></main>. */ ?>

<?php
/* Auto-refresh. The pulse is asked the SAME filter question this page was
   rendered with, so a scan in another department does not announce rows the
   user cannot see. baseId/baseCount are what is on screen right now — the
   poller compares against them. */
$liveMaxId = 0;
foreach ($rows as $__v) { if ((int)$__v['id'] > $liveMaxId) $liveMaxId = (int)$__v['id']; }
?>
<script>
  window.VTS_LIVE = {
    pulse:     <?php echo json_encode('../api/violations_pulse.php' . ($reportQS ? '?' . $reportQS : '')); ?>,
    baseId:    <?php echo (int)$liveMaxId; ?>,
    baseCount: <?php echo (int)count($rows); ?>
  };
</script>
<script src="<?php echo $assetBase ?? '../'; ?>assets/js/vts-live.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/vts-live.js'); ?>" defer></script>

<?php include "../includes/footer.php"; ?>
