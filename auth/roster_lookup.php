<?php
/* Registration helper: find a student's PRE-ENROLLED record (student_roster)
   by School ID or name so they can claim their account without re-typing
   everything. Public (pre-login) but read-only, needs 3+ characters, and
   returns at most 8 rows. */
require_once __DIR__ . "/session.php";   // hardened session start (strict mode, httponly/samesite cookie, fresh ID on first use) + security headers
require_once "../config/database.php";

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 3) {
    echo json_encode(['ok' => false, 'error' => 'Type at least 3 characters of your School ID or name.']);
    exit();
}

try {
    $stmt = $conn->prepare(
        "SELECT school_id, lastname, firstname, course, year_level, is_used
         FROM student_roster
         WHERE school_id LIKE :q1 OR lastname LIKE :q2 OR firstname LIKE :q3
         ORDER BY is_used ASC, lastname ASC, school_id ASC
         LIMIT 8");
    $like = $q . '%';
    $stmt->execute([':q1' => $like, ':q2' => $like, ':q3' => $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Lookup unavailable right now. Please try again.']);
    exit();
}

$matches = [];
foreach ($rows as $r) {
    $matches[] = [
        'school_id'  => $r['school_id'],
        'lastname'   => $r['lastname']   ?? '',
        'firstname'  => $r['firstname']  ?? '',
        'course'     => $r['course']     ?? '',
        'year_level' => $r['year_level'] ?? '',
        'used'       => (int)$r['is_used'] === 1,
    ];
}

echo json_encode(['ok' => true, 'matches' => $matches]);
