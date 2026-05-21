<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../Services/CaseLifecycleService.php';

require_login();
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($input)) {
    $input = $_POST;
}

$caseId = (int) ($input['case_id'] ?? 0);
$newStatus = trim((string) ($input['case_status'] ?? $input['status'] ?? ''));
$confirmArchive = !empty($input['confirm_archive']);

if (!$caseId || $newStatus === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'case_id and case_status are required']);
    exit;
}

$service = new CaseLifecycleService($pdo);
$willArchive = $service->shouldArchive($newStatus);

if ($willArchive && !$confirmArchive) {
    echo json_encode([
        'ok' => false,
        'requires_confirmation' => true,
        'message' => 'Are you sure you want to archive this case?',
        'case_status' => CaseLifecycleService::normalizeStatus($newStatus),
    ]);
    exit;
}

$adminId = (int) $_SESSION['user_id'];
$result = $service->updateCaseStatus($caseId, $newStatus, $adminId);
$counters = $service->getDashboardCounters();

echo json_encode([
    'ok' => $result['ok'],
    'archived' => $result['archived'],
    'case_id' => $result['case_id'],
    'previous_status' => $result['previous_status'],
    'new_status' => $result['new_status'],
    'message' => $result['message'],
    'counters' => $counters,
    'status_label' => $service->statusLabel($result['new_status']),
    'status_class' => $service->statusBadgeClass($result['new_status']),
]);
