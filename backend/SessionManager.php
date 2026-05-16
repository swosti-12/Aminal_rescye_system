<?php

declare(strict_types=1);

/**
 * Multi-role session slots in one PHP session cookie.
 * Each role (admin, rescuer, user) keeps its own login; pages call activateRole()
 * so concurrent tabs do not overwrite each other's effective user_id.
 */
final class SessionManager
{
    private const SLOTS_KEY = 'ars_slots';

    public static function bootstrap(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }

        if (!isset($_SESSION[self::SLOTS_KEY]) || !is_array($_SESSION[self::SLOTS_KEY])) {
            $_SESSION[self::SLOTS_KEY] = [];
        }
    }

    public static function normalizeRole(string $role): string
    {
        $role = strtolower(trim($role));
        if ($role === 'administrator') {
            return 'admin';
        }

        return in_array($role, ['admin', 'rescuer', 'user'], true) ? $role : 'user';
    }

    /**
     * Role requested for this HTTP request (tab context via header or query).
     */
    public static function getRequestedContext(): ?string
    {
        if (defined('ARS_AUTH_ROLE')) {
            return self::normalizeRole((string) ARS_AUTH_ROLE);
        }

        $header = $_SERVER['HTTP_X_ARS_ROLE'] ?? $_SERVER['HTTP_X_ARS_CONTEXT'] ?? '';
        if (is_string($header) && $header !== '') {
            return self::normalizeRole($header);
        }

        if (isset($_GET['ars_ctx']) && $_GET['ars_ctx'] !== '') {
            return self::normalizeRole((string) $_GET['ars_ctx']);
        }

        return null;
    }

    public static function getCurrentContext(): ?string
    {
        $requested = self::getRequestedContext();
        if ($requested !== null && self::isRoleLoggedIn($requested)) {
            return $requested;
        }

        $active = $_SESSION['ars_active_context'] ?? null;
        if (is_string($active) && self::isRoleLoggedIn($active)) {
            return self::normalizeRole($active);
        }

        $roles = self::getActiveRoles();
        return $roles[0] ?? null;
    }

    /** @return array<string, array<string, mixed>> */
    public static function getAllSlots(): array
    {
        $slots = $_SESSION[self::SLOTS_KEY] ?? [];
        return is_array($slots) ? $slots : [];
    }

    /** @return list<string> */
    public static function getActiveRoles(): array
    {
        return array_keys(self::getAllSlots());
    }

    public static function hasAnyRole(): bool
    {
        return self::getAllSlots() !== [];
    }

    public static function isRoleLoggedIn(string $role): bool
    {
        $role = self::normalizeRole($role);
        $slots = self::getAllSlots();

        return isset($slots[$role]['user_id']) && is_numeric($slots[$role]['user_id']);
    }

    public static function getSessionLabel(string $role): string
    {
        return match (self::normalizeRole($role)) {
            'admin' => 'Admin',
            'rescuer' => 'Rescuer',
            default => 'User',
        };
    }

    /**
     * @param array<string, mixed> $user Row from users table.
     */
    public static function login(PDO $pdo, array $user): string
    {
        $role = self::normalizeRole((string) ($user['role'] ?? 'user'));
        $slot = self::buildSlotFromUser($user);

        $_SESSION[self::SLOTS_KEY][$role] = $slot;
        self::persistServerSession($pdo, $role, $slot);
        self::activateRole($role, $pdo);

        return $role;
    }

    public static function activateRole(string $role, ?PDO $pdo = null): bool
    {
        $role = self::normalizeRole($role);
        $slots = self::getAllSlots();
        if (!isset($slots[$role])) {
            return false;
        }

        $slot = $slots[$role];
        $_SESSION['user_id'] = (int) $slot['user_id'];
        $_SESSION['role'] = $role;
        $_SESSION['name'] = (string) ($slot['name'] ?? '');
        $_SESSION['email'] = (string) ($slot['email'] ?? '');
        $_SESSION['ars_active_context'] = $role;

        if ($pdo !== null) {
            self::touchServerSession($pdo, $role, (int) $slot['user_id']);
        }

        return true;
    }

    public static function logoutRole(?string $role, ?PDO $pdo = null): void
    {
        if ($role === null || $role === '') {
            self::logoutAll($pdo);
            return;
        }

        $role = self::normalizeRole($role);
        unset($_SESSION[self::SLOTS_KEY][$role]);

        if ($pdo !== null) {
            self::deleteServerSession($pdo, $role);
        }

        $remaining = self::getActiveRoles();
        if ($remaining === []) {
            self::clearLegacySessionKeys();
            return;
        }

        self::activateRole($remaining[0], $pdo);
    }

    public static function logoutAll(?PDO $pdo = null): void
    {
        if ($pdo !== null) {
            $sid = session_id();
            if ($sid !== '') {
                try {
                    $pdo->prepare('DELETE FROM user_sessions WHERE session_id = ?')->execute([$sid]);
                } catch (Throwable $e) {
                    // Optional table
                }
            }
        }

        $_SESSION[self::SLOTS_KEY] = [];
        self::clearLegacySessionKeys();
    }

    /** @param array<string, mixed> $user */
    private static function buildSlotFromUser(array $user): array
    {
        return [
            'user_id' => (int) $user['id'],
            'name' => (string) ($user['name'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            'account_status' => (string) ($user['account_status'] ?? 'active'),
            'logged_in_at' => time(),
        ];
    }

    private static function clearLegacySessionKeys(): void
    {
        unset(
            $_SESSION['user_id'],
            $_SESSION['role'],
            $_SESSION['name'],
            $_SESSION['email'],
            $_SESSION['ars_active_context']
        );
    }

    /** Optional DB audit — safe if migrate_user_sessions.sql was not run. */
    private static function persistServerSession(PDO $pdo, string $role, array $slot): void
    {
        $sid = session_id();
        if ($sid === '') {
            return;
        }
        try {
            $pdo->prepare(
                'INSERT INTO user_sessions (session_id, user_id, role, last_active)
                 VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), last_active = NOW()'
            )->execute([$sid . ':' . $role, (int) $slot['user_id'], $role]);
        } catch (Throwable $e) {
            // Table optional
        }
    }

    private static function touchServerSession(PDO $pdo, string $role, int $userId): void
    {
        $sid = session_id();
        if ($sid === '') {
            return;
        }
        try {
            $pdo->prepare(
                'UPDATE user_sessions SET last_active = NOW() WHERE session_id = ? AND user_id = ?'
            )->execute([$sid . ':' . $role, $userId]);
        } catch (Throwable $e) {
            // optional
        }
    }

    private static function deleteServerSession(PDO $pdo, string $role): void
    {
        $sid = session_id();
        if ($sid === '') {
            return;
        }
        try {
            $pdo->prepare('DELETE FROM user_sessions WHERE session_id = ?')->execute([$sid . ':' . $role]);
        } catch (Throwable $e) {
            // optional
        }
    }
}
