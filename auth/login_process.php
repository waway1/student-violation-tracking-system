<?php
/* Handles the login POST: validates credentials, starts the session. */
require_once "../config/database.php";
require_once "session.php";
require_once "../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../student_search.php");
    exit();
}

// Staff login submits this via fetch() so the login page never navigates —
// errors are returned as JSON and shown inline in the modal instead.
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

$username   = trim($_POST['username'] ?? '');
$password   = $_POST['password'] ?? '';
$login_type = $_POST['login_type'] ?? 'student';
$wantRole   = $_POST['role'] ?? '';   // only sent for staff

// Where to bounce back on error (reopen the right form, keep role + typed username)
$keep = "&u=" . urlencode($username);
if ($login_type === 'staff') {
    $keepRole = in_array($wantRole, ['Admin','OSA','OSA Staff','Guard'], true) ? "&role=" . urlencode($wantRole) : "";
    $errBack  = "../student_search.php?staff=1" . $keepRole . $keep . "&error=";
} else {
    $errBack  = "../student_search.php?u=" . urlencode($username) . "&error=";
}

// Ends the request: JSON for AJAX callers, a redirect for everyone else.
/* $field names which box is wrong - 'username' or 'password' - so the form
   can mark that box and put the cursor in it, rather than showing one banner
   over both and leaving the reader to work out which. '' is for problems that
   are not a field's fault: an expired session, a lockout, a wrong role. */
function login_fail($isAjax, $errBack, $msg, $field = '') {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $msg, 'field' => $field]);
        exit();
    }
    header("Location: " . $errBack . urlencode($msg)
         . ($field !== '' ? '&f=' . urlencode($field) : ''));
    exit();
}
/* IMPORTANT: success targets are given relative to the APP ROOT
   (e.g. "admin/dashboard.php"). This request runs at /auth/… and the
   AJAX staff form runs at /student_search.php, so a bare relative path
   resolves to a DIFFERENT folder depending on the flow — that's what caused
   the "404 Not Found" after logging in. So the target is made absolute.

   ABSOLUTE PATH, NOT ABSOLUTE URL. This used to prepend base_url(), which
   also pins on the scheme and the Host header the request arrived with.
   Behind ngrok started the usual way (--host-header=localhost) that header
   is "localhost", so a phone signing in was redirected to http://localhost/…
   — itself — and got "This site can't be reached". It read as a login or
   account fault; it was the redirect naming the wrong machine.

   base_path() fixes the 404 exactly as before (an absolute path resolves the
   same from either flow) while leaving the origin to the browser, which is
   the one party that always knows what it connected to. */
function login_goto($isAjax, $url) {
    if (!preg_match('#^(https?:)?/#i', $url)) {              // relative path?
        if (substr($url, 0, 3) === '../') $url = substr($url, 3);
        $url = base_path() . '/' . $url;
    }
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'redirect' => $url]);
        exit();
    }
    header("Location: " . $url);
    exit();
}

if (!csrf_verify()) {
    login_fail($isAjax, $errBack, "Session expired. Please try again.");
}

if ($username === '' || $password === '') {
    login_fail($isAjax, $errBack,
        $username === '' ? "Enter your username." : "Enter your password.",
        $username === '' ? 'username' : 'password');
}

// --- Brute-force / bot guard: block BEFORE we even look the account up ---
$lock = login_lock_seconds($conn, $username);
if ($lock > 0) {
    $mins = max(1, (int)ceil($lock / 60));
    login_fail($isAjax, $errBack, "Too many failed attempts. Please try again in {$mins} minute(s).");
}

$stmt = $conn->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
$stmt->execute([':username' => $username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Field-specific feedback: which one is actually wrong?
if (!$user) {
    login_record_attempt($conn, $username, false);
    login_fail($isAjax, $errBack,
        "No account exists with the username \"{$username}\". Check the spelling.", 'username');
}
if (!password_verify($password, $user['password'])) {
    login_record_attempt($conn, $username, false);
    login_fail($isAjax, $errBack,
        "Incorrect password for \"{$username}\". The username is correct — only the password is wrong.", 'password');
}
login_record_attempt($conn, $username, true);

// Email must be verified (only applies to accounts that were sent an OTP).
// This is a real next step, not a validation error, so it always navigates
// to verify.php — even for the AJAX staff form.
if (isset($user['email_verified']) && (int)$user['email_verified'] === 0) {
    $verifyUrl = "../verify.php?email=" . urlencode($user['email']) .
           "&error=" . urlencode("Your email isn't verified yet. Enter the 6-digit code we sent you, or tap Resend.");
    login_goto($isAjax, $verifyUrl);
}

// Account must be active
if (isset($user['status']) && $user['status'] === 'Inactive') {
    login_fail($isAjax, $errBack, "This account is inactive. Contact the administrator.");
}

// ---- Enforce login channel ----
if ($login_type === 'student') {
    // Student page only logs in students
    if ($user['role'] !== 'Student') {
        header("Location: ../student_search.php?staff=1" . $keep . "&error=" . urlencode("Staff accounts sign in here — pick your role to continue."));
        exit();
    }
} else { // staff
    // Guards may sign in on the website as well as the scanner app.
    if ($user['role'] === 'Student') {
        login_fail($isAjax, $errBack, "This is a student account — please use the student login form above.");
    }
    if ($wantRole !== '' && $user['role'] !== $wantRole) {
        login_fail($isAjax, $errBack, "That account is not a {$wantRole} account.");
    }
}

/* ---- The password was right. For staff, that is only the first half. ----
   A 6-digit code goes to the address the office holds for this account, and
   NOTHING that counts as being logged in is written yet: $_SESSION['pending_otp']
   carries no user_id and no role, so auth.php still sees an anonymous visitor
   until login_otp.php accepts the code.

   WHEN A CODE CANNOT BE SENT, THE SIGN-IN CONTINUES. An account with no real
   address, or a mail outage, would otherwise lock the Office of Student
   Affairs out of their own system with no way back in — a self-inflicted
   outage is not better than the risk it prevents. It falls through to a
   normal single-factor login and says so in the audit log, so the gap is
   visible in Admin > Audit Log rather than silent. */
$otpDevCode = null;
if (vts_staff_otp_applies($user['role'])) {
    if (!vts_otp_deliverable($user['email'] ?? '')) {
        audit_log($conn, "Login (no 2FA)", "users", $user['id'],
            "{$user['fullname']} ({$user['role']}) signed in WITHOUT a sign-in code — the account has no usable email address.");
    } elseif (!vts_issue_login_otp($conn, $user, $otpDevCode)) {
        error_log("Staff OTP: could not send a sign-in code to user #{$user['id']} — falling back to single-factor login.");
        audit_log($conn, "Login (no 2FA)", "users", $user['id'],
            "{$user['fullname']} ({$user['role']}) signed in WITHOUT a sign-in code — the code could not be emailed.");
    } else {
        // Fresh ID for the pending step too: an ID an attacker planted must
        // not be the one holding a half-finished sign-in.
        session_regenerate_id(true);
        $_SESSION['pending_otp'] = [
            // Dev machines only; null everywhere else (vts_dev_reveal_ok()).
            'dev_code'   => $otpDevCode ?? null,
            'user_id'    => (int)$user['id'],
            'email'      => (string)$user['email'],
            'fullname'   => (string)$user['fullname'],
            'role'       => (string)$user['role'],
            'login_type' => $login_type,
            'started'    => time(),
        ];
        vts_otp_resend_record($conn, $user['email']);   // start the resend cooldown
        audit_log($conn, "Login step 1", "users", $user['id'],
            "{$user['fullname']} ({$user['role']}) passed the password step; sign-in code sent to " . vts_mask_email($user['email']));
        login_goto($isAjax, "login_otp.php");
    }
}

// ---- Success: set session ----
/* Populated in one shared place so this door and login_otp.php's cannot drift
   apart. vts_complete_login() regenerates the session ID (killing
   session-fixation), writes the audit entry AFTER the session exists so the
   Role column is filled, and applies the new-device gate: on a browser this
   account has not been seen on before it returns auth/device_verify.php
   instead of a dashboard, with nothing yet written as "logged in". */
if (vts_login_destination($user['role']) === '') {
    login_fail($isAjax, "../student_search.php?error=", "Invalid role.");
}
$landing = vts_complete_login($user,
    "Signed in via " . ($login_type === 'staff' ? 'staff portal' : 'student portal'));

// login_goto() is what makes this work for BOTH doors: a Location header for
// an ordinary POST, and {ok:true, redirect:…} JSON for the staff modal's fetch().
login_goto($isAjax, $landing);
exit();
