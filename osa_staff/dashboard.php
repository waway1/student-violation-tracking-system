<?php
/* OSA Staff dashboard: overview of students and violations. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (($_SESSION['role'] ?? '') !== "OSA Staff") {
    vts_deny_access();
}

// Best-effort, cheap no-op most of the time: export + deactivate students
// registered 6+ years ago so the roster doesn't quietly pile up with graduates.
vts_auto_archive_old_students($conn);

$total   = (int)$conn->query("SELECT COUNT(*) FROM violations")->fetchColumn();
$today   = (int)$conn->query("SELECT COUNT(*) FROM violations WHERE DATE(date_reported)=CURDATE()")->fetchColumn();
$atRisk  = (int)$conn->query("SELECT COUNT(*) FROM (SELECT student_id FROM violations GROUP BY student_id HAVING COUNT(*) >= 3) t")->fetchColumn();
$month   = (int)$conn->query("SELECT COUNT(*) FROM violations WHERE MONTH(date_reported)=MONTH(NOW()) AND YEAR(date_reported)=YEAR(NOW())")->fetchColumn();

$recent = $conn->query("
    SELECT v.id, v.violation, v.offense, v.status, v.date_reported, v.scanner_name,
           u.student_id AS sid, u.fullname
    FROM violations v
    JOIN users u ON v.student_id = u.id
    ORDER BY v.date_reported DESC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

/* Leaderboards — who/what/where has the most violations (per the role spec). */
$topViolators = $conn->query("
    SELECT u.fullname, u.student_id AS sid, COUNT(*) AS total
    FROM violations v JOIN users u ON v.student_id = u.id
    GROUP BY v.student_id ORDER BY total DESC, u.fullname LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$topViolations = $conn->query("
    SELECT violation, COUNT(*) AS total FROM violations
    GROUP BY violation ORDER BY total DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$topCourses = $conn->query("
    SELECT u.course, COUNT(*) AS total
    FROM violations v JOIN users u ON v.student_id = u.id
    WHERE u.course IS NOT NULL AND u.course <> ''
    GROUP BY u.course ORDER BY total DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

$assetBase = "../";
include "../includes/header.php";
include "../includes/navbar.php";
include "../includes/sidebar.php";
?>
<main class="vts-main">
<div class="vts-main-inner">

  <div class="page-head">
    <h1>OSA Dashboard</h1>
    <p>Overview of all recorded violations.</p>
  </div>

  <div class="kpi-grid">
    <a href="violations.php" class="kpi-card">
      <div class="kpi-icon navy"><i class="fas fa-list"></i></div>
      <div><div class="kpi-value" title="<?php echo number_format($total); ?>"><?php echo vts_compact_number($total); ?></div><div class="kpi-label">Total Records</div></div>
    </a>
    <div class="kpi-card">
      <div class="kpi-icon blue"><i class="fas fa-calendar-day"></i></div>
      <div><div class="kpi-value" title="<?php echo number_format($today); ?>"><?php echo vts_compact_number($today); ?></div><div class="kpi-label">Recorded Today</div></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-icon amber"><i class="fas fa-calendar"></i></div>
      <div><div class="kpi-value" title="<?php echo number_format($month); ?>"><?php echo vts_compact_number($month); ?></div><div class="kpi-label">This Month</div></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-icon red"><i class="fas fa-user-injured"></i></div>
      <div><div class="kpi-value" title="<?php echo number_format($atRisk); ?>"><?php echo vts_compact_number($atRisk); ?></div><div class="kpi-label">At Risk Students</div></div>
    </div>
  </div>

  <!-- Leaderboards: top violator / violation / course -->
  <div class="panel-grid-3" style="margin-bottom:22px;">
    <div class="panel">
      <div class="panel-head"><h3><i class="fas fa-user-clock" style="color:#c47f00;"></i> Top Violators</h3><a href="reports.php">View all</a></div>
      <div class="table-scroll table-responsive">
        <table class="mini-table">
          <thead><tr><th>Student</th><th>Violations</th></tr></thead>
          <tbody>
          <?php if ($topViolators): foreach ($topViolators as $t): ?>
            <tr><td><?php echo htmlspecialchars($t['fullname']); ?><div style="font-size:.72rem;color:var(--text-faint);"><?php echo htmlspecialchars($t['sid'] ?? ''); ?></div></td><td><b><?php echo (int)$t['total']; ?></b></td></tr>
          <?php endforeach; else: ?><tr><td colspan="2" class="u-faint">No data yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="panel">
      <div class="panel-head"><h3><i class="fas fa-triangle-exclamation" style="color:var(--danger);"></i> Top Violations</h3><a href="reports.php">View all</a></div>
      <div class="table-scroll table-responsive">
        <table class="mini-table">
          <thead><tr><th>Violation</th><th>Count</th></tr></thead>
          <tbody>
          <?php if ($topViolations): foreach ($topViolations as $t): ?>
            <tr><td><?php echo htmlspecialchars($t['violation']); ?></td><td><b><?php echo (int)$t['total']; ?></b></td></tr>
          <?php endforeach; else: ?><tr><td colspan="2" class="u-faint">No data yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="panel">
      <div class="panel-head"><h3><i class="fas fa-building-columns" style="color:#2563c9;"></i> Top Courses</h3><a href="reports.php">View all</a></div>
      <div class="table-scroll table-responsive">
        <table class="mini-table">
          <thead><tr><th>Course</th><th>Count</th></tr></thead>
          <tbody>
          <?php if ($topCourses): foreach ($topCourses as $t): ?>
            <tr><td><?php echo htmlspecialchars($t['course']); ?></td><td><b><?php echo (int)$t['total']; ?></b></td></tr>
          <?php endforeach; else: ?><tr><td colspan="2" class="u-faint">No data yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="vts-card">
    <div class="card-head">
      <h3>Latest Recorded Violations</h3>
      <a href="reports.php" class="btn-ghost btn-sm">Full report <i class="fas fa-arrow-right"></i></a>
    </div>
    <?php if (count($recent) > 0): ?>
    <?php /* Filters the rows already on screen; the full searchable listing is
             behind "Full report" above. */ ?>
    <div class="vts-filter-row">
      <label class="sr-only" for="recentFilter">Filter recent violations</label>
      <input id="recentFilter" type="search" class="vts-table-filter"
             data-filter-target="#osaRecentTable" aria-describedby="recentFilterStatus"
             placeholder="Filter by student or violation…">
      <span class="filter-status" id="recentFilterStatus" aria-live="polite"></span>
    </div>
    <div class="table-wrap table-responsive">
      <table class="data-table" id="osaRecentTable">
        <?php $showRec = vts_can_see_recorder(); /* OSA/Admin only */ ?>
        <thead><tr><th>Student</th><th>Violation</th><th>Violation Count</th><th>Status</th><?php if ($showRec): ?><th>Recorded By</th><?php endif; ?><th>Date</th></tr></thead>
        <tbody>
          <?php foreach ($recent as $row): ?>
          <tr class="row-link" onclick="location.href='view_violation.php?id=<?php echo $row['id']; ?>'">
            <td><div class="cell-title"><?php echo htmlspecialchars($row['fullname']); ?></div>
                <div class="cell-sub"><?php echo htmlspecialchars($row['sid']); ?></div></td>
            <td><?php echo htmlspecialchars($row['violation']); ?></td>
            <td><?php echo htmlspecialchars(offense_display($row['offense'])); ?></td>
            <td><?php echo status_badge($row['status']); ?></td>
            <?php if ($showRec): ?><td><?php echo htmlspecialchars($row['scanner_name'] ?: '—'); ?></td><?php endif; ?>
            <td><?php echo vts_datetime($row['date_reported']); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
      <div class="es-icon"><i class="fas fa-inbox"></i></div>
      <div class="es-title">No violations recorded yet</div>
    </div>
    <?php endif; ?>
  </div>

<?php /* The app shell (.vts-main-inner + <main>) is closed by
         includes/footer.php below -- closing it here as well emitted a
         stray </div></main> on every one of these pages. */ ?>
<?php include "../includes/footer.php"; ?>
