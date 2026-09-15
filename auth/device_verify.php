<?php
/* New-device gate, step 2: the 6-digit code vts_complete_login() emailed
   because this browser isn't the one already trusted for this account.
   Nothing is written to $_SESSION as "logged in" until a code is accepted
   here — same rule login_otp.php uses for staff, extended to every role.
   See the "NEW-DEVICE GATE" block above vts_complete_login() in
   includes/functions.php for the whole design. */
require_once __DIR__ . "/session.php";            // hardened session + security headers
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/functions.php";

// Already fully signed in? Nothing to finish.
if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    header("Location: " . base_path() . '/' . vts_login_landing($_SESSION['role']));
    exit();
}

/* Path only: a redirect must never name a host, or a phone behind a tunnel
   is sent to whatever the Host header happened to say. See base_path(). */
$loginUrl = base_path() . '/student_search.php';

/* No half-finished device check (bookmarked the page, session cleared, or
   this browser was never sent here) -> back to the login form. Never hint
   at whether an account exists. */
$pending = $_SESSION['device_pending'] ?? null;
if (!is_array($pending) || empty($pending['user_id'])) {
    header("Location: " . $loginUrl . "?error=" . urlencode("Please sign in again."));
    exit();
}

// Re-read the account fresh every time — it may have been deactivated in
// the minutes between the password step and this one.
$st = $conn->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
$st->execute([':id' => (int)$pending['user_id']]);
$user = $st->fetch(PDO::FETCH_ASSOC);
if (!$user || (isset($user['status']) && $user['status'] === 'Inactive')) {
    vts_device_clear_otp($conn, (int)$pending['user_id']);
    unset($_SESSION['device_pending']);
    header("Location: " . $loginUrl . "?error=" . urlencode("That account is no longer available."));
    exit();
}
$email = (string)$user['email'];

/* The pending step expires with the code it is waiting for — otherwise a
   half-finished sign-in could sit open in a browser as long as it stayed up. */
if (time() - (int)($pending['started'] ?? 0) > VTS_DEVICE_OTP_TTL) {
    vts_device_clear_otp($conn, (int)$pending['user_id']);
    unset($_SESSION['device_pending']);
    header("Location: " . $loginUrl . "?error=" . urlencode("That sign-in timed out. Please sign in again."));
    exit();
}

$error = '';
$info  = '';

/* Abandon the sign-in deliberately. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel'])) {
    if (csrf_verify()) {
        vts_device_clear_otp($conn, (int)$pending['user_id']);
        unset($_SESSION['device_pending']);
        session_regenerate_id(true);
        header("Location: " . $loginUrl);
        exit();
    }
    $error = "Session expired. Please try again.";

/* ---- Send a fresh code ---- */
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend'])) {
    if (!csrf_verify()) {
        $error = "Session expired. Please try again.";
    } else {
        $wait = vts_otp_resend_wait($conn, $email, 60);
        if ($wait > 0) {
            $info = "A code was just sent. You can ask for another in {$wait} second" . ($wait === 1 ? '' : 's') . ".";
        } else {
            vts_otp_resend_record($conn, $email);
            $newDev = null;
            $sent = vts_device_issue_otp($conn, $user, $newDev);
            $_SESSION['device_pending']['started']  = time();
            $_SESSION['device_pending']['dev_code'] = $newDev;   // dev machines only
            $info = $sent
                ? "A new code is on its way to " . vts_mask_email($email) . "."
                : "";
            if (!$sent) $error = "Couldn't send a new code right now. Please try again in a moment.";
        }
    }

/* ---- Check the typed code ---- */
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = "Session expired. Please try again.";
    } else {
        // Same 6-wrong-codes-in-15-minutes lock every other OTP step shares.
        $lock = vts_otp_lock_seconds($conn, $email);
        if ($lock > 0) {
            $mins  = (int)ceil($lock / 60);
            $error = "Too many incorrect codes. Please wait "
                   . ($mins > 1 ? "$mins minutes" : "a minute")
                   . " and try again, or ask for a fresh code.";
        } elseif (!vts_device_check_otp($conn, (int)$pending['user_id'], (string)($_POST['code'] ?? ''))) {
            vts_otp_record($conn, $email, false);
            $error = "That code didn't match. Check the 6-digit code in your email.";
        } else {
            vts_otp_record($conn, $email, true);
            $auditNote = (string)($pending['audit_note'] ?? ('Signed in as ' . $user['role']));
            unset($_SESSION['device_pending']);
            vts_establish_session($user);   // this browser is now the recognised one
            audit_log($conn, "Login", "users", $user['id'], $auditNote . ' (new device confirmed)');
            header("Location: " . base_path() . '/' . vts_login_landing($user['role']));
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Confirm This Device — <?php echo htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'QR Shield'); ?></title>
<link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="../assets/vendor/fonts/css/fonts.css">
<link rel="stylesheet" href="../assets/css/vts-forms.css?v=<?php echo @filemtime(__DIR__."/../assets/css/vts-forms.css"); ?>">
<link rel="stylesheet" href="../assets/css/vts-theme.css?v=<?php echo @filemtime(__DIR__.'/../assets/css/vts-theme.css'); ?>">
<link rel="stylesheet" href="../assets/css/vts-polish.css?v=<?php echo @filemtime(__DIR__.'/../assets/css/vts-polish.css'); ?>">
<link rel="stylesheet" href="../assets/css/vts-minimal.css?v=<?php echo @filemtime(__DIR__.'/../assets/css/vts-minimal.css'); ?>">
<link rel="stylesheet" href="../assets/css/auth-pages.css?v=<?php echo @filemtime(__DIR__.'/../assets/css/auth-pages.css'); ?>">
<link rel="stylesheet" href="../assets/css/auth-verify.css?v=<?php echo @filemtime(__DIR__."/../assets/css/auth-verify.css"); ?>">
<link rel="stylesheet" href="../assets/css/vts-design.css?v=<?php echo @filemtime(__DIR__."/../assets/css/vts-design.css"); ?>">
</head>
<body>
<?php include __DIR__ . '/../includes/bfcache_guard.php'; ?>
<a class="auth-home" href="../index.php">
  <i class="fas fa-chevron-left" aria-hidden="true"></i> Back to QR Shield
</a>
  <div class="vbox">
    <div class="ic"><i class="fas fa-mobile-screen-button"></i></div>
    <h1>Confirm this device</h1>
    <p>We don't recognize this browser for <b><?php echo htmlspecialchars($user['fullname']); ?></b>'s account.
       Your other device stays signed in either way — this only decides whether
       <b>this one</b> may sign in too.<br>
       We sent a <b>6-digit code</b> to <b><?php echo htmlspecialchars(vts_mask_email($email)); ?></b>.</p>

    <?php
    // DEV ONLY — same double gate as every other OTP step (vts_dev_reveal_ok()).
    $devCode = vts_dev_reveal_ok() ? (string)($_SESSION['device_pending']['dev_code'] ?? '') : '';
    ?>
    <?php if ($devCode !== ''): ?>
      <div class="dev-code" role="alert">
        <div class="dev-code-warn"><i class="fas fa-triangle-exclamation"></i> Testing mode &mdash; shown because you are on localhost</div>
        <div class="dev-code-value"><?php echo htmlspecialchars($devCode); ?></div>
        <div class="dev-code-note">Turn this off with <code>DEV_SHOW_RESET_CODE</code> in <code>config/app.php</code>.</div>
      </div>
    <?php endif; ?>

    <?php if ($error): ?><div class="alert alert-danger"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($info):  ?><div class="alert alert-info"><i class="fas fa-circle-info"></i> <?php echo htmlspecialchars($info); ?></div><?php endif; ?>

    <form method="POST">
      <?php echo csrf_field(); ?>
      <label for="code" class="sr-only">6-digit device code</label>
      <input id="code" type="text" name="code" class="code-input" maxlength="6" minlength="6"
             inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}"
             title="Enter the 6 digits from the email" placeholder="••••••" required autofocus>
      <button type="submit" class="vbtn"><i class="fas fa-check"></i> Confirm &amp; Continue</button>
    </form>

    <div class="vlinks">
      <form method="POST" class="vts-inline-form" data-no-validate>
        <?php echo csrf_field(); ?>
        <input type="hidden" name="resend" value="1">
        <button type="submit" class="vlink-btn">Send a new code</button>
      </form>
      &nbsp;·&nbsp;
      <form method="POST" class="vts-inline-form" data-no-validate>
        <?php echo csrf_field(); ?>
        <input type="hidden" name="cancel" value="1">
        <button type="submit" class="vlink-btn">Use a different account</button>
      </form>
    </div>
  </div>
<script src="../assets/js/vts-ui.js?v=<?php echo @filemtime(__DIR__."/../assets/js/vts-ui.js"); ?>" defer></script>
</body>
</html>
