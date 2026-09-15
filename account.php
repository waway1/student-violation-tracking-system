<?php
/* My Account: view/edit profile (name, email, contact, photo) and password for every role; updates the DB and refreshes the session immediately. */
require_once "auth/auth.php";
require_once "config/database.php";
require_once "includes/functions.php";
vts_ensure_user_columns($conn);

$userID = $_SESSION['user_id'];
$role   = $_SESSION['role'] ?? '';

$stmt = $conn->prepare("SELECT * FROM users WHERE id=:id");
$stmt->execute([':id' => $userID]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { header("Location: logout.php"); exit(); }

$success = $_GET['success'] ?? "";
$error   = "";

if ($_SERVER['REQUEST_METHOD'] === "POST") {
    if (!csrf_verify()) {
        $error = "Your session expired — please try saving again.";
    } else {
        $fullname = name_case($_POST['fullname'] ?? '');
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $contact  = trim($_POST['contact_number'] ?? '');

        if ($fullname === '' || mb_strlen($fullname) < 4) {
            $error = "Full Name looks too short — please enter your complete name.";
        } elseif (!preg_match("/^[\p{L} .'-]+$/u", $fullname)) {
            $error = "Full Name may only contain letters, spaces, periods, and hyphens.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "\"{$email}\" is not a valid email address.";
        } elseif ($contact !== '' && !preg_match('/^[0-9+\-() ]{7,20}$/', $contact)) {
            $error = "Contact number format looks wrong (digits only, 7–20 characters).";
        } else {
            $dup = $conn->prepare("SELECT id FROM users WHERE email = :e AND id != :id");
            $dup->execute([':e' => $email, ':id' => $userID]);
            if ($dup->fetch()) $error = "The email \"{$email}\" is already used by another account.";
        }

        // Type, size and filename are all decided by vts_accept_image_upload()
        // (includes/functions.php), which student/profile.php calls too — the
        // rules used to live in both files separately.
        $picture = $user['profile_picture'];
        if ($error === "" && isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
            $up = vts_accept_image_upload($_FILES['profile_picture'], __DIR__ . "/uploads/profile/");
            if ($up['ok']) $picture = $up['name'];
            else           $error   = $up['error'];
        }

        // Remove current photo — honoured only when no replacement was uploaded.
        if ($error === "" && !empty($_POST['remove_picture']) &&
            (!isset($_FILES['profile_picture']) || $_FILES['profile_picture']['error'] === UPLOAD_ERR_NO_FILE)) {
            if (!empty($user['profile_picture'])) {
                @unlink(__DIR__ . "/uploads/profile/" . $user['profile_picture']);
            }
            $picture = null;
        }

        $newPw = $_POST['new_password'] ?? '';
        if ($error === "" && $newPw !== '' && ($pe = password_policy_error($newPw)) !== '') {
            $error = $pe;
        }

        if ($error === "") {
          try {
            $conn->beginTransaction();
            $upd = $conn->prepare("UPDATE users
                         SET fullname=:fn, email=:em, contact_number=:cn, profile_picture=:pic
                         WHERE id=:id");
            $upd->execute([':fn'=>$fullname, ':em'=>$email,
                     ':cn'=>($contact !== '' ? $contact : null), ':pic'=>$picture, ':id'=>$userID]);
            if ($newPw !== '') {
              $conn->prepare("UPDATE users SET password=:pw WHERE id=:id")
                 ->execute([':pw'=>password_hash($newPw, PASSWORD_DEFAULT), ':id'=>$userID]);
            }
            $conn->commit();
            $_SESSION['fullname']        = $fullname;
            $_SESSION['profile_picture'] = $picture;
            header("Location: account.php?success=" . urlencode("Account updated successfully."));
            exit();
          } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            error_log('Account update failed: ' . $e->getMessage());
            $error = "Your changes could not be saved. Please check your details and try again.";
          }
        }
    }
}

$assetBase = "";
include "includes/header.php";
include "includes/navbar.php";
include "includes/sidebar.php";
?>
<main class="vts-main">
<div class="vts-main-inner narrow">

  <div class="page-head">
    <h1>My Account</h1>
    <p>Update your personal information. Changes apply immediately.</p>
  </div>

  <?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($success); ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>

  <div class="vts-card">
    <div class="student-strip u-mb-20">
      <div class="avatar-circle" style="overflow:hidden;">
        <?php if (!empty($user['profile_picture'])): ?>
          <!-- Not alt="": this is the photo the form below edits, so whether one
               is set is information the reader needs, not decoration. -->
          <img alt="Your current profile photo" src="uploads/profile/<?php echo htmlspecialchars($user['profile_picture']); ?>"
style="width:100%;height:100%;object-fit:cover;">
        <?php else: ?><i class="fas fa-user"></i><?php endif; ?>
      </div>
      <div>
        <div class="cell-title" style="font-size:1.05rem;"><?php echo htmlspecialchars($user['fullname']); ?></div>
        <div class="cell-sub"><?php echo htmlspecialchars($role); ?> · <?php echo htmlspecialchars($user['username']); ?></div>
      </div>
    </div>

    <?php /* THREE GROUPS, NOT ONE RUN OF SIX FIELDS. It was a flat grid, so
             "change my password" and "fix my phone number" looked like the
             same job, and the password box sitting under Photo read as part
             of uploading one. The <fieldset>/<legend> pattern is the one the
             Settings and Register pages already use, so the grouping is in
             the markup rather than only in the spacing. */ ?>
    <form method="POST" enctype="multipart/form-data">
      <?php echo csrf_field(); ?>

      <fieldset class="vts-fieldset">
        <legend>Your details</legend>
        <p class="vts-fieldset-hint">Your name as it should appear on records, and where the system contacts you.</p>
        <div class="form-grid-2">
          <div class="vts-form-group">
            <label for="fullname">Name <span class="req">*</span></label>
            <input id="fullname" type="text" name="fullname" class="vts-input" required
                   value="<?php echo htmlspecialchars($user['fullname']); ?>" data-allow="letters" pattern="[A-Za-zÀ-ɏÑñ .'\-]{2,60}" title="Letters, spaces, hyphens, apostrophes and periods only." maxlength="80">
          </div>
          <div class="vts-form-group">
            <label for="email">Email <span class="req">*</span></label>
            <input id="email" type="email" name="email" class="vts-input" required
                   value="<?php echo htmlspecialchars($user['email']); ?>">
          </div>
          <div class="vts-form-group span-2">
            <label for="contact_number">Contact</label>
            <input id="contact_number" type="tel" name="contact_number" class="vts-input"
                   pattern="[0-9+()\- ]{7,20}" inputmode="tel"
                   value="<?php echo htmlspecialchars($user['contact_number'] ?? ''); ?>" placeholder="09XX XXX XXXX"
                   aria-describedby="contactHelp">
            <small class="hint" id="contactHelp">Use 7 to 20 digits, spaces, parentheses, or a plus sign.</small>
          </div>
        </div>
      </fieldset>

      <fieldset class="vts-fieldset">
        <legend>Photo</legend>
        <p class="vts-fieldset-hint">Shown beside your name in the app. Optional.</p>
        <div class="form-grid-2">
          <div class="vts-form-group span-2">
            <label for="profile_picture">Upload a photo</label>
            <input id="profile_picture" type="file" name="profile_picture" class="vts-input" accept=".jpg,.jpeg,.png">
            <small class="hint">JPG or PNG · max 3MB</small>
            <?php if (!empty($user['profile_picture'])): ?>
              <?php /* The label wrapped the box, which is a valid association but
                       an implicit one. Naming it with for=/id states the pairing
                       outright, so nothing has to infer it from the nesting. */ ?>
              <label class="vts-check-inline" for="remove_picture">
                <input id="remove_picture" type="checkbox" name="remove_picture" value="1"> Remove current photo
              </label>
            <?php endif; ?>
          </div>
        </div>
      </fieldset>

      <fieldset class="vts-fieldset">
        <legend>Password</legend>
        <p class="vts-fieldset-hint">Leave this blank to keep the password you already have.</p>
        <div class="form-grid-2">
          <div class="vts-form-group span-2">
            <label for="accountPwd">New password</label>
            <!-- The show/hide eye is wired ONCE for the whole app by the delegated
                 handler in assets/js/vts-ui.js (it matches '.password-wrap .eye').
                 This button used to ALSO carry an inline onclick that did the same
                 thing, so one press ran both handlers: the field flipped to text
                 and straight back to password, and the eye looked broken while
                 actually working twice. -->
            <div class="password-wrap">
              <input type="password" autocomplete="new-password" name="new_password" id="accountPwd" class="vts-input" placeholder="8+ mixed chars">
              <button type="button" class="eye" aria-label="Show password" title="Show password"><i class="fas fa-eye" aria-hidden="true"></i></button>
            </div>
          </div>
        </div>
      </fieldset>

      <button type="submit" class="btn-primary u-mt-14"><i class="fas fa-save"></i> Save Changes</button>
    </form>
  </div>

<?php /* The manual for whichever role is reading. It is on the profile page
         because that is the one screen every role has, reaches from the
         navbar, and is not in the middle of doing something else. */ ?>
<?php include "includes/user_manual.php"; ?>

<?php /* .vts-main-inner and <main> are closed by includes/footer.php;
         closing them here too emitted a stray </div></main>. */ ?>
<?php include "includes/footer.php"; ?>
