<?php
/* Admin: violation list with search and management. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (!in_array($_SESSION['role'] ?? '', ['Admin', 'OSA'], true)) {
    vts_deny_access();
}

$adminId = (int)($_SESSION['user_id'] ?? 0);
$scanImport = null;   // result of an offline-scan import done on this page

// Make sure the Minor-clearing columns exist (safe if already there).
vts_ensure_categorization_columns($conn);

// Mark a MINOR violation as Served/Cleared — it stays in history but stops
// counting toward the student's active 1st/2nd/3rd escalation. Major/Grave
// offenses are permanent and can never be cleared here.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_clear_violation'])) {
    if (!csrf_verify()) {
        vts_redirect_back('violations.php', 'error', 'Session expired. Please try again.');
    }
    $vid = (int)($_POST['id'] ?? 0);
    [$ok, $why] = violation_is_clearable($conn, $vid);
    if (!$ok) {
        vts_redirect_back('violations.php', 'error', $why);
    } else {
        $conn->prepare("UPDATE violations SET cleared_at = NOW(), cleared_by = :by WHERE id = :id")
             ->execute([':by' => $adminId, ':id' => $vid]);
        // Clearing a Minor takes it out of the ACTIVE ladder, so the numbers
        // after it have to be re-derived too.
        $cs = $conn->prepare("SELECT student_id FROM violations WHERE id = :id");
        $cs->execute([':id' => $vid]);
        if ($csid = $cs->fetchColumn()) vts_renumber_offenses($conn, (int)$csid);
        audit_log($conn, "Clear Violation", "violations", $vid, "Marked Minor offense as Served/Cleared");
        vts_redirect_back('violations.php', 'success', 'Minor offense marked as Served/Cleared — it no longer counts toward the offense ladder.');
    }
    exit();
}

// Import offline scans (CSV/JSON) right here on the Violations page.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_import_scans'])) {
    if (!csrf_verify()) {
        $scanImport = ['ok' => false, 'error' => 'Session expired. Please try again.'];
    } elseif (!isset($_FILES['scans_file']) || $_FILES['scans_file']['error'] !== UPLOAD_ERR_OK) {
        $ue = $_FILES['scans_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        $scanImport = ['ok' => false, 'error' => in_array($ue, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'That file is too large for this server to accept. Please export a smaller range or use CSV instead.'
            : 'Please choose a scanner file (.csv or .json) first.'];
    } else {
        try {
            $scanImport = import_scan_file($conn, $_FILES['scans_file']['tmp_name'],
                                           $_FILES['scans_file']['name'] ?? '', $adminId);
        } catch (Throwable $e) {
            error_log('Import Scans failed: ' . $e->getMessage());
          $scanImport = ['ok' => false, 'error' => 'That file could not be imported. Check the file and try again.'];
        }
        log_import($conn, $adminId, $_FILES['scans_file']['name'] ?? '', $scanImport);
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
$vfilter    = is_string($_GET['violation'] ?? null) ? trim($_GET['violation']) : '';   // filter by a specific violation type
$section    = is_string($_GET['section'] ?? null) ? trim($_GET['section']) : '';
$college    = is_string($_GET['college_id'] ?? null) ? trim($_GET['college_id']) : '';  // department: CITE / CRIM / COED …
// Two ways to read the same records: the OSA's official sheet (one row per
// student, the layout they submit) or the per-record list with row actions.
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
$violations = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Official sheet view: same filters, but pulled per student (name parts, set
   and department) and pivoted into the OSA's own layout. */
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

// Distinct violation names for the "Violation" filter dropdown.
$violationOptions = $conn->query("SELECT DISTINCT violation FROM violations ORDER BY violation")->fetchAll(PDO::FETCH_COLUMN);

// Options for the Year Level / Course filter dropdowns
$yearLevels = $conn->query("SELECT DISTINCT year_level FROM users WHERE role='Student' AND year_level IS NOT NULL AND year_level <> '' ORDER BY year_level")->fetchAll(PDO::FETCH_COLUMN);
$courses    = $conn->query("SELECT DISTINCT course FROM users WHERE role='Student' AND course IS NOT NULL AND course <> '' ORDER BY course")->fetchAll(PDO::FETCH_COLUMN);

/* Keep the filter that is CURRENTLY APPLIED selectable even when the data
   no longer offers it -- a year level whose students have all graduated, a
   course with no violations left, a violation type that was renamed. The
   dropdown could not show such a value, so the form could never match the
   URL it was rendered from, and "Not applied yet" latched on and stayed on
   with no way to clear it but a reload. Adding the active value back makes
   the form able to say what the page is actually filtered by. */
$keepActive = function (array $options, $active) {
    $active = trim((string)$active);
    if ($active !== '' && !in_array($active, $options, true)) $options[] = $active;
    return $options;
};
$yearLevels       = $keepActive($yearLevels, $yearLevel);
$courses          = $keepActive($courses, $course);
$violationOptions = $keepActive($violationOptions, $vfilter);

// Recent scan-import history (shown in a collapsible panel below) — folded in
// here instead of a separate Import Log page/nav link. Covers BOTH routes the
// scans can arrive by: an offline file handed over, or a live Wi-Fi sync.
$importLogs = [];
try {
    vts_ensure_import_log_table($conn);
    $importLogs = $conn->query("
        SELECT l.filename, l.scanner_name, l.source, l.imported_count, l.skipped_count,
               l.ok, l.error_message, l.details, l.created_at
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
// Icon + colour for whichever department (or course) is in view, so the page
// is identifiable at a glance instead of every department looking identical.
$deptId = vts_dept_identity($course !== '' ? $course : $departmentHeading);

/* Map a status string to a pill colour class */
function pillClass($status) {
    $s = strtolower(trim($status));
    if (str_contains($s, 'approve') || str_contains($s, 'resolve')) return 'resolved';
    if (str_contains($s, 'risk'))    return 'atrisk';
    if (str_contains($s, 'osa') || str_contains($s, 'review') || str_contains($s, 'pending')) return 'review';
    if (str_contains($s, 'reject'))  return 'atrisk';
    return 'warning';
}

$adminActive = 'violations';
include "../includes/admin_header.php";
?>

<?php
  // Export target — same filters as the screen, in the official layout.
  $reportQS = http_build_query(array_filter([
      'search' => $search, 'when' => $when, 'from' => $from, 'to' => $to,
      'year_level' => $yearLevel, 'course' => $course, 'section' => $section,
      'violation' => $vfilter, 'college_id' => $college,
  ], fn($v) => $v !== '' && $v !== null));
  $exportUrl = 'export_excel.php' . ($reportQS ? '?' . $reportQS : '');

  /* Scans arrive on their own — a marshal syncing from the gate adds rows
     while this page sits open — so the list on screen goes stale with no
     sign that it has. This is the reload, carrying the same filters and
     dropping any success/error param so a refresh does not re-announce
     what you did earlier. */
  $refreshQS = $reportQS;
  if (($_GET['view'] ?? '') !== '') {
      $refreshQS .= ($refreshQS ? '&' : '') . 'view=' . urlencode($_GET['view']);
  }
  $refreshUrl = 'violations.php' . ($refreshQS ? '?' . $refreshQS : '');
  require_once "../config/drive.php";
  require_once "../includes/drive.php";
  /* Open the folder the file actually went to. With a department selected
     that is the department's own Drive folder, not the shared one -- sending
     someone to the shared folder to look for a file filed elsewhere is how
     people conclude the upload "didn't work". */
  $deptDrive   = function_exists('vts_drive_dept_folder') ? vts_drive_dept_folder($course, $college) : '';
  $driveFolder = $deptDrive !== ''
               ? 'https://drive.google.com/drive/folders/' . $deptDrive
               : vts_drive_folder_url('violations');
?>
<div class="admin-welcome-row">
  <div class="dept-head">
    <span class="dept-badge" style="--chip-color:<?php echo $deptId['color']; ?>;"><i class="fas <?php echo $deptId['icon']; ?>"></i></span>
    <div>
      <h1><?php echo htmlspecialchars($departmentHeading); ?>: Violations &amp; Reports</h1>
      <!-- Course + record count only. What the two tabs are is explained by
           the tabs themselves; saying it again here was noise. -->
      <div class="dept-sub">
        <?php if ($course !== ''): ?><?php echo vts_course_chip($course, true); ?><?php endif; ?>
        <span style="color:var(--text-muted);font-size:.82rem;">
          <?php echo count($violations); ?> violation record(s)
          &middot; <span id="asOf" title="When this list was loaded">as of <?php echo date('g:i A'); ?></span>
        </span>
      </div>
    </div>
  </div>

  <!-- THREE actions. Export used to be two buttons that both exported the
       same file — one downloaded it, the other downloaded it AND opened
       Drive — which is a difference no one can read off two labels. It is
       one button now, and the menu names the two destinations. -->
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
        <?php /* The "Save to Google Drive" upload was removed: it needs a Google
                 Workspace account (a service account gets no Drive storage of its
                 own), so on this school's plan it could only ever report a
                 failure. Download, then drop the file in the folder below. */ ?>
        <a href="<?php echo htmlspecialchars($driveFolder); ?>" target="_blank" rel="noopener" role="menuitem">
          <i class="fas fa-folder-open"></i>
          <span class="vm-label">Open the Drive folder
            <span class="vm-sub">See what has been filed there</span></span>
        </a>
      </div>
    </div>

    <form id="scanImportForm" method="POST" enctype="multipart/form-data" style="display:inline-flex;margin:0;">
      <?php echo csrf_field(); ?>
      <?php echo vts_return_field(); ?>
      <input type="file" name="scans_file" id="scansFileInput" aria-label="Choose a scanner file to import" accept=".vtsl,.json,.csv,.xlsx" class="u-hidden">
      <button type="button" class="btn-outline" onclick="document.getElementById('scansFileInput').click();"
              title="Import a scanner file (.vtsl, .csv, .xlsx)">
        <i class="fas fa-file-import"></i> Import Scans
      </button>
      <input type="hidden" name="do_import_scans" value="1">
      <?php /* The button above is type="button" — it opens the hidden file
               input, and a script submits this form when a file is chosen. That
               left the form with no submit control of its own, so with scripts
               off it could not be sent at all. Hidden because the visible
               button already says what this does; it is here to exist, not to
               be seen. */ ?>
      <button type="submit" class="sr-only">Upload the chosen scanner file</button>
    </form>
  </div>
</div>

<?php /* A row of one chip per department used to sit here, duplicating the
         Department dropdown that was already in the filter panel — two
         controls for one filter, which could disagree on screen and gave a
         phone a sideways-scrolling strip to get past before the table.
         The dropdown is the single control now, and it has been moved out
         of the collapsed panel onto the filter bar so it still takes one
         click to reach. */ ?>
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

<?php if ($scanImport && $scanImport['ok']): $R=$scanImport['results'];
  /* Report what happened to the RECORDS, not merely that the file parsed.
     A green tick over "Imported 0, skipped 6" read as a success. */
  [$impLabel, $impCls, $impWhy] = vts_import_status([
      'ok' => 1, 'imported_count' => (int)$R['imported'], 'skipped_count' => (int)$R['skipped'],
  ]);
  $impAlert = ($impCls === 'resolved') ? 'alert-success' : 'alert-warning';
  $impIcon  = ($impCls === 'resolved') ? 'fa-circle-check' : 'fa-triangle-exclamation';
?>
  <div class="alert <?php echo $impAlert; ?> u-mb-14">
    <i class="fas <?php echo $impIcon; ?>"></i>
    <strong><?php echo htmlspecialchars($impLabel); ?>.</strong>
    Imported <strong><?php echo (int)$R['imported']; ?></strong>,
    skipped <strong><?php echo (int)$R['skipped']; ?></strong>
    (scanner: <?php echo htmlspecialchars($R['scanner']); ?>).
    <?php if ($impCls !== 'resolved'): ?>
      <div style="margin-top:4px;font-size:.85rem;"><?php echo htmlspecialchars($impWhy); ?></div>
    <?php endif; ?>
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
         clicked Import were neither, so they're gone. -->
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
          <td>
            <?php echo vts_import_status_pill($il); ?>
            <?php
              // WHY, not just how many. The per-row outcome lines are kept with
              // the log now, so a skipped batch can be accounted for later --
              // "6 skipped" on its own told the office nothing.
              [$stLabel, $stCls, $stWhy] = vts_import_status($il);
              $hasDetail = trim((string)($il['details'] ?? '')) !== '';
            ?>
            <div style="font-size:.72rem;color:var(--text-faint);margin-top:4px;max-width:260px;line-height:1.35;">
              <?php echo htmlspecialchars($stWhy); ?>
            </div>
            <?php if ($hasDetail): ?>
              <details style="margin-top:5px;">
                <summary style="cursor:pointer;font-size:.72rem;color:var(--navy);font-weight:700;">View details</summary>
                <div style="max-height:170px;overflow:auto;font-size:.74rem;color:var(--text-soft);
                            background:#f7f9fd;border:1px solid #e3e9f3;border-radius:8px;
                            padding:8px 10px;margin-top:5px;white-space:pre-wrap;max-width:320px;">
<?php echo htmlspecialchars($il['details']); ?>
                </div>
              </details>
            <?php endif; ?>
          </td>
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
  /* What is hidden inside the Filters panel — the badge and the auto-open
     mean "there is a filter on that you cannot currently see". Department is
     deliberately NOT in this list any more: it moved onto the filter bar, so
     a chosen department is already visible and would otherwise spring the
     panel open every time one was picked. */
  $advancedActive = $when !== 'all' || $from !== '' || $to !== '' || $yearLevel !== ''
                 || $course !== '' || $vfilter !== '' || $section !== '';
  $collegeOptions = $conn->query("SELECT id, college_name FROM colleges ORDER BY college_name")->fetchAll(PDO::FETCH_ASSOC);
  $sectionOptions = $conn->query("SELECT DISTINCT section FROM users WHERE role='Student' AND section IS NOT NULL AND section <> '' ORDER BY section")->fetchAll(PDO::FETCH_COLUMN);
  $sectionOptions = $keepActive($sectionOptions, $section);   // see $keepActive above
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
    <?php /* Department sits on the bar rather than inside the Filters panel:
             it is the cut the office makes most often — the page heading and
             the Drive export folder both follow it — so hiding it behind a
             toggle would cost a click every time. It replaces the chip row
             that used to duplicate it above the table. */ ?>
    <div class="filter-field">
      <label for="college_id">Department</label>
      <select id="college_id" name="college_id" class="vts-input">
        <option value="">All departments</option>
        <?php foreach ($collegeOptions as $col): ?>
          <option value="<?php echo (int)$col['id']; ?>" <?php echo $college === (string)$col['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($col['college_name']); ?></option>
        <?php endforeach; ?>
      </select>
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
       and each chip removable. This replaces a prose panel that said the
       same thing and could not be acted on. */
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
  <!-- Raised by script the moment a filter changes but has not been applied,
       so an export can never be silently one Apply behind the screen. -->
  <div class="filter-chips" id="scopeDirty" hidden>
    <span class="fc fc-dirty"><i class="fas fa-triangle-exclamation"></i>
      <span>Not applied yet &mdash; press Apply</span></span>
  </div>

  <!-- ONE help panel, hidden until asked for. It was two always-visible
       accordions whose first two steps were word-for-word identical. -->
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
            <li><b>Export &rsaquo; Download Excel file.</b> It saves to this computer.</li>
            <li><b>Export &rsaquo; Open the Drive folder</b>, then drag the file into that tab.</li>
          </ol>
          <div class="howto-note"><i class="fas fa-triangle-exclamation"></i>
            Drive is one copy, not a backup. Keep taking the <a href="backup.php">Backup</a> onto a USB drive.</div>
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
    <?php if(count($violations) > 0): foreach($violations as $row): ?>
      <tr>
        
        <td><?php echo htmlspecialchars($row['sid'] ?? '-'); ?></td>
        <td class="cell-name"><?php echo htmlspecialchars($row['fullname']); ?></td>
        <!-- Course carries its department's icon + colour, so a mixed list
             sorts itself visually without reading every row. -->
        <td><?php echo vts_course_chip($row['course'] ?? ''); ?></td>
        <td><?php echo htmlspecialchars($row['year_level'] ?? '-'); ?></td>
        <td class="cell-violation">
          <?php echo htmlspecialchars($row['violation']); ?>
          <?php
            // MINOR cool · MAJOR warm · GRAVE hot — one shared set of pills.
            $sev = $row['severity'] ?? 'Minor';
            $sevClass = $sev === 'Grave' ? 'sev-grave' : ($sev === 'Major' ? 'sev-major' : 'sev-minor');
          ?>
          <span class="sev <?php echo $sevClass; ?>" style="margin-left:6px;"><?php echo htmlspecialchars(strtoupper($sev)); ?></span>
          <?php if (!empty($row['cleared_at'])): ?>
            <span class="sev sev-cleared" style="margin-left:4px;" title="Cleared on <?php echo htmlspecialchars(vts_date($row['cleared_at'])); ?>"><i class="fas fa-check"></i> CLEARED</span>
          <?php endif; ?>
        </td>
        <?php $offNum = (int)preg_replace('/\D/', '', offense_display($row['offense'] ?? '')); ?>
        <td><?php echo vts_heat_pill($offNum, offense_display($row['offense'] ?? '-')) ?: htmlspecialchars(offense_display($row['offense'] ?? '-')); ?></td>
        <td class="nowrap"><?php echo htmlspecialchars(vts_date($row['date_reported'])); ?></td>
        <!-- Four controls, same order every row. The three printable
             documents used to be three icon-only buttons that no one could
             tell apart; they are named items in the Print menu now. -->
        <td class="nowrap">
          <div class="row-actions">
            <?php
            /* SEE THE PROOF.

               The photo the marshal took was reachable only by opening the
               Violation Proof page from the sidebar and hunting for the
               student -- so from the list where you are actually judging a
               record, the evidence was invisible. A record with proof and a
               record without one looked identical here, which is the worst
               of it: there was no way to tell, at a glance, which rows were
               backed by anything.

               Both states are shown, deliberately. A camera button opens the
               frame; a struck-through one states there is none. */
            $evFile = trim((string)($row['evidence'] ?? ''));
            $evPath = $evFile !== '' ? __DIR__ . '/../uploads/evidence/' . basename($evFile) : '';
            $hasProof = $evFile !== '' && is_file($evPath);
            ?>
            <?php if ($hasProof): ?>
              <button type="button" class="btn-sm btn-outline js-see-proof"
                      data-src="../uploads/evidence/<?php echo rawurlencode(basename($evFile)); ?>"
                      data-who="<?php echo htmlspecialchars($row['fullname'] . ' · ' . ($row['sid'] ?? '')); ?>"
                      data-what="<?php echo htmlspecialchars($row['violation'] . ' · ' . vts_date($row['date_reported'])); ?>"
                      title="See the proof photo for this record" aria-label="See the proof photo">
                <i class="fas fa-camera"></i>
              </button>
            <?php else: ?>
              <span class="btn-sm btn-outline is-noproof" aria-label="No proof photo attached"
                    title="No proof photo is attached to this record"><i class="fas fa-camera"></i></span>
            <?php endif; ?>

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
                  <i class="fas fa-envelope"></i><span class="vm-label">Parent / Guardian Notice<?php echo (($row['severity'] ?? 'Minor') === 'Major') ? '<span class="vm-sub">Recommended for a Major offense</span>' : ''; ?></span></a>
              </div>
            </div>

            <?php [$rowClearable] = violation_is_clearable($conn, $row['id']); if ($rowClearable): ?>
            <form method="POST" onsubmit="return confirm('Mark this Minor offense as Served/Cleared? It stays in history but stops counting toward the offense ladder.');"><?php echo csrf_field(); ?><?php echo vts_return_field(); ?><input type="hidden" name="do_clear_violation" value="1"><input type="hidden" name="id" value="<?php echo $row['id']; ?>"><button type="submit" class="btn-outline btn-sm" title="Mark as Served — stops counting toward the offense ladder" aria-label="Mark as served"><i class="fas fa-check-double"></i></button></form>
            <?php endif; ?>

            <span class="ra-sep"></span>
            <!-- vts_return_field() carries this exact page (Records & Actions +
                 every active filter) so the delete comes straight back here
                 instead of dumping you on the Official Sheet. -->
            <form method="POST" action="delete_violation.php" onsubmit="return confirm('Delete this violation? This cannot be undone.');"><?php echo csrf_field(); ?><?php echo vts_return_field(); ?><input type="hidden" name="id" value="<?php echo $row['id']; ?>"><button type="submit" class="btn-danger btn-sm" title="Delete this record" aria-label="Delete this record"><i class="fas fa-trash"></i></button></form>
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

<?php /* The proof viewer. One dialog for the whole table -- the rows only
         carry the path and the caption, so a hundred records cost a hundred
         data attributes rather than a hundred hidden dialogs. */ ?>
<style>
  /* "No proof" is a statement, not a control: same footprint so the action
     column stays aligned, but visibly not pressable. */
  .row-actions .is-noproof{
    display:inline-flex; align-items:center; justify-content:center;
    opacity:.38; cursor:default; position:relative;
  }
  .row-actions .is-noproof::after{
    content:''; position:absolute; left:16%; right:16%; top:50%;
    border-top:2px solid currentColor; transform:rotate(-20deg);
  }
  .pv-overlay{
    position:fixed; inset:0; z-index:4000; display:none;
    align-items:center; justify-content:center; padding:22px;
    background:rgba(9,18,38,.72);
  }
  .pv-overlay.open{ display:flex; }
  .pv-box{
    background:#fff; border-radius:14px; overflow:hidden;
    max-width:min(760px, 100%); width:100%; max-height:100%;
    display:flex; flex-direction:column;
    box-shadow:0 24px 70px rgba(8,18,40,.45);
  }
  .pv-head{
    display:flex; align-items:flex-start; gap:12px;
    padding:14px 16px; border-bottom:1px solid #e8edf5;
  }
  .pv-head .t{ flex:1 1 auto; min-width:0; }
  .pv-who{ font-weight:800; color:#0f172a; font-size:.95rem; line-height:1.3; }
  .pv-what{ color:#64748b; font-size:.8rem; margin-top:2px; }
  .pv-x{
    flex:0 0 auto; border:0; background:#f1f5f9; color:#334155;
    width:34px; height:34px; border-radius:9px; cursor:pointer; font-size:.95rem;
  }
  .pv-x:hover{ background:#e2e8f0; }
  /* The image is the point of the dialog, so it gets the room; a tall
     portrait frame scrolls rather than being squashed to a letterbox. */
  .pv-body{ padding:14px 16px; overflow:auto; background:#0f172a; text-align:center; }
  .pv-body img{ max-width:100%; max-height:66vh; border-radius:8px; display:inline-block; }
  .pv-foot{
    display:flex; align-items:center; justify-content:space-between; gap:10px;
    padding:12px 16px; border-top:1px solid #e8edf5; font-size:.82rem;
  }
  @media (max-width:560px){
    .pv-overlay{ padding:10px; }
    .pv-body img{ max-height:58vh; }
  }
</style>

<div class="pv-overlay" id="proofView" role="dialog" aria-modal="true" aria-labelledby="pvWho">
  <div class="pv-box" role="document">
    <div class="pv-head">
      <div class="t">
        <div class="pv-who" id="pvWho"></div>
        <div class="pv-what" id="pvWhat"></div>
      </div>
      <button type="button" class="pv-x" id="pvClose" aria-label="Close">&times;</button>
    </div>
    <div class="pv-body"><img id="pvImg" alt="Proof photo for this violation"></div>
    <div class="pv-foot">
      <span style="color:#64748b;">Taken by the marshal at the time of the scan.</span>
      <a href="proof.php" class="btn-sm btn-outline">Review all proof</a>
    </div>
  </div>
</div>

<script>
/* ---- Proof viewer -------------------------------------------------
   Delegated, so it keeps working when the table is re-rendered by a
   filter, and so one handler serves every row. */
(function(){
  var ov = document.getElementById('proofView');
  if (!ov) return;
  var img = document.getElementById('pvImg'),
      who = document.getElementById('pvWho'),
      what= document.getElementById('pvWhat'),
      last = null;

  function open(btn){
    last = btn;
    img.src = btn.getAttribute('data-src');
    who.textContent  = btn.getAttribute('data-who')  || '';
    what.textContent = btn.getAttribute('data-what') || '';
    ov.classList.add('open');
    document.getElementById('pvClose').focus();
  }
  function close(){
    ov.classList.remove('open');
    img.removeAttribute('src');          // stop a large frame sitting in memory
    if (last) { try { last.focus(); } catch(e){} }   // back where they were
  }

  document.addEventListener('click', function(e){
    var btn = e.target.closest && e.target.closest('.js-see-proof');
    if (btn){ e.preventDefault(); open(btn); return; }
    if (e.target === ov) close();
  });
  document.getElementById('pvClose').addEventListener('click', close);
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && ov.classList.contains('open')) close();
  });
})();
</script>

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

  /* What the page was ACTUALLY rendered with, read from the URL -- the one
     thing that cannot drift. The baseline used to be a snapshot of the form
     taken the instant this script ran, and a browser is still busy with the
     form after that: it restores control values on a refresh or a Back, and
     it autofills remembered text. Any of that moved the form away from a
     baseline captured too early, so "Not applied yet - press Apply" appeared
     on a page nobody had touched. */
  function urlQuery(){
    var p = new URLSearchParams(location.search), qs = [];
    EXPORT_KEYS.forEach(function(k){
      var v = (p.get(k) || '').toString().trim();
      if (v !== '' && !(k === 'when' && v === 'all')) {
        qs.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
      }
    });
    return qs.join('&');
  }

  var urlApplied = urlQuery();

  /* WHEN THE WARNING SHOWS.

     The rule is simply: does the form still describe what is on screen?
     urlApplied is the query the page was rendered from, so comparing the
     live form against it is self-correcting -- change a filter and it
     appears, press Apply (or change it back) and it goes.

     What it replaced: a userChanged flag flipped by any 'input' or 'change'
     event on the form. Two problems. Those events are not only fired by
     people -- a browser autofilling the search box or restoring a date
     field fires them too, after this script has already taken its baseline.
     And once the flag was true nothing but a reload cleared it, so a single
     stray event pinned "Not applied yet" on screen for the rest of the
     visit, on a page nobody had touched. */
  function sync(){
    var q   = currentQuery();
    var url = 'export_excel.php' + (q ? '?' + q : '');
    if (xlsx) xlsx.href = url;
    if (dirty) dirty.hidden = (q === urlApplied);
  }

  /* Re-read the applied query whenever the browser may have finished with
     the page: 'load' covers restore-on-refresh and autofill, 'pageshow' also
     fires when the page comes back from the back/forward cache, where this
     script never re-runs. */
  function rebaseline(){ urlApplied = urlQuery(); sync(); }
  window.addEventListener('load', rebaseline);
  window.addEventListener('pageshow', rebaseline);

  form.addEventListener('change', sync);
  form.addEventListener('input',  sync);
  sync();

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


<?php
/* Auto-refresh. The pulse is asked the SAME filter question this page was
   rendered with, so a scan in another department does not announce rows the
   user cannot see. baseId/baseCount are what is on screen right now — the
   poller compares against them. */
$liveMaxId = 0;
foreach ($violations as $__v) { if ((int)$__v['id'] > $liveMaxId) $liveMaxId = (int)$__v['id']; }
?>
<script>
  window.VTS_LIVE = {
    pulse:     <?php echo json_encode('../api/violations_pulse.php' . ($reportQS ? '?' . $reportQS : '')); ?>,
    baseId:    <?php echo (int)$liveMaxId; ?>,
    baseCount: <?php echo (int)count($violations); ?>
  };
</script>
<script src="<?php echo $assetBase ?? '../'; ?>assets/js/vts-live.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/vts-live.js'); ?>" defer></script>

<?php include "../includes/admin_footer.php"; ?>
