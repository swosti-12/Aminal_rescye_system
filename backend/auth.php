<?php
require_once __DIR__ . '/SessionManager.php';

SessionManager::bootstrap();

if (!isset($pdo)) {
    require_once __DIR__ . '/db_config.php';
}

function is_logged_in(): bool
{
    return isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']);
}

function is_role_logged_in(string $role): bool
{
    return SessionManager::isRoleLoggedIn($role);
}

function require_login(): void
{
    global $pdo;

    if (defined('ARS_AUTH_ROLE')) {
        require_role(ARS_AUTH_ROLE);
        return;
    }

    $requested = SessionManager::getRequestedContext();
    if ($requested !== null && SessionManager::activateRole($requested, $pdo instanceof PDO ? $pdo : null)) {
        return;
    }

    if (SessionManager::hasAnyRole()) {
        foreach (SessionManager::getActiveRoles() as $role) {
            if (SessionManager::activateRole($role, $pdo instanceof PDO ? $pdo : null)) {
                return;
            }
        }
    }

    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function require_role(string $role): void
{
    global $pdo;
    $role = SessionManager::normalizeRole($role);

    if (!SessionManager::isRoleLoggedIn($role)) {
        header('Location: login.php?intent=' . urlencode($role));
        exit;
    }

    if (!SessionManager::activateRole($role, $pdo instanceof PDO ? $pdo : null)) {
        header('Location: login.php?intent=' . urlencode($role));
        exit;
    }

    if (!is_logged_in() || ($_SESSION['role'] ?? '') !== $role) {
        header('Location: login.php?intent=' . urlencode($role));
        exit;
    }
}

/** @param list<string> $roles */
function require_any_role(array $roles): void
{
    global $pdo;
    foreach ($roles as $role) {
        $role = SessionManager::normalizeRole($role);
        if (SessionManager::activateRole($role, $pdo instanceof PDO ? $pdo : null) && is_logged_in()) {
            return;
        }
    }
    header('Location: login.php');
    exit;
}
