<?php
require_once __DIR__ . '/backend/auth.php';

$role = isset($_GET['role']) && $_GET['role'] !== ''
    ? SessionManager::normalizeRole((string)$_GET['role'])
    : (SessionManager::getCurrentContext() ?? ($_SESSION['role'] ?? null));

SessionManager::logoutRole($role, $pdo);

header('Location: index.php');
exit;
