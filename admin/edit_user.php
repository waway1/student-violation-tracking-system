<?php
/* Admin: edit an existing user account. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";
vts_ensure_role_enum($conn);

if (!in_array($_SESSION['role'] ?? '', ['Admin', 'OSA'], true)) {
    vts_deny_access();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: users.php?error=Invalid user ID.");
    exit();
}
$id = (int)$_GET['id'];

$stmt = $conn->prepare("SELECT * FROM users WHERE id=:id AND role <> 'Student'");
$stmt->execute([':id'=>$id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { header("Location: users.php?error=User not found."); exit(); }

// OSA is admin-level but can't touch Admin accounts (privilege-escalation guard).
if (($_SESSION['role'] ?? '') === 'OSA' && $user['role'] === 'Admin') {
    header("Location: users.php?error=" . urlencode("OSA accounts can't edit Admin accounts. Ask an Admin."));
    exit();
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_verify()) { header("Location: users.php?error=" . urlencode("Session expired. Please try again.")); exit(); }
    $fullname = name_case($_POST['fullname'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $role     = $_POST['role'] ?? '';
    $status   = $_POST['status'] ?? 'Active';
    $password = trim($_POST['password'] ?? '');
    if ($role === 'Head Marshal' || $role === 'Head Marshall') $role = 'Guard';
    $allowedRoles = ['Admin', 'OSA', 'OSA Staff', 'Guard'];

    if ($fullname===''||$username===''||$email===''||$role==='') {
        $error = "Please complete all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address.";
    } elseif (!in_array($role, $allowedRoles, true)) {
      $error = "Invalid role.";
    } elseif (!in_array($status, ['Active', 'Inactive'], true)) {
      $error = "Invalid account status.";
    } elseif ($role === 'Admin' && ($_SESSION['role'] ?? '') === 'OSA') {
        $error = "OSA accounts can't promote a user to Admin. Ask an Admin.";
    } elseif ($role === 'Guard' && $status === 'Active' && !vts_guard_slot_available($conn, $id)) {
        $error = "Only 2 Guard/Marshal accounts are allowed at a time (leader + assistant). Deactivate one first.";
    } else {
        try {
            $dupField = null;
            foreach ([['username', $username, 'Username'],
                      ['email',    $email,    'Email']] as [$col, $val, $label]) {
                $c = $conn->prepare("SELECT id FROM users WHERE {$col} = :v AND id <> :id");
                $c->execute([':v' => $val, ':id' => $id]);
                if ($c->fetch()) { $dupField = [$label, $val]; break; }
            }
            if ($dupField) {
                $error = "{$dupField[0]} \"{$dupField[1]}\" is already used by another account.";
            } elseif ($password !== '' && ($pe = password_policy_error($password)) !== '') {
                $error = $pe;
            } else {
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $up = $conn->prepare("UPDATE users SET fullname=:f, username=:u, email=:e, password=:p, role=:r, status=:st WHERE id=:id");
                    $up->execute([':f'=>$fullname,':u'=>$username,':e'=>$email,':p'=>$hash,':r'=>$role,':st'=>$status,':id'=>$id]);
                } else {
                    $up = $conn->prepare("UPDATE users SET fullname=:f, username=:u, email=:e, role=:r, status=:st WHERE id=:id");
                    $up->execute([':f'=>$fullname,':u'=>$username,':e'=>$email,':r'=>$role,':st'=>$status,':id'=>$id]);
                }
                audit_log($conn, "Edit User", "users", $id, "Updated {$role} account");
                header("Location: users.php?success=User updated successfully.");
                exit();
            }
        } catch (PDOException $e) { error_log('Admin edit user failed: ' . $e->getMessage()); $error = 'The account could not be updated. Please check the details and try again.'; }
    }
    $user = array_merge($user, $_POST);
}

$adminActive = 'users';
include "../includes/admin_header.php";
?>

<div class="admin-welcome-row">
  <div><h1>Edit User</h1><p>Update this staff account.</p></div>
  <a href="users.php" class="btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<?php if($error): ?>
  <div class="alert alert-error u-mb-14"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="recent-table-wrap table-responsive" style="max-width:680px;">
  <form method="POST">
<?php echo csrf_field(); ?>
    <div class="form-grid">
      <div class="field full"><label for="fullname">Full Name <span class="req">*</span></label>
        <input id="fullname" type="text" name="fullname" class="vts-input" required value="<?php echo htmlspecialchars($user['fullname'] ?? ''); ?>" data-allow="letters" pattern="[A-Za-zÀ-ɏÑñ .'\-]{2,60}" title="Letters, spaces, hyphens, apostrophes and periods only." maxlength="80"></div>
      <div class="field"><label for="username">Username <span class="req">*</span></label>
        <input id="username" type="text" name="username" class="vts-input" required value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" data-allow="alnum" pattern="[A-Za-z0-9._\-]{3,40}" title="Letters, numbers, dots, underscores and hyphens only." maxlength="40"></div>
      <div class="field"><label for="email">Email <span class="req">*</span></label>
        <input id="email" type="email" name="email" class="vts-input" required value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>"></div>
      <div class="field"><label for="role">Role <span class="req">*</span></label>
        <select id="role" name="role" class="vts-select" required>
          <?php
            $roleOpts = ['Admin','OSA','OSA Staff','Guard'];
            if (($_SESSION['role'] ?? '') === 'OSA') $roleOpts = array_diff($roleOpts, ['Admin']);
            foreach($roleOpts as $r): ?>
            <option <?php echo (($user['role']??'')===$r)?'selected':''; ?>><?php echo $r; ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label for="status">Status</label>
        <select id="status" name="status" class="vts-select">
          <option <?php echo (($user['status']??'')==='Active')?'selected':''; ?>>Active</option>
          <option <?php echo (($user['status']??'')==='Inactive')?'selected':''; ?>>Inactive</option>
        </select></div>
      <div class="field full"><label for="pwd">Pass <span style="color:var(--text-muted);font-weight:400;text-transform:none;">(blank = keep)</span></label>
        <div class="password-wrap">
          <input type="password" autocomplete="new-password" name="password" id="pwd" class="vts-input" placeholder="8+ mixed chars">
          <button type="button" class="eye" aria-label="Show password" title="Show password"><i class="fas fa-eye" aria-hidden="true"></i></button>
        </div></div>
    </div>
    <div class="u-actions u-mt-14">
      <button type="submit" class="btn-primary"><i class="fas fa-floppy-disk"></i> Update User</button>
      <a href="users.php" class="btn-outline">Cancel</a>
    </div>
  </form>
</div>

<script>
/* The eye is handled by the delegated listener in assets/js/vts-ui.js.
   A local copy here meant both ran on one press and cancelled out. */
</script>

<?php include "../includes/admin_footer.php"; ?>
