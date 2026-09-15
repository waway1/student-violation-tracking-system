<?php
/* Admin: view and manage system notifications. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (!in_array($_SESSION['role'] ?? '', ['Admin', 'OSA'], true)) {
    vts_deny_access();
}

// Delete one / clear read (POST + CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    header("Location: notifications.php?error=" . urlencode("This page was open too long and the security token expired. Reload and try again."));
    exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_id'])) {
        $d = $conn->prepare("DELETE FROM notifications WHERE id = :id");
        $d->execute([':id' => (int)$_POST['delete_id']]);
        header("Location: notifications.php?success=" . urlencode("Notification deleted."));
        exit();
    }
    if (isset($_POST['clear_read'])) {
        $conn->query("DELETE FROM notifications WHERE is_read = 1");
        header("Location: notifications.php?success=" . urlencode("All read notifications cleared."));
        exit();
    }
    if (isset($_POST['mark_read'])) {
        try { $conn->query("UPDATE notifications SET is_read=1"); } catch (Throwable $e) {}
        header("Location: notifications.php?success=All marked as read");
        exit();
    }
}

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';            // '', 'unread', 'read'
$limit  = 25;
$page   = (isset($_GET['page']) && is_numeric($_GET['page'])) ? max(1, (int)$_GET['page']) : 1;

$where = [];
$params = [];
if ($search !== '') {
    $where[] = "(u.fullname LIKE :s OR u.student_id LIKE :s OR n.title LIKE :s OR n.message LIKE :s)";
    $params[':s'] = "%{$search}%";
}
if ($status === 'unread') $where[] = "n.is_read = 0";
if ($status === 'read')   $where[] = "n.is_read = 1";
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$joins = "
    FROM notifications n
    LEFT JOIN users u ON n.user_id = u.id
    LEFT JOIN violations v ON n.violation_id = v.id
    LEFT JOIN users r ON v.reported_by = r.id" . $whereSql;

$loadError  = '';
$notes      = [];
$totalRows  = 0;
$totalPages = 1;
$unreadCount = 0;
try {
    $c = $conn->prepare("SELECT COUNT(*) $joins");
    $c->execute($params);
    $totalRows  = (int)$c->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $limit));
    if ($page > $totalPages) $page = $totalPages;   // stale ?page= after narrowing a search
    $offset = ($page - 1) * $limit;

    $st = $conn->prepare("SELECT n.id, n.title, n.message, n.is_read, n.created_at,
                                 u.fullname, u.student_id AS sid,
                                 v.violation, v.scanner_name, r.fullname AS reporter_name
                          $joins ORDER BY n.created_at DESC LIMIT :o, :l");
    foreach ($params as $k => $v) $st->bindValue($k, $v, PDO::PARAM_STR);
    $st->bindValue(':o', $offset, PDO::PARAM_INT);
    $st->bindValue(':l', $limit,  PDO::PARAM_INT);
    $st->execute();
    $notes = $st->fetchAll(PDO::FETCH_ASSOC);

    $unreadCount = (int)$conn->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();
} catch (PDOException $e) {
    error_log('Admin notifications query failed: ' . $e->getMessage());
    $loadError = 'The notification list could not be loaded just now. Refresh the page, and tell IT if it keeps happening.';
}

// The row number continues across pages rather than restarting at 1 each time.
$rowFrom = ($page - 1) * $limit;

$adminActive = 'notifications';
include "../includes/admin_header.php";
?>

<div class="admin-welcome-row">
  <div><h1>Notifications</h1><p>Alerts sent to students (e.g. when a violation is recorded).</p></div>
  <div class="u-row-wrap">
    <span class="u-small u-muted" id="notifRefreshNote"><i class="fas fa-rotate"></i> auto-refreshes every 30s</span>
    <form method="POST" class="inline-form">
      <?php echo csrf_field(); ?><input type="hidden" name="mark_read" value="1">
      <button type="submit" class="btn-outline"><i class="fas fa-check-double"></i> Mark all read</button>
    </form>
    <form method="POST" class="inline-form" onsubmit="return confirm('Delete ALL read notifications?');">
      <?php echo csrf_field(); ?><input type="hidden" name="clear_read" value="1">
      <button type="submit" class="btn-danger btn-sm"><i class="fas fa-trash"></i> Clear read</button>
    </form>
  </div>
</div>

<?php if(isset($_GET['success'])): ?>
  <div class="alert alert-success u-mb-14" role="status"><i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($_GET['success']); ?></div>
<?php endif; ?>
<?php if(isset($_GET['error'])): ?>
  <div class="alert alert-error u-mb-14" role="alert"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($_GET['error']); ?></div>
<?php endif; ?>
<?php if($loadError !== ''): ?>
  <div class="alert alert-error u-mb-14" role="alert"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($loadError); ?></div>
<?php endif; ?>

<div class="recent-table-wrap table-responsive">
  <?php /* Was a blind "newest 200" with no way to search and nothing to reach
           the 201st. Search + status filter + paging replace it. */ ?>
  <form method="GET" class="filter-bar wrap">
    <div class="filter-field grow">
      <label for="notifSearch">Search</label>
      <input id="notifSearch" type="search" name="search" class="vts-input"
             placeholder="Recipient, school ID, title, or message"
             value="<?php echo htmlspecialchars($search); ?>">
    </div>
    <div class="filter-field">
      <label for="notifStatus">Status</label>
      <select id="notifStatus" name="status" class="vts-input">
        <option value="">All</option>
        <option value="unread" <?php echo $status === 'unread' ? 'selected' : ''; ?>>Unread only</option>
        <option value="read"   <?php echo $status === 'read'   ? 'selected' : ''; ?>>Read only</option>
      </select>
    </div>
    <div class="filter-actions">
      <button class="btn-primary btn-sm" type="submit"><i class="fas fa-filter"></i> Apply</button>
      <a href="notifications.php" class="btn-outline btn-sm"><i class="fas fa-rotate"></i> Reset</a>
    </div>
  </form>

  <?php if ($loadError === ''): ?>
    <p class="u-small u-muted u-mb-14">
      <b><?php echo $totalRows; ?></b> <?php echo $totalRows === 1 ? 'notification' : 'notifications'; ?>
      <?php if ($search !== '' || $status !== ''): ?>match these filters<?php else: ?>in total<?php endif; ?>,
      <b><?php echo $unreadCount; ?></b> unread.
    </p>
  <?php endif; ?>

  <div class="table-scroll table-responsive">
  <table class="data-table">
    <?php $showRec = vts_can_see_recorder(); /* OSA/Admin only */ ?>
    <thead><tr><th>#</th><th>Date</th><th>Recipient</th><th>Title</th><th>Details</th><?php if ($showRec): ?><th>Scanned / Reported By</th><?php endif; ?><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if(count($notes) > 0): foreach($notes as $i=>$n): ?>
      <tr>
        <td><?php echo $rowFrom + $i + 1; ?></td>
        <td class="u-nowrap"><?php echo htmlspecialchars(vts_datetime($n['created_at'])); ?></td>
        <td><?php echo htmlspecialchars($n['fullname'] ?? ('User')); ?><?php echo $n['sid'] ? ' ('.htmlspecialchars($n['sid']).')' : ''; ?></td>
        <td><?php echo htmlspecialchars($n['title']); ?></td>
        <td>
          <?php echo htmlspecialchars($n['message']); ?>
          <?php if (!empty($n['violation'])): ?>
            <div style="font-size:.75rem;color:var(--text-soft);margin-top:2px;">
              <?php echo htmlspecialchars($n['violation']); ?>
            </div>
          <?php endif; ?>
        </td>
        <?php if ($showRec): ?>
        <td>
          <?php
            $who = $n['scanner_name'] ?: ($n['reporter_name'] ?? '');
            echo $who ? htmlspecialchars($who) : '<span class="u-faint">—</span>';
            if ($who && !empty($n['reporter_name']) && $n['scanner_name'] && $n['scanner_name'] !== $n['reporter_name']) {
                echo ' <span style="color:var(--text-faint);font-size:.75rem;">(via ' . htmlspecialchars($n['reporter_name']) . ')</span>';
            }
          ?>
        </td>
        <?php endif; ?>
        <td><span class="pill <?php echo $n['is_read'] ? 'resolved' : 'warning'; ?>"><?php echo $n['is_read'] ? 'Read' : 'Unread'; ?></span></td>
        <td>
          <form method="POST" class="inline-form" onsubmit="return confirm('Delete this notification?');">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="delete_id" value="<?php echo $n['id']; ?>">
            <button type="submit" class="btn-danger btn-sm" title="Delete"><i class="fas fa-trash"></i></button>
          </form>
        </td>
      </tr>
    <?php endforeach; else: ?>
      <?php /* colspan has to follow the optional "Scanned / Reported By" column. */ ?>
      <tr><td colspan="<?php echo $showRec ? 8 : 7; ?>" class="table-empty">
        <?php echo ($search !== '' || $status !== '')
          ? 'No notification matches these filters.'
          : 'No notifications yet.'; ?>
      </td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>

  <?php echo vts_pager($page, $totalPages, $_GET, 'notification'); ?>
</div>

<?php include "../includes/admin_footer.php"; ?>
<script>
/* This page reloaded itself every 30s no matter what: mid-search, mid-scroll,
   or with a delete confirmation open. It still refreshes, but only when doing
   so cannot take anything away — tab visible, nothing focused, no dialog. */
(function(){
  var note = document.getElementById('notifRefreshNote');
  function safeToReload(){
    if (document.hidden) return false;
    var a = document.activeElement;
    if (a && /^(INPUT|SELECT|TEXTAREA|BUTTON)$/.test(a.tagName)) return false;
    if (document.querySelector('.vts-menu.open')) return false;
    return true;
  }
  function tick(){
    if (safeToReload()) { location.reload(); return; }
    if (note) note.innerHTML = '<i class="fas fa-pause"></i> auto-refresh paused while you are working';
    setTimeout(tick, 5000);          // try again shortly
  }
  setTimeout(tick, 30000);
})();
</script>
