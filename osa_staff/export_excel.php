<?php
/* OSA Staff: export violations to an Excel (.xlsx) file. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (($_SESSION['role'] ?? '') !== "OSA Staff") {
    exit("Access Denied");
}

$when    = is_string($_GET['when'] ?? null) ? trim($_GET['when']) : 'all';
$from    = trim((string)($_GET['from'] ?? ''));
$to      = trim((string)($_GET['to'] ?? ''));
$student = trim((string)($_GET['search'] ?? ($_GET['student'] ?? '')));
$collegeId = trim((string)($_GET['college_id'] ?? $_GET['college'] ?? ''));

if (($when === 'today' || $when === 'yesterday' || $when === '7days' || $when === 'month') && $from === '' && $to === '') {
    $today = new DateTime('today');
    switch ($when) {
        case 'today':     $from = $today->format('Y-m-d'); $to = $from; break;
        case 'yesterday': $y = (clone $today)->modify('-1 day'); $from = $y->format('Y-m-d'); $to = $from; break;
        case '7days':     $from = (clone $today)->modify('-6 days')->format('Y-m-d'); $to = $today->format('Y-m-d'); break;
        case 'month':     $from = $today->format('Y-m-01'); $to = $today->format('Y-m-t'); break;
    }
}

// Same report filters as the Reports page, so an export matches what's on screen.
$filters = [
    'course'     => trim($_GET['course']     ?? ''),
    'section'    => trim($_GET['section']    ?? ''),
    'year_level' => trim($_GET['year_level'] ?? ''),
    'violation'  => trim($_GET['violation']  ?? ''),
    'college_id' => $collegeId,
];
// Department name (for the caption printed above the sheet).
if ($collegeId !== '') {
    $cs = $conn->prepare("SELECT college_name FROM colleges WHERE id = :id");
    $cs->execute([':id' => (int)$collegeId]);
    $filters['dept'] = (string)$cs->fetchColumn();
}

$rangeLabel = ($from !== '' || $to !== '') ? ($from ?: 'Start') . '_to_' . ($to ?: 'Now') : 'AllDates';
$deptLabel = ($filters['dept'] ?? '') !== '' ? preg_replace('/[^A-Za-z0-9]+/', '_', trim((string)$filters['dept'])) : 'AllDepartments';
$courseLabel = trim((string)($filters['course'] ?? '')) !== '' ? preg_replace('/[^A-Za-z0-9]+/', '_', trim((string)$filters['course'])) : '';
$fname = 'VTS_Violation_Report_' . ($courseLabel !== '' ? 'Course_' . $courseLabel . '_' : ($collegeId !== '' ? 'Dept_' . $deptLabel . '_' : '')) . $rangeLabel . '_' . date('Ymd-Hi');

// ---- Last-resort CSV (only if this server can't write .xlsx at all) —
//      same official columns, one row per student ----
if (!vts_xlsx_writable()) {
    $sql = "SELECT u.student_id, u.lastname, u.firstname, u.middlename, u.fullname,
                   u.year_level, u.section, u.course,
                   COALESCE(col.college_name, u.course, '') AS dept,
                   v.violation, v.offense, v.date_reported
            FROM violations v
            INNER JOIN users u ON v.student_id = u.id
            LEFT JOIN colleges col ON col.id = u.college_id
            WHERE u.role = 'Student'";
    $params = [];
    if ($from !== '')    { $sql .= " AND DATE(v.date_reported) >= :from"; $params[':from'] = $from; }
    if ($to !== '')      { $sql .= " AND DATE(v.date_reported) <= :to";   $params[':to'] = $to; }
    if ($student !== '') { $sql .= " AND (u.student_id LIKE :student OR u.fullname LIKE :student OR v.violation LIKE :student)"; $params[':student'] = "%{$student}%"; }
    $sql .= vts_report_filter_sql($filters, $params);
    $sql .= " ORDER BY u.lastname, u.firstname, v.date_reported ASC";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $students = vts_official_group_students($stmt->fetchAll(PDO::FETCH_ASSOC));
    vts_csv_download($fname . '.csv', vts_official_flat_headers(), vts_official_row_cells($students));
    exit();
}

// ---- .xlsx ----
// split=dept|course  -> ONE SHEET PER DEPARTMENT (or course), official layout.
// no split           -> the usual Major / Minor categorized workbook.
/* ONE export button, filter-aware:
     - a Department or Course filter is set -> ONE sheet for exactly that
     - nothing filtered                     -> ONE SHEET PER DEPARTMENT
   Either way the layout is the official sheet and the top of each sheet
   carries the department name and the date range. */
$split = trim($_GET['split'] ?? '');
// Default is ALWAYS the official sheet grouped by department. With a
// Department/Course filter applied that naturally yields ONE sheet for
// exactly what is on screen; with no filter it yields one per department.
// (severity=1 still gives the old Major/Minor workbook if ever needed.)
if ($split === '') { $split = (trim($_GET['severity'] ?? '') !== '') ? 'none' : 'dept'; }
if ($split === 'dept' || $split === 'course') {
    $sheets = vts_build_split_sheets($conn, $split, $from, $to, $student, $filters);
    $fname = 'VTS_Official_Sheet_' . ($collegeId !== '' ? 'Dept_' . $deptLabel : ($split === 'dept' ? 'By_Department' : 'By_Course')) . '_' . $rangeLabel . '_' . date('Ymd-Hi');
} else {
    $sheets = vts_build_categorized_sheets($conn, $from, $to, $student, $filters);
    $fname = 'VTS_Official_Sheet_' . ($collegeId !== '' ? 'Dept_' . $deptLabel : 'AllDepartments') . '_' . $rangeLabel . '_' . date('Ymd-Hi');
}
vts_xlsx_download_multi($fname . '.xlsx', $sheets);
