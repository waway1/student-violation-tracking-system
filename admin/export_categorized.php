<?php
/* Admin: categorized backup — student violations grouped into one sheet PER
   DEPARTMENT (course/program), BSCRIM-style, with optional date + violation
   filters. Falls back to CSV of the first department where .xlsx can't write. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (!in_array($_SESSION['role'] ?? '', ['Admin', 'OSA'], true)) { exit("Access Denied"); }

$from      = $_GET['from'] ?? '';
$to        = $_GET['to'] ?? '';
$violation = $_GET['violation'] ?? '';
$format    = $_GET['format'] ?? 'xlsx';

$rangeLabel = ($from !== '' || $to !== '') ? ($from ?: 'Start') . '_to_' . ($to ?: 'Now') : 'AllDates';
$fname = 'VTS_ByDepartment_' . $rangeLabel . '_' . date('Ymd-Hi');

$sheets = vts_build_department_sheets($conn, $from, $to, $violation);

audit_log($conn, "Export By Department", "violations", null,
    count($sheets) . " department sheet(s); violation=" . ($violation ?: 'all'));

// Always Excel — one sheet per department. (vts_xlsx_download_multi() falls back
// to a flat CSV of the first sheet only if this server can't write .xlsx.)
vts_xlsx_download_multi($fname . '.xlsx', $sheets);
