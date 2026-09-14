<?php
/* Admin dashboard: system-wide counts and recent activity. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (!in_array($_SESSION['role'] ?? '', ['Admin', 'OSA'], true)) {
    vts_deny_access();
}

// Best-effort, cheap no-op most of the time: export + deactivate students
// registered 6+ years ago so the roster doesn't quietly pile up with graduates.
vts_auto_archive_old_students($conn);

/* ---------- KPI numbers ---------- */
$totalStudents   = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role='Student'")->fetchColumn();
$totalViolations = (int)$conn->query("SELECT COUNT(*) FROM violations")->fetchColumn();
$pendingReview   = (int)$conn->query("SELECT COUNT(*) FROM violations WHERE DATE(date_reported)=CURDATE()")->fetchColumn();
$atRiskStudents  = (int)$conn->query("SELECT COUNT(*) FROM (SELECT student_id FROM violations GROUP BY student_id HAVING COUNT(*) >= 3) t")->fetchColumn();
$totalUsers      = (int)$conn->query("SELECT COUNT(*) FROM users")->fetchColumn();

$pendingPct = $totalViolations ? round($pendingReview / $totalViolations * 100, 2) : 0;
$atRiskPct  = $totalStudents ? round($atRiskStudents / $totalStudents * 100, 2) : 0;

/* ---------- Violations over time (by day), broken down by offense # ---------- */
$timeRows = $conn->query("
    SELECT DATE(date_reported) d,
           SUM(offense='First Offense') first_off,
           SUM(offense='Second Offense') second_off,
           SUM(offense NOT IN ('First Offense','Second Offense')) third_off
    FROM violations
    GROUP BY DATE(date_reported)
    ORDER BY d ASC
")->fetchAll(PDO::FETCH_ASSOC);

$timeDates = []; $timeLabels = []; $firstSeries = []; $secondSeries = []; $thirdSeries = [];
foreach ($timeRows as $r) {
  $timeDates[]    = $r['d'];
    $timeLabels[]   = date('M j', strtotime($r['d']));
    $firstSeries[]  = (int)$r['first_off'];
    $secondSeries[] = (int)$r['second_off'];
    $thirdSeries[]  = (int)$r['third_off'];
}
if (!$timeLabels) { $timeDates=['']; $timeLabels = ['No data']; $firstSeries=[0]; $secondSeries=[0]; $thirdSeries=[0]; }

/* ---------- Violations by type (donut) ---------- */
$byType = $conn->query("
    SELECT violation, COUNT(*) total
    FROM violations
    GROUP BY violation
    ORDER BY total DESC
")->fetchAll(PDO::FETCH_ASSOC);
$typeLabels = array_column($byType, 'violation');
$typeCounts = array_map('intval', array_column($byType, 'total'));

/* ---------- Violations by DEPARTMENT (college) ---------- */
$byDept = $conn->query("
    SELECT COALESCE(c.college_name,'Unassigned') dept, COUNT(v.id) total
    FROM violations v
    JOIN users u   ON v.student_id = u.id
    LEFT JOIN colleges c ON u.college_id = c.id
    GROUP BY dept
    ORDER BY total DESC
")->fetchAll(PDO::FETCH_ASSOC);
$deptLabels = array_column($byDept, 'dept');
$deptCounts = array_map('intval', array_column($byDept, 'total'));

/* ---------- Violations by year level (donut) ---------- */
$byYear = $conn->query("
    SELECT COALESCE(u.year_level,'Unknown') yr, COUNT(v.id) total
    FROM violations v
    JOIN users u ON v.student_id = u.id
    GROUP BY u.year_level
    ORDER BY u.year_level ASC
")->fetchAll(PDO::FETCH_ASSOC);
$yearLabels = array_column($byYear, 'yr');
$yearCounts = array_map('intval', array_column($byYear, 'total'));

/* ---------- Violations by section ---------- */
$bySection = $conn->query("
    SELECT COALESCE(u.section,'Unknown') sec, COUNT(v.id) total
    FROM violations v
    JOIN users u ON v.student_id = u.id
    GROUP BY u.section
    ORDER BY total DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

/* ---------- Top violations ---------- */
$topViol = array_slice($byType, 0, 7);

/* ---------- Top 3 repeat offenders (most recorded violations) ---------- */
$topOffenders = $conn->query("
    SELECT u.student_id, u.fullname, u.course, u.year_level,
           COUNT(*) AS total,
           SUM(v.severity IN ('Major','Grave')) AS serious
    FROM violations v
    JOIN users u ON v.student_id = u.id
    WHERE u.role = 'Student'
    GROUP BY v.student_id
    ORDER BY total DESC, serious DESC
    LIMIT 3
")->fetchAll(PDO::FETCH_ASSOC);

/* ---------- Recent violations ---------- */
$recent = $conn->query("
    SELECT v.id, u.student_id, u.fullname, u.course, u.year_level, u.section,
           v.violation, v.offense, v.status, v.date_reported,
           r.fullname AS reporter
    FROM violations v
    JOIN users u ON v.student_id = u.id
    LEFT JOIN users r ON v.reported_by = r.id
    ORDER BY v.date_reported DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

/* ---------- Recent activity (audit log) ----------

   ADMIN ONLY, panel and query both. This reads audit_logs, which is the
   record of what everyone with an account did — OSA included — so it is the
   audit log in miniature and belongs with it, not beside it. An OSA dashboard
   used to carry the last seven rows of it.

   The query is skipped rather than the panel merely hidden: fetching rows
   nobody may read is how a "just hide it in the template" gate turns into a
   leak the day someone adds a debug dump or an export. */
$vtsIsAdmin = (($_SESSION['role'] ?? '') === 'Admin');
$activity = [];
if ($vtsIsAdmin) try {
    $activity = $conn->query("
        SELECT user_name, role, action, target, details, created_at
        FROM audit_logs ORDER BY created_at DESC LIMIT 7
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $activity = []; }

function pct($n, $total) { return $total ? round($n / $total * 100) : 0; }

// Softer categorical palette — fixed slot order is the colorblind-safety
// mechanism (adjacent pairs validated), so never re-sort or cycle it.
$palette = ['#2a78d6','#eb6834','#1baf7a','#eda100','#e87ba4','#008300','#4a3aa7','#e34948'];
// Hot (most frequent) -> cold (least frequent) colors for the ranked lists below.
$typeHeatColors = array_map(fn($i) => vts_heat_color_rank($i, count($byType)), array_keys($byType));

$adminActive = 'dashboard';
include "../includes/admin_header.php";
?>

<!-- Welcome row -->
<div class="admin-welcome-row">
  <div>
    <h1><?php echo 'Welcome back, ' . htmlspecialchars(vts_first_name($_SESSION['fullname'] ?? '', 'Admin')) . '!'; ?></h1>
    <p>Today's overview.</p>
  </div>
  <div style="display:flex;align-items:center;gap:10px;">
    <div class="date-pill">
      <i class="far fa-calendar"></i>
      <?php echo vts_date('-30 days') . ' - ' . vts_date(time()); ?>
    </div>
    <button type="button" class="btn-outline btn-sm" onclick="location.reload()" title="Refresh"><i class="fas fa-rotate"></i></button>
  </div>
</div>

<!-- KPI CARDS -->
<div class="kpi-grid">
  <div class="kpi-card u-pointer" onclick="location.href='students.php'">
    <div class="kpi-top"><div class="kpi-icon blue"><i class="fas fa-user-graduate"></i></div>
      <div class="kpi-label">Total Students</div></div>
    <div class="kpi-value-row">
      <div class="kpi-value" title="<?php echo number_format($totalStudents); ?>"><?php echo vts_compact_number($totalStudents); ?></div>
    </div>
    <div class="kpi-meta"><b>100%</b> of all students</div>
    <div class="kpi-bar"><span style="width:100%;background:#2563c9;"></span></div>
  </div>
  <div class="kpi-card u-pointer" onclick="location.href='violations.php'">
    <div class="kpi-top"><div class="kpi-icon red"><i class="fas fa-triangle-exclamation"></i></div>
      <div class="kpi-label">Total Violations</div></div>
    <div class="kpi-value-row">
      <div class="kpi-value" title="<?php echo number_format($totalViolations); ?>"><?php echo vts_compact_number($totalViolations); ?></div>
    </div>
    <div class="kpi-meta"><b>100%</b> of all violations</div>
    <div class="kpi-bar"><span style="width:100%;background:var(--danger);"></span></div>
  </div>
  <div class="kpi-card u-pointer" onclick="location.href='violations.php'">
    <div class="kpi-top"><div class="kpi-icon amber"><i class="far fa-clock"></i></div>
      <div class="kpi-label">Recorded Today</div></div>
    <div class="kpi-value-row">
      <div class="kpi-value" title="<?php echo number_format($pendingReview); ?>"><?php echo vts_compact_number($pendingReview); ?></div>
    </div>
    <div class="kpi-meta"><b><?php echo $pendingPct; ?>%</b> of total violations</div>
    <div class="kpi-bar"><span style="width:<?php echo min($pendingPct,100); ?>%;background:#d8920f;"></span></div>
  </div>
  <div class="kpi-card u-pointer" onclick="location.href='students.php'">
    <div class="kpi-top"><div class="kpi-icon rose"><i class="fas fa-user-injured"></i></div>
      <div class="kpi-label">At Risk Students</div></div>
    <div class="kpi-value-row">
      <div class="kpi-value" title="<?php echo number_format($atRiskStudents); ?>"><?php echo vts_compact_number($atRiskStudents); ?></div>
      <?php if ($atRiskStudents > 0): ?><span class="kpi-flag"><i class="fas fa-circle-exclamation"></i> Attention</span><?php endif; ?>
    </div>
    <div class="kpi-meta"><b><?php echo $atRiskPct; ?>%</b> of all students</div>
    <div class="kpi-bar"><span style="width:<?php echo min($atRiskPct,100); ?>%;background:#d23a5a;"></span></div>
  </div>
  <div class="kpi-card u-pointer" onclick="location.href='users.php'">
    <div class="kpi-top"><div class="kpi-icon purple"><i class="fas fa-users"></i></div>
      <div class="kpi-label">Total Users</div></div>
    <div class="kpi-value-row">
      <div class="kpi-value" title="<?php echo number_format($totalUsers); ?>"><?php echo vts_compact_number($totalUsers); ?></div>
    </div>
    <div class="kpi-meta"><b>100%</b> of all users</div>
    <div class="kpi-bar"><span style="width:100%;background:#7b46c9;"></span></div>
  </div>
</div>

<!-- ROW: line chart + type donut + department ranking -->
<div class="panel-grid-3">
  <div class="panel">
    <div class="panel-head" style="align-items:center;">
      <h3>Violations Overview</h3>
      <select id="overviewRange" aria-label="Overview chart time range" class="vts-select" style="width:auto;min-width:116px;padding:6px 28px 6px 9px;font-size:.72rem;">
        <option value="today">Today</option>
        <option value="week">This Week</option>
        <option value="month" selected>This Month</option>
      </select>
    </div>
    <div class="chart-box" style="height:215px;"><canvas id="overviewChart"></canvas></div>
    <div id="overviewTotal" style="font-size:.74rem;color:var(--text-faint);margin-top:8px;"></div>
  </div>

  <div class="panel">
    <div class="panel-head"><h3>Violations by Type</h3><span style="font-size:.72rem;color:var(--text-faint);">Most (hot) to least (cool)</span></div>
    <?php if ($typeCounts): ?>
    <div class="donut-wrap">
      <div class="donut-canvas-box">
        <div class="chart-box" style="height:190px;"><canvas id="typeChart"></canvas></div>
        <div class="donut-center"><span class="dc-num"><?php echo $totalViolations; ?></span><span class="dc-lbl">Total</span></div>
      </div>
      <ul class="legend-list">
        <?php foreach ($byType as $i => $t): if ($i>=6) break; ?>
          <li><span class="sw" style="background:<?php echo $typeHeatColors[$i]; ?>"></span>
            <?php echo htmlspecialchars($t['violation']); ?>
            <span class="lg-val"><?php echo pct($t['total'],$totalViolations); ?>% (<?php echo $t['total']; ?>)</span></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php else: ?>
      <p class="u-note">No violation data yet.</p>
    <?php endif; ?>
  </div>

  <div class="panel">
    <div class="panel-head"><h3>Dept. with Highest Violations</h3><a href="statistic.php">View all</a></div>
    <?php if ($deptCounts): ?>
      <div class="chart-box" style="height:205px;"><canvas id="deptChart"></canvas></div>
    <?php else: ?>
      <p class="u-note">No department data yet.</p>
    <?php endif; ?>
  </div>
</div>

<!-- ROW: Top 3 repeat offenders (real-time watchlist from the CITE meeting) -->
<div class="panel" style="margin-bottom:22px;">
  <div class="panel-head"><h3><i class="fas fa-user-clock" style="color:#c47f00;"></i> Top 3 Repeat Offenders</h3><a href="violations.php">View all</a></div>
  <?php if ($topOffenders): ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px;">
      <?php foreach ($topOffenders as $i => $o):
        $rankBg = $i === 0 ? '#b0122b' : ($i === 1 ? '#c47f00' : '#5a6a8a');
        $serious = (int)$o['serious'];
      ?>
        <div style="display:flex;align-items:center;gap:12px;padding:14px;border:1px solid #e6eaf2;border-radius:12px;background:#fff;">
          <div style="flex:0 0 auto;width:40px;height:40px;border-radius:50%;background:<?php echo $rankBg; ?>;color:#fff;font-weight:800;display:flex;align-items:center;justify-content:center;font-size:1.05rem;">#<?php echo $i+1; ?></div>
          <div style="min-width:0;">
            <div style="font-weight:800;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($o['fullname']); ?></div>
            <div style="font-size:.78rem;color:var(--text-soft);"><?php echo htmlspecialchars($o['student_id'] ?? '—'); ?><?php echo $o['year_level'] ? ' · '.htmlspecialchars($o['year_level']) : ''; ?></div>
            <div style="margin-top:5px;">
              <span style="display:inline-block;padding:1px 9px;border-radius:999px;font-size:.72rem;font-weight:800;color:#b0122b;background:#fdeaee;"><?php echo (int)$o['total']; ?> violations</span>
              <?php if ($serious > 0): ?>
                <span style="display:inline-block;padding:1px 9px;border-radius:999px;font-size:.72rem;font-weight:800;color:#c47f00;background:#fdf3e0;"><?php echo $serious; ?> serious</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="u-note">No repeat offenders yet.</p>
  <?php endif; ?>
</div>

<!-- ROW: year-level donut + section table + top violations table -->
<div class="panel-grid-3b">
  <div class="panel">
    <div class="panel-head"><h3>Violations by Year Level</h3><a href="statistic.php">View all</a></div>
    <?php if ($yearCounts): ?>
    <div class="donut-wrap">
      <div class="donut-canvas-box">
        <div class="chart-box" style="height:190px;"><canvas id="yearChart"></canvas></div>
        <div class="donut-center"><span class="dc-num"><?php echo array_sum($yearCounts); ?></span><span class="dc-lbl">Total</span></div>
      </div>
      <ul class="legend-list">
        <?php foreach ($byYear as $i => $y): ?>
          <li><span class="sw" style="background:<?php echo $palette[$i%count($palette)]; ?>"></span>
            <?php echo htmlspecialchars($y['yr']); ?>
            <span class="lg-val"><?php echo pct($y['total'],array_sum($yearCounts)); ?>% (<?php echo $y['total']; ?>)</span></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php else: ?><p class="u-note">No data yet.</p><?php endif; ?>
  </div>

  <div class="panel">
    <div class="panel-head"><h3>Violations by Section / Set</h3><a href="statistic.php">View all</a></div>
    <div class="table-scroll table-responsive">
      <table class="mini-table">
        <thead><tr><th>Section / Set</th><th>Violations</th><th>Percentage</th></tr></thead>
        <tbody>
        <?php if ($bySection): $secTotal = array_sum(array_map('intval',array_column($bySection,'total'))); ?>
          <?php foreach ($bySection as $i => $s): $p = pct($s['total'],$secTotal); ?>
            <tr>
              <td><?php echo htmlspecialchars($s['sec']); ?></td>
              <td><?php echo $s['total']; ?></td>
              <td><?php echo $p; ?>%
                <span class="mini-pct-track"><span class="mini-pct-fill" style="width:<?php echo $p; ?>%;background:<?php echo $palette[$i%count($palette)]; ?>"></span></span>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?><tr><td colspan="3" class="u-faint">No data yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h3>Top Violations</h3><a href="statistic.php">View all</a></div>
    <div class="table-scroll table-responsive">
      <table class="mini-table">
        <thead><tr><th>Violation</th><th>Violations</th><th>Percentage</th></tr></thead>
        <tbody>
        <?php if ($topViol): ?>
          <?php foreach ($topViol as $i => $t): $p = pct($t['total'],$totalViolations); ?>
            <tr>
              <td><?php echo htmlspecialchars($t['violation']); ?></td>
              <td><?php echo $t['total']; ?></td>
              <td><?php echo $p; ?>%
                <span class="mini-pct-track"><span class="mini-pct-fill" style="width:<?php echo $p; ?>%;background:<?php echo $typeHeatColors[$i]; ?>"></span></span>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?><tr><td colspan="3" class="u-faint">No data yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- RECENT VIOLATIONS -->
<div class="recent-table-wrap table-responsive">
  <div class="panel-head"><h3>Recent Violations</h3><a href="violations.php">View all violations &rarr;</a></div>
  <div class="table-scroll table-responsive">
  <table class="data-table">
    <thead>
      <tr>
        <th>ID</th><th>Student ID</th><th>Student Name</th><th>Course</th><th>Year</th>
        <th>Section</th><th>Violation</th><th>Violation Count</th><th>Date &amp; Time</th><th>Reported By</th><th>Status</th>
      </tr>
    </thead>
    <tbody>
    <?php if ($recent): ?>
      <?php foreach ($recent as $r): ?>
        <tr>
          <td>V-<?php echo str_pad($r['id'],4,'0',STR_PAD_LEFT); ?></td>
          <td><?php echo htmlspecialchars($r['student_id'] ?? '-'); ?></td>
          <td><?php echo htmlspecialchars($r['fullname']); ?></td>
          <td><?php echo htmlspecialchars($r['course'] ?? '-'); ?></td>
          <td><?php echo htmlspecialchars($r['year_level'] ?? '-'); ?></td>
          <td><?php echo htmlspecialchars($r['section'] ?? '-'); ?></td>
          <td><?php echo htmlspecialchars($r['violation']); ?></td>
          <td><?php echo htmlspecialchars(offense_display($r['offense'])); ?></td>
          <td><?php echo vts_datetime($r['date_reported']); ?></td>
          <td><?php echo htmlspecialchars($r['reporter'] ?? 'System'); ?></td>
          <td><?php echo status_badge($r['status']); ?></td>
        </tr>
      <?php endforeach; ?>
    <?php else: ?>
      <tr><td colspan="11" class="table-empty">No violations recorded yet.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- ACTIVITY LOG — Admin only, like the audit log it is a window onto.
     It is a standalone .panel and not part of a grid, so an OSA dashboard
     simply ends at Recent Violations with no hole where this was. -->
<?php if ($vtsIsAdmin): ?>
<div class="panel">
  <div class="panel-head"><h3><i class="fas fa-clock-rotate-left" style="color:var(--d-navy,var(--navy));"></i> Recent Activity</h3><a href="audit_log.php">View audit log &rarr;</a></div>
  <?php if ($activity): ?>
    <div class="activity-list">
      <?php
        $actIcon = function($a){
            $a = strtolower($a);
            if (str_contains($a,'login'))  return 'fa-right-to-bracket';
            if (str_contains($a,'delete')) return 'fa-trash';
            if (str_contains($a,'clear'))  return 'fa-check-double';
            if (str_contains($a,'import')) return 'fa-file-import';
            if (str_contains($a,'send'))   return 'fa-paper-plane';
            if (str_contains($a,'edit') || str_contains($a,'update')) return 'fa-pen';
            if (str_contains($a,'add') || str_contains($a,'creat'))   return 'fa-plus';
            return 'fa-circle-info';
        };
        foreach ($activity as $a):
      ?>
        <div class="activity-item">
          <span class="activity-dot"><i class="fas <?php echo $actIcon($a['action']); ?>"></i></span>
          <div class="activity-body">
            <div class="a-act"><?php echo htmlspecialchars($a['action']); ?><?php echo $a['target'] ? ' · ' . htmlspecialchars($a['target']) : ''; ?></div>
            <div class="a-meta"><b><?php echo htmlspecialchars($a['user_name'] ?? 'System'); ?></b><?php echo $a['role'] ? ' (' . htmlspecialchars($a['role']) . ')' : ''; ?><?php echo $a['details'] ? ' — ' . htmlspecialchars(mb_strimwidth($a['details'],0,70,'…')) : ''; ?></div>
          </div>
          <span class="activity-time"><?php echo htmlspecialchars(vts_datetime($a['created_at'])); ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="u-note">No recorded activity yet.</p>
  <?php endif; ?>
</div>
<?php endif; /* $vtsIsAdmin */ ?>

<script src="../assets/vendor/chart.umd.min.js"></script>
<script>
const PALETTE = <?php echo json_encode($palette); ?>;
Chart.defaults.font.family = "'Inter', Arial, sans-serif";
Chart.defaults.font.size = 13;
Chart.defaults.color = '#54627f';
// Cleaner, higher-contrast tooltips shared by every chart
Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(21,34,56,.95)';
Chart.defaults.plugins.tooltip.padding = 10;
Chart.defaults.plugins.tooltip.cornerRadius = 8;
Chart.defaults.plugins.tooltip.titleFont = { weight:'700', size:12 };
Chart.defaults.plugins.tooltip.boxPadding = 5;

/* Daily comparison → stacked bars (ordinal light→dark blue ramp: 1st→3rd+).
   White 1px segment borders keep stacked segments readable. */
const overviewDates = <?php echo json_encode($timeDates); ?>;
const overviewLabels = <?php echo json_encode($timeLabels); ?>;
const overviewSeries = [
  <?php echo json_encode($firstSeries); ?>,
  <?php echo json_encode($secondSeries); ?>,
  <?php echo json_encode($thirdSeries); ?>
];
const overviewRange = document.getElementById('overviewRange');
const overviewTotal = document.getElementById('overviewTotal');
const overviewChart = new Chart(document.getElementById('overviewChart'), {
  type: 'bar',
  data: {
    labels: overviewLabels,
    datasets: [
      { label:'1st Offense', data:overviewSeries[0], backgroundColor:'#86b6ef', borderColor:'#ffffff', borderWidth:1, borderRadius:3, maxBarThickness:26 },
      { label:'2nd Offense', data:overviewSeries[1], backgroundColor:'#2a78d6', borderColor:'#ffffff', borderWidth:1, borderRadius:3, maxBarThickness:26 },
      { label:'3rd+ Offense', data:overviewSeries[2], backgroundColor:'#104281', borderColor:'#ffffff', borderWidth:1, borderRadius:3, maxBarThickness:26 }
    ]
  },
  options: {
    responsive:true, maintainAspectRatio:false,
    interaction:{ mode:'index', intersect:false },
    layout:{ padding:{ top:4, right:6 } },
    plugins:{ legend:{ position:'top', align:'end', labels:{ usePointStyle:true, pointStyle:'circle', boxWidth:7, padding:16, font:{ size:12, weight:'600' } } } },
    scales:{
      y:{ stacked:true, beginAtZero:true, ticks:{ precision:0, padding:8 }, grid:{ color:'#eef2f8', drawBorder:false } },
      x:{ stacked:true, ticks:{ padding:6, autoSkip:true, maxRotation:0 }, grid:{ display:false, drawBorder:false } }
    }
  }
});

function updateOverviewRange(range){
  const now = new Date();
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const monday = new Date(today);
  const day = monday.getDay() || 7;
  monday.setDate(monday.getDate() - day + 1);
  const monthStart = new Date(today.getFullYear(), today.getMonth(), 1);
  const start = range === 'today' ? today : (range === 'week' ? monday : monthStart);
  const indexes = overviewDates.reduce((found, iso, index) => {
    if (!iso) return found;
    const date = new Date(iso + 'T00:00:00');
    if (date >= start && date <= today) found.push(index);
    return found;
  }, []);
  const selected = indexes;
  overviewChart.data.labels = selected.length ? selected.map(index => overviewLabels[index]) : ['No data'];
  overviewChart.data.datasets.forEach((dataset, series) => {
    dataset.data = selected.length ? selected.map(index => overviewSeries[series][index]) : [0];
  });
  const total = selected.reduce((sum, index) => sum + overviewSeries[0][index] + overviewSeries[1][index] + overviewSeries[2][index], 0);
  const title = range === 'today' ? 'Today' : (range === 'week' ? 'This week' : 'This month');
  overviewTotal.textContent = 'Total ' + title.toLowerCase() + ': ' + total.toLocaleString();
  overviewChart.update();
}
overviewRange.addEventListener('change', function(){ updateOverviewRange(this.value); });
updateOverviewRange(overviewRange.value);

/* Shared donut tooltip: name, count and share of the total */
function donutTooltip(ctx){
  const total = ctx.dataset.data.reduce((a,b)=>a+b,0) || 1;
  return ' ' + ctx.label + ': ' + ctx.parsed + ' (' + Math.round(ctx.parsed/total*100) + '%)';
}

<?php if ($typeCounts): ?>
new Chart(document.getElementById('typeChart'), {
  type:'doughnut',
  data:{ labels:<?php echo json_encode($typeLabels); ?>,
         datasets:[{ data:<?php echo json_encode($typeCounts); ?>, backgroundColor:<?php echo json_encode($typeHeatColors); ?>, borderColor:'#ffffff', borderWidth:2 }] },
  options:{ cutout:'70%', plugins:{ legend:{ display:false }, tooltip:{ callbacks:{ label:donutTooltip } } } }
});
<?php endif; ?>

<?php if ($deptCounts): ?>
new Chart(document.getElementById('deptChart'), {
  type:'bar',
  data:{ labels:<?php echo json_encode($deptLabels); ?>,
         datasets:[{ data:<?php echo json_encode($deptCounts); ?>,
            backgroundColor:'#2a78d6', borderRadius:5, barThickness:14 }] },
  options:{ indexAxis:'y', responsive:true, maintainAspectRatio:false,
    plugins:{ legend:{ display:false } },
    scales:{ x:{ beginAtZero:true, grid:{ color:'#eef2f8' } }, y:{ grid:{ display:false } } } }
});
<?php endif; ?>

<?php if ($yearCounts): ?>
new Chart(document.getElementById('yearChart'), {
  type:'doughnut',
  data:{ labels:<?php echo json_encode($yearLabels); ?>,
         datasets:[{ data:<?php echo json_encode($yearCounts); ?>, backgroundColor:PALETTE, borderColor:'#ffffff', borderWidth:2 }] },
  options:{ cutout:'70%', plugins:{ legend:{ display:false }, tooltip:{ callbacks:{ label:donutTooltip } } } }
});
<?php endif; ?>

/* Animated counters — count plain-integer KPI/stat values up from zero. */
(function(){
  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  const els = document.querySelectorAll('.kpi-value, .stat-value');
  els.forEach(el => {
    const raw = (el.textContent || '').trim().replace(/,/g,'');
    if (!/^\d+$/.test(raw)) return;                 // skip compact ("1.2K") or text values
    const target = parseInt(raw, 10);
    if (target <= 0) return;
    const dur = 750, t0 = performance.now();
    function tick(now){
      const p = Math.min((now - t0) / dur, 1);
      const eased = 1 - Math.pow(1 - p, 3);          // easeOutCubic
      el.textContent = Math.round(eased * target).toLocaleString();
      if (p < 1) requestAnimationFrame(tick);
    }
    el.textContent = '0';
    requestAnimationFrame(tick);
  });
})();
</script>

<?php include "../includes/admin_footer.php"; ?>
