<?php
/* Admin: export the USER accounts backup (staff + optionally students).
   Safe columns only — passwords, QR filenames, and verification codes are
   NEVER exported. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (!in_array($_SESSION['role'] ?? '', ['Admin', 'OSA'], true)) { exit("Access Denied"); }

$format = $_GET['format'] ?? 'xlsx';
$scope  = $_GET['scope']  ?? 'staff';   // 'staff' = non-student accounts, 'all' = everyone

$sql = "SELECT student_id, fullname, username, email, role, contact_number,
               gender, college_id, course, year_level, section, status, created_at
        FROM users";
if ($scope === 'staff') $sql .= " WHERE role <> 'Student'";
$sql .= " ORDER BY FIELD(role,'Admin','OSA','OSA Staff','Guard','Student'), fullname";
$rows = [];
foreach ($conn->query($sql) as $u) {
    $rows[] = [
        $u['student_id'] ?? '', $u['fullname'], $u['username'], $u['email'], $u['role'],
        $u['contact_number'] ?? '', $u['gender'] ?? '', $u['course'] ?? '',
        $u['year_level'] ?? '', $u['section'] ?? '', $u['status'], $u['created_at'],
    ];
}
$headers = ['School ID','Full Name','Username','Email','Role','Contact','Gender','Course','Year','Section','Status','Created'];
$fname   = 'VTS_Users_Backup_' . ($scope === 'all' ? 'All' : 'Staff') . '_' . date('Ymd-Hi');

audit_log($conn, "Export Users", "users", null, "scope={$scope}, {$format}, " . count($rows) . " rows");

// Every export in the system is Excel — vts_xlsx_download_multi() only drops to
// CSV by itself if this server can't write .xlsx at all.
vts_xlsx_download_multi($fname . '.xlsx', [[
    'name'    => 'Users',
    'title'   => vts_export_caption($scope === 'all' ? 'All Accounts' : 'Staff Accounts'),
    'headers' => $headers,
    'rows'    => $rows,
]]);
