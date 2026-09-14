<?php
/* Student: view personal notifications. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (($_SESSION['role'] ?? '') !== "Student") {
    vts_deny_access();
}

$assetBase = "../";
include "../includes/header.php";
include "../includes/navbar.php";
include "../includes/sidebar.php";

$userID = $_SESSION['user_id'];

/* Actions (all scoped to THIS user's own notifications).

   Each one now finishes with a redirect and a flash key instead of falling
   through to the render. Two reasons: nothing on screen used to confirm that
   a delete had happened (the row just vanished, which looks the same as a
   page that failed to load it), and refreshing after a delete re-posted it. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { vts_csrf_fail('update those notifications'); }

    $done = '';
    $failed = false;

    try {
        if (isset($_POST['clear'])) {
            $st = $conn->prepare("UPDATE notifications SET is_read=1 WHERE user_id=:id");
            $st->execute([':id'=>$userID]);
            $done = 'read';
        }
        if (isset($_POST['delete_id'])) {
            $st = $conn->prepare("DELETE FROM notifications WHERE id=:nid AND user_id=:id");
            $st->execute([':nid'=>(int)$_POST['delete_id'], ':id'=>$userID]);
            // rowCount 0 means it was already gone — say so rather than claiming success.
            $done = $st->rowCount() > 0 ? 'deleted' : 'missing';
        }
        if (isset($_POST['delete_all'])) {
            $st = $conn->prepare("DELETE FROM notifications WHERE user_id=:id");
            $st->execute([':id'=>$userID]);
            $done = 'cleared';
        }
    } catch (PDOException $e) {
        error_log('Student notifications action failed: ' . $e->getMessage());
        $failed = true;
    }

    header('Location: notifications.php?' . ($failed ? 'failed=1' : 'done=' . urlencode($done)));
    exit();
}

$FLASHES = [
    'read'    => ['success', 'All your notifications are marked as read.'],
    'deleted' => ['success', 'That notification was deleted.'],
    'cleared' => ['success', 'All your notifications were deleted.'],
    'missing' => ['info',    'That notification had already been deleted.'],
];
$flashKey  = $_GET['done'] ?? '';
$flashPair = $FLASHES[$flashKey] ?? null;
if (isset($_GET['failed'])) {
    $flashPair = ['error', 'That did not go through. Try again, and tell the office if it keeps failing.'];
}

// Fetch
$notifs = $conn->prepare("SELECT * FROM notifications WHERE user_id=:id ORDER BY created_at DESC LIMIT 30");
$notifs->execute([':id'=>$userID]);
$notifs = $notifs->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="../assets/css/student-notifications.css?v=<?php echo @filemtime(__DIR__."/../assets/css/student-notifications.css"); ?>">
<main class="vts-main">
<div class="vts-main-inner">

  <div class="admin-welcome-row student-notifications-head">
    <div>
      <h1><i class="fas fa-bell"></i> Notifications</h1>
      <p>Your latest alerts and updates.</p>
    </div>
    <?php if(count($notifs)>0): ?>
    <div class="student-notification-actions">
      <form method="POST" class="inline-form">
        <?php echo csrf_field(); ?>
        <button type="submit" name="clear" class="btn-outline">
          <i class="fas fa-check-double"></i> Mark all read
        </button>
      </form>
      <form method="POST" class="inline-form"
            onsubmit="return confirm('Delete ALL your notifications? This cannot be undone.');">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="delete_all" value="1">
        <button type="submit" class="btn-danger btn-sm">
          <i class="fas fa-trash"></i> Delete all
        </button>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($flashPair): ?>
    <div class="alert alert-<?php echo $flashPair[0]; ?> student-notifications-flash" role="status">
      <i class="fas fa-<?php echo $flashPair[0] === 'error' ? 'circle-exclamation' : 'circle-check'; ?>"></i>
      <?php echo htmlspecialchars($flashPair[1]); ?>
    </div>
  <?php endif; ?>

  <div class="vts-card student-notifications-card">

    <?php if(count($notifs)>0): ?>
      <?php foreach($notifs as $n): ?>
      <div class="notif-item <?php echo (!$n['is_read']) ? 'unread' : ''; ?>">
        <div class="notif-main">
          <div class="notif-title"><?php echo htmlspecialchars($n['title'] ?? 'New Violation'); ?></div>
          <div class="notif-desc"><?php echo htmlspecialchars($n['message']); ?></div>
        </div>
        <div class="notif-side">
          <div class="notif-time"><?php echo vts_date($n['created_at']) . ' · ' . vts_time($n['created_at']); ?></div>
          <div class="notif-actions">
            <?php if(isset($n['violation_id']) && $n['violation_id']): ?>
            <a href="violations.php" class="btn-primary btn-sm">View</a>
            <?php endif; ?>
            <form method="POST" class="inline-form" onsubmit="return confirm('Delete this notification?');">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="delete_id" value="<?php echo $n['id']; ?>">
              <button type="submit" class="btn-danger btn-sm" title="Delete"><i class="fas fa-trash"></i></button>
            </form>
          </div>
        </div>
      </div>
      <?php endforeach; ?>

    <?php else: ?>
      <div class="notif-empty">
        <i class="fas fa-bell-slash" aria-hidden="true"></i>
        No notifications yet.
      </div>
    <?php endif; ?>

  </div>

<?php /* The app shell (.vts-main-inner + <main>) is closed by
         includes/footer.php below -- closing it here as well emitted a
         stray </div></main> on every one of these pages. */ ?>
<?php include "../includes/footer.php"; ?>
