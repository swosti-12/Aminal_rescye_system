<?php
require_once __DIR__ . '/backend/auth.php';

if (isset($_GET['role']) && $_GET['role'] === 'all') {
    SessionManager::logoutAll($pdo);
} else {
    $role = isset($_GET['role']) && $_GET['role'] !== ''
        ? SessionManager::normalizeRole((string) $_GET['role'])
        : (SessionManager::getCurrentContext() ?? ($_SESSION['role'] ?? null));

    SessionManager::logoutRole($role, $pdo);
}

header('Location: index.php');
exit;
