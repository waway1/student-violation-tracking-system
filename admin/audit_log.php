<?php
/* Audit log (Admin): who did what, to which record, when and from which IP -- deletes, edits, user/roster changes, logins (scans live in scan_logs). */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

/* ADMIN ONLY. This used to admit OSA as readers. The log is the record of
   what everyone with an account did, OSA included, so the people it reports on
   are not the people who decide what it says — reading it is now the same
   single account that can erase it. */
if (($_SESSION['role'] ?? '') !== 'Admin') { vts_deny_access(); }

// Make sure the table exists (harmless if it already does)
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS audit_logs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NULL, user_name VARCHAR(180) NULL, role VARCHAR(20) NULL,
        action VARCHAR(60) NOT NULL, target VARCHAR(60) NULL, target_id VARCHAR(60) NULL,
        details VARCHAR(255) NULL, ip VARCHAR(45) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_audit_user (user_id, created_at),
        INDEX idx_audit_action (action, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

/* ---- Deleting entries ----

   ADMIN ONLY. This is now the same answer as the guard at the top of the file,
   since OSA lost read access too — so $canPurge is, today, always true.

   IT STAYS ANYWAY, and deliberately. Erasing the record of who did what is a
   materially different power from reading it, and the two should not be the
   same decision written once. If this page is ever reopened to a second role
   as a READER — which is a reasonable thing to want — the delete column has
   to stay shut on its own, without anyone remembering to put this line back.

   THE PURGE IS ITSELF LOGGED. Deleting a row, or all of them, immediately
   writes a fresh entry saying so. A log that can be silently emptied is not an
   audit log — after any deletion there is still a line naming who did it, when,
   and from which IP. That entry is written after the delete so it survives it. */
$canPurge = (($_SESSION['role'] ?? '') === 'Admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        vts_redirect_back('audit_log.php', 'error', 'Session expired. Please try again.');
        exit();
    }
    if (!$canPurge) {
        vts_redirect_back('audit_log.php', 'error', 'Only an Administrator can delete audit entries.');
        exit();
    }

    try {
        if (isset($_POST['delete_id'])) {
            $id = (int)$_POST['delete_id'];
            $d  = $conn->prepare("DELETE FROM audit_logs WHERE id = :id");
            $d->execute([':id' => $id]);
            if ($d->rowCount() > 0) {
                audit_log($conn, 'Delete Audit Entry', 'audit_logs', $id, 'One audit entry deleted');
                vts_redirect_back('audit_log.php', 'success', 'That entry was deleted.');
            } else {
                vts_redirect_back('audit_log.php', 'info', 'That entry had already been deleted.');
            }
            exit();
        }

        if (isset($_POST['delete_all'])) {
            /* Deliberately NOT scoped to the current filter. Someone looking at
               "Today" who presses a button labelled "Delete all" means all of
               it; quietly deleting only the filtered subset would leave them
               believing the log was empty when it was not. The button and its
               confirmation both say "every entry". */
            $n = (int)$conn->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
            $conn->exec("DELETE FROM audit_logs");
            audit_log($conn, 'Clear Audit Log', 'audit_logs', null,
                      "Deleted all {$n} audit entries");
            vts_redirect_back('audit_log.php', 'success',
                              "Audit log cleared — {$n} entries deleted. This action was itself recorded.");
            exit();
        }
    } catch (Throwable $e) {
        error_log('Audit log delete failed: ' . $e->getMessage());
        vts_redirect_back('audit_log.php', 'error', 'That did not go through. Please try again.');
        exit();
    }
}

$search = trim($_GET['search'] ?? '');
$when   = $_GET['when'] ?? 'all';

$where = []; $params = [];
if ($search !== '') {
    $where[] = "(user_name LIKE :s OR action LIKE :s OR target LIKE :s OR details LIKE :s)";
    $params[':s'] = "%{$search}%";
}
switch ($when) {
    case 'today':     $where[] = "DATE(created_at) = CURDATE()"; break;
    case 'yesterday': $where[] = "DATE(created_at) = CURDATE() - INTERVAL 1 DAY"; break;
    case '7days':     $where[] = "created_at >= CURDATE() - INTERVAL 6 DAY"; break;
}
$sql = "SELECT * FROM audit_logs";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY created_at DESC LIMIT 300";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$adminActive = 'scanlogs';
include "../includes/admin_header.php";
?>

<div class="admin-welcome-row">
  <div><h1>Audit Log</h1><p>Who did what, to which record, and when. Every change is recorded here.</p></div>
</div>

<?php if (isset($_GET['success'])): ?>
  <div class="alert alert-success u-mb-14" role="status">
    <i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($_GET['success']); ?>
  </div>
<?php endif; ?>
<?php if (isset($_GET['info'])): ?>
  <div class="alert alert-info u-mb-14" role="status">
    <i class="fas fa-circle-info"></i> <?php echo htmlspecialchars($_GET['info']); ?>
  </div>
<?php endif; ?>
<?php if (isset($_GET['error'])): ?>
  <div class="alert alert-error u-mb-14" role="alert">
    <i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($_GET['error']); ?>
  </div>
<?php endif; ?>

<div class="recent-table-wrap is-panel">
  <?php /* Search, filter and Delete all share one row. They are two separate
           forms — the filters are a GET, the purge is a POST — so a flex
           wrapper puts them on the same line rather than nesting one inside
           the other, which is not valid and would submit the wrong thing. */ ?>
  <div class="audit-toolbar">
    <form method="GET" class="audit-filters">
      <input aria-label="Search user, action, or record" type="text" name="search" class="vts-input audit-search"
             placeholder="Search user, action, or record" value="<?php echo htmlspecialchars($search); ?>">
      <select name="when" aria-label="Filter by time period" class="vts-input audit-when" onchange="this.form.submit()">
        <?php foreach (['all'=>'All dates','today'=>'Today','yesterday'=>'Yesterday','7days'=>'Last 7 days'] as $k=>$l) {
          echo "<option value=\"$k\"".($when===$k?' selected':'').">$l</option>"; } ?>
      </select>
      <button class="btn-primary" type="submit"><i class="fas fa-magnifying-glass"></i> Search</button>
      <a href="audit_log.php" class="btn-outline"><i class="fas fa-rotate"></i> Reset</a>
    </form>

    <?php if ($canPurge && $logs): ?>
      <form method="POST" class="audit-purge"
            onsubmit="return confirm('Delete EVERY audit entry?\n\nThis deletes the whole log, not just the rows currently shown, and it cannot be undone. The deletion itself will be recorded.');">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="delete_all" value="1">
        <button type="submit" class="btn-danger">
          <i class="fas fa-trash"></i> Delete all entries
        </button>
      </form>
    <?php endif; ?>
  </div>

  <div class="table-scroll table-responsive">
    <table class="data-table">
      <thead><tr>
        <th>When</th><th>Who</th><th>Action</th><th>Record</th><th>Details</th><th>IP</th>
        <?php if ($canPurge): ?><th class="nowrap">Delete</th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php if (!$logs): ?>
        <tr><td colspan="<?php echo $canPurge ? 7 : 6; ?>" class="table-empty">
          No activity recorded yet. Deletes, edits, imports, and logins will appear here.
        </td></tr>
      <?php else: foreach ($logs as $l): ?>
        <tr>
          <td style="white-space:nowrap;font-size:.84rem;"><?php echo vts_datetime($l['created_at']); ?></td>
          <td><?php echo htmlspecialchars($l['user_name'] ?? 'System'); ?></td>
          <td><strong><?php echo htmlspecialchars($l['action']); ?></strong></td>
          <td style="font-size:.84rem;color:var(--text-soft);">
            <?php echo htmlspecialchars($l['target'] ?? '—'); ?>
            <?php echo $l['target_id'] ? ' #' . htmlspecialchars($l['target_id']) : ''; ?>
          </td>
          <td style="font-size:.84rem;color:var(--text-soft);"><?php echo htmlspecialchars($l['details'] ?? ''); ?></td>
          <td style="font-size:.78rem;color:var(--text-faint);"><?php echo htmlspecialchars($l['ip'] ?? ''); ?></td>
          <?php if ($canPurge): ?>
          <td class="nowrap">
            <form method="POST" class="inline-form"
                  onsubmit="return confirm('Delete this audit entry? The deletion will itself be recorded.');">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="delete_id" value="<?php echo (int)$l['id']; ?>">
              <button type="submit" class="btn-danger btn-sm" title="Delete this entry"
                      aria-label="Delete the entry from <?php echo htmlspecialchars(vts_datetime($l['created_at'])); ?>">
                <i class="fas fa-trash"></i>
              </button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include "../includes/admin_footer.php"; ?>
