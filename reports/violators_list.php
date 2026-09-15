<?php
/* Printable "List of Students with Violations" — mirrors the exact columns of
   the admin Violations table and respects the same filters (search / date /
   year / course / violation). Browser-print (Ctrl+P / Save as PDF); no library.
   Opened from the Violations page "Print List" button. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (!in_array($_SESSION['role'] ?? '', ['Admin','OSA','OSA Staff'], true)) {
    exit("Access Denied");
}

// Same filter inputs as admin/violations.php
$search    = trim($_GET['search'] ?? '');
$when      = $_GET['when'] ?? 'all';
$from      = trim($_GET['from'] ?? '');
$to        = trim($_GET['to'] ?? '');
$yearLevel = trim($_GET['year_level'] ?? '');
$course    = trim($_GET['course'] ?? '');
$vfilter   = trim($_GET['violation'] ?? '');

$sql = "
    SELECT v.*, u.student_id AS sid, u.fullname, u.course, u.year_level, u.section,
           r.fullname AS reporter_name, r.role AS reporter_role
    FROM violations v
    INNER JOIN users u ON v.student_id = u.id
    LEFT JOIN users r ON v.reported_by = r.id";
$where = []; $params = [];
if ($search !== "")    { $where[] = "(u.student_id LIKE :s OR u.fullname LIKE :s OR v.violation LIKE :s)"; $params[':s'] = "%{$search}%"; }
if ($yearLevel !== "") { $where[] = "u.year_level = :yl"; $params[':yl'] = $yearLevel; }
if ($course !== "")    { $where[] = "u.course = :crs"; $params[':crs'] = $course; }
if ($vfilter !== "")   { $where[] = "v.violation = :vf"; $params[':vf'] = $vfilter; }
$isDate = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
if ($isDate($from) && $isDate($to)) {
    $where[] = "DATE(v.date_reported) BETWEEN :df AND :dt"; $params[':df'] = $from; $params[':dt'] = $to;
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
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Human-readable filter summary line
$parts = [];
if ($search !== '')    $parts[] = "Search: “{$search}”";
if ($vfilter !== '')   $parts[] = "Violation: {$vfilter}";
if ($course !== '')    $parts[] = "Course: {$course}";
if ($yearLevel !== '') $parts[] = "Year: {$yearLevel}";
if ($isDate($from) && $isDate($to)) $parts[] = "Dates: {$from} → {$to}";
elseif ($when !== 'all') $parts[] = "Period: " . ucfirst($when);
$filterSummary = $parts ? implode('  ·  ', $parts) : 'All records';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<!-- These slips build their own <head> instead of including header.php,
     so they never inherited the viewport tag the rest of the app has.
     Without it a phone lays the page out at ~980px and zooms out, which
     is exactly how staff read a slip at the gate. -->
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>List of Students with Violations</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0;font-family:'Segoe UI',Arial,sans-serif;}
  body{background:#eef1f6;padding:22px;color:#1a2340;}
  .toolbar{max-width:1040px;margin:0 auto 14px;display:flex;gap:10px;justify-content:flex-end;}
  .btn{padding:9px 18px;border-radius:8px;border:0;font-weight:700;cursor:pointer;text-decoration:none;font-size:.9rem;}
  .btn-print{background:#1a3a6b;color:#fff;} .btn-back{background:#fff;color:#1a3a6b;border:1px solid #ccd4e4;}
  .sheet{max-width:1040px;margin:0 auto;background:#fff;border:1px solid #cfd6e4;padding:26px 30px;}
  .head{text-align:center;border-bottom:2px solid #1a2340;padding-bottom:10px;margin-bottom:8px;}
  .head .sch{font-size:1.15rem;font-weight:800;} .head .addr{font-size:.76rem;color:#333;}
  .head .osa{font-size:.8rem;font-weight:700;}
  .title{text-align:center;font-size:1rem;font-weight:800;letter-spacing:.06em;margin:10px 0 4px;}
  .meta{display:flex;justify-content:space-between;font-size:.76rem;color:#555;margin:6px 2px 12px;flex-wrap:wrap;gap:6px;}
  table{width:100%;border-collapse:collapse;font-size:.78rem;}
  th{background:#1a3a6b;color:#fff;text-align:left;padding:7px 8px;font-size:.72rem;text-transform:uppercase;letter-spacing:.02em;border:1px solid #14305a;}
  td{padding:6px 8px;border:1px solid #d8dfea;vertical-align:top;}
  tbody tr:nth-child(even){background:#f6f8fc;}
  .sev{display:inline-block;padding:0 7px;border-radius:999px;font-size:.66rem;font-weight:800;}
  .sev.Grave{background:#fdeaee;color:#b0122b;} .sev.Major{background:#fdf3e0;color:#c47f00;} .sev.Minor{background:#e9f7ef;color:#2a7a4b;}
  .cleared{color:#5a6a8a;font-size:.66rem;font-weight:800;}
  .count{text-align:right;font-size:.78rem;color:#333;margin-top:10px;font-weight:700;}
  .sign{display:flex;gap:30px;margin-top:34px;}
  .sign .s{flex:1;} .sign .line{border-top:1px solid #333;margin-top:30px;padding-top:4px;font-size:.76rem;}
  .sign .nm{font-weight:800;} .sign .rl{font-size:.72rem;color:#444;}
  .foot{text-align:right;font-size:.64rem;color:#888;margin-top:12px;}
  /* Wide table scrolls sideways on a phone instead of stretching the page. */
  .table-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;}
  .table-scroll table{min-width:760px;}
  @media (max-width:640px){
    body{padding:14px 10px;}
    .sheet{padding:16px 14px;}
    .toolbar{justify-content:stretch;}
    .toolbar .btn{flex:1 1 auto;text-align:center;}
  }
  @media print{
    body{background:#fff;padding:0;} .toolbar{display:none;}
    .sheet{border:0;max-width:100%;margin:0;padding:0;}
    /* Never clip or scroll on paper. */
    .table-scroll{overflow:visible;} .table-scroll table{min-width:0;}
    th{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  }
</style>
</head>
<body>
  <div class="toolbar">
    <button type="button" onclick="vtsBack()" class="btn btn-back">&larr; Back</button>
    <button class="btn btn-print" onclick="window.print()">🖨 Print / Save as PDF</button>
  </div>
  <div class="sheet">
    <div class="head">
      <div class="sch">GOLDEN WEST COLLEGES, INC.</div>
      <div class="addr">San Jose Drive, Alaminos City, Pangasinan</div>
      <div class="osa">Office of Student Affairs</div>
    </div>
    <h1 class="title">LIST OF STUDENTS WITH VIOLATIONS</h1>
    <div class="meta">
      <span><b>Filter:</b> <?php echo htmlspecialchars($filterSummary); ?></span>
      <span>Generated: <?php echo htmlspecialchars(vts_datetime(time())); ?></span>
    </div>

    <div class="table-scroll table-responsive">
    <table>
      <thead><tr>
        <th style="width:34px;">No.</th>
        <th>Student ID</th><th>Name</th><th>Course</th><th>Year</th>
        <th>Violation</th><th>Severity</th><th>Violation Count</th><th>Recorded By</th><th>Date</th>
      </tr></thead>
      <tbody>
      <?php if ($rows): $n = 0; foreach ($rows as $r): $n++; $sev = $r['severity'] ?? 'Minor'; ?>
        <tr>
          <td><?php echo $n; ?></td>
          <td><?php echo htmlspecialchars($r['sid'] ?? '-'); ?></td>
          <td><?php echo htmlspecialchars($r['fullname']); ?></td>
          <td><?php echo htmlspecialchars($r['course'] ?? '-'); ?></td>
          <td><?php echo htmlspecialchars($r['year_level'] ?? '-'); ?></td>
          <td><?php echo htmlspecialchars($r['violation']); ?><?php echo !empty($r['cleared_at']) ? ' <span class="cleared">(CLEARED)</span>' : ''; ?></td>
          <td><span class="sev <?php echo htmlspecialchars($sev); ?>"><?php echo htmlspecialchars(strtoupper($sev)); ?></span></td>
          <td><?php echo htmlspecialchars(offense_display($r['offense'] ?? '-')); ?></td>
          <td><?php echo htmlspecialchars(violation_recorder($r)); ?></td>
          <td class="u-nowrap"><?php echo htmlspecialchars(vts_date($r['date_reported'])); ?></td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="10" style="text-align:center;color:#8a93a8;padding:20px;">No violation records match these filters.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    </div>
    <div class="count"><?php echo count($rows); ?> record(s)</div>

    <div class="sign">
      <div class="s"><div class="line">Prepared by</div></div>
      <div class="s"><div class="line"><span class="nm">ALMA G. VIRAY</span><br><span class="rl">Head, Office of Student Affairs</span></div></div>
    </div>
    <div class="foot">GWC-OSA · QR Shield · printed <?php echo date('Y-m-d H:i'); ?></div>
  </div>
  <?php if (isset($_GET['print'])): ?><script>window.addEventListener('load', () => window.print());</script><?php endif; ?>

<script>
function vtsBack(){
  if (document.referrer && history.length > 1) { history.back(); return; }
  if (window.opener && !window.opener.closed) { window.close(); return; }
  location.href = "../admin/violations.php";
}
</script>
</body>
</html>
