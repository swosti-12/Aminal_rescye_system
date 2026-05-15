<?php
require_once 'auth.php';
require_role('rescuer');

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);

$status = isset($data['status']) ? (int)$data['status'] : 0;

$stmt = $pdo->prepare("UPDATE users SET availability_status=? WHERE id=?");

if ($stmt->execute([$status, $_SESSION['user_id']])) {
    echo json_encode(["success" => true]);
} else {
    echo json_encode(["success" => false]);
}