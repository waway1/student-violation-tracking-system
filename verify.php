<?php
/* Email verification: after registering, the student enters the 6-digit OTP (or clicks the emailed link) to activate login. */
require_once __DIR__ . "/auth/session.php";   // hardened session start (strict mode, httponly/samesite cookie, fresh ID on first use) + security headers
require_once "config/database.php";
require_once "includes/functions.php";

$email = trim($_GET['email'] ?? $_POST['email'] ?? '');
$error = $_GET['error'] ?? '';
$info  = $_GET['info']  ?? '';

// Auto-verify via emailed link: verify.php?email=..&code=123456
$codeIn = trim($_GET['code'] ?? $_POST['code'] ?? '');

if ($email !== '' && $codeIn !== '') {
    // A 6-digit code is a million guesses, and this accepted all of them.
    // Cap it before touching the database.
    $wait = vts_otp_lock_seconds($conn, $email);
    if ($wait > 0) {
        $mins  = (int)ceil($wait / 60);
        $error = "Too many incorrect codes. Please wait "
               . ($mins > 1 ? "$mins minutes" : "a minute")
               . " and try again, or request a fresh code.";
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
        $error = "Session expired. Please try again.";
    } else {
        $st = $conn->prepare("SELECT id, verify_code, verify_expires, email_verified
                              FROM users WHERE email = :e LIMIT 1");
        $st->execute([':e' => $email]);
        $u = $st->fetch(PDO::FETCH_ASSOC);

        if (!$u) {
            // Deliberately the same wording as a wrong code: saying "no
            // account found" turns this page into a way to test which email
            // addresses are registered.
            vts_otp_record($conn, $email, false);
            $error = "That code didn't match. Check the 6-digit code in your email.";
        } elseif ((int)$u['email_verified'] === 1) {
            // No ?staff=1: whoever just verified an email is far more likely a
            // student than staff, and that flag pops the staff role picker open.
            header("Location: student_search.php?success=" . urlencode("Email already verified — you can log in."));
            exit();
        } elseif (!$u['verify_code'] || !hash_equals($u['verify_code'], $codeIn)) {
            vts_otp_record($conn, $email, false);
            $error = "That code didn't match. Check the 6-digit code in your email.";
        } elseif ($u['verify_expires'] && strtotime($u['verify_expires']) < time()) {
            $error = "That code has expired. Use \"Send a new code\" below.";
        } else {
            vts_otp_record($conn, $email, true);
            $ok = $conn->prepare("UPDATE users
                                  SET email_verified = 1, verify_code = NULL, verify_expires = NULL
                                  WHERE id = :id");
            $ok->execute([':id' => $u['id']]);
            header("Location: student_search.php?success=" . urlencode("Email verified! You can now log in."));
            exit();
        }
    }
}

// Send a fresh code.
// This used to be a plain GET (verify.php?resend=1&email=…) with no cooldown:
// anyone could point it at a student's address and refill their inbox on a
// loop, and a link prefetcher could fire it by accident. POST + CSRF + a
// per-address cooldown.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend']) && $email !== '') {
    if (!csrf_verify()) {
        header("Location: verify.php?email=" . urlencode($email) . "&error=" . urlencode("Session expired. Please try again."));
        exit();
    }
    $wait = vts_otp_resend_wait($conn, $email);
    if ($wait > 0) {
        header("Location: verify.php?email=" . urlencode($email) . "&info="
             . urlencode("A code was just sent. You can ask for another in {$wait} second" . ($wait === 1 ? '' : 's') . "."));
        exit();
    }
    vts_otp_resend_record($conn, $email);

    $st = $conn->prepare("SELECT id, fullname, email_verified FROM users WHERE email = :e LIMIT 1");
    $st->execute([':e' => $email]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    // Always report the same thing, whether or not that address is
    // registered — otherwise this confirms which emails have accounts.
    $info = "If that email has an unverified account, a new code is on its way.";
    if ($u && (int)$u['email_verified'] === 0) {
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $up = $conn->prepare("UPDATE users SET verify_code=:c, verify_expires=DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id=:id");
        $up->execute([':c' => $code, ':id' => $u['id']]);
        $sent = false;
        if (file_exists(__DIR__ . "/includes/mailer.php")) {
            require_once __DIR__ . "/includes/mailer.php";
            if (function_exists('send_verification_email')) {
                $sent = send_verification_email($email, $u['fullname'], $code);
            }
        }
        if (!$sent) {
            $info = "Mail isn't set up on this server — ask the admin to verify your account.";
        }
    }
    header("Location: verify.php?email=" . urlencode($email) . "&info=" . urlencode($info));
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify Email — VTS</title>
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<!-- Inter + Plus Jakarta Sans, served from this server (assets/vendor/fonts).
     NOT from fonts.googleapis.com: a stylesheet <link> is render-blocking, so
     with no internet every page sat blank until that request timed out. -->
<link rel="stylesheet" href="assets/vendor/fonts/css/fonts.css">
  <!-- Same form-validation presentation as the rest of the app: every
       problem marked at once, in the app's own words, under the field. -->
  <link rel="stylesheet" href="assets/css/vts-forms.css?v=<?php echo @filemtime(__DIR__."/assets/css/vts-forms.css"); ?>">
<link rel="stylesheet" href="assets/css/vts-theme.css?v=<?php echo @filemtime(__DIR__.'/assets/css/vts-theme.css'); ?>">
<link rel="stylesheet" href="assets/css/vts-polish.css?v=<?php echo @filemtime(__DIR__.'/assets/css/vts-polish.css'); ?>">
  <link rel="stylesheet" href="assets/css/vts-minimal.css?v=<?php echo @filemtime(__DIR__.'/assets/css/vts-minimal.css'); ?>">
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
    <div class="ic"><i class="fas fa-envelope-open-text"></i></div>
    <h1>Verify your email</h1>
    <p>We sent a <b>6-digit code</b> to<br><b><?php echo htmlspecialchars($email ?: 'your email'); ?></b>.<br>
       Enter it below to activate your account.</p>

    <?php if ($error): ?><div class="alert alert-danger"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($info):  ?><div class="alert alert-info"><i class="fas fa-circle-info"></i> <?php echo htmlspecialchars($info); ?></div><?php endif; ?>

    <form method="POST">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
      <!-- The old aria-label was the literal bullet placeholder, which a
           screen reader announced as six dots. Use a real label instead. -->
      <label for="code" class="sr-only">6-digit verification code</label>
      <input id="code" type="text" name="code" class="code-input" maxlength="6" minlength="6"
             inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}"
             title="Enter the 6 digits from the email" placeholder="••••••" required autofocus>
      <button type="submit" class="vbtn"><i class="fas fa-check"></i> Verify</button>
    </form>

    <div class="vlinks">
      <!-- A POST, so it cannot be fired by a prefetcher or a crafted link. -->
      <form method="POST" style="display:inline;" data-no-validate>
        <?php echo csrf_field(); ?>
        <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
        <input type="hidden" name="resend" value="1">
        <button type="submit" class="vlink-btn">Send a new code</button>
      </form>
      &nbsp;·&nbsp; <a href="student_search.php">Back to login</a>
    </div>
  </div>
<!-- Menus + form validation, the same file the rest of the app loads. -->
<script src="assets/js/vts-ui.js?v=<?php echo @filemtime(__DIR__."/assets/js/vts-ui.js"); ?>" defer></script>
</body>
</html>
