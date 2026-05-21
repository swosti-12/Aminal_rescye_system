<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../Services/CaseLifecycleService.php';

require_login();
require_role('admin');

$service = new CaseLifecycleService($pdo);
$items = $service->getActiveQueue(200);

$out = [];
foreach ($items as $r) {
    $status = (string) ($r['case_status'] ?? 'pending');
    $out[] = [
        'request_id' => (int) $r['id'],
        'case_id' => !empty($r['linked_case_id']) ? (int) $r['linked_case_id'] : (!empty($r['case_id']) ? (int) $r['case_id'] : null),
        'reporter_name' => $r['reporter_name'] ?? '',
        'reporter_email' => $r['reporter_email'] ?? '',
        'ai_result' => $r['ai_result'] ?? '',
        'confidence' => (float) ($r['confidence'] ?? 0),
        'request_status' => $r['status'] ?? '',
        'priority' => $r['priority'] ?? '',
        'decision_source' => $r['decision_source'] ?? 'ai',
        'case_status' => $status,
        'case_status_label' => $service->statusLabel($status),
        'case_status_class' => $service->statusBadgeClass($status),
        'animal_type' => $r['animal_type'] ?? '',
        'rescuer_name' => $r['rescuer_name'] ?? '',
        'image' => $r['image'] ?? '',
        'description' => $r['description'] ?? '',
        'location' => $r['location'] ?? '',
        'case_latitude' => $r['case_latitude'] ?? null,
        'case_longitude' => $r['case_longitude'] ?? null,
        'case_address' => $r['case_address'] ?? '',
        'created_at' => $r['created_at'] ?? '',
    ];
}

echo json_encode(['ok' => true, 'items' => $out, 'count' => count($out)]);
