<?php
/* Passwordless student login.
   The student found their name in the roster and confirmed their School ID.
   There is NO password: the security check is that the caller must supply the
   exact School ID AND a matching last name, both of which must already be on
   the official enrolled-student roster (or an existing student account).
   Repeated wrong attempts are rate-limited exactly like password login. */
require_once "../config/database.php";
require_once "session.php";
require_once "../includes/functions.php";

$HAS_QR   = @include_once "../includes/qr_helper.php";
$HAS_MAIL = @include_once "../includes/mailer.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../student_search.php");
    exit();
}

$school_id = strtoupper(trim($_POST['school_id'] ?? ''));
$lastname  = name_case($_POST['lastname'] ?? '');
// Not trimmed — a space is a legal password character (see register_process.php).
$password  = (string)($_POST['password'] ?? '');

/* $field names the box that is wrong - 'identity' or 'password' - and it
   travels back so student_search.php can put the message under THAT field and
   focus it, instead of a banner at the top that makes the reader hunt for
   which of the two boxes it means. '' means the problem is not a single
   field's fault (an expired session, a lockout) and stays a banner. */
function slp_fail($school_id, $msg, $field = '') {
    header("Location: ../student_search.php?error=" . urlencode($msg)
         . ($field !== '' ? '&f=' . urlencode($field) : ''));
    exit();
}

if (!csrf_verify()) slp_fail($school_id, "Session expired. Please try again.");

/* ---- ONE FIELD ON THE PAGE, TWO THINGS IT MIGHT BE ----

   student_search.php now asks for "School ID or name" in a single box. It
   sends a School ID when what was typed looks like one, and otherwise sends
   the typed text as name_hint (plus the surname of whichever suggestion was
   picked). Either way the ACCOUNT is resolved here, never in the browser.

   Resolving by name is only allowed to get as far as finding the account —
   what proves it is theirs is checked below, and for an account with no
   password that proof IS the School ID. So a name alone can reach the
   password prompt and no further. */
$nameHint = trim($_POST['name_hint'] ?? '');
$resolvedByName = false;   // true when only a name was given, never a School ID

if ($school_id === '' && $nameHint !== '') {
    if (mb_strlen($nameHint) < 3) {
        slp_fail($school_id, "Type at least 3 letters of your name, or your 10-digit School ID.", 'identity');
    }
    $like = '%' . $nameHint . '%';
    $find = $conn->prepare(
        "SELECT student_id FROM users
          WHERE role = 'Student' AND student_id IS NOT NULL AND student_id <> ''
            AND (fullname LIKE :a OR CONCAT(firstname,' ',lastname) LIKE :b)
          LIMIT 2");
    $find->execute([':a' => $like, ':b' => $like]);
    $hits = $find->fetchAll(PDO::FETCH_COLUMN);

    if (count($hits) === 1) {
        $school_id = strtoupper((string)$hits[0]);
        $resolvedByName = true;
    } elseif (count($hits) > 1) {
        // Two students share that name: the School ID is the only thing that
        // separates them, so it has to be typed.
        slp_fail($school_id, "More than one student matches that name. Enter your 10-digit School ID instead.", 'identity');
    } else {
        slp_fail($school_id, "No student named \"{$nameHint}\" is on the enrolled list. Check the spelling, or use your School ID.", 'identity');
    }
}

if ($school_id === '')    slp_fail($school_id, "Enter your School ID or your name.", 'identity');
if (strlen($school_id) > 10) slp_fail($school_id, "A School ID is 10 digits — you typed " . strlen($school_id) . ".", 'identity');

/* WHAT A STUDENT HAS TO PROVE, AND WHO DECIDES WHICH.

   A student with a password must type it. A student who predates passwords
   falls back to their last name until they set one — see
   STUDENT_PASSWORD_REQUIRED in config/app.php for the switch that ends that.

   THE SERVER PICKS WHICH TEST APPLIES, from has_password on the account. The
   caller does not get a vote, and that is the whole point: the last name used
   to be checked only "if one was submitted", and it arrives in a hidden
   field, so clearing it in the inspector skipped the check entirely and a
   School ID alone opened the account. Now an account with a password cannot
   be talked back down to the weaker test by leaving a box empty — sending no
   password simply fails. */

// Brute-force guard — keyed by School ID, same helper the password login uses.
$lock = login_lock_seconds($conn, $school_id);
if ($lock > 0) {
    $mins = max(1, (int)ceil($lock / 60));
    slp_fail($school_id, "Too many attempts. Please try again in {$mins} minute(s).");
}

/* ---- 1. Already a registered student account? Log straight in. ---- */
$existing = $conn->prepare("SELECT * FROM users WHERE student_id = :sid AND role = 'Student' LIMIT 1");
$existing->execute([':sid' => $school_id]);
$user = $existing->fetch(PDO::FETCH_ASSOC);

if ($user) {
    if (($user['status'] ?? 'Active') === 'Inactive') {
        slp_fail($school_id, "This account is inactive. Please contact the OSA/Admin.");
    }

    if (vts_student_has_password($conn, $user)) {
        /* ---- This student has a password: it is the only way in. ---- */
        if ($password === '') {
            slp_fail($school_id, "Enter your password to continue.", 'password');
        }
        if (!password_verify($password, (string)$user['password'])) {
            login_record_attempt($conn, $school_id, false);
            /* IT NAMES THE PASSWORD NOW, and the old caution is written out
               here so the change is not mistaken for carelessness.

               The comment that stood here said the two halves were merged
               because telling them apart "is a way to confirm which School IDs
               are real". That protection did not exist. Reaching this line at
               all means the School ID matched a real account; an ID that
               matches nothing never gets here - it is redirected to
               register.php with "We couldn't find School ID X yet" (see the
               roster branch below). So the two outcomes were already a
               different PAGE and a different sentence, and anyone probing
               could tell them apart without reading this message.

               What actually holds the line is the rate limit above
               (login_lock_seconds / login_record_attempt), which is counted
               per School ID and locks out long before a list can be walked.

               So the merge bought nothing and cost the student the one fact
               they needed: whether to retype the password or check the ID. */
            slp_fail($school_id, "Incorrect password for School ID " . $school_id
                   . ". Check it and try again, or use \"Forgot password\".", 'password');
        }

    } else {
        /* ---- No password yet: the last-name route, while it is still open. ---- */
        if (defined('STUDENT_PASSWORD_REQUIRED') && STUDENT_PASSWORD_REQUIRED === true) {
            slp_fail($school_id, "Your account needs a password now. Use \"Forgot password\" to set one, or ask the OSA to help.");
        }
        if (trim((string)($user['lastname'] ?? '')) === '') {
            // Nothing on file to check the typed name against, so this route
            // cannot establish who is asking. An account nobody can verify
            // must not become the one account anybody can open.
            login_record_attempt($conn, $school_id, false);
            slp_fail($school_id, "We can't verify this account online yet. Please see the OSA to have your record completed.");
        }
        /* THE ACCOUNT WAS FOUND BY NAME, AND THERE IS NO PASSWORD TO ASK FOR.

           For these accounts the School ID *is* the proof — so it has to have
           been typed. Letting a name through here would mean the surname is
           checked against a surname derived from that same name, which always
           matches: anyone could sign in as anyone by typing their name. That
           is the bypass this file was fixed for, and merging the two steps
           into one field is exactly how it would come back. */
        if ($resolvedByName) {
            slp_fail($school_id, "This account has no password yet, so it needs your School ID rather than your name.", 'identity');
        }
        if ($lastname === '') {
            // Name the field that is actually missing. This used to say "pick
            // your name from the list, or enter your School ID" to someone who
            // had just entered their School ID — and whose ID, having no
            // password, produced no list to pick from.
            slp_fail($school_id, "This account has no password yet, so type your last name here as well.", 'identity');
        }
        if (mb_strtolower(trim($user['lastname'])) !== mb_strtolower(trim($lastname))) {
            login_record_attempt($conn, $school_id, false);
            slp_fail($school_id, "That last name does not match our record for School ID {$school_id}.", 'identity');
        }
        /* Signed in the old way. Two nudges, because they do different jobs:
           the session flag drives a banner they see on this visit, and the
           notification survives the visit so it is still waiting in the bell
           next time if they close the banner and forget. */
        $_SESSION['needs_password'] = true;
        vts_notify_set_password($conn, (int)$user['id']);
    }

    login_record_attempt($conn, $school_id, true);
    slp_login_session($user);   // sets session + redirects (never returns)
}

/* ---- 2. Not registered yet — must be on the official roster. ---- */
$rosterStmt = $conn->prepare("SELECT * FROM student_roster WHERE school_id = :sid LIMIT 1");
$rosterStmt->execute([':sid' => $school_id]);
$roster = $rosterStmt->fetch(PDO::FETCH_ASSOC);

if (!$roster) {
    // Nobody by that School ID — registration is open, so send them there.
    login_record_attempt($conn, $school_id, false);
    header("Location: ../register.php?error=" . urlencode(
        "We couldn't find School ID \"{$school_id}\" yet — register below and you're in."));
    exit();
}
/* An enrolled student who has never registered gets an account made for them
   here. Once passwords are mandatory there is no password to make it with, so
   they are sent to Register to choose one instead of being let in without. */
if (defined('STUDENT_PASSWORD_REQUIRED') && STUDENT_PASSWORD_REQUIRED === true) {
    header("Location: ../register.php?error=" . urlencode(
        "You're on the enrollment list but haven't set a password yet — register below to choose one."));
    exit();
}

// Same rule on the enrollment roster: a name must be on file, and the one
// submitted must match it.
if (trim((string)($roster['lastname'] ?? '')) === '') {
    login_record_attempt($conn, $school_id, false);
    slp_fail($school_id, "We can't verify this enrollment record online yet. Please see the OSA.");
}
if ($resolvedByName) {
    // Same rule on the roster side: a name cannot claim an unregistered record.
    slp_fail($school_id, "Please enter your School ID to claim this record.");
}
if ($lastname === '') {
    slp_fail($school_id, "Please also enter your last name to claim this enrollment record.");
}
if (mb_strtolower(trim($roster['lastname'])) !== mb_strtolower(trim($lastname))) {
    login_record_attempt($conn, $school_id, false);
    slp_fail($school_id, "That last name doesn't match our enrollment record for School ID \"{$school_id}\".");
}

/* ---- 3. Auto-create the pre-enrolled account (no password). ---- */
$firstname = name_case($roster['firstname'] ?? '');
$lastReal  = name_case($roster['lastname'] ?? '') ?: $lastname;
$fullname  = trim($firstname . ' ' . $lastReal);
if ($fullname === '') $fullname = 'Student ' . $school_id;
$course    = trim($roster['course'] ?? '');
$year      = trim($roster['year_level'] ?? '');

// Create the passwordless, scannable account + QR (shared with admin enrollment).
$prov = vts_provision_student_account($conn, $school_id, [
    'lastname' => $lastReal, 'firstname' => $firstname, 'course' => $course, 'year_level' => $year,
]);
$newId = (int)$prov['id'];
if ($newId <= 0) {
    slp_fail($school_id, "We couldn't sign you in right now. Please try again or contact the OSA/Admin.");
}

// Let Admin/OSA know a pre-enrolled student just activated their access.
try {
    $staff = $conn->query("SELECT id FROM users WHERE role IN ('Admin','OSA') AND status='Active'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($staff as $sid) {
        notify_once($conn, (int)$sid, 'Student Signed In',
            $fullname . ' (' . $school_id . ') accessed their student portal for the first time.');
    }
} catch (Throwable $e) { /* non-fatal */ }

login_record_attempt($conn, $school_id, true);

// Provisioned without a password (there was none to provision with), so the
// dashboard asks them to set one and the bell keeps asking.
$_SESSION['needs_password'] = true;
vts_notify_set_password($conn, $newId);

$freshStmt = $conn->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
$freshStmt->execute([':id' => $newId]);
slp_login_session($freshStmt->fetch(PDO::FETCH_ASSOC));


/* Finish the sign-in. Never returns.

   This used to be its own hand-copied duplicate of login_process.php's
   session block — the two drifted apart exactly as the comment feared, and
   this copy was the one that never got a second factor of any kind: School
   ID + surname, on any browser in the world, was a complete login. It goes
   through the same door as every other flow now, which also applies the
   new-device gate (see vts_complete_login()). */
function slp_login_session($user) {
    global $conn;
    $user['role'] = 'Student';   // this door only ever signs in students
    $landing = vts_complete_login($user, "Student passwordless lookup login");
    // Path only - see base_path(). An absolute URL here sent a phone signing
    // in through ngrok to localhost, which is the phone itself.
    header("Location: " . base_path() . '/' . $landing);
    exit();
}
