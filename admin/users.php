<?php
/* Admin: user account list and management. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";
vts_ensure_role_enum($conn);

if (!in_array($_SESSION['role'] ?? '', ['Admin', 'OSA'], true)) {
    vts_deny_access();
}

$search = isset($_GET['search']) ? trim($_GET['search']) : "";

/* This list used to be unpaged: every staff account on one screen. It is
   short today and long the moment guards are enrolled per gate, so it pages
   the same way the student list does. */
$limit  = 15;
$page   = (isset($_GET['page']) && is_numeric($_GET['page'])) ? max(1, (int)$_GET['page']) : 1;

$where  = "WHERE role != 'Student'";
$params = [];
if ($search !== "") {
    $where .= " AND (fullname LIKE :s OR username LIKE :s OR email LIKE :s OR role LIKE :s)";
    $params[':s'] = "%{$search}%";
}

$loadError = '';
$users = [];
$totalRows = 0;
$totalPages = 1;
try {
    $countStmt = $conn->prepare("SELECT COUNT(*) FROM users $where");
    $countStmt->execute($params);
    $totalRows  = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $limit));
    // A stale ?page= from a since-narrowed search would land on a blank table.
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $limit;

    $stmt = $conn->prepare("SELECT id, fullname, username, email, role, status, email_verified, created_at, profile_picture
                            FROM users $where ORDER BY role, fullname LIMIT :o, :l");
    foreach ($params as $k => $v) $stmt->bindValue($k, $v, PDO::PARAM_STR);
    $stmt->bindValue(':o', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':l', $limit,  PDO::PARAM_INT);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Admin users query failed: ' . $e->getMessage());
    $loadError = 'The user list could not be loaded just now. Refresh the page, and tell IT if it keeps happening.';
}

/* The role colour key now lives in includes/functions.php as vts_role_pill()
   — view_user.php shows the same badge, and one mapping in two files is one
   mapping that drifts. */

$viewerRole = $_SESSION['role'] ?? '';

$adminActive = 'users';
include "../includes/admin_header.php";
?>

<div class="admin-welcome-row">
  <div><h1>System Users</h1><p>Staff accounts: administrators, deans, OSA officers, and guards.</p></div>
  <a href="add_user.php" class="btn-primary"><i class="fas fa-plus"></i> Add User</a>
</div>

<?php if(isset($_GET['success'])): ?>
  <div class="alert alert-success u-mb-14"><i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($_GET['success']); ?></div>
<?php endif; ?>
<?php if(isset($_GET['error'])): ?>
  <div class="alert alert-error u-mb-14"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($_GET['error']); ?></div>
<?php endif; ?>

<?php if ($loadError !== ''): ?>
  <div class="alert alert-error u-mb-14" role="alert">
    <i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($loadError); ?>
  </div>
<?php endif; ?>

<div class="recent-table-wrap table-responsive">
  <form method="GET" class="u-row-wrap user-search-form">
    <label for="userSearch" class="sr-only">Search by name, username, email, or role</label>
    <input id="userSearch" type="text" name="search" class="vts-input u-grow"
           placeholder="Search by name, username, email, or role" value="<?php echo htmlspecialchars($search); ?>">
    <button class="btn-primary" type="submit"><i class="fas fa-magnifying-glass"></i> Search</button>
    <a href="users.php" class="btn-outline"><i class="fas fa-rotate"></i> Reset</a>
  </form>

  <?php if ($loadError === ''): ?>
    <p class="u-small u-muted u-mb-14">
      <?php if ($search !== ''): ?>
        <b><?php echo $totalRows; ?></b> <?php echo $totalRows === 1 ? 'account matches' : 'accounts match'; ?>
        &ldquo;<?php echo htmlspecialchars($search); ?>&rdquo;.
      <?php else: ?>
        <b><?php echo $totalRows; ?></b> staff <?php echo $totalRows === 1 ? 'account' : 'accounts'; ?>.
      <?php endif; ?>
    </p>
  <?php endif; ?>

  <div class="table-scroll table-responsive">
  <table class="data-table">
    <thead><tr><th>Full Name</th><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>Created</th><th>Action</th></tr></thead>
    <tbody>
    <?php if(count($users) > 0): foreach($users as $row): ?>
      <tr>
        
        <td>
          <?php $sp = trim($row['profile_picture'] ?? ''); ?>
          <span style="display:inline-flex;align-items:center;gap:10px;">
            <?php if ($sp !== ''): ?>
              <img alt="" src="../uploads/profile/<?php echo htmlspecialchars($sp); ?>"
style="width:34px;height:34px;border-radius:50%;object-fit:cover;flex-shrink:0;border:1.5px solid var(--border-soft);">
            <?php else: ?>
              <span style="width:34px;height:34px;border-radius:50%;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;background:var(--surface-tint);color:var(--navy);font-size:.95rem;">
                <i class="fas fa-user"></i></span>
            <?php endif; ?>
            <span><?php echo htmlspecialchars($row['fullname']); ?></span>
          </span>
        </td>
        <td><?php echo htmlspecialchars($row['username']); ?></td>
        <td><?php echo htmlspecialchars($row['email']); ?></td>
        <td><span class="pill <?php echo vts_role_pill($row['role']); ?>"><?php echo htmlspecialchars(vts_role_label($row['role'])); ?></span></td>
        <td><span class="pill <?php echo ($row['status']==='Active'?'resolved':'atrisk'); ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
        <td class="u-nowrap"><?php echo htmlspecialchars(vts_date($row['created_at'])); ?></td>
        <td class="u-nowrap">
          <?php /* The eye is for everyone who can open this page; the pen and
                   the bin are for whoever may actually use them. An OSA looking
                   at an Admin row may only look — the server has always refused
                   the edit and the delete, so the buttons are no longer offered
                   and then taken back. */ ?>
          <a class="btn-outline btn-sm" href="view_user.php?id=<?php echo $row['id']; ?>"
             title="View this account"><i class="fas fa-eye"></i></a>
          <?php $rowLocked = ($viewerRole === 'OSA' && $row['role'] === 'Admin'); ?>
          <?php if (!$rowLocked): ?>
          <a class="btn-sm btn-primary" href="edit_user.php?id=<?php echo $row['id']; ?>" title="Edit this account"><i class="fas fa-pen"></i></a>
          <?php if (isset($row['email_verified']) && (int)$row['email_verified'] === 0): ?>
          <form method="POST" action="verify_user.php" class="inline-form" onsubmit="return confirm('Manually mark this account\'s email as verified?');"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo $row['id']; ?>"><button type="submit" class="btn-sm" style="background:#e8a400;color:#fff;" title="Email not verified — click to verify manually"><i class="fas fa-envelope-circle-check"></i></button></form>
          <?php endif; ?>
          <form method="POST" action="delete_user.php" class="inline-form" onsubmit="return confirm('Delete this user?');"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo $row['id']; ?>"><button type="submit" class="btn-danger btn-sm" title="Delete"><i class="fas fa-trash"></i></button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="7" class="table-empty">
        <?php echo $search !== ''
          ? 'No staff account matches &ldquo;' . htmlspecialchars($search) . '&rdquo;.'
          : 'No staff accounts yet.'; ?>
      </td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>

  <?php echo vts_pager($page, $totalPages, $_GET, 'user'); ?>
</div>

<?php include "../includes/admin_footer.php"; ?>
