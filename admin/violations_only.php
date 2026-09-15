<?php
/* VIOLATIONS ONLY — every detail of each violation EXCEPT who recorded it.
   Deliberately privacy-safe: the marshal/officer who reported a violation is
   never shown or exported here, so this page can be printed, projected or
   handed to anyone (dean, parent, hearing panel) without exposing the person
   who filed the report. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (!in_array($_SESSION['role'] ?? '', ['Admin', 'OSA'], true)) {
    vts_deny_access();
}
vts_ensure_categorization_columns($conn);

$search   = trim($_GET['search'] ?? '');
$from     = trim($_GET['from'] ?? '');
$to       = trim($_GET['to'] ?? '');
$course   = trim($_GET['course'] ?? '');
$section  = trim($_GET['section'] ?? '');
$vfilter  = trim($_GET['violation'] ?? '');
$severity = trim($_GET['severity'] ?? '');
$college  = trim($_GET['college_id'] ?? '');

/* NOTE: v.reported_by / scanner_name are intentionally NOT selected. */
$sql = "SELECT v.id, v.violation, v.severity, v.offense, v.description, v.remarks,
               v.evidence, v.date_reported, v.cleared_at,
               u.student_id AS sid, u.fullname, u.course, u.year_level, u.section,
               COALESCE(col.college_name, u.course, '') AS dept
        FROM violations v
        INNER JOIN users u ON v.student_id = u.id
        LEFT JOIN colleges col ON col.id = u.college_id";
$where = []; $params = [];
if ($search !== '')  { $where[] = "(u.student_id LIKE :s OR u.fullname LIKE :s OR v.violation LIKE :s)"; $params[':s'] = "%{$search}%"; }
if ($course !== '')  { $where[] = "u.course = :crs";   $params[':crs'] = $course; }
if ($section !== '') { $where[] = "u.section = :sec";  $params[':sec'] = $section; }
if ($vfilter !== '') { $where[] = "v.violation = :vf"; $params[':vf'] = $vfilter; }
if ($severity !== ''){ $where[] = "v.severity = :sv";  $params[':sv'] = $severity; }
if ($college !== '') { $where[] = "u.college_id = :col"; $params[':col'] = (int)$college; }
$isDate = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);

/* A date the browser would not accept, or a range that runs backwards, used to
   be dropped in silence: the filter simply did nothing, or returned an empty
   table, with no way to tell that from "there are genuinely no records". Both
   are reported now. */
$filterErrors = [];
if ($from !== '' && !$isDate($from)) { $filterErrors[] = 'The "From" date was not a valid date, so it was ignored.'; $from = ''; }
if ($to   !== '' && !$isDate($to))   { $filterErrors[] = 'The "To" date was not a valid date, so it was ignored.';   $to   = ''; }
if ($from !== '' && $to !== '' && $from > $to) {
    $filterErrors[] = 'The "From" date is after the "To" date, so no record can fall between them. The dates were swapped.';
    [$from, $to] = [$to, $from];
}

if ($isDate($from)) { $where[] = "DATE(v.date_reported) >= :df"; $params[':df'] = $from; }
if ($isDate($to))   { $where[] = "DATE(v.date_reported) <= :dt"; $params[':dt'] = $to; }
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY v.date_reported DESC";

$loadError = '';
$rows = [];
/* Paged. This listing rendered every matching row in one go, so a full term's
   violations arrived as a single enormous table. The filter bar above narrows
   it; this makes whatever is left readable a page at a time. */
$perPage    = 25;
$page       = (isset($_GET['page']) && is_numeric($_GET['page'])) ? max(1, (int)$_GET['page']) : 1;
$totalRows  = 0;
$totalPages = 1;
try {
    $countSql = preg_replace('/^SELECT .*? FROM/s', 'SELECT COUNT(*) FROM', $sql, 1);
    $countSql = preg_replace('/\s+ORDER BY .*$/s', '', $countSql);
    $cs = $conn->prepare($countSql);
    $cs->execute($params);
    $totalRows  = (int)$cs->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) $page = $totalPages;   // stale ?page= after the filter narrows

    $st = $conn->prepare($sql . " LIMIT :vo_off, :vo_lim");
    foreach ($params as $k => $v) $st->bindValue($k, $v);
    $st->bindValue(':vo_off', ($page - 1) * $perPage, PDO::PARAM_INT);
    $st->bindValue(':vo_lim', $perPage, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Say that the list could not be read, rather than showing an empty table
    // that looks exactly like "no violations recorded".
    error_log('violations_only query failed: ' . $e->getMessage());
    $loadError = 'The violation list could not be loaded just now. Refresh the page, and tell the office if it keeps happening.';
}

/* Which filters are actually in force — echoed back so the count on screen is
   never mistaken for the whole database. */
$activeFilters = [];
if ($search   !== '') $activeFilters[] = 'matching "' . $search . '"';
if ($severity !== '') $activeFilters[] = strtolower($severity) . ' only';
if ($course   !== '') $activeFilters[] = 'course ' . $course;
if ($section  !== '') $activeFilters[] = 'set ' . $section;
if ($from !== '' || $to !== '') $activeFilters[] = 'dated ' . ($from ?: 'the beginning') . ' to ' . ($to ?: 'today');

$majorCount = count(array_filter($rows, fn($r) => ($r['severity'] ?? '') === 'Major'));

$violationOptions = $conn->query("SELECT DISTINCT violation FROM violations ORDER BY violation")->fetchAll(PDO::FETCH_COLUMN);
$courses          = $conn->query("SELECT DISTINCT course FROM users WHERE role='Student' AND course<>'' ORDER BY course")->fetchAll(PDO::FETCH_COLUMN);
$collegeOptions   = $conn->query("SELECT id, college_name FROM colleges ORDER BY college_name")->fetchAll(PDO::FETCH_ASSOC);

$assetBase = '../';
include "../includes/header.php";
include "../includes/navbar.php";
include "../includes/sidebar.php";
?>
<main class="vts-main">
  <div class="vts-main-inner">

    <div class="admin-welcome-row">
      <div><h1>Violations Only</h1>
        <p>Every violation detail — <b>without</b> the name of whoever recorded it.
           Safe to print or share.</p></div>
      <div class="u-actions">
        <a href="violations.php" class="btn-outline"><i class="fas fa-arrow-left"></i> Back to Violations</a>
        <button onclick="window.print()" class="btn-primary"><i class="fas fa-print"></i> Print</button>
      </div>
    </div>

    <div class="vts-note" style="background:#eef4ff;border:1px solid #cfe0f8;border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:.86rem;color:#26436e;">
      <i class="fas fa-user-shield"></i>
      <b>Privacy:</b> the reporting marshal/officer is never shown on this page or in its print-out.
    </div>

    <?php if ($loadError !== ''): ?>
      <div class="alert alert-error no-print u-mb-14" role="alert">
        <i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($loadError); ?>
      </div>
    <?php endif; ?>

    <?php foreach ($filterErrors as $fe): ?>
      <div class="alert alert-warning no-print u-mb-14" role="alert">
        <i class="fas fa-triangle-exclamation"></i> <?php echo htmlspecialchars($fe); ?>
      </div>
    <?php endforeach; ?>

    <?php if ($loadError === '' && $activeFilters): ?>
      <div class="alert alert-info no-print u-mb-14" role="status">
        <i class="fas fa-filter"></i>
        <span>Showing <b><?php echo count($rows); ?></b>
          <?php echo count($rows) === 1 ? 'violation' : 'violations'; ?>
          <?php echo htmlspecialchars(implode(', ', $activeFilters)); ?>.
          <?php if (!$rows): ?>Nothing matched — try widening the filters.<?php endif; ?></span>
      </div>
    <?php endif; ?>

    <div class="kpi-grid u-mb-20">
      <div class="kpi-card"><div class="kpi-top"><div class="kpi-icon blue"><i class="fas fa-list-check"></i></div><div class="kpi-label">Violations shown</div></div><div class="kpi-value"><?php echo count($rows); ?></div><div class="kpi-meta"><?php echo $from !== '' || $to !== '' ? htmlspecialchars(($from ?: 'start') . ' → ' . ($to ?: 'now')) : 'all dates'; ?></div></div>
      <div class="kpi-card"><div class="kpi-top"><div class="kpi-icon amber"><i class="fas fa-triangle-exclamation"></i></div><div class="kpi-label">Major</div></div><div class="kpi-value"><?php echo $majorCount; ?></div><div class="kpi-meta"><?php echo count($rows) - $majorCount; ?> minor</div></div>
    </div>

    <div class="recent-table-wrap table-responsive">
      <form method="GET" class="filter-bar wrap no-print">
        <div class="filter-field"><label for="from">From</label><input id="from" type="date" name="from" class="vts-input" value="<?php echo htmlspecialchars($from); ?>"></div>
        <div class="filter-field"><label for="to">To</label><input id="to" type="date" name="to" class="vts-input" value="<?php echo htmlspecialchars($to); ?>"></div>
        <div class="filter-field"><label for="severity">Kind</label>
          <select id="severity" name="severity" class="vts-input">
            <option value="">All</option>
            <option value="Major" <?php echo $severity === 'Major' ? 'selected' : ''; ?>>Major</option>
            <option value="Minor" <?php echo $severity === 'Minor' ? 'selected' : ''; ?>>Minor</option>
          </select></div>
        <div class="filter-field"><label for="college_id">Department</label>
          <select id="college_id" name="college_id" class="vts-input">
            <option value="">All</option>
            <?php foreach ($collegeOptions as $c): ?>
              <option value="<?php echo $c['id']; ?>" <?php echo (string)$college === (string)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['college_name']); ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="filter-field"><label for="course">Course</label>
          <select id="course" name="course" class="vts-input">
            <option value="">All</option>
            <?php foreach ($courses as $c): ?>
              <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $course === $c ? 'selected' : ''; ?>><?php echo htmlspecialchars($c); ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="filter-field grow"><label for="search">Search</label>
          <div class="search-wrap"><i class="fas fa-magnifying-glass"></i>
            <input id="search" type="text" name="search" class="vts-input" placeholder="Student, ID, or violation" value="<?php echo htmlspecialchars($search); ?>"></div></div>
        <div class="filter-actions">
          <button class="btn-primary btn-sm" type="submit"><i class="fas fa-filter"></i> Apply</button>
          <a href="violations_only.php" class="btn-outline btn-sm"><i class="fas fa-rotate"></i> Reset</a>
        </div>
      </form>

      <div class="table-scroll table-responsive">
      <table class="data-table">
        <thead><tr>
          <th>Date &amp; Time</th><th>School ID</th><th>Student</th><th>Course</th><th>Year &amp; Set</th>
          <th>Department</th><th>Violation</th><th>Kind</th><th>Violation Count</th>
          <th>Details</th><th>Evidence</th>
        </tr></thead>
        <tbody>
        <?php if ($rows): foreach ($rows as $r):
          $sev   = ($r['severity'] ?? 'Minor') === 'Major' ? 'Major' : 'Minor';
          $isImg = !empty($r['evidence']) && preg_match('/\.(jpe?g|png|gif|webp)$/i', $r['evidence']);
        ?>
          <tr>
            <td class="u-nowrap"><?php echo htmlspecialchars(vts_datetime($r['date_reported'])); ?></td>
            <td><?php echo htmlspecialchars($r['sid'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($r['fullname']); ?></td>
            <td><?php echo htmlspecialchars($r['course'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars(trim(($r['year_level'] ?? '') . ' ' . ($r['section'] ?? '')) ?: '-'); ?></td>
            <td><?php echo htmlspecialchars($r['dept'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($r['violation']); ?></td>
            <td><span style="display:inline-block;padding:1px 9px;border-radius:999px;font-size:.74rem;font-weight:800;
                       color:<?php echo $sev === 'Major' ? '#8a4b00' : '#1f6b41'; ?>;
                       background:<?php echo $sev === 'Major' ? '#fdf0dc' : '#e6f5ec'; ?>;">
                  <?php echo strtoupper($sev); ?></span>
                <?php if (!empty($r['cleared_at'])): ?>
                  <span style="display:inline-block;padding:1px 8px;border-radius:999px;font-size:.7rem;font-weight:700;background:#eef1f6;color:var(--text-muted);">Cleared</span>
                <?php endif; ?></td>
            <td><?php echo (int)offense_number($r['offense'] ?? ''); ?></td>
            <td style="max-width:280px;white-space:normal;">
              <?php
                $bits = array_filter([$r['description'] ?? '', $r['remarks'] ?? '']);
                echo $bits ? nl2br(htmlspecialchars(implode(' — ', $bits))) : '<span class="u-faint">—</span>';
              ?></td>
            <td><?php if (!empty($r['evidence'])): ?>
                  <?php /* The thumbnail is the link's only content, so its alt text
                           is what a screen reader reads as the link name. */ ?>
                  <a href="../uploads/evidence/<?php echo htmlspecialchars($r['evidence']); ?>" target="_blank" rel="noopener" title="View evidence">
                    <?php if ($isImg): ?><img alt="Evidence photo for <?php echo htmlspecialchars($r['fullname'] ?? 'this violation'); ?> — open full size" src="../uploads/evidence/<?php echo htmlspecialchars($r['evidence']); ?>" style="width:34px;height:34px;object-fit:cover;border-radius:6px;border:1px solid #d8dfea;">
                    <?php else: ?><i class="fas fa-paperclip" aria-hidden="true"></i><span class="sr-only">Open the evidence file attached to this violation</span><?php endif; ?></a>
                <?php else: ?><span class="u-faint">—</span><?php endif; ?></td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="11" class="table-empty">No violations match these filters.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
      </div>
      <p class="vo-count"><?php echo (int)$totalRows; ?> violation(s) match. Reporter names are withheld.</p>
      <?php echo vts_pager($page, $totalPages, $_GET, 'violation'); ?>
    </div>

<?php /* .vts-main-inner and <main> are closed by includes/footer.php;
         closing them here too emitted a stray </div></main>. */ ?>

<style>
@media print{
  .vts-sidebar, .vts-navbar, .no-print, .admin-welcome-row .btn-outline,
  .admin-welcome-row .btn-primary, .kpi-grid{ display:none !important; }
  .vts-main{ margin:0 !important; padding:0 !important; }
  .data-table{ font-size:.72rem; }
}
</style>
<?php include "../includes/footer.php"; ?>
