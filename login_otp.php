<?php
/* Staff two-step sign-in, step 2: the 6-digit code emailed by
   auth/login_process.php after a correct password. Nothing in the session
   counts as "logged in" until a code is accepted here — see the block comment
   above vts_issue_login_otp() in includes/functions.php for why. */
require_once __DIR__ . "/auth/session.php";       // hardened session + security headers
require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/functions.php";

// Already fully signed in? Nothing to finish.
if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    $dest = vts_login_destination($_SESSION['role']);
    header("Location: " . base_path() . '/' . ($dest !== '' ? $dest : 'student_search.php'));
    exit();
}

/* Path only - see base_path(). */
$loginUrl = base_path() . '/student_search.php?staff=1';

/* No half-finished sign-in (bookmarked the page, or the session was cleared)
   -> back to the login form. Never hint at whether an account exists. */
$pending = $_SESSION['pending_otp'] ?? null;
if (!is_array($pending) || empty($pending['user_id'])) {
    header("Location: " . $loginUrl . "&error=" . urlencode("Please sign in again."));
    exit();
}

/* The pending step expires with the code it is waiting for. Without this a
   half-finished sign-in could sit in a session for as long as the browser
   stayed open, waiting for someone to walk up to the machine. */
$ttl = defined('STAFF_OTP_TTL') ? (int)STAFF_OTP_TTL : 600;
if (time() - (int)($pending['started'] ?? 0) > $ttl) {
    vts_clear_login_otp($conn, (int)$pending['user_id']);
    unset($_SESSION['pending_otp']);
    header("Location: " . $loginUrl . "&error=" . urlencode("That sign-in timed out. Please enter your password again."));
    exit();
}

$email = (string)$pending['email'];
$error = '';
$info  = '';

/* Abandon the sign-in deliberately (the "Use a different account" link). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel'])) {
    if (csrf_verify()) {
        vts_clear_login_otp($conn, (int)$pending['user_id']);
        unset($_SESSION['pending_otp']);
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
        $wait = vts_otp_resend_wait($conn, $email, defined('STAFF_OTP_RESEND') ? (int)STAFF_OTP_RESEND : 60);
        if ($wait > 0) {
            $info = "A code was just sent. You can ask for another in {$wait} second" . ($wait === 1 ? '' : 's') . ".";
        } else {
            vts_otp_resend_record($conn, $email);
            $newDev = null;
            $sent = vts_issue_login_otp($conn, [
                'id'       => (int)$pending['user_id'],
                'email'    => $email,
                'fullname' => (string)$pending['fullname'],
                'role'     => (string)$pending['role'],
            ], $newDev);
            // The clock restarts with the new code, so the page does not expire
            // out from under someone who just asked for one.
            $_SESSION['pending_otp']['started']  = time();
            $_SESSION['pending_otp']['dev_code'] = $newDev;   // dev machines only
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
        // Six digits is a million guesses; cap them before touching the row.
        // Same counters the registration/reset codes use.
        $lock = vts_otp_lock_seconds($conn, $email);
        if ($lock > 0) {
            $mins  = (int)ceil($lock / 60);
            $error = "Too many incorrect codes. Please wait "
                   . ($mins > 1 ? "$mins minutes" : "a minute")
                   . " and try again, or ask for a fresh code.";
        } elseif (!vts_check_login_otp($conn, (int)$pending['user_id'], (string)($_POST['code'] ?? ''))) {
            vts_otp_record($conn, $email, false);
            $error = "That code didn't match. Check the 6-digit code in your email.";
        } else {
            vts_otp_record($conn, $email, true);

            /* Re-read the account rather than trusting the pending copy: it may
               have been deactivated, or had its role changed, in the minutes
               between the password and the code. */
            $st = $conn->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
            $st->execute([':id' => (int)$pending['user_id']]);
            $user = $st->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                unset($_SESSION['pending_otp']);
                header("Location: " . $loginUrl . "&error=" . urlencode("That account is no longer available."));
                exit();
            }
            if (isset($user['status']) && $user['status'] === 'Inactive') {
                unset($_SESSION['pending_otp']);
                header("Location: " . $loginUrl . "&error=" . urlencode("This account is inactive. Contact the administrator."));
                exit();
            }

            unset($_SESSION['pending_otp']);
            /* The code was right, so the PASSWORD step and the STAFF 2FA step
               are both done. vts_complete_login() adds the last gate: if this
               browser is not the one recognised for the account it routes to
               auth/device_verify.php instead of a dashboard, and no session is
               written until that second code is entered too. On a browser this
               account already uses (the ordinary case) it returns the
               dashboard and nothing about this flow changes. */
            $landing = vts_complete_login($user, "Signed in via staff portal (two-step code confirmed)");
            header("Location: " . base_path() . '/' . $landing);
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
<title>Confirm Sign-In — <?php echo htmlspecialchars(APP_NAME); ?></title>
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<!-- Inter + Plus Jakarta Sans, served from this server (assets/vendor/fonts).
     NOT from fonts.googleapis.com: a stylesheet <link> is render-blocking, so
     with no internet every page sat blank until that request timed out. -->
<link rel="stylesheet" href="assets/vendor/fonts/css/fonts.css">
<link rel="stylesheet" href="assets/css/vts-forms.css?v=<?php echo @filemtime(__DIR__."/assets/css/vts-forms.css"); ?>">
<link rel="stylesheet" href="assets/css/vts-theme.css?v=<?php echo @filemtime(__DIR__.'/assets/css/vts-theme.css'); ?>">
<link rel="stylesheet" href="assets/css/vts-polish.css?v=<?php echo @filemtime(__DIR__.'/assets/css/vts-polish.css'); ?>">
<link rel="stylesheet" href="assets/css/vts-minimal.css?v=<?php echo @filemtime(__DIR__.'/assets/css/vts-minimal.css'); ?>">
<!-- The same shell as verify.php: this is the same act (type the 6 digits we
     emailed you), so it should not look like a different kind of page. -->
<link rel="stylesheet" href="assets/css/auth-pages.css?v=<?php echo @filemtime(__DIR__.'/assets/css/auth-pages.css'); ?>">
<link rel="stylesheet" href="assets/css/auth-verify.css?v=<?php echo @filemtime(__DIR__."/assets/css/auth-verify.css"); ?>">
  <!-- Design system: tokens, chrome and components. Loaded LAST so it
       settles anything the older stylesheets disagree about. -->
  <link rel="stylesheet" href="assets/css/vts-design.css?v=<?php echo @filemtime(__DIR__."/assets/css/vts-design.css"); ?>">
</head>
<body>
<?php include __DIR__ . '/includes/bfcache_guard.php'; ?>
<?php /* One way back to the page that explains what this is. These
         three screens had none: they were reached from a link or an
         email and ended at their own form. */ ?>
<a class="auth-home" href="index.php">
  <i class="fas fa-chevron-left" aria-hidden="true"></i> Back to QR Shield
</a>
  <div class="vbox">
    <div class="ic"><i class="fas fa-shield-halved"></i></div>
    <h1>Confirm it's you</h1>
    <p>Signing in as <b><?php echo htmlspecialchars($pending['fullname']); ?></b>
       (<?php echo htmlspecialchars($pending['role']); ?>).<br>
       We sent a <b>6-digit code</b> to <b><?php echo htmlspecialchars(vts_mask_email($email)); ?></b>.</p>

    <?php
    /* DEV ONLY. The code is emailed as normal; this also prints it when the
       request is local, so a sign-in can be finished on a machine whose
       mailbox you cannot read. Double-gated exactly like the password-reset
       code — see vts_dev_reveal_ok() in includes/functions.php. */
    $devCode = vts_dev_reveal_ok() ? (string)($_SESSION['pending_otp']['dev_code'] ?? '') : '';
    ?>
    <?php if ($devCode !== ''): ?>
      <div class="dev-code" role="alert">
        <div class="dev-code-warn"><i class="fas fa-triangle-exclamation"></i> Testing mode &mdash; shown because you are on localhost</div>
        <div class="dev-code-value"><?php echo htmlspecialchars($devCode); ?></div>
        <div class="dev-code-note">
          Turn this off with <code>DEV_SHOW_RESET_CODE</code>, or switch the whole
          step off with <code>STAFF_OTP_ENABLED</code>, both in <code>config/app.php</code>.
        </div>
      </div>
    <?php endif; ?>

    <?php if ($error): ?><div class="alert alert-danger"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($info):  ?><div class="alert alert-info"><i class="fas fa-circle-info"></i> <?php echo htmlspecialchars($info); ?></div><?php endif; ?>

    <form method="POST">
      <?php echo csrf_field(); ?>
      <label for="code" class="sr-only">6-digit sign-in code</label>
      <input id="code" type="text" name="code" class="code-input" maxlength="6" minlength="6"
             inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}"
             title="Enter the 6 digits from the email" placeholder="••••••" required autofocus>
      <button type="submit" class="vbtn"><i class="fas fa-check"></i> Confirm &amp; Sign In</button>
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
<script src="assets/js/vts-ui.js?v=<?php echo @filemtime(__DIR__."/assets/js/vts-ui.js"); ?>" defer></script>
</body>
</html>
