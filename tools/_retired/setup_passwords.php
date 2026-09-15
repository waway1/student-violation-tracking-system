<?php
/* One-time script: sets each seeded demo account password to password123.
   Run once after importing the .sql, then delete this file.

   ------------------------------------------------------------------
   SAFETY GUARD (added 2026-09-11)
   This file was still sitting in the web root, reachable at
   /setup_passwords.php, long after setup. Anyone who loaded that URL --
   no login required -- reset admin, osa and ten other accounts to
   "password123" and owned the system.

   It now refuses to run unless you deliberately enable it. To use it:
     1. set VTS_ALLOW_PASSWORD_RESET below to true
     2. load the page
     3. set it back to false (or delete this file, which is better)
   It also refuses outright unless the request comes from this machine,
   so even enabled it cannot be triggered from outside.
   ------------------------------------------------------------------ */

const VTS_ALLOW_PASSWORD_RESET = false;

$vtsLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
if (!VTS_ALLOW_PASSWORD_RESET || !$vtsLocal) {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . "/config/database.php";

$defaultPassword = "password123";
$hash = password_hash($defaultPassword, PASSWORD_DEFAULT);

$usernames = ['veronica', 'admin', 'osa', 'osa.staff', 'dean',
              'dean.cit', 'dean.cba', 'dean.coe', 'dean.crim',
              'james', 'elena', 'scanner.app'];

$done = [];
$missing = [];

foreach ($usernames as $u) {
    $stmt = $conn->prepare("UPDATE users SET password = :p WHERE username = :u");
    $stmt->execute([':p' => $hash, ':u' => $u]);
    if ($stmt->rowCount() > 0) {
        $done[] = $u;
    } else {
        // Row may already have this hash, or the account doesn't exist
        $chk = $conn->prepare("SELECT id FROM users WHERE username = :u");
        $chk->execute([':u' => $u]);
        if ($chk->fetch()) { $done[] = $u . " (already set)"; }
        else { $missing[] = $u; }
    }
}

header('Content-Type: text/html; charset=utf-8');
echo "<div style='font-family:Inter,Arial,sans-serif;max-width:560px;margin:60px auto;padding:28px 30px;border:1px solid #dde4f0;border-radius:12px;box-shadow:0 4px 20px rgba(26,58,107,.15)'>";
echo "<h2 style='color:#1a3a6b;margin:0 0 10px'>SVTMS — Password Setup</h2>";
echo "<p style='color:#1b7f46;font-weight:600'>All demo passwords set to <code>password123</code>.</p>";
echo "<p style='color:#1a2340'>Updated accounts: <b>" . htmlspecialchars(implode(', ', $done)) . "</b></p>";
if ($missing) {
    echo "<p style='color:#e03535'>Not found (import the .sql first): " . htmlspecialchars(implode(', ', $missing)) . "</p>";
}
echo "<hr style='border:none;border-top:1px solid #eef2f8;margin:18px 0'>";
echo "<p style='color:#e07a10;font-weight:600'>⚠ For security, delete this file (setup_passwords.php) now.</p>";
echo "<p style='font-size:.85rem;color:#5a6a8a'>You can now log in:<br>
   • Student — <b>veronica</b><br>
   • Admin — <b>admin</b><br>
   • OSA — <b>osa</b><br>
   • OSA Staff — <b>osa.staff</b><br>
   • Guard/Marshal — <b>james</b></p>";
echo "</div>";
