<?php
/* Students.csv — the plain, openable student list for the OFFLINE flow.
 *
 * Step 1 of the offline chain:
 *   Admin/OSA exports this -> copies it to the scanner laptop by
 *   USB, LAN, or direct copy -> the scanner imports it and works with no
 *   internet at all.
 *
 * Deliberately NOT encrypted (that's what export_students.php/.vtsl is for):
 * this one has to be readable by the scanner on a machine that may never touch
 * the network, and it carries NO passwords or account hashes — only the roster
 * fields the scanner needs to identify a student.
 */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (!in_array($_SESSION['role'] ?? '', ['Admin', 'OSA', 'OSA Staff'], true)) {
    exit("Access Denied");
}

$st = $conn->prepare("SELECT student_id, fullname, lastname, firstname, middlename,
                             course, year_level, section
                      FROM users
                      WHERE role = 'Student' AND status = 'Active'
                      ORDER BY lastname, firstname");
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$headers = ['student_id','fullname','lastname','firstname','middlename','course','year_level','section'];
$cells   = [];
foreach ($rows as $r) {
    $cells[] = [
        (string)($r['student_id'] ?? ''),
        (string)($r['fullname']   ?? ''),
        (string)($r['lastname']   ?? ''),
        (string)($r['firstname']  ?? ''),
        (string)($r['middlename'] ?? ''),
        (string)($r['course']     ?? ''),
        (string)($r['year_level'] ?? ''),
        (string)($r['section']    ?? ''),
    ];
}

audit_log($conn, "Export Students CSV", "users", null,
    count($cells) . ' active student(s) exported for offline scanning');

vts_csv_download('Students.csv', $headers, $cells);
