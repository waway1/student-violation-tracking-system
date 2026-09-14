<?php
/* Full logout: clears session data and the session cookie, then redirects to login via an absolute app path. */
require_once __DIR__ . "/auth/session.php";   // hardened session start (strict mode, httponly/samesite cookie, fresh ID on first use) + security headers

// 1. Clear all session variables
$_SESSION = [];

// 2. Delete the session cookie itself
if (ini_get("session.use_cookies")) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
}

// 3. Destroy the session on the server
session_destroy();

// 4. Redirect to login using an absolute path to THIS app's folder,
//    so it works from / or any subfolder.
$dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');   // e.g. /SAD or /SAD/admin
// If logout.php lives at the app root, $dir is the app root. If a copy is
// ever called from a subfolder, climb one level to the app root.
$base = $dir;
if (basename($dir) !== '' && in_array(basename($dir), ['admin','student','guard','osa','dean','headmarshal','auth'])) {
    $base = dirname($dir);
}
// Back to the very first page (Welcome) with a logout-success pop-up.
header("Location: " . $base . "/index.php?logout=1");
exit();
