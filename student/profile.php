<?php
/* Student: view and edit own profile. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";
vts_ensure_user_columns($conn);

if (($_SESSION['role'] ?? '') !== "Student") {
    vts_deny_access();
}

$userID  = $_SESSION['user_id'];
$success = $_GET['success'] ?? "";
$error   = "";

$stmt = $conn->prepare("SELECT * FROM users WHERE id=:id");
$stmt->execute([':id' => $userID]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {                                   // never a blank screen
    header("Location: ../logout.php");
    exit();
}

vts_ensure_password_flag($conn);
$hasPassword = vts_student_has_password($conn, $user);
$pwError     = "";        // kept apart from $error so each form reports its own

/* ---- The password form (its own POST, its own messages) ----

   Separate from the profile form on purpose: saving a new course should not
   be able to fail because of a password rule, and changing a password should
   not quietly rewrite the profile fields that happened to be on screen.

   Students who predate passwords have none to confirm, so they are not asked
   for one — for everyone else the current password is required, which is what
   stops someone who finds an unattended logged-in session from locking the
   owner out of their own account. */
if ($_SERVER['REQUEST_METHOD'] === "POST" && isset($_POST['password_form'])) {
    if (!csrf_verify()) {
        $pwError = "Your session expired — please try again.";
    } else {
        // Not trimmed: a space is a legal password character.
        $curPw = (string)($_POST['current_password'] ?? '');
        $newPw = (string)($_POST['new_password'] ?? '');
        $cfmPw = (string)($_POST['confirm_password'] ?? '');

        if ($hasPassword && $curPw === '') {
            $pwError = "Please enter your current password.";
        } elseif ($hasPassword && !password_verify($curPw, (string)$user['password'])) {
            $pwError = "That is not your current password.";
        } elseif ($newPw === '') {
            $pwError = "Please choose a new password.";
        } elseif (strlen($newPw) > 72) {
            $pwError = "Password can be at most 72 characters.";
        } elseif (($pwWhy = password_policy_error($newPw)) !== '') {
            // Says which rule failed, in the same words as the form's own hints.
            $pwError = $pwWhy;
        } elseif ($cfmPw === '') {
            $pwError = "Please type your new password a second time to confirm it.";
        } elseif (!hash_equals($newPw, $cfmPw)) {
            $pwError = "The two passwords do not match.";
        } elseif ($hasPassword && hash_equals($curPw, $newPw)) {
            $pwError = "Your new password must be different from your current one.";
        } else {
            try {
                $conn->prepare("UPDATE users SET password = :p WHERE id = :id")
                     ->execute([':p' => password_hash($newPw, PASSWORD_DEFAULT), ':id' => $userID]);
                // Flips has_password and clears the "Set your password" notice.
                vts_mark_password_set($conn, $userID);
                unset($_SESSION['needs_password']);
                audit_log($conn, "Password Set", "users", $userID,
                    $hasPassword ? "Student changed their own password" : "Student set their first password");
                header("Location: profile.php?success=" . urlencode(
                    $hasPassword ? "Your password has been changed."
                                 : "Your password is set — use it with your School ID from now on."));
                exit();
            } catch (Throwable $e) {
                error_log('Student password update failed: ' . $e->getMessage());
                $pwError = "Your password could not be saved. Please try again.";
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === "POST" && !isset($_POST['password_form'])) {
    if (!csrf_verify()) {
        $error = "Your session expired — please try saving again.";
    } else {
        // Formal formatting: names auto Title Case, email lowercased
        $fullname = name_case($_POST['fullname'] ?? '');
        $suffix   = trim($_POST['suffix'] ?? '');
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $course   = trim($_POST['course'] ?? '');
        $year     = trim($_POST['year_level'] ?? '');
        $section  = mb_strtoupper(trim($_POST['section'] ?? ''));   // students set their own Set
        $contact  = trim($_POST['contact_number'] ?? '');

        // Suffix is a controlled value; it is stored in its own column AND
        // appended to the display name so it always shows correctly.
        if (!in_array($suffix, ['', 'Jr.', 'Sr.', 'II', 'III', 'IV', 'V'], true)) $suffix = '';
        // Strip any suffix the student typed at the end of the name first,
        // so changing the dropdown never doubles it up ("Cruz Jr. Jr.").
        $fullname = trim(preg_replace('/\s+(Jr\.?|Sr\.?|II|III|IV|V)\.?$/i', '', $fullname));
        if ($suffix !== '') $fullname .= ' ' . $suffix;

        // ---- Field-specific validation (only the wrong field is flagged) ----
        $nameError = vts_validate_full_name($fullname);
        $emailError = vts_validate_email_address($email);
        $phoneError = vts_validate_phone_number($contact);

        if ($nameError !== '') {
            $error = $nameError;
        } elseif ($emailError !== '') {
            $error = $emailError;
        } elseif ($phoneError !== '') {
            $error = $phoneError;
        } else {
            // ---- Duplicate check: email must not belong to ANOTHER account ----
            $dup = $conn->prepare("SELECT id FROM users WHERE email = :e AND id != :id");
            $dup->execute([':e' => $email, ':id' => $userID]);
            if ($dup->fetch()) {
                $error = "The email \"{$email}\" is already used by another account.";
            }
        }

        // ---- Profile picture (validated, safe filename) ----
        // Type, size and filename are all decided by vts_accept_image_upload()
        // (includes/functions.php), which account.php calls too — the rules used
        // to live in both files separately.
        $picture = $user['profile_picture'];
        if ($error === "" && isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
            $up = vts_accept_image_upload($_FILES['profile_picture'], __DIR__ . "/../uploads/profile/");
            if ($up['ok']) $picture = $up['name'];
            else           $error   = $up['error'];
        }

        // ---- Remove current photo (honoured only when no replacement uploaded) ----
        if ($error === "" && !empty($_POST['remove_picture']) &&
            (!isset($_FILES['profile_picture']) || $_FILES['profile_picture']['error'] === UPLOAD_ERR_NO_FILE)) {
            if (!empty($user['profile_picture'])) {
                @unlink(__DIR__ . "/../uploads/profile/" . $user['profile_picture']);
            }
            $picture = null;
        }

        // NB: students are passwordless — they sign in by looking themselves up
        // and typing their own School ID, so there is no password to change here.

        // ---- Save everything in one transaction; refresh session name ----
        if ($error === "") {
          try {
            $conn->beginTransaction();
            $upd = $conn->prepare("UPDATE users
                         SET fullname=:fn, suffix=:sf, email=:em, course=:co, year_level=:yr,
                           section=:sec, contact_number=:cn, profile_picture=:pic
                         WHERE id=:id");
            $upd->execute([':fn'=>$fullname, ':sf'=>($suffix !== '' ? $suffix : null),
                     ':em'=>$email, ':co'=>$course, ':yr'=>$year,
                     ':sec'=>($section !== '' ? $section : null),
                     ':cn'=>($contact !== '' ? $contact : null), ':pic'=>$picture, ':id'=>$userID]);
            $conn->commit();
            $_SESSION['fullname']        = $fullname;
            $_SESSION['profile_picture'] = $picture;
            header("Location: profile.php?success=" . urlencode("Profile updated successfully."));
            exit();
          } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            error_log('Student profile update failed: ' . $e->getMessage());
            $error = "Your profile could not be saved. Please check your details and try again.";
          }
        }
    }
}

$assetBase = "../";
include "../includes/header.php";
include "../includes/navbar.php";
include "../includes/sidebar.php";

// QR — uses the unified helper (phpqrcode if installed, else qrserver web API)
require_once "../includes/qr_helper.php";
$qrPayload = $user['student_id'] . "|" . $user['fullname'] . "|" . $user['year_level'] . "|" . $user['course'];
$qrSrc  = get_student_qr($user['student_id'], "../", $qrPayload);   // web path or null
$qrFile = qr_file_path($user['student_id']);                        // disk path
$qrName = preg_replace('/[^A-Za-z0-9]+/', '', (string)$user['fullname']);
if ($qrName === '') $qrName = 'Student';
?>

<main class="vts-main">
<div class="vts-main-inner">

  <!-- A real heading element, not a styled div: this is the page's heading,
       and a screen reader has no other way to find out what this page is.
       The .page-title class carries the same look either way. -->
  <h1 class="page-title"><i class="fas fa-user" aria-hidden="true"></i> Student Profile</h1>

  <?php if($success): ?>
  <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
  <?php endif; ?>
  <?php if($error): ?>
  <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>

  <?php if (trim((string)($user['section'] ?? '')) === ''): ?>
  <div class="alert alert-warning" style="display:flex;align-items:center;gap:10px;">
    <i class="fas fa-circle-exclamation"></i>
    <span>Your <b>Set / Section</b> isn't filled in yet — add it below so your records are filed under the right set.</span>
  </div>
  <?php endif; ?>

  <div class="vts-card">
    <div class="profile-card">
      <!-- Avatar -->
      <div class="profile-avatar">
        <?php if(!empty($user['profile_picture'])): ?>
          <!-- Not alt="": this is the photo the form below edits, so whether one
               is set is information the reader needs, not decoration. -->
          <img alt="Your current profile photo" src="../uploads/profile/<?php echo htmlspecialchars($user['profile_picture']); ?>">
        <?php else: ?>
          <i class="fas fa-user-graduate"></i>
        <?php endif; ?>
      </div>

      <!-- Info -->
      <div class="profile-fields">
        <div class="profile-row"><span class="profile-label">Student ID</span><span class="profile-value">: <?php echo htmlspecialchars($user['student_id']); ?></span></div>
        <div class="profile-row"><span class="profile-label">Full Name</span><span class="profile-value">: <?php echo htmlspecialchars($user['fullname']); ?></span></div>
        <div class="profile-row"><span class="profile-label">Course</span><span class="profile-value">: <?php echo htmlspecialchars($user['course']); ?></span></div>
        <div class="profile-row"><span class="profile-label">Year</span><span class="profile-value">: <?php echo htmlspecialchars($user['year_level']); ?></span></div>
        <div class="profile-row"><span class="profile-label">Section</span><span class="profile-value">: <?php echo htmlspecialchars($user['section'] ?? '—'); ?></span></div>
        <div class="profile-row"><span class="profile-label">Email</span><span class="profile-value">: <?php echo htmlspecialchars($user['email'] ?? '—'); ?></span></div>
        <div class="profile-row"><span class="profile-label">Contact</span><span class="profile-value">: <?php echo htmlspecialchars(($user['contact_number'] ?? '') !== '' ? $user['contact_number'] : '—'); ?></span></div>
        <div class="profile-row"><span class="profile-label">QR Status</span><span class="profile-value qr-status-active">: Active</span></div>
      </div>

      <!-- QR -->
      <div class="u-center">
        <div style="font-size:0.8rem;font-weight:700;color:var(--navy);letter-spacing:0.06em;text-transform:uppercase;margin-bottom:12px;">YOUR QR CODE</div>
        <?php if($qrSrc && file_exists($qrFile)): ?>
          <div class="qr-img-wrap">
            <img alt="QR Code" src="<?php echo $qrSrc; ?>" style="width:160px;height:160px;display:block;">
          </div>
          <a href="<?php echo $qrSrc; ?>" class="btn-primary btn-sm qr-download-btn u-mt-12"
             data-src="<?php echo htmlspecialchars($qrSrc); ?>"
             data-filename="QR_<?php echo htmlspecialchars($user['student_id'] . '_' . $qrName); ?>.png"
             data-name="<?php echo htmlspecialchars($user['fullname']); ?>"
             data-id="<?php echo htmlspecialchars($user['student_id']); ?>"
             data-course="<?php echo htmlspecialchars($user['course']); ?>"
             data-logo="<?php echo htmlspecialchars($assetBase); ?>assets/images/logo-sm.jpg">
            <i class="fas fa-download"></i> Download QR
          </a>
        <?php else: ?>
          <div class="qr-img-wrap" style="width:160px;height:160px;display:flex;align-items:center;justify-content:center;background:#f0f4fa;border:1px dashed #c2cce0;color:#8895b3;font-size:.72rem;text-align:center;padding:10px;">
            QR will appear here once generated
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Edit Form -->
    <hr style="margin:28px 0;border-color:var(--border);">
    <div style="font-size:0.85rem;font-weight:700;color:var(--navy);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:18px;">
      <i class="fas fa-pen"></i> Edit Profile
    </div>
    <form method="POST" enctype="multipart/form-data">
<?php echo csrf_field(); ?>
      <?php
        // Edit the name WITHOUT its suffix — the suffix has its own dropdown,
        // and the two are recombined on save (prevents "Cruz Jr. Jr.").
        $editName = trim(preg_replace('/\s+(Jr\.?|Sr\.?|II|III|IV|V)\.?$/i', '', $user['fullname'] ?? ''));
      ?>
      <?php /* Nine fields in one undivided grid read as a wall. Three
               <fieldset> groups say what each run of fields is for, and
               .form-grid-2 (unlike the hand-written grid that was here)
               collapses to one column on a phone. */ ?>
      <fieldset class="vts-fieldset">
        <legend>Your name</legend>
        <p class="vts-fieldset-hint">This is the name that appears on violation records and on your QR code.</p>
        <div class="form-grid-2">
          <div class="vts-form-group">
            <label for="fullname">Full Name</label>
            <input id="fullname" type="text" name="fullname" class="vts-input" value="<?php echo htmlspecialchars($editName); ?>" required data-allow="letters" pattern="[A-Za-zÀ-ɏÑñ .'\-]{2,60}" title="Letters, spaces, hyphens, apostrophes and periods only." maxlength="80">
          </div>
          <div class="vts-form-group">
            <label for="suffix">Suffix</label>
            <select id="suffix" name="suffix" class="vts-select">
              <option value="">None</option>
              <?php foreach (['Jr.','Sr.','II','III','IV','V'] as $sf): ?>
                <option value="<?php echo $sf; ?>" <?php echo (($user['suffix'] ?? '') === $sf) ? 'selected' : ''; ?>><?php echo $sf; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </fieldset>

      <fieldset class="vts-fieldset">
        <legend>Your studies</legend>
        <div class="form-grid-2">
          <div class="vts-form-group">
            <label for="course">Course</label>
            <input id="course" type="text" name="course" class="vts-input" value="<?php echo htmlspecialchars($user['course']); ?>">
          </div>
          <div class="vts-form-group">
            <label for="year_level">Year Level</label>
            <input id="year_level" type="text" name="year_level" class="vts-input" value="<?php echo htmlspecialchars($user['year_level']); ?>">
          </div>
          <div class="vts-form-group">
            <label for="section">Set / Section <?php if (trim((string)($user['section'] ?? '')) === ''): ?><span class="u-warn-note">&mdash; not set yet</span><?php endif; ?></label>
            <input id="section" type="text" name="section" class="vts-input js-upper u-upper" maxlength="1" pattern="[A-Ja-j]" inputmode="text"
                   title="A single letter from A to J"
                   value="<?php echo htmlspecialchars($user['section'] ?? ''); ?>" placeholder="e.g. A">
          </div>
        </div>
      </fieldset>

      <fieldset class="vts-fieldset">
        <legend>How we reach you</legend>
        <p class="vts-fieldset-hint">Violation notices are emailed to the address below.</p>
        <div class="form-grid-2">
          <div class="vts-form-group">
            <label for="email">Email</label>
            <input id="email" type="email" name="email" class="vts-input" value="<?php echo htmlspecialchars($user['email']); ?>" required>
          </div>
          <div class="vts-form-group">
            <label for="contact_number">Contact Number</label>
            <?php /* type=tel brings up the phone keypad; the pattern accepts the
                     shapes people actually type and the field stays optional. */ ?>
            <input id="contact_number" type="tel" name="contact_number" class="vts-input"
                   inputmode="tel" maxlength="20" pattern="[0-9 ()+-]{7,20}"
                   title="Digits, spaces, brackets, + and - only"
                   value="<?php echo htmlspecialchars($user['contact_number'] ?? ''); ?>" placeholder="09XX XXX XXXX">
          </div>
          <div class="vts-form-group">
            <label for="profile_picture">Profile Picture</label>
            <input id="profile_picture" type="file" name="profile_picture" class="vts-input" accept=".jpg,.jpeg,.png">
            <?php if (!empty($user['profile_picture'])): ?>
              <?php /* Explicit for=/id rather than relying on the wrapping
                       alone — same reason as account.php. */ ?>
              <label class="vts-check-inline" for="remove_picture">
                <input id="remove_picture" type="checkbox" name="remove_picture" value="1"> Remove current photo
              </label>
            <?php endif; ?>
          </div>
        </div>
      </fieldset>
      <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Save Changes</button>
    </form>
  </div>

  <?php /* Its own card and its own form — see the note above the handler. */ ?>
  <div class="vts-card vts-card-stacked" id="password">
    <form method="POST" autocomplete="off">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="password_form" value="1">

      <fieldset class="vts-fieldset">
        <legend><?php echo $hasPassword ? 'Change your password' : 'Set your password'; ?></legend>
        <p class="vts-fieldset-hint">
          <?php if ($hasPassword): ?>
            You sign in with your School ID and this password.
          <?php else: ?>
            Your account doesn't have a password yet. Set one now — you'll need it
            to sign in once the older sign-in method is switched off.
          <?php endif; ?>
        </p>

        <?php if ($pwError): ?>
          <div class="alert alert-error vts-field-alert" role="alert">
            <i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($pwError); ?>
          </div>
        <?php endif; ?>

        <div class="form-grid">

          <?php if ($hasPassword): ?>
          <div class="field">
            <label for="current_password">Current Password <span class="req">*</span></label>
            <div class="password-wrap">
              <input id="current_password" type="password" name="current_password" class="vts-input"
                     autocomplete="current-password" maxlength="72" required>
              <button type="button" class="eye" aria-label="Show password" title="Show password" tabindex="-1">
                <i class="fas fa-eye" aria-hidden="true"></i>
              </button>
            </div>
            <small class="field-hint">Confirms it's you making the change.</small>
          </div>
          <?php endif; ?>

          <div class="field">
            <label for="new_password"><?php echo $hasPassword ? 'New Password' : 'Password'; ?> <span class="req">*</span></label>
            <div class="password-wrap">
              <input id="new_password" type="password" name="new_password" class="vts-input"
                     autocomplete="new-password" minlength="8" maxlength="72" required
                     aria-describedby="pwRules">
              <button type="button" class="eye" aria-label="Show password" title="Show password" tabindex="-1">
                <i class="fas fa-eye" aria-hidden="true"></i>
              </button>
            </div>
            <?php /* Same live checklist as Register: the rules are on screen and
                     tick off as they are met, rather than being revealed one at
                     a time by successive rejections. */ ?>
            <ul id="pwRules" class="pw-rules" aria-live="polite">
              <li data-rule="len"><i class="fas fa-circle" aria-hidden="true"></i> At least 8 characters</li>
              <li data-rule="upper"><i class="fas fa-circle" aria-hidden="true"></i> One capital letter (A–Z)</li>
              <li data-rule="lower"><i class="fas fa-circle" aria-hidden="true"></i> One small letter (a–z)</li>
              <li data-rule="digit"><i class="fas fa-circle" aria-hidden="true"></i> One number (0–9)</li>
              <li data-rule="special"><i class="fas fa-circle" aria-hidden="true"></i> One special character (!&nbsp;?&nbsp;#&nbsp;&amp;&nbsp;…)</li>
            </ul>
          </div>

          <div class="field">
            <label for="confirm_password">Confirm <?php echo $hasPassword ? 'New ' : ''; ?>Password <span class="req">*</span></label>
            <div class="password-wrap">
              <input id="confirm_password" type="password" name="confirm_password" class="vts-input"
                     autocomplete="new-password" maxlength="72" required>
              <button type="button" class="eye" aria-label="Show password" title="Show password" tabindex="-1">
                <i class="fas fa-eye" aria-hidden="true"></i>
              </button>
            </div>
            <small class="field-hint">Both boxes must match exactly.</small>
          </div>

        </div>
      </fieldset>

      <button type="submit" class="btn-primary">
        <i class="fas fa-key"></i> <?php echo $hasPassword ? 'Change Password' : 'Set Password'; ?>
      </button>
    </form>
  </div>

<?php /* The student's manual. Same component the staff profile uses — it
         renders only what this role can actually reach. */ ?>
<?php include "../includes/user_manual.php"; ?>

<?php /* The app shell (.vts-main-inner + <main>) is closed by
         includes/footer.php below -- closing it here as well emitted a
         stray </div></main> on every one of these pages. */ ?>
<?php include "../includes/footer.php"; ?>
