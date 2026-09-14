<?php
/* Admin: download the violations list as CSV, honouring the Violations page search + date filters (when / from-to / search). */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (($_SESSION['role'] ?? '') !== "OSA Staff") {
    vts_deny_access();
}

$search    = trim($_GET['search'] ?? '');
$when      = $_GET['when'] ?? 'all';
$from      = trim($_GET['from'] ?? '');
$to        = trim($_GET['to'] ?? '');
$yearLevel = trim($_GET['year_level'] ?? '');
$course    = trim($_GET['course'] ?? '');

$where  = [];
$params = [];

if ($search !== '') {
    $where[] = "(u.student_id LIKE :s OR u.fullname LIKE :s OR v.violation LIKE :s)";
    $params[':s'] = "%{$search}%";
}
if ($yearLevel !== '') {
    $where[] = "u.year_level = :yl";
    $params[':yl'] = $yearLevel;
}
if ($course !== '') {
    $where[] = "u.course = :crs";
    $params[':crs'] = $course;
}

/* --- Date window on v.date_reported --- */
$isDate = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
if ($isDate($from) && $isDate($to)) {
    $where[] = "DATE(v.date_reported) BETWEEN :df AND :dt";
    $params[':df'] = $from;
    $params[':dt'] = $to;
    $label = "range_{$from}_to_{$to}";
} else {
    switch ($when) {
        case 'today':
            $where[] = "DATE(v.date_reported) = CURDATE()";
            $label = 'today'; break;
        case 'yesterday':
            $where[] = "DATE(v.date_reported) = CURDATE() - INTERVAL 1 DAY";
            $label = 'yesterday'; break;
        case '7days':
            $where[] = "v.date_reported >= CURDATE() - INTERVAL 6 DAY";
            $label = 'last7days'; break;
        case 'month':
            $where[] = "YEAR(v.date_reported) = YEAR(CURDATE()) AND MONTH(v.date_reported) = MONTH(CURDATE())";
            $label = 'this-month'; break;
        default:
            $label = 'all';
    }
}

$sql = "SELECT u.student_id AS sid, u.fullname, u.course, u.year_level, u.section,
               v.violation, v.offense,
               v.scanner_name, r.fullname AS reporter_name, r.role AS reporter_role,
               v.date_reported
        FROM violations v
        INNER JOIN users u ON v.student_id = u.id
        LEFT JOIN users r ON v.reported_by = r.id";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY v.date_reported DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$fname = 'VTS_Violator_List_' . $label . '_' . date('Ymd-Hi') . '.xlsx';

// OSA Staff export omits the recorder (OSA/Admin-only).
$showRec = vts_can_see_recorder();

$xrows = [];
foreach ($rows as $r) {
    $row = [
        $r['sid'] ?? '',
        $r['fullname'] ?? '',
        $r['course'] ?? '',
        $r['year_level'] ?? '',
        $r['section'] ?? '',
        $r['violation'] ?? '',
        offense_display($r['offense'] ?? ''),
    ];
    if ($showRec) $row[] = violation_recorder($r);
    $row[] = $r['date_reported'] ? date('Y-m-d H:i', strtotime($r['date_reported'])) : '';
    $xrows[] = $row;
}

$headers = ['Student ID','Full Name','Course','Year','Section','Violation','Offense'];
if ($showRec) $headers[] = 'Recorded By';
$headers[] = 'Date';

vts_xlsx_download_multi($fname, [[
    'name'    => 'Violations',
    'title'   => vts_export_caption('Violator List', ['course' => $course], $from, $to),
    'headers' => $headers,
    'rows'    => $xrows,
]], ['kind' => 'violations', 'course' => $course]);
