<?php
require_once __DIR__ . '/../auth.php';
require_login();
require_role('rescuer');

header('Content-Type: application/json');

$rescuerId = (int)$_SESSION['user_id'];

try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE rescuer_id = ? AND status = 'unread'");
    $countStmt->execute([$rescuerId]);
    $unreadCount = (int)$countStmt->fetchColumn();

    $latestStmt = $pdo->prepare("
        SELECT id, message, status, created_at
        FROM notifications
        WHERE rescuer_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $latestStmt->execute([$rescuerId]);
    $items = $latestStmt->fetchAll();

    if (isset($_GET['mark_read']) && $_GET['mark_read'] === '1') {
        $updateStmt = $pdo->prepare("UPDATE notifications SET status = 'read' WHERE rescuer_id = ? AND status = 'unread'");
        $updateStmt->execute([$rescuerId]);
        $unreadCount = 0;
    }

    echo json_encode([
        'ok' => true,
        'unread_count' => $unreadCount,
        'notifications' => $items,
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => 'Notifications unavailable',
    ]);
}

