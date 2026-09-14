<?php
/* JSON: current scanning on/off state + who is on duty right now. Polled by
   the on-duty panel on the Settings page (includes/on_duty_panel.php — it
   used to be a sidebar widget) so the roster reflects a marshal signing on or
   off without a page reload — the "should pop up" ask. Read-only, no CSRF
   needed. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

header('Content-Type: application/json');
/* ADMIN ONLY, matching the page that polls it. */
if (($_SESSION['role'] ?? '') !== 'Admin') {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit();
}

$roster = array_map(static function ($r) {
    return [
        // id is what the panel's sign-off form posts back; this endpoint is
        // Admin/OSA-only, so it is not exposed to anyone who couldn't already
        // see the same roster on the page.
        'id'        => (int)$r['id'],
        'name'      => $r['fullname'],
        'school_id' => $r['student_id'] ?: $r['username'],
        'role'      => $r['role'],
    ];
}, vts_on_duty_roster($conn));

echo json_encode([
    'ok'          => true,
    'enabled'     => vts_scanning_enabled($conn),
    // The schedule travels with the roster so the panel can say "Closed ·
    // opens Monday at 7:30 AM" without a second request or its own clock —
    // and without duplicating the window rules in JavaScript.
    'window_open' => vts_duty_window_open(),
    'hours'       => vts_duty_window_label(),
    'next_open'   => vts_duty_next_open(),
    'roster'      => $roster,
]);
