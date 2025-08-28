<?php
include 'connection.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['group_name'])) {
    $group_name = trim($_POST['group_name']);
    $response = ['success' => false, 'group' => null];

    $stmt = $conn->prepare("
        SELECT g.name, GROUP_CONCAT(s.full_name SEPARATOR ', ') AS members
        FROM groups g
        LEFT JOIN group_members gm ON g.id = gm.group_id
        LEFT JOIN students s ON gm.student_id = s.id
        WHERE g.name = ?
        GROUP BY g.id, g.name
    ");
    if ($stmt) {
        $stmt->bind_param("s", $group_name);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $response['success'] = true;
            $response['group'] = [
                'name' => $row['name'],
                'members' => $row['members'] ?? 'None'
            ];
        }
        $stmt->close();
    } else {
        $response['error'] = "Prepare failed: " . $conn->error;
    }

    echo json_encode($response);
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
}
?>