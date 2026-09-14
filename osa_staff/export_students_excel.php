<?php
/* OSA Staff: student list as Excel — ONE SHEET PER COURSE.

   Downloads the workbook to this computer. ?course=XXX narrows it to a
   single course. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (($_SESSION['role'] ?? '') !== "OSA Staff") {
    exit("Access Denied");
}

$course = trim($_GET['course'] ?? '');      // optional: just one course

$sql = "SELECT u.student_id, u.lastname, u.firstname, u.middlename, u.suffix,
               u.course, u.year_level, u.section, u.email, u.contact_number,
               COALESCE(c.college_name,'') AS dept, u.status, u.created_at
        FROM users u
        LEFT JOIN colleges c ON c.id = u.college_id
        WHERE u.role = 'Student'";
$params = [];
if ($course !== '') { $sql .= " AND u.course = :crs"; $params[':crs'] = $course; }
$sql .= " ORDER BY u.course, u.lastname, u.firstname";

$stmt = $conn->prepare($sql);
$stmt->execute($params);

$headers = ['SCHOOL ID','LAST NAME','FIRST NAME','MIDDLE NAME','SUFFIX','COURSE','YEAR','SET',
            'DEPT','EMAIL','CONTACT','STATUS','REGISTERED'];

// Group by course so every course gets its own sheet (and its own Drive file).
$byCourse = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $key = ($r['course'] ?? '') !== '' ? $r['course'] : 'Unassigned';
    $byCourse[$key][] = [
        $r['student_id'], $r['lastname'], $r['firstname'], $r['middlename'], $r['suffix'],
        $r['course'], $r['year_level'], $r['section'], $r['dept'],
        $r['email'], $r['contact_number'], $r['status'], $r['created_at'],
    ];
}
ksort($byCourse);
if (!$byCourse) $byCourse['No students'] = [];

/* The ?drive=1 upload was removed. Filing straight into Drive needs a
   Google Workspace account -- a service account has no Drive storage of
   its own -- so on this plan every upload came back refused. The export
   downloads; the Drive folder link in the Export menu is the manual
   route. */

audit_log($conn, "Export Students", "users", null,
    count($byCourse) . " course sheet(s)" . ($course !== '' ? " (course={$course})" : ''));

/* ---- Download: ONE workbook that serves both the office and the scanner ----
   Sheet 1 is a flat list of every student with the plain column names the
   offline scanner looks for (student_id / fullname / course / year_level /
   section) and NO caption above them — the scanner reads row 1 as the header.
   The per-course sheets after it are the human-readable ones. */
$scanRows = [];
foreach ($byCourse as $c => $rows) {
    foreach ($rows as $r) {
        // $r = [school id, last, first, middle, suffix, course, year, set, dept, …]
        $full = trim(preg_replace('/\s+/', ' ',
            ($r[2] ?? '') . ' ' . ($r[3] ?? '') . ' ' . ($r[1] ?? '') . ' ' . ($r[4] ?? '')));
        $scanRows[] = [$r[0], $full, $r[5], $r[6], $r[7]];
    }
}
$sheets = [[
    'name'    => 'All Students',
    'headers' => ['student_id', 'fullname', 'course', 'year_level', 'section'],
    'rows'    => $scanRows,
]];
foreach ($byCourse as $c => $rows) {
    $sheets[] = [
        'name'    => mb_substr($c, 0, 31),
        'title'   => vts_export_caption('Student List — ' . $c, ['course' => $c]),
        'headers' => $headers,
        'rows'    => $rows,
    ];
}
vts_xlsx_download_multi('VTS_Student_List_' . ($course !== '' ? preg_replace('/[^A-Za-z0-9]+/', '', $course) . '_' : '') . date('Ymd-Hi') . '.xlsx', $sheets);
