<?php
/* Admin: violation statistics and charts. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (!in_array($_SESSION['role'] ?? '', ['Admin', 'OSA'], true)) {
    vts_deny_access();
}

$totalViolations = (int)$conn->query("SELECT COUNT(*) FROM violations")->fetchColumn();
$totalStudents   = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role='Student'")->fetchColumn();

/* Highest violation TYPE (bar graph) */
$byType = $conn->query("
    SELECT violation, COUNT(*) total
    FROM violations
    GROUP BY violation
    ORDER BY total DESC
")->fetchAll(PDO::FETCH_ASSOC);
$typeLabels = array_column($byType, 'violation');
$typeCounts = array_map('intval', array_column($byType, 'total'));

/* Department with highest violations (bar graph) */
$byDept = $conn->query("
    SELECT COALESCE(c.college_name,'Unassigned') dept, COUNT(v.id) total
    FROM violations v
    JOIN users u ON v.student_id = u.id
    LEFT JOIN colleges c ON u.college_id = c.id
    GROUP BY dept
    ORDER BY total DESC
")->fetchAll(PDO::FETCH_ASSOC);
$deptLabels = array_column($byDept, 'dept');
$deptCounts = array_map('intval', array_column($byDept, 'total'));

/* Status breakdown (donut) */
$byStatus = $conn->query("
  SELECT offense AS status, COUNT(*) total FROM violations GROUP BY offense
  ORDER BY CASE offense
    WHEN 'First Offense' THEN 1
    WHEN 'Second Offense' THEN 2
    WHEN 'Third Offense' THEN 3
    ELSE 4 END
")->fetchAll(PDO::FETCH_ASSOC);
$statLabels = array_column($byStatus, 'status');
$statCounts = array_map('intval', array_column($byStatus, 'total'));

/* Violations over time (line) */
$timeRows = $conn->query("
    SELECT DATE(date_reported) d, COUNT(*) total
    FROM violations GROUP BY DATE(date_reported) ORDER BY d ASC
")->fetchAll(PDO::FETCH_ASSOC);
$timeLabels = []; $timeCounts = [];
foreach ($timeRows as $r) { $timeLabels[] = date('M j', strtotime($r['d'])); $timeCounts[] = (int)$r['total']; }
if (!$timeLabels) { $timeLabels=['No data']; $timeCounts=[0]; }

/* Top students */
$topStudents = $conn->query("
    SELECT u.fullname, u.course, COUNT(v.id) total
    FROM violations v JOIN users u ON v.student_id = u.id
    GROUP BY u.id ORDER BY total DESC LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

function pct($n,$t){ return $t ? round($n/$t*100) : 0; }
$palette = ['#2563c9','#1b7f46','#d8920f','#e03535','#7b46c9','#8a8f99','#b5651d','#0d9488','#c2185b','#5a6a8a'];
$typeHeatColors = array_map(fn($i) => vts_heat_color_rank($i, count($byType)), array_keys($byType));

$highestType = $byType[0]['violation'] ?? '—';
$highestDept = $byDept[0]['dept'] ?? '—';

$adminActive = 'statistic';
include "../includes/admin_header.php";
?>

<div class="admin-welcome-row">
  <div>
    <h1>Statistics &amp; Reports</h1>
    <p>Visual breakdown of violations across types, departments, and time.</p>
  </div>
</div>

<!-- Highlight cards -->
<div class="kpi-grid" style="grid-template-columns:repeat(4,1fr);">
  <div class="kpi-card">
    <div class="kpi-top"><div class="kpi-icon red"><i class="fas fa-triangle-exclamation"></i></div>
      <div class="kpi-label">Total Violations</div></div>
    <div class="kpi-value" title="<?php echo number_format($totalViolations); ?>"><?php echo vts_compact_number($totalViolations); ?></div>
  </div>
  <div class="kpi-card">
    <div class="kpi-top"><div class="kpi-icon blue"><i class="fas fa-ranking-star"></i></div>
      <div class="kpi-label">Highest Violation Type</div></div>
    <div class="kpi-value" style="font-size:1.15rem;line-height:1.3;margin-top:6px;"><?php echo htmlspecialchars($highestType); ?></div>
  </div>
  <div class="kpi-card">
    <div class="kpi-top"><div class="kpi-icon purple"><i class="fas fa-building-columns"></i></div>
      <div class="kpi-label">Top Department</div></div>
    <div class="kpi-value" style="font-size:1.15rem;line-height:1.3;margin-top:6px;"><?php echo htmlspecialchars($highestDept); ?></div>
  </div>
  <div class="kpi-card">
    <div class="kpi-top"><div class="kpi-icon amber"><i class="fas fa-user-graduate"></i></div>
      <div class="kpi-label">Total Students</div></div>
    <div class="kpi-value" title="<?php echo number_format($totalStudents); ?>"><?php echo vts_compact_number($totalStudents); ?></div>
  </div>
</div>

<!-- HIGHEST VIOLATION TYPE (bar) + DEPARTMENT (bar) -->
<div class="panel-grid-3" style="grid-template-columns:1fr 1fr;">
  <div class="panel">
    <div class="panel-head"><h3>Highest Violation Type</h3></div>
    <?php if ($typeCounts): ?>
      <div class="chart-box" style="height:230px;"><canvas id="typeBar"></canvas></div>
    <?php else: ?><p class="u-faint">No data yet.</p><?php endif; ?>
  </div>
  <div class="panel">
    <div class="panel-head"><h3>Department with Highest Violations</h3></div>
    <?php if ($deptCounts): ?>
      <div class="chart-box" style="height:230px;"><canvas id="deptBar"></canvas></div>
    <?php else: ?><p class="u-faint">No data yet.</p><?php endif; ?>
  </div>
</div>

<!-- Offense donut + over-time line. Two panels, so TWO columns — a 3-column
     grid here left a whole empty column of dead space on the right. -->
<div class="panel-grid-3" style="grid-template-columns:1fr 1fr;">
  <div class="panel">
    <div class="panel-head"><h3>By Offense Number</h3></div>
    <?php if ($statCounts): ?>
    <div class="donut-wrap">
      <div class="donut-canvas-box"><div class="chart-box" style="height:190px;"><canvas id="statChart"></canvas></div>
        <div class="donut-center"><span class="dc-num"><?php echo array_sum($statCounts); ?></span><span class="dc-lbl">Total</span></div></div>
      <ul class="legend-list">
        <?php foreach ($byStatus as $i=>$s): ?>
          <li><span class="sw" style="background:<?php echo $palette[$i%count($palette)]; ?>"></span>
            <?php echo htmlspecialchars($s['status']); ?>
            <span class="lg-val"><?php echo $s['total']; ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php else: ?><p class="u-faint">No data yet.</p><?php endif; ?>
  </div>

  <div class="panel">
    <div class="panel-head"><h3>Violations Over Time</h3></div>
    <div class="chart-box" style="height:215px;"><canvas id="timeLine"></canvas></div>
  </div>
</div>

<!-- Top students -->
<div class="recent-table-wrap table-responsive">
  <div class="panel-head"><h3>Students with Most Violations</h3></div>
  <?php /* The whole ranking is rendered at once, so finding one student meant
           scrolling it. Filters the rows already on the page — no reload. */ ?>
  <div class="vts-filter-row">
    <label class="sr-only" for="topStudentFilter">Filter students</label>
    <input id="topStudentFilter" type="search" class="vts-table-filter"
           data-filter-target="#topStudentsTable" aria-describedby="topStudentFilterStatus"
           placeholder="Filter by name or course…">
    <span class="filter-status" id="topStudentFilterStatus" aria-live="polite"></span>
  </div>
  <table class="data-table" id="topStudentsTable">
    <thead><tr><th>#</th><th>Student Name</th><th>Course</th><th>Total Violations</th><th>Share</th></tr></thead>
    <tbody>
    <?php if ($topStudents): $studentHeatColors = array_map(fn($i) => vts_heat_color_rank($i, count($topStudents)), array_keys($topStudents)); ?>
      <?php foreach ($topStudents as $i=>$s): $p = pct($s['total'],$totalViolations); ?>
        <tr>
          <td><?php echo $i+1; ?></td>
          <td><?php echo htmlspecialchars($s['fullname']); ?></td>
          <td><?php echo htmlspecialchars($s['course'] ?? '-'); ?></td>
          <td><?php echo $s['total']; ?></td>
          <td><?php echo $p; ?>%
            <span class="mini-pct-track"><span class="mini-pct-fill" style="width:<?php echo $p; ?>%;background:<?php echo $studentHeatColors[$i]; ?>"></span></span></td>
        </tr>
      <?php endforeach; ?>
    <?php else: ?><tr><td colspan="5" style="text-align:center;color:var(--text-faint);">No data yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<script src="../assets/vendor/chart.umd.min.js"></script>
<script>
const PALETTE = <?php echo json_encode($palette); ?>;
Chart.defaults.font.family = "'Inter', Arial, sans-serif";
Chart.defaults.font.size = 11;
Chart.defaults.color = '#5a6a8a';

<?php if ($typeCounts): ?>
new Chart(document.getElementById('typeBar'), {
  type:'bar',
  data:{ labels:<?php echo json_encode($typeLabels); ?>,
         datasets:[{ label:'Violations', data:<?php echo json_encode($typeCounts); ?>,
            backgroundColor:<?php echo json_encode($typeHeatColors); ?>, borderRadius:6, barThickness:22 }] },
  options:{ indexAxis:'y', responsive:true, maintainAspectRatio:false,
    plugins:{ legend:{ display:false } },
    scales:{ x:{ beginAtZero:true, grid:{ color:'#eef2f8' } }, y:{ grid:{ display:false } } } }
});
<?php endif; ?>

<?php if ($deptCounts): ?>
new Chart(document.getElementById('deptBar'), {
  type:'bar',
  data:{ labels:<?php echo json_encode($deptLabels); ?>,
         datasets:[{ label:'Violations', data:<?php echo json_encode($deptCounts); ?>,
            backgroundColor:<?php echo json_encode(array_slice($palette,0,max(1,count($deptCounts)))); ?>, borderRadius:6, barThickness:22 }] },
  options:{ indexAxis:'y', responsive:true, maintainAspectRatio:false,
    plugins:{ legend:{ display:false } },
    scales:{ x:{ beginAtZero:true, grid:{ color:'#eef2f8' } }, y:{ grid:{ display:false } } } }
});
<?php endif; ?>

<?php if ($statCounts): ?>
new Chart(document.getElementById('statChart'), {
  type:'doughnut',
  data:{ labels:<?php echo json_encode($statLabels); ?>,
         datasets:[{ data:<?php echo json_encode($statCounts); ?>, backgroundColor:PALETTE, borderWidth:0 }] },
  options:{ cutout:'70%', plugins:{ legend:{ display:false } } }
});
<?php endif; ?>

new Chart(document.getElementById('timeLine'), {
  type:'line',
  data:{ labels:<?php echo json_encode($timeLabels); ?>,
         datasets:[{ label:'Violations', data:<?php echo json_encode($timeCounts); ?>,
            borderColor:'#2563c9', backgroundColor:'rgba(37,99,201,.12)', fill:true, tension:.35, pointRadius:3 }] },
  options:{ responsive:true, maintainAspectRatio:false,
    plugins:{ legend:{ display:false } },
    scales:{ y:{ beginAtZero:true, grid:{ color:'#eef2f8' } }, x:{ grid:{ display:false } } } }
});
</script>

<?php include "../includes/admin_footer.php"; ?>
