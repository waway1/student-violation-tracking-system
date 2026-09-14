<?php
/* Mail diagnostic (Admin): shows why email/OTP is failing and sends a live test message. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (!in_array($_SESSION['role'] ?? '', ['Admin', 'OSA'], true)) { vts_deny_access(); }

$HAS_MAIL_CFG = @include_once __DIR__ . "/../config/mail.php";
@include_once __DIR__ . "/../includes/mailer.php";

/* ---------- Checks ---------- */
$checks = [];

// 1. mail config file
$checks[] = [
  'name' => 'config/mail.php exists',
  'ok'   => defined('MAIL_ENABLED'),
  'fix'  => 'The file config/mail.php is missing or has an error.',
];

// 2. mail enabled
$checks[] = [
  'name' => 'MAIL_ENABLED is true',
  'ok'   => defined('MAIL_ENABLED') && MAIL_ENABLED,
  'fix'  => 'Set  define(\'MAIL_ENABLED\', true);  in config/mail.php',
];

// 3. PHPMailer library
$hasVendor = file_exists(__DIR__ . '/../vendor/autoload.php');
$hasManual = file_exists(__DIR__ . '/../PHPMailer/src/PHPMailer.php');
$checks[] = [
  'name' => 'PHPMailer library installed (needed for Gmail SMTP)',
  'ok'   => $hasVendor || $hasManual,
  'fix'  => 'Run  composer require phpmailer/phpmailer  OR download PHPMailer and place it at /PHPMailer/src/PHPMailer.php',
];

// 4. Gmail creds filled in
$gmailSet = defined('MAIL_USERNAME') && MAIL_USERNAME !== 'your_email@gmail.com'
         && defined('MAIL_PASSWORD') && MAIL_PASSWORD !== 'your_app_password_here';
$checks[] = [
  'name' => 'Gmail address + App Password filled in (used on localhost)',
  'ok'   => $gmailSet,
  'fix'  => 'Put your Gmail + 16-char App Password in config/mail.php (NOT your normal password).',
];

// 5. Brevo key (needed live)
$brevoSet = defined('BREVO_API_KEY') && strpos(BREVO_API_KEY, 'REPLACE') === false
         && defined('BREVO_SENDER_EMAIL') && BREVO_SENDER_EMAIL !== 'your_verified_sender@example.com';
$checks[] = [
  'name' => 'Brevo API key + verified sender (REQUIRED on the live site)',
  'ok'   => $brevoSet,
  'fix'  => 'InfinityFree blocks SMTP. Get a free Brevo key (brevo.com) and set BREVO_API_KEY + BREVO_SENDER_EMAIL.',
];

// 6. Which route will actually be used right now?
$isLocal = function_exists('mail_is_local') ? mail_is_local() : false;
$route   = $isLocal ? 'Gmail SMTP (localhost)' : 'Brevo HTTP API (live server)';
$routeOk = $isLocal ? ($gmailSet && ($hasVendor || $hasManual)) : $brevoSet;

/* ---------- Send test ---------- */
$sendResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $sendResult = ['ok' => false, 'msg' => 'This page was left open too long and the security token expired. Reload the page and send the test again.'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $to = trim($_POST['to'] ?? '');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $sendResult = ['ok' => false, 'msg' => 'That email address looks invalid.'];
    } elseif (!function_exists('send_verification_email')) {
        $sendResult = ['ok' => false, 'msg' => 'send_verification_email() not found — includes/mailer.php failed to load.'];
    } else {
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $sent = false;
        try { $sent = send_verification_email($to, 'Mail Test', $code); }
        catch (Throwable $e) { $sendResult = ['ok' => false, 'msg' => 'Error: ' . $e->getMessage()]; }
        if ($sendResult === null) {
            $sendResult = $sent
              ? ['ok' => true,  'msg' => "Sent! Check {$to} (and Spam). The test code was {$code}."]
              : ['ok' => false, 'msg' => 'The mailer returned FALSE — the email did not go out. Fix the red items above, then retry. Check your PHP error log for details.'];
        }
    }
}

$adminActive = 'settings';
include "../includes/admin_header.php";
?>

<div class="admin-welcome-row">
  <div><h1>Mail / OTP Diagnostic</h1><p>Find out exactly why verification &amp; password-reset emails aren't sending.</p></div>
</div>

<div class="recent-table-wrap table-responsive" style="max-width:820px;">
  <h3 style="margin:0 0 4px;font-size:1rem;color:var(--navy);"><i class="fas fa-route"></i> Route being used right now</h3>
  <p style="color:var(--text-soft);font-size:.88rem;margin-bottom:14px;">
    This server looks like <strong><?php echo $isLocal ? 'LOCALHOST' : 'a LIVE server'; ?></strong>,
    so mail will go through <strong><?php echo htmlspecialchars($route); ?></strong>.
    <?php if (!$isLocal): ?><br>
      <em>Note: free hosts (InfinityFree) block SMTP — only the Brevo HTTP API works online.</em>
    <?php endif; ?>
  </p>
  <div class="alert alert-static <?php echo $routeOk ? 'alert-success' : 'alert-error'; ?> u-mb-20">
    <i class="fas fa-<?php echo $routeOk ? 'circle-check' : 'circle-exclamation'; ?>"></i>
    <?php echo $routeOk
      ? 'This route looks configured. Send a test below to confirm.'
      : 'This route is NOT ready — fix the red items below, or no email will send.'; ?>
  </div>

  <h3 style="margin:0 0 10px;font-size:1rem;color:var(--navy);"><i class="fas fa-list-check"></i> Checks</h3>
  <div class="table-scroll table-responsive">
    <table class="data-table">
      <thead><tr><th style="width:44px;">Ok</th><th>Check</th><th>How to fix</th></tr></thead>
      <tbody>
      <?php foreach ($checks as $c): ?>
        <tr>
          <td style="text-align:center;font-size:1.05rem;color:<?php echo $c['ok'] ? '#1db866' : '#e05050'; ?>;">
            <i class="fas fa-<?php echo $c['ok'] ? 'circle-check' : 'circle-xmark'; ?>"></i>
          </td>
          <td><?php echo htmlspecialchars($c['name']); ?></td>
          <td style="color:var(--text-soft);font-size:.84rem;">
            <?php echo $c['ok'] ? '<span style="color:#1db866;">— ready —</span>' : htmlspecialchars($c['fix']); ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <h3 style="margin:26px 0 10px;font-size:1rem;color:var(--navy);"><i class="fas fa-paper-plane"></i> Send a test email</h3>
  <?php if ($sendResult): ?>
    <div class="alert <?php echo $sendResult['ok'] ? 'alert-success' : 'alert-error'; ?> u-mb-12">
      <i class="fas fa-<?php echo $sendResult['ok'] ? 'circle-check' : 'circle-exclamation'; ?>"></i>
      <?php echo htmlspecialchars($sendResult['msg']); ?>
    </div>
  <?php endif; ?>
  <form method="POST" class="u-row-wrap mailtest-form">
    <?php echo csrf_field(); ?>
    <label for="mailTestTo" class="sr-only">Email address to send the test to</label>
    <input id="mailTestTo" type="email" name="to" class="vts-input" required
           placeholder="send test to…" value="<?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?>">
    <button type="submit" class="btn-primary"><i class="fas fa-paper-plane"></i> Send test</button>
  </form>
  <p style="color:var(--text-faint);font-size:.8rem;margin-top:10px;">
    Where OTP is used now: <strong>Forgot Password</strong> (reset code) and <strong>Verify Email</strong>.
    Registration no longer needs OTP — the School ID roster already proves enrollment.
  </p>
</div>

<?php include "../includes/admin_footer.php"; ?>
