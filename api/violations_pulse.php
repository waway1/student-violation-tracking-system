<?php
/* =====================================================================
   "HAS ANYTHING NEW ARRIVED?" — for the Violations page to poll.

   Scans sync in from the gate while the page sits open, so what is on
   screen quietly stops being current and nothing says so. This answers
   that question cheaply: a COUNT and the newest id for the SAME filters
   the page is showing, so a scan outside the current department or date
   range does not set off an alarm about rows the user cannot see.

   It returns numbers only — no student names, no violation text — so the
   polling that happens every few seconds carries nothing sensitive and
   cannot become a data-leak route of its own.

   Session-gated like any other page: a poller is still a reader.
   ===================================================================== */
header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (!in_array($_SESSION['role'] ?? '', ['Admin', 'OSA', 'OSA Staff'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not allowed.']);
    exit();
}

$f = vts_violation_filter($_GET);

$sql = "SELECT COUNT(*) AS n, COALESCE(MAX(v.id), 0) AS max_id
        FROM violations v
        INNER JOIN users u ON v.student_id = u.id";
if ($f['where']) $sql .= " WHERE " . implode(" AND ", $f['where']);

try {
    $st = $conn->prepare($sql);
    $st->execute($f['params']);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'max_id' => 0];
} catch (Throwable $e) {
    error_log('violations_pulse failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not read right now.']);
    exit();
}

echo json_encode([
    'ok'     => true,
    'n'      => (int)$row['n'],
    'max_id' => (int)$row['max_id'],
    'at'     => date('c'),
]);
