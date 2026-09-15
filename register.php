<?php
/* Student self-registration form.
   Collects identity + contact details AND the password the student will sign
   in with (the username is generated from their School ID). Required fields:
   Last Name, First Name, Middle Name (optional), Suffix (optional), School ID,
   Email, Contact Number, Gender, Course, Year Level, Password + Confirm.
   College is derived from the chosen course.

   NOTE: registration used to be passwordless — a throwaway random hash was
   written and students signed in by looking themselves up. See
   database/upgrade_2026-09_student_passwords.sql for that transition. */
require_once __DIR__ . "/auth/session.php";   // hardened session start (strict mode, httponly/samesite cookie, fresh ID on first use) + security headers
require_once "config/database.php";
require_once "includes/functions.php";

// College + course pickers: the College choice filters the course list, and the
// course itself carries the college. Courses show their short code (BSIT, BSED…).
$colleges = $conn->query("SELECT id, college_name FROM colleges ORDER BY college_name")->fetchAll(PDO::FETCH_ASSOC);
$courses  = $conn->query(
    "SELECT c.id, c.course_name, c.short_name, c.college_id, col.college_name
       FROM courses c LEFT JOIN colleges col ON col.id = c.college_id
      ORDER BY col.college_name, c.short_name, c.course_name")->fetchAll(PDO::FETCH_ASSOC);

$error   = $_GET['error']   ?? '';
$success = $_GET['success'] ?? '';

// Re-fill values if redirected back after an error
$old = $_SESSION['reg_old'] ?? [];
unset($_SESSION['reg_old']);

// Field-specific server error (which field to flag + the message under it)
$regErr   = $_SESSION['reg_error'] ?? null;
unset($_SESSION['reg_error']);
$errField = $regErr['field'] ?? '';
$errMsg   = $regErr['msg']   ?? '';

function old($k, $old) { return htmlspecialchars($old[$k] ?? ''); }
// Emits the per-field error line (server-side). $name = form field name.
function fe($name, $errField, $errMsg, $fallback) {
    if ($errField === $name) {
        echo '<div class="field-error show">' . htmlspecialchars($errMsg) . '</div>';
    } else {
        echo '<div class="field-error">' . htmlspecialchars($fallback) . '</div>';
    }
}
function invalid($name, $errField) { return $errField === $name ? ' is-invalid' : ''; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Student Registration | QR Shield</title>
  <!-- Inter + Plus Jakarta Sans, served from this server (assets/vendor/fonts).
       NOT from fonts.googleapis.com: a stylesheet <link> is render-blocking, so
       with no internet every page sat blank until that request timed out. -->
  <link rel="stylesheet" href="assets/vendor/fonts/css/fonts.css">
  <link rel="stylesheet" href="assets/css/vts-theme.css?v=<?php echo @filemtime(__DIR__.'/assets/css/vts-theme.css'); ?>">
  <link rel="stylesheet" href="assets/css/vts-admin.css?v=<?php echo @filemtime(__DIR__.'/assets/css/vts-admin.css'); ?>">
  <link rel="stylesheet" href="assets/css/vts-polish.css?v=<?php echo @filemtime(__DIR__.'/assets/css/vts-polish.css'); ?>">
  <link rel="stylesheet" href="assets/css/vts-minimal.css?v=<?php echo @filemtime(__DIR__.'/assets/css/vts-minimal.css'); ?>">
  <link rel="stylesheet" href="assets/css/register.css?v=<?php echo @filemtime(__DIR__.'/assets/css/register.css'); ?>">
  <link rel="preload" href="assets/vendor/fontawesome/webfonts/fa-solid-900.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="assets/css/vts-authbar.css?v=<?php echo @filemtime(__DIR__."/assets/css/vts-authbar.css"); ?>">
  <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
  <!-- Same form-validation presentation as the rest of the app: every
       problem marked at once, in the app's own words, under the field. -->
  <link rel="stylesheet" href="assets/css/vts-forms.css?v=<?php echo @filemtime(__DIR__."/assets/css/vts-forms.css"); ?>">
  <!-- Design system: tokens, chrome and components. Loaded LAST so it
       settles anything the older stylesheets disagree about. -->
  <link rel="stylesheet" href="assets/css/vts-design.css?v=<?php echo @filemtime(__DIR__."/assets/css/vts-design.css"); ?>">
</head>
<body>
<?php include __DIR__ . '/includes/bfcache_guard.php'; ?>
<div class="register-page">

  <?php /* Seal, school name and page title sit ABOVE the card, exactly as they
           do on the login page — the crest belongs to the institution, not to
           this one form. They used to be inside the card, which made the two
           screens read as different designs. */ ?>
  <div class="auth-header">
    <?php /* The seal goes home. Register used to be a dead end: the only way
             on was to finish the form, and the only way out was the browser's
             own back button. */ ?>
    <a class="auth-logo-ring" href="index.php" title="Back to the QR Shield home page"
       aria-label="Back to the QR Shield home page" data-staff-key-trigger>
      <img src="assets/images/logo-sm.jpg" alt="Golden West Colleges seal" class="auth-logo">
    </a>
    <p class="auth-wordmark">Golden West Colleges, Inc.</p>
    <h1 class="auth-heading">Student Registration</h1>
    <p class="auth-sub">All fields marked * are required.</p>
  </div>

  <div class="register-card">

    <a href="student_search.php" class="slp-back-link"><i class="fas fa-chevron-left"></i> Back</a>

<?php if ($success): ?>
      <div class="alert alert-success u-mb-16"><i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-error u-mb-16"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form id="regForm" action="auth/register_process.php" method="POST" novalidate
      data-no-validate><?php /* This form has its own purpose-built
         validation further down (validateField + a summary box). The
         shared module would be a second system on the same fields, so
         it stands aside here. */ ?>
      <?php echo csrf_field(); ?>
      <fieldset class="vts-fieldset">
        <legend>Your name</legend>
        <p class="vts-fieldset-hint">As it appears on your school records.</p>
      <div class="form-grid">

        <div class="field">
          <label for="lastname">Last Name <span class="req">*</span></label>
          <input id="lastname" type="text" name="lastname" class="vts-input js-upper<?php echo invalid('lastname',$errField); ?>" data-required maxlength="60"
                 value="<?php echo old('lastname',$old); ?>" placeholder="e.g. DELA CRUZ" data-allow="letters" pattern="[A-Za-zÀ-ɏÑñ .'\-]{2,60}" title="Letters, spaces, hyphens, apostrophes and periods only.">
          <?php fe('lastname',$errField,$errMsg,'Last name is required.'); ?>
        </div>

        <div class="field">
          <label for="firstname">First Name <span class="req">*</span></label>
          <input id="firstname" type="text" name="firstname" class="vts-input js-upper<?php echo invalid('firstname',$errField); ?>" data-required maxlength="60"
                 value="<?php echo old('firstname',$old); ?>" placeholder="e.g. JUAN" data-allow="letters" pattern="[A-Za-zÀ-ɏÑñ .'\-]{2,60}" title="Letters, spaces, hyphens, apostrophes and periods only.">
          <?php fe('firstname',$errField,$errMsg,'First name is required.'); ?>
        </div>

        <div class="field">
          <label for="middlename">Middle Name</label>
          <input id="middlename" type="text" name="middlename" class="vts-input js-upper<?php echo invalid('middlename',$errField); ?>" maxlength="60"
                 value="<?php echo old('middlename',$old); ?>" placeholder="Optional" data-allow="letters" pattern="[A-Za-zÀ-ɏÑñ .'\-]{2,60}" title="Letters, spaces, hyphens, apostrophes and periods only.">
          <?php fe('middlename',$errField,$errMsg,'Leave blank if you have none.'); ?>
        </div>

        <div class="field">
          <label for="suffix">Suffix</label>
          <select id="suffix" name="suffix" class="vts-select">
            <option value="">Suffix (optional)</option>
            <?php foreach (['Jr.','Sr.','II','III','IV','V'] as $sf): ?>
              <option value="<?php echo $sf; ?>" <?php if (($old['suffix'] ?? '') === $sf) echo 'selected'; ?>><?php echo $sf; ?></option>
            <?php endforeach; ?>
          </select>
        </div>

      </div>
      </fieldset>

      <fieldset class="vts-fieldset">
        <legend>How we reach you</legend>
        <p class="vts-fieldset-hint">Your verification code goes to this email.</p>
      <div class="form-grid">

        <div class="field">
          <label for="student_id">School ID <span class="req">*</span></label>
          <input id="student_id" type="text" name="student_id" class="vts-input js-upper<?php echo invalid('student_id',$errField); ?>" data-required maxlength="10"
                 value="<?php echo old('student_id',$old); ?>" placeholder="e.g. 1242500410" data-allow="digits" pattern="[0-9]{10}" title="A School ID is exactly 10 digits." inputmode="numeric">
          <?php fe('student_id',$errField,$errMsg,'School ID is required — 10 digits.'); ?>
        </div>

        <div class="field">
          <label for="email">Email Address <span class="req">*</span></label>
          <input id="email" type="email" name="email" class="vts-input<?php echo invalid('email',$errField); ?>" data-required data-type="email"
                 value="<?php echo old('email',$old); ?>" placeholder="name@gmail.com">
          <?php fe('email',$errField,$errMsg,'A valid email address is required.'); ?>
        </div>

        <div class="field">
          <label for="contact_number">Contact Number <span class="req">*</span></label>
          <input id="contact_number" type="text" name="contact_number" class="vts-input<?php echo invalid('contact_number',$errField); ?>" data-required data-type="phone" maxlength="11"
                 value="<?php echo old('contact_number',$old); ?>" placeholder="09XXXXXXXXX">
          <?php fe('contact_number',$errField,$errMsg,'Enter a valid 11-digit contact number.'); ?>
        </div>

        <div class="field">
          <label for="gender">Gender <span class="req">*</span></label>
          <select id="gender" name="gender" class="vts-select<?php echo invalid('gender',$errField); ?>" data-required>
            <option value="">Select Gender</option>
            <?php foreach (['Male','Female','Other'] as $g): ?>
              <option value="<?php echo $g; ?>" <?php if (($old['gender'] ?? '') === $g) echo 'selected'; ?>><?php echo $g; ?></option>
            <?php endforeach; ?>
          </select>
          <?php fe('gender',$errField,$errMsg,'Please select a gender.'); ?>
        </div>

      </div>
      </fieldset>

      <fieldset class="vts-fieldset">
        <legend>Where you study</legend>
        <p class="vts-fieldset-hint">Pick your college first.</p>
      <div class="form-grid">

        <div class="field">
          <label for="collegeSelect">College <span class="req">*</span></label>
          <select id="collegeSelect" class="vts-select" data-required>
            <option value="">Select College</option>
            <?php foreach ($colleges as $col): ?>
              <option value="<?php echo (int)$col['id']; ?>" <?php if ((string)($old['college_filter'] ?? '') === (string)$col['id']) echo 'selected'; ?>><?php echo htmlspecialchars($col['college_name']); ?></option>
            <?php endforeach; ?>
          </select>
          <input type="hidden" name="college_filter" id="collegeFilterValue" value="<?php echo old('college_filter',$old); ?>">
          <div class="field-error">Please select a college.</div>
        </div>

        <div class="field">
          <label for="courseSelect">Course <span class="req">*</span></label>
          <select name="course_id" id="courseSelect" class="vts-select<?php echo invalid('course_id',$errField); ?>" data-required>
            <option value="">Select Course</option>
            <?php foreach ($courses as $c): ?>
              <option value="<?php echo (int)$c['id']; ?>" data-college="<?php echo (int)$c['college_id']; ?>"
                      title="<?php echo htmlspecialchars($c['course_name']); ?>"
                      <?php if ((string)($old['course_id'] ?? '') === (string)$c['id']) echo 'selected'; ?>>
                <?php echo htmlspecialchars($c['short_name'] !== '' ? $c['short_name'] : $c['course_name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php fe('course_id',$errField,$errMsg,'Please select a course.'); ?>
        </div>

        <div class="field">
          <label for="year_level">Year Level <span class="req">*</span></label>
          <select id="year_level" name="year_level" class="vts-select<?php echo invalid('year_level',$errField); ?>" data-required>
            <option value="">Select Year Level</option>
            <?php foreach (['1st Year','2nd Year','3rd Year','4th Year','5th Year'] as $yl): ?>
              <option <?php if (($old['year_level'] ?? '') === $yl) echo 'selected'; ?>><?php echo $yl; ?></option>
            <?php endforeach; ?>
          </select>
          <?php fe('year_level',$errField,$errMsg,'Please select a year level.'); ?>
        </div>

        <div class="field">
          <label for="section">Section</label>
          <input id="section" type="text" name="section" class="vts-input js-upper u-upper" maxlength="1" pattern="[A-Za-z]" inputmode="text"
                 title="Enter one capital letter from A to Z."
                 value="<?php echo old('section',$old); ?>" placeholder="Optional, e.g. A">
          <?php fe('section',$errField,$errMsg,'Enter one capital letter from A to Z, or leave blank.'); ?>
        </div>

      </div>
      </fieldset>

      <fieldset class="vts-fieldset">
        <legend>Your password</legend>
        <p class="vts-fieldset-hint">You'll sign in with your School ID and this password.</p>
      <div class="form-grid">

        <div class="field">
          <label for="password">Password <span class="req">*</span></label>
          <div class="password-wrap">
            <input id="password" type="password" name="password" class="vts-input<?php echo invalid('password',$errField); ?>"
                   data-required autocomplete="new-password" minlength="8" maxlength="72"
                   aria-describedby="pwRules" placeholder="At least 8 characters">
            <button type="button" class="eye" aria-label="Show password" title="Show password" tabindex="-1">
              <i class="fas fa-eye" aria-hidden="true"></i>
            </button>
          </div>
          <?php fe('password',$errField,$errMsg,'A password is required.'); ?>

          <?php /* The rules are listed and ticked off as they are met, rather
                   than revealed one at a time after each rejected submit. Each
                   line states what is required; the tick states whether it is
                   there yet. aria-live so it is not a purely visual signal. */ ?>
          <ul id="pwRules" class="pw-rules" aria-live="polite">
            <li data-rule="len"><i class="fas fa-circle" aria-hidden="true"></i> At least 8 characters</li>
            <li data-rule="upper"><i class="fas fa-circle" aria-hidden="true"></i> One capital letter (A–Z)</li>
            <li data-rule="lower"><i class="fas fa-circle" aria-hidden="true"></i> One small letter (a–z)</li>
            <li data-rule="digit"><i class="fas fa-circle" aria-hidden="true"></i> One number (0–9)</li>
            <li data-rule="special"><i class="fas fa-circle" aria-hidden="true"></i> One special character (!&nbsp;?&nbsp;#&nbsp;&amp;&nbsp;…)</li>
          </ul>
        </div>

        <div class="field">
          <label for="confirm_password">Confirm Password <span class="req">*</span></label>
          <div class="password-wrap">
            <input id="confirm_password" type="password" name="confirm_password" class="vts-input<?php echo invalid('confirm_password',$errField); ?>"
                   data-required autocomplete="new-password" maxlength="72" placeholder="Type it again">
            <button type="button" class="eye" aria-label="Show password" title="Show password" tabindex="-1">
              <i class="fas fa-eye" aria-hidden="true"></i>
            </button>
          </div>
          <?php fe('confirm_password',$errField,$errMsg,'Type the same password again.'); ?>
        </div>

      </div>
      </fieldset>

      <!-- Summary sits at the BOTTOM, right above the Register button -->
      <div id="formSummary" class="alert alert-error" style="<?php echo ($errMsg? 'display:flex;':'display:none;'); ?>margin-top:14px;">
        <i class="fas fa-circle-exclamation"></i> <span id="formSummaryText"><?php echo $errMsg ? htmlspecialchars($errMsg) : 'Please fix the highlighted fields below.'; ?></span>
      </div>

      <button type="submit" class="register-submit">Register Account</button>

      <p class="auth-alt">
        Already have an account? <a href="student_search.php">Log in</a>
      </p>

    </form>

  </div>
</div>

<script>
// ---- Auto-UPPERCASE while typing (name + School ID) ----
document.addEventListener('input', function(e) {
  const el = e.target;
  if (!el.classList || !el.classList.contains('js-upper')) return;
  const s = el.selectionStart, en = el.selectionEnd;
  const up = el.value.toUpperCase();
  if (up !== el.value) {
    el.value = up;
    try { el.setSelectionRange(s, en); } catch (err) {}
  }
});

// ---- College narrows the course list (the course carries its own college) ----
(function(){
  var col = document.getElementById('collegeSelect'),
      crs = document.getElementById('courseSelect'),
      keep = document.getElementById('collegeFilterValue');
  if (!col || !crs) return;
  function paint(){
    var want = col.value;
    keep.value = want;
    crs.disabled = !want;
    Array.prototype.forEach.call(crs.options, function(o){
      if (!o.value) return;
      var show = !want || o.getAttribute('data-college') === want;
      o.hidden = !show; o.disabled = !show;
    });
    if (crs.selectedOptions[0] && crs.selectedOptions[0].disabled) crs.value = '';
  }
  col.addEventListener('change', paint); paint();
})();

// ---- Per-field messages ----
function messageFor(el) {
  const val = (el.value || '').trim();
  const type = el.getAttribute('data-type');
  const required = el.hasAttribute('data-required');
  if (required && val === '') return 'This field is required.';
  if (type === 'email'  && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) return 'Enter a valid email (e.g. name@gmail.com).';
  if (type === 'phone'  && !/^[0-9]{11}$/.test(val)) return 'Contact number must be exactly 11 digits (e.g. 09171234567).';
  /* Each of these says WHICH rule was broken and, where it helps, what was
     typed instead. "Invalid input" tells the person nothing they can act on;
     "10 digits - you typed 8" tells them exactly what to do next. The server
     repeats every one of these, so this is the fast answer, not the decision. */
  if (el.name === 'student_id') {
    if (/[^0-9]/.test(val))  return 'A School ID is digits only — remove "' + val.replace(/[0-9]/g, '').charAt(0) + '".';
    if (val.length !== 10)   return 'A School ID is exactly 10 digits — you typed ' + val.length + '.';
  }
  if (el.name === 'lastname' || el.name === 'firstname' || el.name === 'middlename') {
    if (/[0-9]/.test(val))   return 'A name cannot contain numbers.';
    var bad = val.replace(/[\p{L} .'\-]/gu, '');
    if (bad)                 return 'A name cannot contain "' + bad.charAt(0) + '". Letters, spaces, hyphens, apostrophes and periods only.';
    if (val.length === 1)    return 'That looks too short — type the full name.';
  }

  /* Passwords. These five rules are the same five the SERVER enforces in
     password_policy_error() (includes/functions.php), worded the same way —
     if the two ever drift, this form starts accepting what the server then
     rejects, and the person is bounced with no idea why.

     This check is a convenience, not a control. Anything typed here can be
     edited away in the browser, so the server repeats every one of these
     rules on the way in and is the only thing that actually decides. */
  if (el.name === 'password' || el.name === 'confirm_password') {
    // NOT trimmed: a space is a legal password character, and silently
    // dropping it here would mean the password saved is not the one typed.
    const pw = el.value || '';
    if (pw === '') return 'This field is required.';

    if (el.name === 'password') {
      if (pw.length < 8)              return 'Password must be at least 8 characters — you have ' + pw.length + '.';
      if (!/[A-Z]/.test(pw))          return 'Password must contain at least one capital letter (A–Z).';
      if (!/[a-z]/.test(pw))          return 'Password must contain at least one small letter (a–z).';
      if (!/[0-9]/.test(pw))          return 'Password must contain at least one number (0–9).';
      if (!/[^A-Za-z0-9]/.test(pw))   return 'Password must contain at least one special character, such as ! ? # or &.';
      if (pw.length > 72)             return 'Password can be at most 72 characters.';
    } else {
      const first = document.getElementById('password');
      if (first && pw !== first.value) return 'This does not match the password above.';
    }
    return '';
  }
  return '';
}
function fieldIsValid(el) { return messageFor(el) === ''; }

function validateField(el) {
  const errBox = el.closest('.field') ? el.closest('.field').querySelector('.field-error') : null;
  const val = (el.value || '').trim();
  const required = el.hasAttribute('data-required');
  if (!required && val === '') {
    el.classList.remove('is-valid','is-invalid');
    if (errBox) errBox.classList.remove('show');
    return true;
  }
  const msg = messageFor(el);
  if (msg === '') {
    el.classList.add('is-valid'); el.classList.remove('is-invalid');
    if (errBox) errBox.classList.remove('show');
    return true;
  } else {
    el.classList.add('is-invalid'); el.classList.remove('is-valid');
    if (errBox) { errBox.textContent = msg; errBox.classList.add('show'); }
    return false;
  }
}

const fields = document.querySelectorAll('#regForm input, #regForm select');
fields.forEach(el => {
  const ev = (el.tagName === 'SELECT') ? 'change' : 'input';
  el.addEventListener(ev, () => { validateField(el); maybeHideSummary(); });
  el.addEventListener('blur', () => validateField(el));
});

const summaryBox  = document.getElementById('formSummary');
const summaryText = document.getElementById('formSummaryText');
function maybeHideSummary() {
  let allOk = true;
  fields.forEach(el => { if (!fieldIsValid(el) && !(!el.hasAttribute('data-required') && (el.value||'').trim()==='')) allOk = false; });
  if (allOk) summaryBox.style.display = 'none';
}

document.getElementById('regForm').addEventListener('submit', function(e) {
  let ok = true, firstBad = null, badCount = 0;
  fields.forEach(el => {
    const valid = validateField(el);
    if (!valid) { ok = false; badCount++; if (!firstBad) firstBad = el; }
  });
  if (!ok) {
    e.preventDefault();
    summaryText.textContent = badCount === 1
      ? 'Please fix the 1 highlighted field below before submitting.'
      : 'Please fix the ' + badCount + ' highlighted fields below before submitting.';
    summaryBox.style.display = 'flex';
    if (firstBad) { firstBad.focus(); firstBad.scrollIntoView({behavior:'smooth', block:'center'}); }
  }
});

/* ---- Password: keep the confirm box in step with the first ----
   The rules checklist and the show/hide eye are handled once, for every page
   that has them, in assets/js/vts-ui.js. Only the cross-field re-check is
   page-specific, because it drives THIS form's error boxes. */
(function(){
  var pw = document.getElementById('password'),
      confirm = document.getElementById('confirm_password');
  if (!pw || !confirm) return;
  // Fixing the first field can make the second right (or wrong) without it
  // being touched, so re-check it whenever either one changes.
  pw.addEventListener('input', function(){ if (confirm.value !== '') validateField(confirm); });
  confirm.addEventListener('input', function(){ validateField(confirm); });
})();

// If the server flagged a specific field, scroll to it so the user sees why.
<?php if ($errField): ?>
(function(){
  const bad = document.querySelector('[name="<?php echo $errField; ?>"]');
  if (bad) { bad.scrollIntoView({behavior:'smooth', block:'center'}); bad.focus(); }
})();
<?php endif; ?>
</script>
<!-- Menus + form validation, the same file the rest of the app loads. -->
<script src="assets/js/vts-ui.js?v=<?php echo @filemtime(__DIR__."/assets/js/vts-ui.js"); ?>" defer></script>
</body>
</html>
