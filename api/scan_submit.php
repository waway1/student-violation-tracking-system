<?php
/* Public write endpoint for spck_scanner.html's "Connect Online" mode.

   No password is required — but the marshal is no longer merely trusted.
   The School ID sent with every scan must match an ACTIVE Guard on the
   staff list, and the name filed against the violation is read off that
   record rather than taken from the request, so the device cannot choose
   whose name a scan is recorded under. See vts_resolve_marshal().

   Beyond that the endpoint stays narrow: it can only add a violation for a
   student who already exists on the server, and can't create, edit or
   delete any account, so a live sync still does nothing the USB/.vtsl
   workflow couldn't already do.

   Deliberately reuses import_scan_file() -- the exact function the Head
   Marshal's .vtsl/.csv import already runs -- instead of a second,
   separate insert path. That gets this endpoint the same duplicate-scan
   guard for free: if a record synced live here AND the .vtsl file gets
   imported later as a backup, it will not be double-counted. */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(); }

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/functions.php";

function reply($arr) { echo json_encode($arr); exit(); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply(['ok' => false, 'error' => 'POST required.']);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) reply(['ok' => false, 'error' => 'Invalid request body.']);

$sid       = trim((string)($in['student_id'] ?? ''));
$violation = trim((string)($in['violation_name'] ?? ''));
if ($sid === '' || $violation === '') {
    reply(['ok' => false, 'error' => 'Missing student or violation.']);
}

/* ---- WHO IS RECORDING THIS? ----
   The device used to send whatever the marshal typed on the Duty screen and
   this endpoint wrote it straight into violations.scanner_name, so a scan
   could be filed under a colleague's name, or one that does not exist. The
   staff list decides now.

   This check lives HERE and not only in the phone's UI on purpose: the
   scanner is a plain HTML page anybody can save, edit and re-post from, so
   a browser-side check is a convenience for honest users, not a control.
   The name that gets stored is the one on the staff record -- never the
   typed one -- so the office sees a consistent name no matter how it was
   entered on a phone keyboard. */
$scannerSid   = trim((string)($in['scanner_school_id'] ?? ''));
$scannerTyped = trim((string)($in['scanner_name'] ?? ''));
$sessionToken = trim((string)($in['scanner_session_token'] ?? ''));

if ($scannerSid === '') {
    reply(['ok' => false, 'fatal' => true,
           'error' => 'This device has not said who is on duty. Open "Who is on duty?" and enter your School ID.']);
}

$marshal = vts_resolve_marshal($conn, $scannerSid, $scannerTyped);
if (!$marshal) {
    /* The phone can only say "not on the staff list". The server can see WHY,
       and the commonest cause by far is somebody entering the School ID of
       their STUDENT record -- which looks like a perfectly good ID and is
       simply the wrong account. Naming that saves a support round-trip. */
    $why = '';
    try {
        $q = $conn->prepare("SELECT fullname, role, status FROM users
                              WHERE UPPER(student_id) = :a OR UPPER(username) = :b LIMIT 1");
        $q->execute([':a' => strtoupper($scannerSid), ':b' => strtoupper($scannerSid)]);
        if ($found = $q->fetch(PDO::FETCH_ASSOC)) {
            /* Three different problems wear the same "refused" badge, and the
               fix differs for each: a wrong name is the person mistyping, an
               inactive account needs the OSA, a disallowed role needs a
               different account entirely. Saying which saves a guess. */
            if (($found['status'] ?? '') !== 'Active') {
                $why = ' That account (' . $found['fullname'] . ') is '
                     . strtolower((string)$found['status']) . ' — ask the OSA to reactivate it.';
            } elseif (!in_array($found['role'], VTS_SCANNER_ROLES, true)) {
                $why = ' That ID belongs to a ' . $found['role'] . ' account, which cannot record violations.';
            } else {
                /* Role and status are fine, so the NAME is what did not match. */
                $why = ' That ID is registered to ' . $found['fullname']
                     . ' — enter your own name exactly as the school holds it.';
            }
        }
    } catch (Throwable $e) { /* explanation is a nicety, never fail on it */ }
    /* fatal: re-sending the same rejected identity forever helps nobody, so
       the client is told to stop and show this instead of silently queueing. */
    reply(['ok' => false, 'fatal' => true,
           'error' => 'School ID ' . $scannerSid . ' does not match an active marshal'
                    . ($scannerTyped !== '' ? ' named "' . $scannerTyped . '"' : '')
                    . '.' . ($why !== ''
                         ? $why
                         : ' Ask the OSA to add you to the staff list, then sign in again.')]);
}
$scannerName = (string)$marshal['fullname'];
$scannerId   = (string)$marshal['staff_id'];

$session = $conn->prepare("SELECT scanner_session_token FROM users WHERE id=:id
    AND scanner_session_expires > NOW()");
$session->execute([':id' => $marshal['id']]);
if ($sessionToken === '' || !hash_equals((string)$session->fetchColumn(), $sessionToken)) {
    reply(['ok' => false, 'fatal' => true, 'relogin' => true,
        'error' => 'This account is active on another scanner, or its shift has ended. Enter your School ID again to continue here.']);
}

// import_scan_file() reads a file path, so hand it the same JSON shape
// finishSession()'s .vtsl/.json export already uses -- one record.
/* Both names come from the staff record resolved above, not from the
   request body, so the stored label cannot be chosen by the device. */
$payload = [
    'app' => 'VTS-SCANNER', 'type' => 'violations',
    'scanner_name' => $scannerName,
    'records' => [[
        'student_id'     => $sid,
        'violation_name' => $violation,
        // Free text the marshal typed for an "Others". Kept as the record's
        // description rather than folded into the violation NAME, so picking
        // Others does not mint a new violation type per incident.
        'violation_detail' => mb_substr(trim((string)($in['violation_detail'] ?? '')), 0, 200),
        'severity'       => (string)($in['severity'] ?? ''),
        'scanner_name'   => $scannerName,
        'scanner_role'   => 'Marshal · School ID ' . $scannerId,
        'scanned_at'     => (string)($in['scanned_at'] ?? ''),
        /* The proof photo the scanner captured after its cooldown, as a
           base64 frame. Passed straight through: import_scan_file() is the
           one place that turns it into a file on disk, so the live route and
           a hand-carried .vtsl produce the same record rather than each
           writing evidence its own way.

           NOT required here. The web forms refuse a violation without proof,
           but a gate device can be an older build, or a phone whose camera
           permission was refused mid-shift — and a scan that really happened
           must reach the office either way. It arrives with no photo and
           shows as "No proof attached" on admin/proof.php, which is a
           visible gap the OSA can act on rather than a silently lost record. */
        'photo'          => (string)($in['photo'] ?? ''),
    ]],
];

$tmp = tempnam(sys_get_temp_dir(), 'vts_sync_');
if ($tmp === false) reply(['ok' => false, 'error' => 'Server could not process that (temp file).']);
file_put_contents($tmp, json_encode($payload));

try {
    $result = import_scan_file($conn, $tmp, 'live-sync.json', 0);
    // Show live syncs in the same "Recent scan imports" panel as file
    // imports, so the office sees ONE history covering both routes. Rolls
    // up per scanner per day inside log_import(), so a busy shift is one
    // running row rather than hundreds of single-scan entries.
    log_import($conn, 0, '', $result, 'online');
} catch (Throwable $e) {
    error_log('scan_submit.php failed: ' . $e->getMessage());
    reply(['ok' => false, 'error' => 'Server error — it will stay queued and retry.']);
} finally {
    @unlink($tmp);
}

if (!$result['ok']) {
    reply(['ok' => false, 'error' => $result['error'] ?: 'Could not save that violation.']);
}

$r = $result['results'];
if (($r['imported'] ?? 0) >= 1) {
    reply(['ok' => true, 'imported' => true]);
}
// "Skipped" here almost always means: already synced before (duplicate
// guard), unknown/inactive student id, or an unrecognized violation name
// that also failed auto-create. Either way the client should stop
// re-sending it forever, so treat a clean skip as done-enough too.
reply(['ok' => true, 'imported' => false, 'note' => ($r['lines'][0] ?? 'Skipped (likely already synced).')]);
