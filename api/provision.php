<?php
/* ONLINE DEVICE PROVISIONING for the offline scanner (spck_scanner.html).
 *
 * The flow this supports:
 *   1. ONLINE  — an Admin/OSA signs in here once and the phone pulls the
 *                students list + violation types + staff accounts straight
 *                from the web. No file to export, copy, or paste.
 *   2. OFFLINE — the phone scans all day from that local copy.
 *   3. ONLINE  — records sync back to the server …
 *      OFFLINE — …or are exported to a file if no Admin/OSA is around to
 *                receive them.
 *
 * The `accounts` block carries bcrypt hashes only (never plaintext) so the
 * device can still verify an Admin/OSA login later with no internet.
 * POST { username, password } -> JSON.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

function pv_fail($msg, $code = 200) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pv_fail('POST required.', 405);

/* ------------------------------------------------------------------
   MODE A — GUARD/MARSHAL SELF-SERVE (no Admin/OSA, no password).
   The guard proves who they are with their own NAME + STAFF ID, checked
   against the server. This is what keeps the gate from stopping the day
   when Admin and OSA are both away: the person actually holding the
   phone can unlock it themselves.
   They get students + violation types ONLY — never the staff password
   hashes, so a self-provisioned device carries no credentials.
   ------------------------------------------------------------------ */
if (($_POST['mode'] ?? '') === 'marshal') {
    $name = trim($_POST['name'] ?? '');
    $sid  = strtoupper(trim($_POST['school_id'] ?? ''));
    if ($name === '' || $sid === '') pv_fail('Enter your name and your staff/School ID.');

    $lock = login_lock_seconds($conn, 'marshal:' . $sid);
    if ($lock > 0) pv_fail('Too many tries. Wait ' . max(1, (int)ceil($lock / 60)) . ' minute(s).');

    $ms = $conn->prepare("SELECT id, fullname, role FROM users
                          WHERE student_id = :sid AND role = 'Guard'
                            AND status = 'Active'
                            AND (fullname LIKE :n OR lastname LIKE :n OR firstname LIKE :n)
                          LIMIT 1");
    $ms->execute([':sid' => $sid, ':n' => '%' . $name . '%']);
    $marshal = $ms->fetch(PDO::FETCH_ASSOC);

    if (!$marshal) {
        login_record_attempt($conn, 'marshal:' . $sid, false);
        pv_fail('No marshal found with that name and ID. Ask the OSA to add you to the staff list first.');
    }
    login_record_attempt($conn, 'marshal:' . $sid, true);

    try {
        $guards = $conn->query(
            "SELECT student_id, fullname FROM users
             WHERE role='Guard' AND status='Active' AND student_id IS NOT NULL
             ORDER BY fullname")->fetchAll(PDO::FETCH_ASSOC);
        $students = $conn->query(
            "SELECT student_id, fullname, section, year_level, course, scanner_access
             FROM users WHERE role='Student' AND status='Active'
             ORDER BY fullname")->fetchAll(PDO::FETCH_ASSOC);
        $types = $conn->query(
            "SELECT id, violation_name, severity, max_points
             FROM violation_types ORDER BY violation_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('VTS marshal provisioning failed: ' . $e->getMessage());
        pv_fail('Unable to load the required data right now. Please try again later.');
    }

    audit_log($conn, "Provision Device", "users", $marshal['id'],
        'Self-serve device setup by marshal ' . $marshal['fullname'] . ' — ' . count($students) . ' students');

    echo json_encode([
        'ok'          => true,
        'app'         => 'VTS-SCANNER',
        'type'        => 'students-list',
        'exported_at' => date('c'),
        'exported_by' => $marshal['fullname'] . ' (' . $marshal['role'] . ', self-setup)',
        'students'    => $students,
        'guards'      => $guards,
        'types'       => $types,
        'accounts'    => [],          // marshals never receive password hashes
        'marshal'     => ['name' => $marshal['fullname'], 'role' => $marshal['role']],
    ]);
    exit();
}

/* ------------------------------------------------------------------
   MODE B — ADMIN / OSA (full payload, includes account hashes so the
   device can still verify a staff login with no internet later).
   ------------------------------------------------------------------ */
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
if ($username === '' || $password === '') pv_fail('Enter your username and password.');

// Same brute-force guard the web login uses.
$lock = login_lock_seconds($conn, $username);
if ($lock > 0) {
    pv_fail('Too many failed attempts. Try again in ' . max(1, (int)ceil($lock / 60)) . ' minute(s).');
}

// Only an Admin or OSA may unlock a scanning device.
$st = $conn->prepare("SELECT id, fullname, username, role, password, status
                      FROM users WHERE username = :u LIMIT 1");
$st->execute([':u' => $username]);
$user = $st->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($password, $user['password'])) {
    login_record_attempt($conn, $username, false);
    pv_fail('Incorrect username or password.');
}
login_record_attempt($conn, $username, true);

if (($user['status'] ?? '') === 'Inactive')                pv_fail('That account is inactive.');
if (!in_array($user['role'], ['Admin', 'OSA'], true))      pv_fail('Only an Admin or OSA can set up a scanning device.');

/* ---- Build the same payload the secure .vtsl file carries ---- */
$students = [];
$guards   = [];
$accounts = [];
$types    = [];
try {
        $students = $conn->query(
            "SELECT student_id, fullname, section, year_level, course, scanner_access
         FROM users WHERE role='Student' AND status='Active'
         ORDER BY fullname")->fetchAll(PDO::FETCH_ASSOC);
    $guards = $conn->query(
        "SELECT student_id, fullname FROM users
         WHERE role='Guard' AND status='Active' AND student_id IS NOT NULL
         ORDER BY fullname")->fetchAll(PDO::FETCH_ASSOC);

    // bcrypt hashes only — lets the phone verify a staff login offline later.
    $accounts = $conn->query(
        "SELECT username, fullname, role, password
         FROM users WHERE role IN ('Admin','OSA','OSA Staff','Guard') AND status='Active'")
        ->fetchAll(PDO::FETCH_ASSOC);

    $types = $conn->query(
        "SELECT id, violation_name, severity, max_points
         FROM violation_types ORDER BY violation_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('VTS admin provisioning failed: ' . $e->getMessage());
    pv_fail('Unable to load the required data right now. Please try again later.');
}

audit_log($conn, "Provision Device", "users", $user['id'],
    'Scanner device provisioned online by ' . $user['role'] . ' — '
    . count($students) . ' students, ' . count($types) . ' types');

echo json_encode([
    'ok'          => true,
    'app'         => 'VTS-SCANNER',
    'type'        => 'students-list',
    'exported_at' => date('c'),
    'exported_by' => $user['fullname'] . ' (' . $user['role'] . ', online)',
    'students'    => $students,
    'guards'      => $guards,
    'accounts'    => $accounts,
    'types'       => $types,
]);
