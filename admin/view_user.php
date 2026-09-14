<?php
/* Read-only staff account record.

   WHY THIS PAGE EXISTS
   The user list could create an account, edit one and delete one, but it
   could never simply show one. Anyone who wanted to check which role a
   guard holds, whether an address had been verified, or when an account was
   made, had to open edit_user.php -- a live form, every field editable, a
   role dropdown and a password box on it, and Save at the bottom. Looking
   at an account and changing one were the same act, exactly as they were
   for students until admin/view_student.php separated the two.

   This is that page for staff accounts. There is no form on it at all,
   which is the only version of read-only worth having, and editing is a
   deliberate second step.

   WHO CAN OPEN IT
   Admin and OSA -- the same two roles that may open the user list. OSA may
   read an Admin account but not change one, so the Edit button is withheld
   on those rather than offered and then refused; edit_user.php and
   delete_user.php already enforce that rule on the server. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";
vts_ensure_role_enum($conn);

if (!in_array($_SESSION['role'] ?? '', ['Admin', 'OSA'], true)) {
    vts_deny_access();
}

$viewerRole = $_SESSION['role'] ?? '';

$id = (isset($_GET['id']) && is_numeric($_GET['id'])) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: users.php?error=' . urlencode('No account was chosen.'));
    exit();
}

$stmt = $conn->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute([':id' => $id]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$u) {
    header('Location: users.php?error=' . urlencode('That account no longer exists.'));
    exit();
}

/* Students live in the same table but are not staff, and they have a record
   page of their own. An id that belongs to one lands there instead of on a
   "not found" that would not be true. */
if (($u['role'] ?? '') === 'Student') {
    header('Location: view_student.php?id=' . $id);
    exit();
}

/* The same privilege rule edit_user.php applies, asked here so the button is
   simply absent instead of bouncing off an error page. */
$canEdit = !($viewerRole === 'OSA' && ($u['role'] ?? '') === 'Admin');

/* WHEN THEY LAST SIGNED IN
   There is no last_login column on users, and adding one would mean a write
   on every sign-in. login_attempts already records each successful attempt
   by username, so the answer is there for the asking. */
$lastLogin = null;
try {
    vts_login_table($conn);
    $q = $conn->prepare("SELECT attempted_at FROM login_attempts
                         WHERE username = :u AND success = 1
                         ORDER BY attempted_at DESC LIMIT 1");
    $q->execute([':u' => (string)$u['username']]);
    $lastLogin = $q->fetchColumn() ?: null;
} catch (Throwable $e) { /* a nicety, never a reason to fail the page */ }

/* WHAT THIS ACCOUNT HAS DONE
   Counted, not listed -- the full trail has its own page, linked below.
   Every one of these is wrapped: a database that predates one of these
   tables must still render the record. */
$reported = 0;
$scans    = 0;
try {
    $r = $conn->prepare("SELECT COUNT(*) FROM violations WHERE reported_by = :id");
    $r->execute([':id' => $id]);
    $reported = (int)$r->fetchColumn();
} catch (Throwable $e) {}
try {
    $s = $conn->prepare("SELECT COUNT(*) FROM scan_logs WHERE scanned_by = :id");
    $s->execute([':id' => $id]);
    $scans = (int)$s->fetchColumn();
} catch (Throwable $e) {}

/* Recent activity: ADMIN ONLY, query included. These eight rows are
   audit_logs filtered to one account — the same record, cut narrower — so
   they follow the audit log itself rather than being a loophole around it.
   The rest of this page (the role, the counts, the last sign-in) still shows
   for OSA: knowing an account exists and what it has been used for is not the
   same as reading the trail of what its owner did. */
$recent = [];
if ($viewerRole === 'Admin') try {
    $a = $conn->prepare("SELECT action, target, target_id, details, ip, created_at
                         FROM audit_logs WHERE user_id = :id
                         ORDER BY created_at DESC LIMIT 8");
    $a->execute([':id' => $id]);
    $recent = $a->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$pic  = trim((string)($u['profile_picture'] ?? ''));
$name = trim((string)($u['fullname'] ?? ''));
$role = (string)($u['role'] ?? '');

$adminActive = 'users';
include "../includes/admin_header.php";
?>

<div class="admin-welcome-row">
  <div>
    <h1><i class="fas fa-id-badge"></i> Staff Account</h1>
    <p>Everything on file for this account. This page does not change anything.</p>
  </div>
  <div class="u-nowrap">
    <a href="users.php" class="btn-outline btn-sm"><i class="fas fa-chevron-left"></i> Back to users</a>
    <?php if ($canEdit): ?>
      <a href="edit_user.php?id=<?php echo $id; ?>" class="btn-primary btn-sm"><i class="fas fa-pen"></i> Edit</a>
    <?php endif; ?>
  </div>
</div>

<?php /* Said once, plainly, to whoever cannot change anything here -- so a
         missing Edit button reads as a rule rather than a page that failed
         to finish loading. */ ?>
<?php if (!$canEdit): ?>
  <div class="alert alert-info alert-static u-mb-14" role="note">
    <i class="fas fa-circle-info"></i>
    You can view Administrator accounts. Changing or removing one is an Admin action.
  </div>
<?php endif; ?>

<div class="vs-card">
  <div class="vs-head">
    <?php if ($pic !== ''): ?>
       <img class="vs-avatar" src="../uploads/profile/<?php echo htmlspecialchars($pic); ?>"
         alt="Profile photo of <?php echo htmlspecialchars($name !== '' ? $name : 'account holder'); ?>">
    <?php else: ?>
      <span class="vs-avatar vs-avatar-none"><i class="fas fa-user-shield"></i></span>
    <?php endif; ?>
    <div class="vs-head-t">
      <h2><?php echo htmlspecialchars($name !== '' ? $name : 'Unnamed account'); ?></h2>
      <p><?php echo htmlspecialchars('@' . ($u['username'] ?: 'no username')); ?></p>
    </div>
    <span class="pill <?php echo vts_role_pill($role); ?>"><?php echo htmlspecialchars(vts_role_label($role)); ?></span>
    <span class="pill <?php echo (($u['status'] ?? '') === 'Active' ? 'resolved' : 'atrisk'); ?>">
      <?php echo htmlspecialchars($u['status'] ?? '-'); ?>
    </span>
  </div>

  <dl class="vs-grid">
    <div><dt>Username</dt><dd><?php echo htmlspecialchars($u['username'] ?: '-'); ?></dd></div>
    <div><dt>Role</dt><dd><?php echo htmlspecialchars(vts_role_label($role)); ?></dd></div>
    <div><dt>Email</dt><dd>
      <?php echo htmlspecialchars($u['email'] ?: '-'); ?>
      <?php if (isset($u['email_verified'])): ?>
        <span class="vs-flag <?php echo ((int)$u['email_verified'] === 1 ? 'ok' : 'warn'); ?>">
          <?php echo ((int)$u['email_verified'] === 1 ? 'verified' : 'not verified'); ?>
        </span>
      <?php endif; ?>
    </dd></div>
    <div><dt>Contact number</dt><dd><?php echo htmlspecialchars($u['contact_number'] ?: '-'); ?></dd></div>
    <div><dt>Account status</dt><dd>
      <?php echo htmlspecialchars($u['status'] ?? '-'); ?>
      <?php if (($u['status'] ?? '') !== 'Active'): ?>
        <span class="vs-sub">an inactive account cannot sign in</span>
      <?php endif; ?>
    </dd></div>
    <div><dt>Account created</dt><dd><?php echo htmlspecialchars(!empty($u['created_at']) ? vts_date($u['created_at']) : '-'); ?></dd></div>
    <div><dt>Last signed in</dt><dd>
      <?php echo $lastLogin ? htmlspecialchars(vts_datetime($lastLogin)) : 'Never signed in'; ?>
      <?php if ($lastLogin): ?>
        <span class="vs-sub"><?php echo htmlspecialchars(vts_time_ago($lastLogin)); ?></span>
      <?php endif; ?>
    </dd></div>
    <div><dt>Violations reported</dt><dd>
      <?php echo (int)$reported; ?>
      <?php if ($role === 'Guard'): ?>
        <span class="vs-sub"><?php echo (int)$scans; ?> scan<?php echo $scans === 1 ? '' : 's'; ?> recorded</span>
      <?php endif; ?>
    </dd></div>
  </dl>

  <div class="vs-foot">
    <?php /* Admin only, like the log it opens. $viewerRole, not $role — $role
             here is the account being LOOKED AT, not the one looking. */ ?>
    <?php if ($viewerRole === 'Admin'): ?>
    <a class="btn-outline btn-sm" href="audit_log.php?search=<?php echo urlencode($name); ?>">
      <i class="fas fa-clipboard-list"></i> See this account's full activity
    </a>
    <?php endif; ?>
    <?php if ($reported > 0): ?>
      <a class="btn-outline btn-sm" href="violations.php?view=records&amp;search=<?php echo urlencode($name); ?>">
        <i class="fas fa-list"></i> Violations they reported
      </a>
    <?php endif; ?>
  </div>
</div>

<?php /* The last few things this account did. Eight rows, not three hundred:
         enough to recognise recent behaviour at a glance, with the full log
         one link away. Admin only — see the note on $recent above. */ ?>
<?php if ($viewerRole === 'Admin'): ?>
<div class="recent-table-wrap table-responsive u-mt-14">
  <div class="panel-head"><h3>Recent activity</h3>
    <a href="audit_log.php?search=<?php echo urlencode($name); ?>">View the full log &rarr;</a></div>
  <div class="table-scroll table-responsive">
    <table class="data-table">
      <thead><tr><th>When</th><th>Action</th><th>Record</th><th>Details</th><th>IP</th></tr></thead>
      <tbody>
      <?php if ($recent): foreach ($recent as $r):
              $rec = trim(($r['target'] ?? '') . ' ' . (($r['target_id'] ?? '') !== '' ? '#' . $r['target_id'] : '')); ?>
        <tr>
          <td class="u-nowrap"><?php echo htmlspecialchars(vts_datetime($r['created_at'])); ?></td>
          <td><?php echo htmlspecialchars($r['action']); ?></td>
          <td><?php echo htmlspecialchars($rec !== '' ? $rec : '-'); ?></td>
          <td><?php echo htmlspecialchars($r['details'] ?: '-'); ?></td>
          <td class="u-nowrap"><?php echo htmlspecialchars($r['ip'] ?: '-'); ?></td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="5" class="table-empty">Nothing recorded for this account yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; /* $viewerRole === 'Admin' */ ?>

<?php include "../includes/admin_footer.php"; ?>
