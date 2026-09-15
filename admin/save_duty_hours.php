<?php
/* Admin/OSA sidebar: save the scanning duty schedule (which days, and the
   opening/closing time). See vts_set_duty_schedule() for the validation —
   a window with no days, or one that closes before it opens, would shut the
   gate permanently, so both are refused there rather than here. */
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

[$ok, $message] = vts_set_duty_schedule(
    $conn,
    (array)($_POST['days'] ?? []),
    (string)($_POST['start'] ?? ''),
    (string)($_POST['end'] ?? ''),
    (int)($_SESSION['user_id'] ?? 0)
);
vts_redirect_back('dashboard.php', $ok ? 'success' : 'error', $message);
