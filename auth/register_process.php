<?php
/* Handles the registration POST.
   Passwordless system: students never log in with a password, so registration
   collects identity + contact only. A username + random password are generated
   automatically to satisfy the shared `users` table (staff still use those). */
// Use the same hardened session bootstrap as the registration form and every
// protected page. Calling session_start() directly would write the new
// student's login to PHP's default save path, while the dashboard reads the
// app-private sessions directory configured by session.php.
require_once __DIR__ . "/session.php";

require_once "../config/database.php";
require_once "../includes/functions.php";

/* Redirect back to the form with a FIELD-SPECIFIC error.
   $field = the form field name to flag (error shows directly under it).
   Everything the student typed is preserved (only re-shown, nothing cleared). */
function back_with_error($msg, $field = '') {
    $old = $_POST;
    unset($old['csrf_token']);
    $_SESSION['reg_old']   = $old;
    $_SESSION['reg_error'] = ['field' => $field, 'msg' => $msg];
    header("Location: ../register.php");
    exit();
}

$HAS_QR   = @include_once "../includes/qr_helper.php";
$HAS_MAIL = @include_once "../includes/mailer.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../register.php");
    exit();
}

if (!csrf_verify()) {
    back_with_error("Session expired. Please try again.", '');
}

// ---- Collect ----
// Name is entered in three separate boxes (last / first / middle), so it is
// never guessed by splitting one string.
$lastname   = name_case($_POST['lastname']   ?? '');
$firstname  = name_case($_POST['firstname']  ?? '');
$middlename = name_case($_POST['middlename'] ?? '');
$fullname   = trim(preg_replace('/\s+/', ' ', "$firstname $middlename $lastname"));
$suffix     = trim($_POST['suffix'] ?? '');
$student_id = strtoupper(trim($_POST['student_id'] ?? ''));   // IDs are stored uppercase
$email      = trim($_POST['email'] ?? '');
$phone      = trim($_POST['contact_number'] ?? '');
$gender     = trim($_POST['gender'] ?? '');
$course_id  = trim($_POST['course_id'] ?? '');
$year       = trim($_POST['year_level'] ?? '');
$section    = strtoupper(trim($_POST['section'] ?? ''));
/* Passwords are NOT trimmed. A leading or trailing space is a legal character,
   and quietly stripping it would store a password different from the one the
   student typed — they would then be unable to sign in with what they chose. */
$password   = (string)($_POST['password'] ?? '');
$confirmPw  = (string)($_POST['confirm_password'] ?? '');

// ---- Server-side validation (flags the SPECIFIC field that is wrong) ----
if ($lastname === '')          back_with_error("Last name is required.", 'lastname');
if ($firstname === '')         back_with_error("First name is required.", 'firstname');
if ($suffix !== '' && !preg_match('/^(Jr\.?|Sr\.?|II|III|IV|V)$/i', $suffix))
                               back_with_error("Suffix must be one of: Jr., Sr., II, III, IV, V — or leave it blank.", 'suffix');
// Reject blank AND obviously-fake IDs (1234567890, 0000000000, 1212121212…).
[$sidOk, $sidWhy] = vts_validate_school_id($student_id);
if (!$sidOk) back_with_error($sidWhy, 'student_id');
if (!filter_var($email, FILTER_VALIDATE_EMAIL))
                               back_with_error("Please enter a valid email address.", 'email');
if (!preg_match('/^[0-9]{11}$/', $phone))
                               back_with_error("Contact number must be exactly 11 digits.", 'contact_number');
if (!in_array($gender, ['Male','Female','Other'], true))
                               back_with_error("Please select a gender.", 'gender');
if (!ctype_digit($course_id))  back_with_error("Please select a course.", 'course_id');
if ($year === '')              back_with_error("Please select a year level.", 'year_level');
if ($section !== '' && !preg_match('/^[A-Z]$/', $section))
                               back_with_error("Section must be one capital letter (A-Z).", 'section');

/* ---- Password: the authoritative check ----

   register.php runs the same five rules as you type, and that copy exists
   only so the form can answer "what does it want?" without a round trip. It
   is not a control: anything the browser enforces, the browser can be made
   to stop enforcing — remove the attribute in the inspector, or skip the
   page entirely and POST here directly. These lines are the ones that decide,
   and they run on every request no matter what the form did.

   The message still names the exact rule that failed and flags the field it
   belongs to, so being bounced by the server reads the same as being stopped
   by the form. It describes the RULE, never anything about the system
   behind it. */
if ($password === '')          back_with_error("Please choose a password.", 'password');
if (strlen($password) > 72)    back_with_error("Password can be at most 72 characters.", 'password');
if (($pwWhy = password_policy_error($password)) !== '')
                               back_with_error($pwWhy, 'password');
if ($confirmPw === '')         back_with_error("Please type your password a second time to confirm it.", 'confirm_password');
if (!hash_equals($password, $confirmPw))
                               back_with_error("The two passwords do not match.", 'confirm_password');

// Resolve the course -> its name + college (college is derived, not asked).
$cStmt = $conn->prepare("SELECT course_name, short_name, college_id FROM courses WHERE id = :id");
$cStmt->execute([':id' => $course_id]);
$courseRow = $cStmt->fetch(PDO::FETCH_ASSOC);
if (!$courseRow) {
    back_with_error("Selected course does not exist.", 'course_id');
}
// Store the short code (BSIT, BSED…) — that's how courses read across the app.
$course     = ($courseRow['short_name'] ?? '') !== '' ? $courseRow['short_name'] : $courseRow['course_name'];
$college_id = $courseRow['college_id'] !== null ? (int)$courseRow['college_id'] : null;

/* =====================================================================
   OPEN REGISTRATION. Students no longer have to wait for the OSA/Admin to
   put their School ID on the enrolled list — anyone can register and the
   account is active at once. The enrolled list is kept in step instead:
   a missing School ID is added to it here, so the Students page still
   shows everyone. (Trade-off: the School ID typed in is not verified
   against school records any more — only "already registered" is blocked.)
   ===================================================================== */
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS student_roster (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        school_id VARCHAR(10) NOT NULL UNIQUE,
        lastname VARCHAR(60) NOT NULL,
        firstname VARCHAR(60) NULL,
        course VARCHAR(150) NULL,
        year_level VARCHAR(20) NULL,
        is_used TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_roster_lastname (lastname)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) { /* ignore */ }

$rosterStmt = $conn->prepare("SELECT id, lastname, firstname FROM student_roster WHERE school_id = :sid LIMIT 1");
$rosterStmt->execute([':sid' => $student_id]);
$rosterRow = $rosterStmt->fetch(PDO::FETCH_ASSOC);
if (!$rosterRow) {
    // Not on the enrolled list yet — add them to it instead of turning them away.
    try {
        $conn->prepare("INSERT INTO student_roster (school_id, lastname, firstname, course, year_level, is_used)
                        VALUES (:sid, :ln, :fn, :c, :y, 1)")
             ->execute([':sid' => $student_id, ':ln' => $lastname, ':fn' => ($firstname ?: null),
                        ':c' => ($course ?: null), ':y' => ($year ?: null)]);
    } catch (Throwable $e) { /* non-fatal — the account still gets created */ }
}

// Rebuild the stored full name to include the suffix (kept out of name parsing).
$fullnameStored = $fullname . ($suffix !== '' ? ' ' . $suffix : '');

// ---- Uniqueness checks (email, School ID) — each flags its own field ----
$dup = $conn->prepare("SELECT email, student_id FROM users WHERE email = :e OR student_id = :s");
$dup->execute([':e' => $email, ':s' => $student_id]);
foreach ($dup->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if ($r['email'] === $email)           back_with_error("That email is already registered.", 'email');
    if ($r['student_id'] === $student_id) back_with_error("That School ID is already registered.", 'student_id');
}

// ---- Auto username + the password the student just chose ----
// This used to be password_hash(random_bytes(32)) — a throwaway nobody could
// ever type, because students signed in without one. They choose their own
// now, and has_password=1 records that, which is what tells student login to
// ask for it rather than fall back to the last-name route.
$username = 'stu_' . strtolower(preg_replace('/[^a-z0-9]/i', '', $student_id));
$hash     = password_hash($password, PASSWORD_DEFAULT);
vts_ensure_password_flag($conn);

// ---- Insert ----
try {
    $stmt = $conn->prepare("
        INSERT INTO users
            (student_id, firstname, middlename, lastname, suffix, fullname, username, email, password,
             role, contact_number, gender, college_id, course, year_level, section, status, email_verified,
             has_password)
        VALUES
            (:student_id, :firstname, :middlename, :lastname, :suffix, :fullname, :username, :email, :password,
             'Student', :phone, :gender, :college_id, :course, :year, :section, 'Active', 1,
             1)
    ");
    $stmt->execute([
        ':student_id' => $student_id,
        ':firstname'  => ($firstname !== '' ? $firstname : null),
        ':middlename' => ($middlename !== '' ? $middlename : null),
        ':lastname'   => ($lastname !== '' ? $lastname : null),
        ':suffix'     => ($suffix !== '' ? $suffix : null),
        ':fullname'   => $fullnameStored,
        ':username'   => $username,
        ':email'      => $email,
        ':password'   => $hash,
        ':phone'      => $phone,
        ':gender'     => $gender,
        ':college_id' => $college_id,
        ':course'     => $course,
        ':year'       => $year,
        ':section'    => $section !== '' ? $section : null,
    ]);
    $newId = $conn->lastInsertId();
    // Mark this School ID as used on the roster.
    try { $conn->prepare("UPDATE student_roster SET is_used=1 WHERE school_id=:sid")->execute([':sid'=>$student_id]); }
    catch (Throwable $e) { /* non-fatal */ }
} catch (PDOException $e) {
    error_log('VTS registration failed: ' . $e->getMessage());
    back_with_error("Registration could not be completed. Please check your details and try again.", '');
}

/* ---- Notify the ADMIN(s) and OSA staff that a new student registered. ---- */
try {
    $staff = $conn->query("SELECT id FROM users WHERE role IN ('Admin','OSA') AND status='Active'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($staff as $sid) {
        notify_once($conn, (int)$sid, 'New Student Registered',
            $fullnameStored . ' (' . $student_id . ') created a new student account — visible under Students.');
    }
} catch (Throwable $e) { /* non-fatal */ }

// ---- Generate QR + store filename ----
$qrPath = null;
if ($HAS_QR && function_exists('ensure_student_qr_file')) {
    try {
        $qrPath = ensure_student_qr_file($student_id, $student_id . "|" . $fullnameStored . "|" . $year . "|" . $course);
        if ($qrPath) {
            $qrName = basename($qrPath);
            $u = $conn->prepare("UPDATE users SET qr_code = :qr WHERE id = :id");
            $u->execute([':qr' => $qrName, ':id' => $newId]);
        }
    } catch (Throwable $e) {
        error_log('SVTMS QR generation skipped: ' . $e->getMessage());
    }
}

// ---- Welcome email with QR (never blocks) ----
if ($HAS_MAIL && function_exists('send_welcome_qr_email')) {
    try { send_welcome_qr_email($email, $fullnameStored, $student_id, $qrPath); }
    catch (Throwable $e) { error_log('SVTMS register mail exception: ' . $e->getMessage()); }
}

// ---- No OTP / no approval: log the student straight in and go to their
//      dashboard (register → dashboard, no extra login step). ----
/* This was a third hand-copied duplicate of login_process.php's session block.
   It calls the shared one now, so a field added there can never again be
   missing here.

   No new-device code on this path, deliberately: the account did not exist a
   moment ago, so there is no other session to protect and nobody to ask. This
   browser simply becomes its first recognised device — every later browser
   still has to pass the gate in vts_complete_login(). */
unset($_SESSION['reg_old'], $_SESSION['reg_error']);
vts_establish_session([
    'id'              => $newId,
    'fullname'        => $fullnameStored,
    'role'            => 'Student',
    'email'           => $email ?? '',
    'profile_picture' => '',
]);
vts_device_trust_now($conn, (int)$newId);
try { audit_log($conn, "Register", "users", $newId, "New student self-registered"); } catch (Throwable $e) {}
header("Location: ../student/dashboard.php");
exit();
