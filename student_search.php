<?php
/* Single entry page for the whole system.

   • Students just look themselves up — type a last name / School ID, tap your
     name → your dashboard. No password, no separate login page.
   • Staff sign in through a HIDDEN pop-up on this same page. (This absorbed
     the old login.php, which no longer exists.) Three ways in:
       - Ctrl+Shift+S, or typing "gwcstaff" anywhere on the page — desktop.
       - Typing "gwcstaff" into the search box — desktop.
       - Five taps or a long press on the seal, which opens a small pad to
         type the key into — the phone route, and the only one a touchscreen
         can actually do. See assets/js/staff-key.js for why the search box
         alone was not enough there. */
require_once __DIR__ . "/auth/session.php";   // hardened session start (strict mode, httponly/samesite cookie, fresh ID on first use) + security headers
require_once "includes/functions.php";

// Already signed in? go straight to the right dashboard.
if (isset($_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case "Admin":        header("Location: admin/dashboard.php");       exit();
        case "OSA":          header("Location: admin/dashboard.php");       exit();
        case "OSA Staff":    header("Location: osa_staff/dashboard.php");   exit();
        case "Guard":        header("Location: spck_scanner.html");         exit();
        case "Student":      header("Location: student/dashboard.php");     exit();
        default:             unset($_SESSION['role']);                      break;
    }
}

/* ---- WHAT THE LANDING PAGE HANDED OVER ----

   index.php's "Find your record" box is a GET form pointed straight at this
   page (?q=...), and this page ignored the parameter completely: you typed
   your name, pressed Enter, and arrived at an EMPTY field with your typing
   thrown away. From the outside that is indistinguishable from "the search
   button just opens the login page", which is how it was reported.

   Capped at 80 characters because it is echoed back into the field, and a
   search term is a surname or a School ID, never a paragraph. */
$handoff = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($handoff) > 80) $handoff = mb_substr($handoff, 0, 80);

/* ---- Staff modal state (absorbed from the old login.php) ----
   A failed NON-AJAX staff attempt bounces back here with ?staff=1 (+ the role
   and username they typed) so the pop-up re-opens on the right step with the
   error shown. The AJAX path shows errors inline without ever leaving. */
$error     = $_GET['error']   ?? '';
$success   = $_GET['success'] ?? '';
$openStaff = isset($_GET['staff']);          // ?staff=1 opens the staff pop-up

/* The staff code typed into the LANDING page's box arrives here as ?q=, where
   the on-page shortcut (which watches keystrokes) never sees it. Treat it as
   the shortcut it is: open the staff pop-up, and drop the value rather than
   painting it into a visible field. It still only DECIDES WHETHER THE CARD IS
   SHOWN — every role behind it signs in with a real password checked by the
   server — which is why this is safe to act on from a query string. */
if ($handoff !== '' && preg_replace('/\s+/', '', mb_strtolower($handoff)) === 'gwcstaff') {
    $handoff   = '';
    $openStaff = true;
}

$retUser   = trim($_GET['u'] ?? '');         // username they typed (sticky)
$retRole   = $_GET['role'] ?? '';            // role they picked (sticky)
if (!in_array($retRole, ['Admin','OSA','OSA Staff','Guard'], true)) $retRole = '';

// The error belongs to whichever form was used.
$staffError   = $openStaff ? $error : '';
$studentError = !$openStaff ? $error : '';

/* WHICH BOX THE MESSAGE BELONGS TO.
   The sign-in handlers send back &f=identity / &f=password (staff: username /
   password) naming the field that was actually wrong. A message shown under
   the box it is about, with the cursor already in it, answers "what do I
   retype" in one glance; the same sentence in a banner above two boxes does
   not. Anything unrecognised falls back to the banner, which is still the
   right place for a session timeout or a lockout. */
$errField = $_GET['f'] ?? '';
if (!in_array($errField, ['identity', 'password', 'username'], true)) $errField = '';
$fieldErr = $errField !== '' ? $error : '';        // shown on the field
$bannerStudent = ($errField === '' ) ? $studentError : '';
$bannerStaff   = ($errField === '' ) ? $staffError   : '';

$roleTitles = [
    'Admin'     => ['Admin Login', 'System Administrator access.'],
    'OSA'       => ['OSA Login',   'Office of Student Affairs — full access.'],
    'OSA Staff' => ['OSA Staff Login', 'Office of Student Affairs — add/edit/view only.'],
    'Guard'     => ['Guard/Marshal Login', 'Campus security — scan + report to OSA/Admin.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Student Access | QR Shield</title>
  <!-- Inter + Plus Jakarta Sans, served from this server (assets/vendor/fonts).
       NOT from fonts.googleapis.com: a stylesheet <link> is render-blocking, so
       with no internet every page sat blank until that request timed out. -->
  <link rel="stylesheet" href="assets/vendor/fonts/css/fonts.css">
  <link rel="stylesheet" href="assets/css/vts-theme.css?v=<?php echo @filemtime(__DIR__.'/assets/css/vts-theme.css'); ?>">
  <link rel="stylesheet" href="assets/css/vts-admin.css?v=<?php echo @filemtime(__DIR__.'/assets/css/vts-admin.css'); ?>">
  <link rel="stylesheet" href="assets/css/vts-polish.css?v=<?php echo @filemtime(__DIR__.'/assets/css/vts-polish.css'); ?>">
  <link rel="stylesheet" href="assets/css/vts-minimal.css?v=<?php echo @filemtime(__DIR__.'/assets/css/vts-minimal.css'); ?>">
  <link rel="stylesheet" href="assets/css/student-search.css?v=<?php echo @filemtime(__DIR__.'/assets/css/student-search.css'); ?>">
  <link rel="stylesheet" href="assets/css/vts-authbar.css?v=<?php echo @filemtime(__DIR__."/assets/css/vts-authbar.css"); ?>">
  <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
  <!-- Same form-validation presentation as the rest of the app: every
       problem marked at once, in the app's own words, under the field. -->
  <link rel="stylesheet" href="assets/css/vts-forms.css?v=<?php echo @filemtime(__DIR__."/assets/css/vts-forms.css"); ?>">
  <!-- Design system: tokens, chrome and components. Loaded LAST so it
       settles anything the older stylesheets disagree about. -->
  <link rel="stylesheet" href="assets/css/vts-design.css?v=<?php echo @filemtime(__DIR__."/assets/css/vts-design.css"); ?>">
  <!-- The phone's way into the staff login. Self-contained, so it looks the
       same here and on the welcome page, which loads none of the above. -->
  <link rel="stylesheet" href="assets/css/staff-key.css?v=<?php echo @filemtime(__DIR__."/assets/css/staff-key.css"); ?>">
</head>
<body>
<?php include __DIR__ . '/includes/bfcache_guard.php'; ?>
<div class="auth-page">
  <div class="auth-header">
    <?php /* Seal, then who it belongs to. The full system name used to sit
             here again in a bordered pill — the page already says what it is,
             and the register page said the same words a third way. */ ?>
    <?php /* The seal doubles as the hidden staff door on a phone — five taps
             or a long press opens the access-key pad (assets/js/staff-key.js).
             Nothing about it looks tappable, which is the point: Ctrl+Shift+S
             does not exist on a touchscreen, and a mobile keyboard mangles the
             code before the search box below ever sees it. */ ?>
    <div class="auth-logo-ring" data-staff-key-trigger>
      <img src="assets/images/logo-sm.jpg" alt="Golden West Colleges seal" class="auth-logo">
    </div>
    <p class="auth-wordmark">Golden West Colleges, Inc.</p>
  </div>

  <div class="auth-card">
    <!-- Hidden while the confirm step is open: that step has its own, better-worded
         back control ("Not you? Search again"), and two stacked chevron-left
         links read as the same button twice even though this one leaves the page
         and that one steps back to the search. -->
    <a href="index.php" class="slp-back" id="slpBackLink"><i class="fas fa-chevron-left"></i> Back</a>
    <h1 class="auth-heading">Find your student record</h1>
    <p class="auth-sub">Search the enrolled list with your last name or School ID.</p>

    <?php if ($bannerStudent): ?>
      <div class="alert alert-error"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($bannerStudent); ?></div>
    <?php endif; ?>

    <?php /* ONE step. This used to be two: search your name, pick yourself
             from a list, and then type your School ID on a second screen —
             which asked you to identify yourself twice.

             The field takes either now. Type a name and matching students are
             suggested as you go; type your School ID and there is nothing to
             suggest, so just carry on to the password.

             NOTE ON THE SUGGESTIONS: they are names only, never School IDs.
             auth/student_lookup_search.php is public (it runs before anyone
             has signed in), and returning IDs there turned a photographed ID
             card into a working login. A typed School ID is resolved by the
             SERVER on submit instead. */ ?>
    <div class="vts-form-group slp-search-wrap" id="slpIdentityWrap">
      <label class="auth-label" for="slpIdentity">School ID or name</label>
      <div class="slp-search-box" id="slpIdentityBox">
        <i class="fas fa-magnifying-glass slp-search-ic" aria-hidden="true"></i>
        <?php /* autocorrect/autocapitalize/spellcheck off is not tidiness: a
                 phone keyboard capitalises the first letter of a field like
                 this and swaps an unknown word for a real one, so neither a
                 surname it does not recognise nor the staff code below ever
                 arrived as typed. */ ?>
        <input type="text" id="slpIdentity" name="identity" form="slpForm" class="vts-input"
               value="<?php echo htmlspecialchars($handoff, ENT_QUOTES, 'UTF-8'); ?>"
               placeholder="Enter ID or name" autocomplete="off" autofocus required
               autocorrect="off" autocapitalize="off" spellcheck="false"
               aria-describedby="slpConfirmErr" aria-autocomplete="list"
               aria-expanded="false" role="combobox" aria-controls="slpResults">
        <i class="fas fa-circle-check slp-ok" aria-hidden="true"></i>
      </div>
      <div class="slp-results" id="slpResults" role="listbox"></div>
      <?php if (!$openStaff && $errField === 'identity' && $fieldErr): ?>
        <p class="slp-field-err" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i> <?php echo htmlspecialchars($fieldErr); ?></p>
      <?php endif; ?>
      <span class="slp-picked" id="slpPicked" hidden>
        <span class="live" aria-hidden="true"></span>
        <span id="slpPickName"></span>
        <button type="button" class="slp-picked-x" id="slpPickClear" aria-label="Choose someone else">
          <i class="fas fa-xmark" aria-hidden="true"></i>
        </button>
      </span>
    </div>

    <label class="auth-label slp-label-spaced" for="slpConfirmPw">Password</label>
    <div class="slp-search-box password-wrap">
      <i class="fas fa-lock slp-search-ic" aria-hidden="true"></i>
      <input type="password" id="slpConfirmPw" name="password" form="slpForm" class="vts-input"
             placeholder="Your password" autocomplete="current-password" maxlength="72"
             aria-describedby="slpPwHint">
      <button type="button" class="eye" aria-label="Show password" title="Show password" tabindex="-1">
        <i class="fas fa-eye" aria-hidden="true"></i>
    </button>
    </div>

    <?php if (!$openStaff && $errField === 'password' && $fieldErr): ?>
      <p class="slp-field-err" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i> <?php echo htmlspecialchars($fieldErr); ?></p>
    <?php endif; ?>
    <div class="slp-confirm-err" id="slpConfirmErr" role="alert"></div>

    <button type="submit" form="slpForm" class="btn-primary btn-full" id="slpConfirmGo">
      Continue <i class="fas fa-arrow-right" aria-hidden="true"></i>
    </button>

    <?php /* The same reset the staff modal offers. It was only ever linked
             there, so a student who had set a password and forgotten it had
             no way back in at all. The page takes a School ID as well as an
             email now, because that is what a student knows. */ ?>
    <div class="auth-forgot-link slp-forgot">
      <a href="forgot_password.php">Forgot password?</a>
    </div>

    <div id="slpOpening"><i class="fas fa-circle-notch fa-spin"></i> Opening your dashboard…</div>

    <form action="auth/student_lookup_process.php" method="POST" id="slpForm" autocomplete="off" class="u-hidden">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="lastname"  id="slpLastname" value="">
      <input type="hidden" name="school_id" id="slpSchoolId" value="">
      <input type="hidden" name="name_hint" id="slpNameHint" value="">
    </form>

    <div class="slp-foot">
      <div class="q">Not on the list?</div>
      <a href="register.php" class="slp-register-btn"><i class="fas fa-user-plus"></i> Register</a>
    </div>

    <?php
    /* The connection line states what is ACTUALLY true of this request.
       A fixed "256-bit SSL" badge is the kind of thing that keeps saying
       "secure" on a page served over plain http — which is precisely the
       page where a student should not be reassured. Live, behind the
       .htaccess https redirect, this reads as encrypted; on a classroom
       XAMPP over http it says so instead. */
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    ?>
    <div class="auth-trust">
      <?php if ($isSecure): ?>
        <i class="fas fa-shield-halved" aria-hidden="true"></i> Encrypted connection
      <?php else: ?>

      <?php endif; ?>
    </div>
  </div>

  <?php
  /* A blank URL renders as plain text rather than a link to nowhere.
     Set them in config/app.php once the real pages exist. */
  $legal = [
      'Privacy Policy'           => defined('LINK_PRIVACY')  ? LINK_PRIVACY  : '',
      'Student Handbook'         => defined('LINK_HANDBOOK') ? LINK_HANDBOOK : '',
      'Campus Security Helpdesk' => defined('LINK_HELPDESK') ? LINK_HELPDESK : '',
  ];
  ?>
  <div class="auth-legal">
    <div class="links">
      <?php $first = true; foreach ($legal as $label => $href): ?>
        <?php if (!$first): ?><span class="sep" aria-hidden="true">&bull;</span><?php endif; $first = false; ?>
        <?php if ($href !== ''): ?>
          <a href="<?php echo htmlspecialchars($href); ?>"><?php echo htmlspecialchars($label); ?></a>
        <?php else: ?>
          <span class="flat"><?php echo htmlspecialchars($label); ?></span>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <div class="copy">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars(APP_SCHOOL); ?> All rights reserved.</div>
  </div>
</div>

<!-- STAFF ROLE MODAL (hidden — opens via Ctrl+Shift+S, typing "gwcstaff",
     or the access-key pad the seal opens on a phone) -->
<div class="modal-overlay <?php echo $openStaff ? 'open' : ''; ?>" id="roleModal">
  <div class="role-modal">

    <?php if ($success): ?>
      <div class="alert alert-success u-mb-14"><i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <div class="alert alert-error auth-alert-staff" id="staffErrorBox" style="<?php echo $bannerStaff ? '' : 'display:none;'; ?>">
      <i class="fas fa-circle-exclamation"></i> <span id="staffErrorText"><?php echo htmlspecialchars($bannerStaff); ?></span>
    </div>

    <div id="roleStep1" <?php if ($openStaff && $retRole) echo 'style="display:none;"'; ?>>
      <h3>Staff Login</h3>
      <p>Select your role to continue — highest position on top.</p>

      <!-- Laid out as a pyramid: one tile at the top for the highest position,
           then two, then two at the base. -->
      <div class="role-pyramid">
        <div class="role-tier" data-tier="1">
          <div class="role-option" onclick="pickRole('Admin')">
            <i class="fas fa-user-gear"></i><span>Admin</span><small>System Administrator</small>
          </div>
        </div>
        <div class="role-tier" data-tier="2">
          <div class="role-option" onclick="pickRole('OSA')">
            <i class="fas fa-building-user"></i><span>OSA</span><small>Office of Student Affairs</small>
          </div>
        </div>
        <div class="role-tier" data-tier="3">
          <div class="role-option" onclick="pickRole('OSA Staff')">
            <i class="fas fa-user-group"></i><span>OSA Staff</span><small>Add / edit / view only</small>
          </div>
          <div class="role-option" onclick="location.href='spck_scanner.html'"> 
            <i class="fas fa-shield-halved"></i><span>Guard / Marshal</span><small>Opens the Scanner App</small>
          </div>
        </div>
      </div>
      <button class="modal-close" onclick="closeRoleModal()">Cancel</button>
    </div>

    <!-- Step 2: staff credentials (server pre-opens this after a failed attempt) -->
    <div id="roleStep2" style="display:<?php echo ($openStaff && $retRole) ? 'block' : 'none'; ?>;">
      <button class="back-to-roles" onclick="backToRoles()"><i class="fas fa-chevron-left"></i> Change role</button>
      <h3 id="staffRoleTitle"><?php echo $retRole ? htmlspecialchars($roleTitles[$retRole][0]) : 'Login'; ?></h3>
      <p id="staffRoleSub"><?php echo $retRole ? htmlspecialchars($roleTitles[$retRole][1]) : 'Enter your credentials.'; ?></p>

      <form action="auth/login_process.php" method="POST" class="staff-login-form" id="staffLoginForm">
        <input type="hidden" name="login_type" value="staff">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="role" id="staffRoleInput" value="<?php echo htmlspecialchars($retRole); ?>">
        <div class="vts-form-group">
          <label for="username" class="auth-label">Username</label>
          <input id="username" type="text" name="username" class="vts-input" placeholder="Enter your username" required
                 autocapitalize="off" autocorrect="off" spellcheck="false"
                 aria-describedby="staffUserErr"
                 value="<?php echo $openStaff ? htmlspecialchars($retUser) : ''; ?>" data-allow="alnum" pattern="[A-Za-z0-9._\-]{3,40}" title="Letters, numbers, dots, underscores and hyphens only." maxlength="40">
          <p class="slp-field-err" id="staffUserErr" role="alert"
             <?php echo ($openStaff && $errField === 'username' && $fieldErr) ? '' : 'hidden'; ?>>
            <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
            <span><?php echo htmlspecialchars(($openStaff && $errField === 'username') ? $fieldErr : ''); ?></span>
          </p>
        </div>
        <div class="vts-form-group">
          <label for="staffPwd" class="auth-label">Password</label>
          <div class="pwd-wrap">
            <input type="password" autocomplete="current-password" name="password" id="staffPwd" class="vts-input" placeholder="Enter your password" required
                   aria-describedby="staffPwdErr">
            <button type="button" class="eye-toggle" aria-label="Show password" title="Show password"><i class="fas fa-eye" aria-hidden="true"></i></button>
          </div>
          <p class="slp-field-err" id="staffPwdErr" role="alert"
             <?php echo ($openStaff && $errField === 'password' && $fieldErr) ? '' : 'hidden'; ?>>
            <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
            <span><?php echo htmlspecialchars(($openStaff && $errField === 'password') ? $fieldErr : ''); ?></span>
          </p>
        </div>
        <div class="auth-forgot-link">
          <a href="forgot_password.php">Forgot password?</a>
        </div>
        <button type="submit" class="btn-primary btn-full auth-submit staff" id="staffSubmitBtn">LOGIN</button>
      </form>
    </div>

  </div>
</div>

<script>
/* ===================== STAFF LOGIN MODAL ===================== */
function openRoleModal() {
  document.getElementById('roleModal').classList.add('open');
  backToRoles();
}
/* assets/js/staff-key.js calls this once the access key checks out. It is a
   plain function declaration, so it is already on window in a browser — named
   here so it is obvious the pad depends on it. */
window.openRoleModal = openRoleModal;

/* ---- THE STAFF FLAG MUST NOT SURVIVE A REFRESH ----

   ?staff=1 is what tells this page to open the staff picker, and several
   flows arrive carrying it: a failed staff sign-in bounces back with it, and
   so did the "Back to login" links. It then SAT IN THE ADDRESS BAR — so every
   refresh re-opened the staff card, which looked like Ctrl+Shift+R was
   triggering the Ctrl+Shift+S shortcut. It was not the keyboard; it was the
   URL still asking for it.

   The flag has done its job by the time the page has rendered, so it is wiped
   from the address bar here along with the other one-shot bits (the sticky
   username, role and error). The modal that is already open stays open; a
   reload now shows the ordinary student card. */
(function stripOneShotParams(){
  if (!window.history || !history.replaceState) return;
  try {
    var url = new URL(window.location.href);
    /* Only the flags that decide WHICH CARD opens. error/success are left
       alone: the toast in vts-ui.js reads them from the URL on
       DOMContentLoaded and cleans them up itself, and this script runs during
       parsing — stripping them here would swallow the message before it was
       ever shown. */
    var drop = ['staff', 'u', 'role'];
    var had = drop.some(function(k){ return url.searchParams.has(k); });
    if (!had) return;
    drop.forEach(function(k){ url.searchParams.delete(k); });
    history.replaceState(null, '', url.pathname + (url.search || '') + url.hash);
  } catch (e) { /* older browser: leave the URL alone rather than break the page */ }
})();
function closeRoleModal() {
  document.getElementById('roleModal').classList.remove('open');
}
function pickRole(role) {
  document.getElementById('staffRoleInput').value = role;
  const titles = {
    'Admin': ['Admin Login', 'System Administrator access.'],
    'OSA':   ['OSA Login', 'Office of Student Affairs — full access.'],
    'OSA Staff': ['OSA Staff Login', 'Office of Student Affairs — add/edit/view only.'],
    'Guard': ['Guard/Marshal Login', 'Campus security — scan + report to OSA/Admin.'],
  };
  document.getElementById('staffRoleTitle').textContent = titles[role][0];
  document.getElementById('staffRoleSub').textContent = titles[role][1];
  document.getElementById('roleStep1').style.display = 'none';
  document.getElementById('roleStep2').style.display = 'block';
  clearStaffError();
}
function backToRoles() {
  document.getElementById('roleStep1').style.display = 'block';
  document.getElementById('roleStep2').style.display = 'none';
  clearStaffError();
}
/* The server says WHICH box is wrong ('username' or 'password'), so the
   message goes under that box and the cursor goes into it. Only a fault that
   belongs to neither - a lockout, an expired session, a wrong role - falls
   back to the banner over the whole form. */
function showStaffError(msg, field) {
  clearStaffError();
  var slot = field === 'username' ? document.getElementById('staffUserErr')
           : field === 'password' ? document.getElementById('staffPwdErr')
           : null;
  if (slot) {
    slot.querySelector('span').textContent = msg;
    slot.hidden = false;
    var input = document.getElementById(field === 'username' ? 'username' : 'staffPwd');
    if (input) {
      input.classList.add('is-invalid');
      input.focus();
      if (field === 'password') input.select();
    }
    return;
  }
  document.getElementById('staffErrorText').textContent = msg;
  document.getElementById('staffErrorBox').style.display = 'flex';
}
function clearStaffError() {
  document.getElementById('staffErrorBox').style.display = 'none';
  ['staffUserErr', 'staffPwdErr'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.hidden = true;
  });
  ['username', 'staffPwd'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.classList.remove('is-invalid');
  });
}
/* Typing is the user answering the complaint - clear it as they do. */
['username', 'staffPwd'].forEach(function (id) {
  var el = document.getElementById(id);
  if (el) el.addEventListener('input', function () {
    el.classList.remove('is-invalid');
    var slot = document.getElementById(id === 'username' ? 'staffUserErr' : 'staffPwdErr');
    if (slot) slot.hidden = true;
  });
});

/* Staff login submits in place (fetch) so errors show inside the modal;
   only a real success or a required next step (e.g. verify email) navigates. */
(function () {
  const form = document.getElementById('staffLoginForm');
  const btn  = document.getElementById('staffSubmitBtn');
  if (!form) return;
  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    clearStaffError();
    btn.disabled = true;
    const originalLabel = btn.textContent;
    btn.textContent = 'Signing in…';
    try {
      const res  = await fetch(form.action, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(form),
      });
      const data = await res.json();
      if (data.redirect) { window.location.href = data.redirect; return; }
      showStaffError(data.error || 'Something went wrong. Please try again.', data.field);
    } catch (err) {
      showStaffError('Could not reach the server. Check your connection and try again.', '');
    }
    btn.disabled = false;
    btn.textContent = originalLabel;
  });
})();

// Close the modal by clicking the dark backdrop.
document.getElementById('roleModal').addEventListener('click', function (e) {
  if (e.target === this) closeRoleModal();
});

/* ===================== STUDENT SELF-LOOKUP =====================
   ONE field, not two screens.

   It used to be: search your surname, pick yourself from a list, then type
   your School ID on a second screen. That asked you to identify yourself
   twice — once loosely, once exactly — and the first answer was thrown away.

   Now the single field takes either. Typing a name suggests matching
   students as you go; picking one fills the field with that name. Typing a
   School ID suggests nothing (see below) and simply goes through.

   WHY A TYPED ID GETS NO SUGGESTIONS. auth/student_lookup_search.php is
   reachable before anyone has signed in, so everything it returns is public.
   It answers on names only and never returns a School ID — when it did, a
   photographed ID card could be pasted in to get the matching name, which
   together were the entire login. A typed School ID is resolved by the
   SERVER on submit instead, where the password (or the name) is checked with
   it. Nothing here decides who anyone is.
   ================================================================ */
(function () {
  const idEl      = document.getElementById('slpIdentity');
  const idBox     = document.getElementById('slpIdentityBox');
  const resultsEl = document.getElementById('slpResults');
  const pickedEl  = document.getElementById('slpPicked');
  const pickName  = document.getElementById('slpPickName');
  const pickClear = document.getElementById('slpPickClear');
  const confErr   = document.getElementById('slpConfirmErr');
  const form      = document.getElementById('slpForm');
  const lastEl    = document.getElementById('slpLastname');
  const sidEl     = document.getElementById('slpSchoolId');
  const opening   = document.getElementById('slpOpening');
  if (!idEl || !form) return;

  const SECRET_CODE = 'gwcstaff';          // hidden staff shortcut
  let timer = null, secretBuffer = '', searchSeq = 0;
  let pickedLast = '', pickedName = '';    // set only by choosing a suggestion

  function esc(s){ return (s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
  function lastNameOf(name){ const p = (name||'').trim().split(/\s+/); return p.length ? p[p.length-1] : ''; }

  /* A School ID here is digits — if it looks like one, there is nothing to
     search for, because the suggestion endpoint answers on names only. */
  function looksLikeId(v){ return /^[0-9][0-9-]*$/.test(v.trim()); }

  function closeList(){
    resultsEl.classList.remove('open');
    idEl.setAttribute('aria-expanded', 'false');
  }
  function openList(){
    resultsEl.classList.add('open');
    idEl.setAttribute('aria-expanded', 'true');
  }

  function clearPick(){
    pickedLast = ''; pickedName = '';
    if (pickedEl) pickedEl.hidden = true;
  }

  function render(list){
    if (!list.length){
      /* Deliberately not "no such student". Suggestions are a shortcut, not a
         gate: an ID for an account without a password is not suggested, and
         nothing typed here is verified until submit. Saying "not found" would
         be both discouraging and, half the time, wrong. */
      resultsEl.innerHTML = '<div class="slp-empty">No suggestions — carry on and press Continue. '
        + '<a href="register.php" class="slp-empty-link">New student? Register &rarr;</a></div>';
      openList(); return;
    }
    resultsEl.innerHTML = list.map(s => {
      const meta = [s.course, s.year].filter(Boolean).join(' · ');
      return '<div class="slp-item" role="option" tabindex="-1"'
           + ' data-name="'+esc(s.name)+'" data-last="'+esc(s.lastname||'')+'">'
           + '<span class="av"><i class="fas fa-user-graduate" aria-hidden="true"></i></span>'
           + '<span class="tx"><span class="nm">'+esc(s.name)+'</span>'
           + '<span class="meta">'+ (meta ? esc(meta) : 'Enrolled student') +'</span></span></div>';
    }).join('');
    openList();
    resultsEl.querySelectorAll('.slp-item').forEach(el => {
      el.addEventListener('click', () => pick(el.dataset.name, el.dataset.last));
    });
  }

  function busy(on){
    idEl.setAttribute('aria-busy', on ? 'true' : 'false');
    if (!on) return;
    resultsEl.innerHTML = '<div class="slp-empty"><span class="vts-spinner" aria-hidden="true"></span> Searching…</div>';
    openList();
  }

  function doSearch(q){
    const mine = ++searchSeq;              // ignore replies from superseded keystrokes
    busy(true);
    fetch('auth/student_lookup_search.php?q=' + encodeURIComponent(q))
      .then(r => r.json())
      .then(list => { if (mine !== searchSeq) return; busy(false); render(list); })
      .catch(() => {
        if (mine !== searchSeq) return;
        busy(false);
        resultsEl.innerHTML = '<div class="slp-empty">Could not search just now — check your connection and try again.</div>';
        openList();
      });
  }

  /* Choosing a suggestion fills the field with that name and remembers the
     surname, which is what the server checks for an account that has no
     password yet. It does NOT sign anyone in. */
  function pick(name, lastname){
    pickedName = name || '';
    pickedLast = (lastname && lastname.trim()) ? lastname.trim() : lastNameOf(name);
    idEl.value = pickedName;
    if (pickedEl && pickName){ pickName.textContent = pickedName; pickedEl.hidden = false; }
    closeList();
    confErr.classList.remove('show');
    paintTick();
    const pw = document.getElementById('slpConfirmPw');
    if (pw) pw.focus();                    // straight to the only thing left
  }

  /* The tick means the field is filled, NOT that the value is correct — only
     the server knows that, and it never says so before sign-in. */
  function paintTick(){
    if (idBox) idBox.classList.toggle('is-ok', idEl.value.trim() !== '');
  }

  idEl.addEventListener('input', function(){
    const q = this.value.trim();
    confErr.classList.remove('show');
    paintTick();

    // Typing again after choosing someone means they are no longer chosen.
    if (pickedName && this.value !== pickedName) clearPick();

    /* Spaces stripped as well as case dropped: a phone keyboard that split
       the code or left a trailing space still counts. The pad on the seal
       (assets/js/staff-key.js) is the reliable route on a touchscreen —
       this stays for anyone already in the habit of typing it here. */
    if (q.toLowerCase().replace(/\s+/g, '') === SECRET_CODE){   // hidden staff shortcut
      this.value = ''; closeList(); paintTick(); openRoleModal(); return;
    }
    clearTimeout(timer);
    /* Both a name and a School ID are searched. An ID starts suggesting from
       the FIRST digit; a name needs three characters — the server applies the
       same two floors, and the note in auth/student_lookup_search.php explains
       why they differ. If nothing comes back that is not a rejection: what was
       typed still goes through on submit and is checked against the database
       there. */
    const min = looksLikeId(q) ? 1 : 3;
    if (q.length < min){ searchSeq++; busy(false); closeList(); return; }
    timer = setTimeout(() => doSearch(q), 180);
  });

  if (pickClear) pickClear.addEventListener('click', function(){
    clearPick(); idEl.value = ''; paintTick(); idEl.focus();
  });

  /* A term handed over from the landing page is already in the field. Fire the
     SAME handler a keystroke fires rather than repeating what it does — so the
     suggestions open and the student can pick themselves straight away, which
     is the whole point of having typed it on the previous page. The caret goes
     to the end so an edit appends instead of overwriting. */
  if (idEl.value.trim() !== ''){
    paintTick();
    try { idEl.setSelectionRange(idEl.value.length, idEl.value.length); } catch (e) {}
    idEl.dispatchEvent(new Event('input', { bubbles: true }));
  }

  document.addEventListener('click', function(e){
    if (!resultsEl.contains(e.target) && e.target !== idEl) closeList();
  });

  /* Submit: split what was typed into the two fields the server expects.
     A value that looks like a School ID goes in as one; anything else is a
     name, and travels with the surname from the suggestion that was picked. */
  form.addEventListener('submit', function(e){
    const v = idEl.value.trim();
    if (v === ''){
      e.preventDefault();
      confErr.textContent = 'Enter your School ID or your name.';
      confErr.classList.add('show'); idEl.focus();
      return;
    }
    if (looksLikeId(v)){
      sidEl.value  = v.toUpperCase();
      lastEl.value = pickedLast;
    } else {
      sidEl.value  = '';                    // the server resolves by name
      lastEl.value = pickedLast || lastNameOf(v);
      form.elements['name_hint'] && (form.elements['name_hint'].value = v);
    }
    closeList();
    opening.style.display = 'block';        // "Opening your dashboard…"
  });

  /* Hidden staff access: Ctrl+Shift+S, or type "gwcstaff" outside a text box. */
  document.addEventListener('keydown', function(e){
    if (e.ctrlKey && e.shiftKey && (e.key === 'S' || e.key === 's')){ e.preventDefault(); openRoleModal(); return; }
    if (document.getElementById('roleModal').classList.contains('open') && e.key === 'Escape'){ closeRoleModal(); return; }
    const tag = (e.target.tagName || '').toLowerCase();
    if (tag === 'input' || tag === 'textarea') return;
    /* A held modifier means this is a BROWSER shortcut, not typing —
       Ctrl+Shift+R, Ctrl+R, Ctrl+F and the rest were all being fed into
       the buffer a letter at a time. It could never spell the code, but a
       reload has no business touching a hidden-access buffer at all. */
    if (e.ctrlKey || e.altKey || e.metaKey) return;
    if (e.key && e.key.length === 1){
      secretBuffer = (secretBuffer + e.key.toLowerCase()).slice(-SECRET_CODE.length);
      if (secretBuffer === SECRET_CODE){ secretBuffer = ''; openRoleModal(); }
    }
  });
})();
</script>
<!-- Menus + form validation, the same file the rest of the app loads. -->
<script src="assets/js/vts-ui.js?v=<?php echo @filemtime(__DIR__."/assets/js/vts-ui.js"); ?>" defer></script>
<!-- Staff access key. No data-target: the staff modal is on THIS page, so a
     correct key opens it here rather than navigating anywhere. -->
<script src="assets/js/staff-key.js?v=<?php echo @filemtime(__DIR__."/assets/js/staff-key.js"); ?>"
        data-key="gwcstaff" defer></script>
</body>
</html>
