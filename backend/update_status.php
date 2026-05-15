<?php
require_once 'auth.php';

header('Content-Type: application/json');

require_role('rescuer');

$data = json_decode(file_get_contents("php://input"), true);

$status = $data['status'] ?? '';

$allowed = ['available', 'busy', 'offline'];

if (!in_array($status, $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit;
}

$stmt = $pdo->prepare("UPDATE users SET availability_status = ? WHERE id = ?");
$success = $stmt->execute([$status, $_SESSION['user_id']]);

echo json_encode(['success' => $success]);