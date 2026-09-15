<?php
/* Forgot password (all roles): email a 6-digit reset code, then set a new password. If mail is off, an admin can reset via Admin > Users. */
require_once __DIR__ . "/auth/session.php";   // hardened session start (strict mode, httponly/samesite cookie, fresh ID on first use) + security headers
require_once "config/database.php";
require_once "includes/functions.php";

$step  = 'email';
$email = trim($_POST['email'] ?? $_GET['email'] ?? '');
$error = ''; $info = $_GET['info'] ?? '';
$devCode = '';   // only ever set on a dev machine, see below

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $error = "Session expired. Please try again.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_code'])) {
    if ($email === '') {
        $error = "Enter your School ID or your email address.";
    } else {
        /* ---- SCHOOL ID *OR* EMAIL ----
           Staff sign in with an email and know it. Students sign in with a
           School ID and may not know which address the office holds for them,
           so asking only for an email shut them out of their own reset. Both
           are accepted; the column each one matches is the only difference. */
        $st = $conn->prepare(
            "SELECT id, fullname, email FROM users
              WHERE email = :e OR (student_id IS NOT NULL AND student_id <> '' AND student_id = :s)
              LIMIT 1");
        $st->execute([':e' => $email, ':s' => strtoupper($email)]);
        $u = $st->fetch(PDO::FETCH_ASSOC);

        /* ---- THE SAME ANSWER EITHER WAY ----
           This used to reply "No account is registered under X", which turns
           the page into a way to test whether an address — or now a School ID
           — belongs to somebody. verify.php already refuses to do that on
           purpose; this matches it. A real account gets a code, an imaginary
           one gets the same sentence and no code, and the reset step simply
           fails on a wrong code. */
        $neutral = "If that account exists, a 6-digit reset code is on its way to the email we hold for it.";

        if (!$u) {
            $info = $neutral;
            $step = 'reset';
        } else {
            /* ---- ONE CODE PER MINUTE ----
               There was no cooldown at all, so this form could be pointed at
               somebody's address and fired in a loop. Same helper and same
               limit verify.php uses. */
            $wait = vts_otp_resend_wait($conn, (string)$u['email']);
            if ($wait > 0) {
                $info = "A code was just sent. You can ask for another in {$wait} second" . ($wait === 1 ? '' : 's') . ".";
                $step = 'reset';
            } else {
                vts_otp_resend_record($conn, (string)$u['email']);
                $email = (string)$u['email'];   // the reset step matches on this

                /* Its own column, and hashed. This used to write verify_code,
                   which verify.php uses for NEW-registration email checks —
                   so asking for a reset silently wiped a pending verification
                   and vice versa. See vts_ensure_reset_columns(). */
                $code = vts_issue_reset_code($conn, (int)$u['id']);
                if ($code === null) {
                    $error = "Couldn't start a reset just now. Please try again.";
                    $code  = '';
                }

                require_once __DIR__ . "/includes/mailer.php";
            // Send through the unified sender (Brevo HTTP API — no PHPMailer needed).
            $body = vts_mail_shell('Password reset code',
                '<p style="font-size:14px;color:#333;">Hi <b>' . htmlspecialchars($u['fullname']) . '</b>,</p>'
              . '<p style="font-size:14px;color:#333;">Use this code to reset your password. It expires in 30 minutes.</p>'
              . '<p style="text-align:center;font-size:30px;font-weight:800;letter-spacing:8px;color:var(--navy);margin:16px 0;">' . htmlspecialchars($code) . '</p>'
              . '<p style="font-size:13px;color:#777;">If you didn\'t request this, you can ignore this email.</p>');
            $sent = function_exists('vts_send_mail')
                ? vts_send_mail($email, $u['fullname'], 'Your GWC password reset code: ' . $code, $body)
                : (function_exists('send_verification_email') && send_verification_email($email, $u['fullname'], $code));

                if ($sent) {
                    $info = "A 6-digit reset code was sent to your email.";
                    $step = 'reset';
                } elseif (vts_dev_reveal_ok()) {
                /* DEV ONLY, and doubly gated — see vts_dev_reveal_ok().
                   The code is generated either way; this just shows it when
                   there is no mailbox to send it to, so a reset can be
                   finished on a development machine. */
                    $devCode = $code;
                    $step    = 'reset';
                } else {
                    /* Neutral again: a failure to send must not confirm that
                       the account exists either. The admin route is offered
                       regardless of whether there was anything to send. */
                    $info = $neutral . " If nothing arrives, ask the OSA/Admin to reset it for you.";
                    $step = 'reset';
                }
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_reset'])) {
    $step = 'reset';
    $code = trim($_POST['code'] ?? '');
    $pw1  = $_POST['password'] ?? '';
    $pw2  = $_POST['confirm'] ?? '';

    $st = $conn->prepare("SELECT id FROM users WHERE email = :e LIMIT 1");
    $st->execute([':e' => $email]);
    $u = $st->fetch(PDO::FETCH_ASSOC);

    /* "Incorrect reset code" covers a missing account too: saying "no account
       found" here would confirm which addresses are registered, which is the
       thing step 1 deliberately refuses to do. */
    if (!$u)                                            $error = "Incorrect or expired reset code.";
    elseif (!vts_check_reset_code($conn, (int)$u['id'], $code))
                                                        $error = "Incorrect or expired reset code.";
    elseif (($pe = password_policy_error($pw1)) !== '') $error = $pe;
    elseif ($pw1 !== $pw2)                              $error = "The two passwords do not match.";
    else {
        /* email_verified is set here because reaching this point proves the
           person reads that mailbox — but verify_code is NOT touched, so a
           registration check that was already in flight still works. */
        $up = $conn->prepare("UPDATE users SET password=:p, email_verified=1 WHERE id=:id");
        $up->execute([':p' => password_hash($pw1, PASSWORD_DEFAULT), ':id' => $u['id']]);
        vts_clear_reset_code($conn, (int)$u['id']);   // single use
        // This is one of the two ways a student gets a real password (the
        // other is registering). Recording it is what moves them off the
        // last-name login — see vts_ensure_password_flag().
        vts_mark_password_set($conn, (int)$u['id']);
        /* No ?staff=1 — that query flag pops the staff role picker open, which is
           what a student saw after resetting their own password. Back to the
           ordinary sign-in card. */
        header("Location: student_search.php?success=" . urlencode("Password reset! You can now log in with your new password."));
        exit();
    }
}
if (isset($_GET['step']) && $_GET['step'] === 'reset') $step = 'reset';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password — VTS</title>
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
<link rel="stylesheet" href="assets/css/auth-pages.css?v=<?php echo @filemtime(__DIR__."/assets/css/auth-pages.css"); ?>">
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
<div class="fbox">
  <div class="ic"><i class="fas fa-key"></i></div>
  <h1>Forgot your password?</h1>
  <p class="sub"><?php echo $step === 'email'
      ? "Enter your School ID or email and we'll send a reset code."
      : "Enter the 6-digit code from your email and your new password."; ?></p>

  <?php if ($error): ?><div class="alert alert-danger"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>
  <?php if ($info):  ?><div class="alert alert-info"><i class="fas fa-circle-info"></i> <?php echo htmlspecialchars($info); ?></div><?php endif; ?>

  <?php if ($devCode !== ''): ?>
    <?php /* Deliberately ugly. This is a secret on a screen, and it should
             never look like a normal part of the page. */ ?>
    <div class="dev-code" role="alert">
      <div class="dev-code-warn"><i class="fas fa-triangle-exclamation"></i> Testing mode &mdash; email is not configured</div>
      <div class="dev-code-value"><?php echo htmlspecialchars($devCode); ?></div>
      <div class="dev-code-note">
        Shown because <code>DEV_SHOW_RESET_CODE</code> is on and you are on localhost.
        Turn it off in <code>config/app.php</code> before deploying.
      </div>
    </div>
  <?php endif; ?>

  <?php if ($step === 'email'): ?>
  <form method="POST">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="send_code" value="1">
    <div class="vts-form-group">
      <label for="email">School ID or Email</label>
      <input id="email" type="text" name="email" class="vts-input" required autofocus
             value="<?php echo htmlspecialchars($email); ?>" placeholder="Your School ID or email">
    </div>
    <button class="fbtn" type="submit"><i class="fas fa-paper-plane"></i> Send Reset Code</button>
  </form>
  <?php else: ?>
  <form method="POST">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="do_reset" value="1">
    <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
    <div class="vts-form-group">
      <label for="code">6-Digit Code</label>
      <input id="code" type="text" name="code" class="vts-input" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" required autofocus>
    </div>
    <div class="vts-form-group">
      <label for="password">New Password</label>
      <div class="password-wrap">
        <input id="password" type="password" autocomplete="new-password" name="password" class="vts-input" required
               placeholder="8+ mixed chars" minlength="8" style="padding-right:46px;">
        <button type="button" class="eye" aria-label="Show password" title="Show password"><i class="fas fa-eye" aria-hidden="true"></i></button>
      </div>
    </div>
    <div class="vts-form-group">
      <label for="confirm">Confirm New Password</label>
      <div class="password-wrap">
        <input id="confirm" type="password" autocomplete="new-password" name="confirm" class="vts-input" required style="padding-right:46px;">
        <button type="button" class="eye" aria-label="Show password" title="Show password"><i class="fas fa-eye" aria-hidden="true"></i></button>
      </div>
    </div>
    <button class="fbtn" type="submit"><i class="fas fa-rotate"></i> Reset Password</button>
  </form>
  <?php endif; ?>

  <div class="flinks">
    <?php if ($step === 'reset'): ?><a href="forgot_password.php">Resend code</a> &nbsp;·&nbsp;<?php endif; ?>
    <a href="student_search.php">Back to login</a>
  </div>
</div>
<!-- Menus + form validation, the same file the rest of the app loads. -->
<script src="assets/js/vts-ui.js?v=<?php echo @filemtime(__DIR__."/assets/js/vts-ui.js"); ?>" defer></script>
<?php /* The show/hide eye is handled once, for every page, by the delegated
         listener in assets/js/vts-ui.js. This page used to carry its own copy
         as well — so each click ran BOTH, the field flipped twice, and the
         control looked broken. One handler only. */ ?>
</body>
</html>
