<?php
require_once __DIR__ . '/backend/auth.php';
require_login();
require_role('admin');
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: manage_rescuer.php');
    exit;
}

$requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT) ?: 0;
$rescuerId = filter_input(INPUT_POST, 'rescuer_id', FILTER_VALIDATE_INT) ?: 0;

if ($requestId <= 0 || $rescuerId <= 0) {
    header('Location: manage_rescuer.php?err=' . urlencode('Invalid request or rescuer selected.'));
    exit;
}

try {
    $pdo->beginTransaction();

    $rescuerStmt = $pdo->prepare('SELECT id, name, status FROM rescuers WHERE id = ? FOR UPDATE');
    $rescuerStmt->execute([$rescuerId]);
    $rescuer = $rescuerStmt->fetch();
    if (!$rescuer) {
        throw new RuntimeException('Rescuer not found.');
    }
    if ($rescuer['status'] !== 'available') {
        throw new RuntimeException('Selected rescuer is not currently available.');
    }

    $requestStmt = $pdo->prepare('SELECT id, user_name, status FROM rescue_requests WHERE id = ? FOR UPDATE');
    $requestStmt->execute([$requestId]);
    $request = $requestStmt->fetch();
    if (!$request) {
        throw new RuntimeException('Rescue request not found.');
    }
    if ($request['status'] !== 'pending') {
        throw new RuntimeException('Only pending requests can be assigned.');
    }

    $assignStmt = $pdo->prepare("UPDATE rescue_requests SET status = 'assigned', assigned_rescuer_id = ? WHERE id = ?");
    $assignStmt->execute([$rescuerId, $requestId]);

    $busyStmt = $pdo->prepare("UPDATE rescuers SET status = 'busy' WHERE id = ?");
    $busyStmt->execute([$rescuerId]);

    $message = sprintf(
        'New assignment: Request #%d for %s has been assigned to you.',
        $requestId,
        $request['user_name']
    );
    $notificationStmt = $pdo->prepare("INSERT INTO notifications (rescuer_id, message, status, created_at) VALUES (?, ?, 'unread', NOW())");
    $notificationStmt->execute([$rescuerId, $message]);

    $pdo->commit();

    header('Location: manage_rescuer.php?request_id=' . $requestId . '&msg=' . urlencode('Rescuer assigned successfully.'));
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header('Location: manage_rescuer.php?request_id=' . $requestId . '&err=' . urlencode($e->getMessage()));
    exit;
}

