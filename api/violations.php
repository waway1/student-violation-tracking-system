<?php
/* API (JSON): staff-only student violation history. */
require_once "../auth/session.php";
require_once "../config/database.php";
header("Content-Type: application/json");

// Staff-only: this returns student names/IDs and violation history, so it
// must never be reachable by an anonymous request.
$staffRoles = ['Admin', 'OSA', 'OSA Staff', 'Guard'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $staffRoles, true)) {
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Authentication required."]);
    exit();
}

$status = $_GET['status'] ?? "";

$sql = "
SELECT
violations.id,
users.student_id,
users.fullname,
violations.violation,
violations.offense,
violations.status,
violations.date_reported

FROM violations

INNER JOIN users
ON violations.student_id = users.id

WHERE 1=1
";

$params = [];

if (!empty($status)) {
    $sql .= " AND violations.status = :status";
    $params[':status'] = $status;
}

$sql .= " ORDER BY violations.date_reported DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);

echo json_encode([
    "success" => true,
    "count" => $stmt->rowCount(),
    "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)
]);
?>
