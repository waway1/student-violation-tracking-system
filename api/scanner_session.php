<?php
/* Who's on duty at the scanner. See the "ON-DUTY MARSHAL SYSTEM" block in
   includes/functions.php for the full design: any active Student or Guard
   account may claim a slot now (no admin pre-approval), but at most 2 can
   be on duty AT ONCE, system-wide — a 3rd distinct claimant is refused and
   the office is notified by name.

   One active connected scanner per marshal ACCOUNT is still true underneath
   this: claiming rotates that account's own token, so a second phone signed
   into the SAME account replaces the first one, same as before. */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

function scanner_session_reply(array $data): void { echo json_encode($data); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') scanner_session_reply(['ok' => false, 'error' => 'This scanner check must be sent by the app.']);

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) scanner_session_reply(['ok' => false, 'error' => 'The scanner sent an unreadable request. Try again.']);

$schoolId = trim((string)($in['school_id'] ?? ''));
$mode = (string)($in['mode'] ?? '');
if ($schoolId === '') scanner_session_reply(['ok' => false, 'error' => 'Enter your School ID first.']);

/* No `scanner_access=1` requirement for Student any more — that column
   used to be the whole gate (admin hand-picks exactly 2 accounts, forever).
   Any active Student can now attempt a shift; vts_claim_duty_slot() is
   what actually enforces the "2 at once" limit, dynamically. */
$account = $conn->prepare("SELECT id, fullname FROM users
    WHERE (student_id=:id OR (role='Guard' AND username=:id))
      AND status='Active'
      AND role IN ('Guard','Student') LIMIT 1");
$account->execute([':id' => $schoolId]);
$user = $account->fetch(PDO::FETCH_ASSOC);
if (!$user) scanner_session_reply(['ok' => false, 'relogin' => true,
    'error' => 'That School ID does not match an active student or guard account.']);

if ($mode === 'claim') {
    [$ok, $msg, $token] = vts_claim_duty_slot($conn, (int)$user['id'], (string)$user['fullname'], $schoolId);
    /* NOT 'relogin': every one of these is a "come back later", not a "your
       identity is wrong" — re-entering a School ID fixes none of them, and
       the phone must not send someone round the sign-in loop over a closed
       gate. The message already says which it is and what to do. */
    if (!$ok) scanner_session_reply(['ok' => false, 'refused' => true, 'error' => $msg]);
    scanner_session_reply(['ok' => true, 'session_token' => $token, 'name' => $user['fullname']]);
}

if ($mode === 'release') {
    vts_release_duty_slot($conn, (int)$user['id']);
    scanner_session_reply(['ok' => true]);
}

if ($mode === 'check') {
    /* THE MASTER SWITCH IS CHECKED FIRST, and it is checked here at all for
       two reasons. Turning it off already clears every live token
       (vts_set_scanning_enabled), so this would refuse anyway — but it would
       refuse with the wrong sentence, and "opened on another scanner" sends a
       marshal hunting for a second phone that does not exist. It is also the
       backstop: if the release ever failed halfway, the flag alone still ends
       the shift on the next poll rather than leaving someone scanning against
       a gate the office believes is shut. */
    if (!vts_scanning_enabled($conn)) {
        scanner_session_reply(['ok' => false, 'relogin' => true,
            'error' => 'Scanning has been switched off by the office, so your shift ended. '
                     . 'Scans already on this phone are safe. Sign on again once it is back on.']);
    }

    $token = (string)($in['session_token'] ?? '');
    $stored = $conn->prepare("SELECT scanner_session_token FROM users WHERE id=:id
        AND scanner_session_expires > NOW()");
    $stored->execute([':id' => $user['id']]);
    $valid = $token !== '' && hash_equals((string)$stored->fetchColumn(), $token);
    scanner_session_reply($valid ? ['ok' => true] : ['ok' => false, 'relogin' => true,
        'error' => 'Your shift has ended — the office signed you off, or this account was opened '
                 . 'on another scanner. Enter your School ID again to continue here.']);
}

scanner_session_reply(['ok' => false, 'error' => 'Unknown scanner session request.']);
