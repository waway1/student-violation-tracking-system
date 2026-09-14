<?php
/* Admin/OSA: transfer the (filtered) violation report to CHED — the final hop
   of the chain: Guard/Marshal → Admin/OSA → CHED. Emails a CSV +
   summary to the CHED inbox; falls back to a downloadable CSV if mail is off. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";
$HAS_MAIL = @include_once "../includes/mailer.php";

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['Admin','OSA'], true)) { vts_deny_access(); }

$backPage = '../admin/reports.php'; // Admin and OSA both use the same admin/ pages now.

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    header("Location: $backPage?error=" . urlencode('Session expired. Please try again.'));
    exit();
}

// Same filters as the reports page.
$from    = trim($_POST['from'] ?? '');
$to      = trim($_POST['to'] ?? '');
$student = trim($_POST['student'] ?? '');

$sql = "SELECT u.student_id AS sid, u.fullname AS name, u.course, u.year_level AS year,
               v.violation, v.severity, v.offense, v.date_reported
        FROM violations v INNER JOIN users u ON v.student_id = u.id WHERE 1=1";
$params = [];
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $sql .= " AND DATE(v.date_reported) >= :from"; $params[':from'] = $from; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $sql .= " AND DATE(v.date_reported) <= :to";   $params[':to'] = $to; }
if ($student !== '') { $sql .= " AND u.fullname LIKE :st"; $params[':st'] = "%{$student}%"; }
$sql .= " ORDER BY v.date_reported DESC";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$raw) {
    header("Location: $backPage?error=" . urlencode('No records match the current filters — nothing to transfer.'));
    exit();
}

$rows = array_map(fn($r) => [
    'sid' => $r['sid'], 'name' => $r['name'], 'course' => $r['course'], 'year' => $r['year'],
    'violation' => $r['violation'], 'severity' => $r['severity'],
    'offense' => offense_display($r['offense']), 'date' => vts_datetime($r['date_reported']),
], $raw);

$rangeLabel = ($from !== '' || $to !== '') ? (($from ?: 'start') . ' to ' . ($to ?: 'now')) : 'all dates';
$byName = $_SESSION['fullname'] ?? $role;

$sent = false;
if ($HAS_MAIL && function_exists('send_violation_report_email') && defined('CHED_REPORT_EMAIL')) {
    $sent = send_violation_report_email(CHED_REPORT_EMAIL, defined('CHED_REPORT_NAME') ? CHED_REPORT_NAME : 'CHED',
        $byName . ' (' . $role . ')', $rows, $rangeLabel);
}

audit_log($conn, "Transfer to CHED", "violations", null, count($rows) . " records ({$rangeLabel}); emailed=" . ($sent ? 'yes' : 'no'));

if ($sent) {
    header("Location: $backPage?success=" . urlencode('Transferred ' . count($rows) . ' record(s) to CHED (' . CHED_REPORT_EMAIL . ').'));
    exit();
}
// Mail unavailable — hand back the Excel file so the transfer can still happen manually.
$headers = ['School ID','Name','Course','Year','Violation','Severity','Offense','Date'];
$out = array_map(fn($r) => [$r['sid'],$r['name'],$r['course'],$r['year'],$r['violation'],$r['severity'],$r['offense'],$r['date']], $rows);
vts_xlsx_download_multi('CHED_Report_' . date('Ymd-Hi') . '.xlsx', [[
    'name'    => 'CHED Report',
    'title'   => vts_export_caption('CHED Report'),
    'headers' => $headers,
    'rows'    => $out,
]]);
