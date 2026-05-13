<?php
require_once 'auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $stmt = $pdo->query("
        SELECT r.id, r.name, r.phone, rl.latitude, rl.longitude, rl.updated_at
        FROM rescuers r
        INNER JOIN rescuer_locations rl ON rl.rescuer_id = r.id
        WHERE rl.status = 'active'
        ORDER BY rl.updated_at DESC
    ");
    echo json_encode(['ok' => true, 'rescuers' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'rescuers' => []]);
}