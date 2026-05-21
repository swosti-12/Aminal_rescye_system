<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../Services/CaseLifecycleService.php';

require_login();
require_role('admin');

$service = new CaseLifecycleService($pdo);

$result = $service->getArchivedHistory([
    'search' => trim((string) ($_GET['search'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
    'rescuer_id' => (int) ($_GET['rescuer_id'] ?? 0) ?: null,
    'page' => (int) ($_GET['page'] ?? 1),
    'per_page' => (int) ($_GET['per_page'] ?? 20),
]);

$items = [];
foreach ($result['items'] as $c) {
    $status = (string) ($c['status'];
    $items[] = [
        'case_id' => (int) $c['id'],
        'request_id' => isset($c['request_id']) ? (int) $c['request_id'] : null,
        'animal_type' => $c['animal_type'] ?? '',
        'status' => $status,
        'status_label' => $service->statusLabel($status),
        'status_class' => $service->statusBadgeClass($status),
        'priority_level' => $c['priority_level'] ?? '',
        'reporter_name' => $c['reporter_name'] ?? '',
        'rescuer_name' => $c['rescuer_name'] ?? '',
        'archived_at' => $c['archived_at'] ?? $c['resolved_at'] ?? $c['created_at'],
        'created_at' => $c['created_at'] ?? '',
        'ai_result' => $c['ai_result'] ?? null,
        'confidence' => isset($c['confidence']) ? (float) $c['confidence'] : null,
    ];
}

echo json_encode([
    'ok' => true,
    'items' => $items,
    'total' => $result['total'],
    'page' => $result['page'],
    'per_page' => $result['per_page'],
    'total_pages' => $result['per_page'] > 0 ? (int) ceil($result['total'] / $result['per_page']) : 0,
]);
