<?php
/* Auth guard: require a logged-in user, else redirect to login. */
require_once __DIR__ . "/session.php";

// Not logged in at all -> back to login (path is worked out dynamically,
// so this is correct from root pages AND from admin/ osa/ dean/ student/).
if (!isset($_SESSION['user_id'])) {
    header("Location: " . vts_login_url("Please log in to continue."));
    exit();
}

// Logged in but no role -> broken session, clear it out.
if (!isset($_SESSION['role'])) {
    vts_kill_session("Please log in again.");
}
