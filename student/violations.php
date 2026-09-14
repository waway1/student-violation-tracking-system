<?php
/* Student: list of the student's own violations. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";   // vts_date() used below

if (($_SESSION['role'] ?? '') !== "Student") {
    vts_deny_access();
}

$assetBase = "../";
include "../includes/header.php";
include "../includes/navbar.php";
include "../includes/sidebar.php";

$userID = $_SESSION['user_id'];
$search = trim((string)($_GET['search'] ?? ""));

$sql = "SELECT v.*, r.fullname AS reporter_name
        FROM violations v
        LEFT JOIN users r ON v.reported_by = r.id
        WHERE v.student_id = :student";
$params = [':student' => $userID];

if($search != ""){
    $sql .= " AND (v.violation LIKE :search OR v.offense LIKE :search OR v.description LIKE :search)";
    $params[':search'] = "%".$search."%";
}
$sql .= " ORDER BY v.date_reported DESC";

/* Paged. One student's own record is usually short, but "usually" is not a
   guarantee, and the page previously rendered every row it had — a student
   with four years of minor offenses got one long unbroken table.
   vts_pager() is the same pager the admin listings use. */
$perPage = 15;
$page    = (isset($_GET['page']) && is_numeric($_GET['page'])) ? max(1, (int)$_GET['page']) : 1;

$countSql = preg_replace('/^SELECT .*? FROM/s', 'SELECT COUNT(*) FROM', $sql, 1);
$countSql = preg_replace('/\s+ORDER BY .*$/s', '', $countSql);
$cnt = $conn->prepare($countSql);
$cnt->execute($params);
$totalRows  = (int)$cnt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;      // stale ?page= after a search narrows it

$stmt = $conn->prepare($sql . " LIMIT :off, :lim");
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':off', ($page - 1) * $perPage, PDO::PARAM_INT);
$stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stmt->execute();
$violations = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* A per-violation-type running count used to be built here by walking the
   rows oldest-first. Nothing rendered it — the Count column shows
   offense_number($row['offense']), which is stored per row — and now that
   the list is paged it could not be built correctly anyway: page 2 has no
   sight of page 1, so every tally would restart. Removed rather than left
   as a half-right number waiting to be used. */
?>

<main class="vts-main">
<div class="vts-main-inner">

  <!-- A real heading element, not a styled div: this is the page's heading,
       and a screen reader has no other way to find out what this page is.
       The .page-title class carries the same look either way. -->
  <h1 class="page-title"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i> Violation Records</h1>

  <!-- Search -->
  <form method="GET">
    <div class="search-row">
      <input aria-label="Search violation..." type="text" name="search" class="vts-input" placeholder="Search violation..." value="<?php echo htmlspecialchars($search); ?>" style="max-width:300px;">
      <button type="submit" class="btn-primary btn-sm"><i class="fas fa-search"></i> Search</button>
      <a href="violations.php" class="btn-outline btn-sm"><i class="fas fa-times"></i> Reset</a>
    </div>
  </form>

  <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success"><i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($_GET['success']); ?></div>
  <?php endif; ?>
  <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($_GET['error']); ?></div>
  <?php endif; ?>

  <div class="vts-table-wrap table-responsive">
    <table class="vts-table">
      <?php /* For confidentiality the student sees only the KIND (Major/Minor),
               the running count and the status — NOT the specific violation
               type or who recorded it. */ ?>
      <thead>
        <tr>
          <th>Date</th>
          <th>Kind</th>
          <th class="violation-count-head" title="Your running total across all violations">Violation Count</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if(count($violations)>0): ?>
          <?php foreach($violations as $row): ?>
          <tr>
            <td><?php echo strtoupper(vts_date($row['date_reported'])); ?></td>
            <?php $sev = ($row['severity'] ?? 'Minor') === 'Major' ? 'Major' : 'Minor'; ?>
            <td><span class="kind-chip <?php echo strtolower($sev); ?>"><?php echo strtoupper($sev); ?></span></td>
            <td class="violation-count-cell"><?php echo (int)offense_number($row['offense']); ?></td>
            <?php /* A record the office reviewed and found unsupported stays
                     in the list — it is still history — but says so, because
                     it no longer counts toward the running total beside it.
                     The cell is a link either way: the record's own page is
                     where the rest of the detail lives. */
              $rid  = (int)$row['id'];
              $done = (($row['proof_status'] ?? 'Pending') === 'Rejected');
            ?>
            <td>
              <a class="vstatus-link is-<?php echo $done ? 'cleared' : 'active'; ?>"
                 href="view_violation.php?id=<?php echo $rid; ?>">
                <?php echo $done ? 'Removed after review' : 'On record'; ?>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="4" class="table-empty">No violation records found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php /* Keeps the search term when you turn the page. */ ?>
  <?php echo vts_pager($page, $totalPages, $_GET, 'violation'); ?>

<?php /* The app shell (.vts-main-inner + <main>) is closed by
         includes/footer.php below -- closing it here as well emitted a
         stray </div></main> on every one of these pages. */ ?>
<?php include "../includes/footer.php"; ?>
