<?php

declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../Services/UserCaseTracking.php';
require_once __DIR__ . '/../Repositories/RescueRepository.php';

require_login();
if (($_SESSION['role'] ?? '') !== 'user') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$userId = (int) $_SESSION['user_id'];
$repo = new RescueRepository($pdo);
$reports = $repo->findCasesByReporter($userId);
$notifications = $repo->getUserNotifications($userId, 20);
$unread = $repo->getUnreadNotificationCount($userId);

if (isset($_GET['mark_read']) && $_GET['mark_read'] === '1') {
    $repo->markNotificationsRead($userId);
    $unread = 0;
    foreach ($notifications as &$n) {
        $n['is_read'] = 1;
    }
    unset($n);
}

$out = [];
foreach ($reports as $rep) {
    $out[] = [
        'id' => (int) $rep['id'],
        'status' => (string) $rep['status'],
        'priority_level' => (string) ($rep['priority_level'] ?? 'low'),
        'updated_at' => $rep['updated_at'] ?? $rep['created_at'],
        'tracking' => UserCaseTracking::fromRow($rep),
        'priority_badge' => UserCaseTracking::priorityBadge($rep),
    ];
}

echo json_encode([
    'ok' => true,
    'cases' => $out,
    'notifications' => $notifications,
    'unread_notifications' => $unread,
    'server_time' => date(DATE_ATOM),
]) ?: '{"ok":false}';
