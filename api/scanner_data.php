<?php
/* Public read-only JSON feed of students + violations, so spck_scanner.html can refresh its offline cache with one online tap. */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(); }

require_once __DIR__ . "/../config/database.php";

$out = ['app' => 'VTS-SCANNER', 'type' => 'lists', 'exported_at' => date('c'),
    'students' => [], 'guards' => [], 'types' => []];

try {
    $guards = $conn->query("SELECT COALESCE(NULLIF(student_id, ''), username) AS student_id, fullname
                FROM users
                WHERE role='Guard' AND status='Active' AND username <> 'scanner.app'
                ORDER BY fullname ASC")->fetchAll(PDO::FETCH_ASSOC);
    $out['guards'] = $guards;
} catch (Throwable $e) { $out['guards'] = []; }

try {
    $st = $conn->query("SELECT student_id, fullname, section, year_level, course, scanner_access
                        FROM users
                        WHERE role='Student' AND status='Active' AND student_id IS NOT NULL
                        ORDER BY fullname ASC");
    $out['students'] = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $out['students'] = []; }

try {
    $vt = $conn->query("SELECT id, violation_name, severity FROM violation_types ORDER BY severity, violation_name");
    $out['types'] = $vt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $out['types'] = []; }

echo json_encode($out);
