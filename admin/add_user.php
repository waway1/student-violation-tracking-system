<?php
/* Admin: form + handler to create a new staff/user account. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";
vts_ensure_role_enum($conn);
vts_ensure_user_columns($conn);

if (!in_array($_SESSION['role'] ?? '', ['Admin', 'OSA'], true)) {
    vts_deny_access();
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_verify()) { vts_csrf_fail('add that account'); }
    $fullname = name_case($_POST['fullname'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role     = $_POST['role'] ?? '';

    if ($fullname===''||$username===''||$email===''||$password===''||$role==='') {
        $error = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address.";
    } elseif (($pe = password_policy_error($password)) !== '') {
        $error = $pe;
    } elseif (!in_array($role, ['Admin','OSA','OSA Staff','Guard'], true)) {
        $error = "Invalid role.";
    } elseif ($role === 'Admin' && ($_SESSION['role'] ?? '') === 'OSA') {
        $error = "OSA accounts can't create Admin accounts. Ask an Admin.";
    } elseif ($role === 'Guard' && !vts_guard_slot_available($conn)) {
        $error = "Only 2 Guard/Marshal accounts are allowed at a time (leader + assistant). Deactivate one first if you need to add a different person.";
    } else {
        try {
            $checkU = $conn->prepare("SELECT id FROM users WHERE username=:u");
            $checkU->execute([':u'=>$username]);
            $checkE = $conn->prepare("SELECT id FROM users WHERE email=:e");
            $checkE->execute([':e'=>$email]);
            if ($checkU->fetch()) {
                $error = "The username \"{$username}\" is already taken. Please choose another.";
            } elseif ($checkE->fetch()) {
                $error = "The email \"{$email}\" is already registered to another account.";
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $ins = $conn->prepare("INSERT INTO users (fullname, username, email, password, role, status, email_verified)
                                       VALUES (:f, :u, :e, :p, :r, 'Active', 1)");
                $ins->execute([':f'=>$fullname, ':u'=>$username, ':e'=>$email, ':p'=>$hash, ':r'=>$role]);
                audit_log($conn, "Add User", "users", (int)$conn->lastInsertId(), "Created {$role} account");
                header("Location: users.php?success=User added successfully.");
                exit();
            }
        } catch (Throwable $e) { error_log('Admin add user failed: ' . $e->getMessage()); $error = 'The account could not be saved. Please check the details and try again.'; }
    }
}

$adminActive = 'users';
include "../includes/admin_header.php";
?>

<div class="admin-welcome-row">
  <div><h1>Add User</h1><p>Create a new staff account (Admin, OSA, OSA Staff, or Guard/Marshal).</p></div>
  <a href="users.php" class="btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<?php if($error): ?>
  <div class="alert alert-error u-mb-14"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="recent-table-wrap table-responsive" style="max-width:680px;">
  <form method="POST">
<?php echo csrf_field(); ?>
    <div class="form-grid">
      <div class="field full"><label for="fullname">Name <span class="req">*</span></label>
        <input id="fullname" type="text" name="fullname" class="vts-input" required value="<?php echo htmlspecialchars($_POST['fullname'] ?? ''); ?>" data-allow="letters" pattern="[A-Za-zÀ-ɏÑñ .'\-]{2,60}" title="Letters, spaces, hyphens, apostrophes and periods only." maxlength="80"></div>
      <div class="field"><label for="username">User <span class="req">*</span></label>
        <input id="username" type="text" name="username" class="vts-input" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" data-allow="alnum" pattern="[A-Za-z0-9._\-]{3,40}" title="Letters, numbers, dots, underscores and hyphens only." maxlength="40"></div>
      <div class="field"><label for="email">Email <span class="req">*</span></label>
        <input id="email" type="email" name="email" class="vts-input" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"></div>
      <div class="field"><label for="role">Role <span class="req">*</span></label>
        <select id="role" name="role" class="vts-select" required>
          <option value="">Select Role</option>
          <?php
            $roleOpts = ['Admin','OSA','OSA Staff','Guard'];
            if (($_SESSION['role'] ?? '') === 'OSA') $roleOpts = array_diff($roleOpts, ['Admin']);
            foreach($roleOpts as $r): ?>
            <option <?php echo (($_POST['role']??'')===$r)?'selected':''; ?>><?php echo $r==='Guard'?'Guard':$r; ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label for="pwd">Pass <span class="req">*</span></label>
        <div class="password-wrap">
          <input type="password" autocomplete="new-password" name="password" id="pwd" class="vts-input" minlength="8" required placeholder="8+ mixed chars">
          <button type="button" class="eye" aria-label="Show password" title="Show password"><i class="fas fa-eye" aria-hidden="true"></i></button>
        </div></div>
    </div>
    <div class="u-actions u-mt-14">
      <button type="submit" class="btn-primary"><i class="fas fa-floppy-disk"></i> Save User</button>
      <a href="users.php" class="btn-outline">Cancel</a>
    </div>
  </form>
</div>

<script>
/* The eye is handled by the delegated listener in assets/js/vts-ui.js.
   A local copy here meant both ran on one press and cancelled out. */
</script>

<?php include "../includes/admin_footer.php"; ?>
