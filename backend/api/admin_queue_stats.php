<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../Services/CaseLifecycleService.php';

require_login();
require_role('admin');

$service = new CaseLifecycleService($pdo);
echo json_encode(['ok' => true, 'counters' => $service->getDashboardCounters()]);
