<?php
/* API (JSON): staff-only searchable student directory. */
require_once "../auth/session.php";
require_once "../config/database.php";
header("Content-Type: application/json");

// Staff-only: this is a searchable directory of every student's name, ID,
// course, and email — it must never be reachable by an anonymous request.
$staffRoles = ['Admin', 'OSA', 'OSA Staff', 'Guard'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $staffRoles, true)) {
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Authentication required."]);
    exit();
}

$search = $_GET['search'] ?? "";

$sql = "
SELECT
id,
student_id,
fullname,
course,
year_level,
email
FROM users
WHERE role='Student'
";

$params = [];

if (!empty($search)) {
    $sql .= "
    AND (
        student_id LIKE :search
        OR fullname LIKE :search
        OR course LIKE :search
    )";
    $params[':search'] = "%".$search."%";
}

$sql .= " ORDER BY fullname ASC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);

echo json_encode([
    "success" => true,
    "count" => $stmt->rowCount(),
    "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)
]);
?>
