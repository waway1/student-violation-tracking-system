<?php
/* Admin/OSA: end someone's scanner shift from the sidebar, freeing their slot.
   There are only 2, so without this a marshal who walks off with a live
   session blocks the next one for up to 8 hours — and the refusal the third
   person sees ("ask the office to sign one off") has to mean something. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

/* ADMIN ONLY, matching admin/setting.php where the only form that posts here
   lives. Leaving this at Admin + OSA would have left the control reachable by
   posting straight at the URL after the page around it was locked down. */
if (($_SESSION['role'] ?? '') !== 'Admin') vts_deny_access();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    vts_redirect_back('dashboard.php', 'error', 'Session expired. Please try again.');
    exit();
}

$userId = (int)($_POST['user_id'] ?? 0);
if ($userId <= 0) {
    vts_redirect_back('dashboard.php', 'error', 'No marshal selected.');
    exit();
}

$who = $conn->prepare("SELECT fullname, COALESCE(NULLIF(student_id,''), username) AS ident FROM users WHERE id = :id LIMIT 1");
$who->execute([':id' => $userId]);
$row = $who->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    vts_redirect_back('dashboard.php', 'error', 'That account no longer exists.');
    exit();
}

vts_release_duty_slot($conn, $userId);
audit_log($conn, 'Scanner Sign-Off', 'users', $userId,
    $row['fullname'] . ' (' . $row['ident'] . ') was signed off the scanner by user #' . (int)($_SESSION['user_id'] ?? 0));

/* Tell them, so a shift ending under them is not a silent mystery on the
   phone — the scanner's own session check will bounce them to the duty
   screen within its next poll either way. */
try {
    notify_once($conn, $userId, 'Your scanner shift was ended',
        'The Office of Student Affairs signed you off the scanner. Any scans still on the phone are safe and will sync or export as normal.');
} catch (Throwable $e) { /* the sign-off itself already succeeded */ }

vts_redirect_back('dashboard.php', 'success', $row['fullname'] . ' was signed off the scanner.');
