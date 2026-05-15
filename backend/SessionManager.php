<?php
/**
 * Multi-role session storage in a single PHP session cookie.
 * Each role (admin, rescuer, user) has an isolated slot; require_role() activates the slot per request.
 */
class SessionManager
{
    private const STORAGE_KEY = 'ars_multi';
    private const CONTEXT_KEY = 'ars_context';
    private const ALLOWED_ROLES = ['admin', 'rescuer', 'user'];

    public static function bootstrap(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        self::migrateLegacySession();
    }

    private static function migrateLegacySession(): void
    {
        if (isset($_SESSION[self::STORAGE_KEY]) || !isset($_SESSION['user_id'])) {
            return;
        }
        $role = self::normalizeRole((string)($_SESSION['role'] ?? 'user'));
        $_SESSION[self::STORAGE_KEY][$role] = [
            'user_id' => (int)$_SESSION['user_id'],
            'name' => (string)($_SESSION['name'] ?? 'User'),
            'token' => (string)($_SESSION['session_token'] ?? bin2hex(random_bytes(16))),
            'login_time' => time(),
        ];
        $_SESSION[self::CONTEXT_KEY] = $role;
    }

    public static function normalizeRole(string $role): string
    {
        $role = strtolower(trim($role));
        return in_array($role, self::ALLOWED_ROLES, true) ? $role : 'user';
    }

    /** @return array<string, array{user_id:int,name:string,token:string,login_time:int}> */
    public static function getAllSlots(): array
    {
        return is_array($_SESSION[self::STORAGE_KEY] ?? null) ? $_SESSION[self::STORAGE_KEY] : [];
    }

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
        return isset(self::getAllSlots()[$role]);
    }

    public static function getCurrentContext(): ?string
    {
        $ctx = $_SESSION[self::CONTEXT_KEY] ?? null;
        return is_string($ctx) ? self::normalizeRole($ctx) : null;
    }

    /**
     * Activate a role slot and mirror legacy $_SESSION keys for backward compatibility.
     */
    public static function activateRole(string $role, ?PDO $pdo = null): bool
    {
        $role = self::normalizeRole($role);
        $slots = self::getAllSlots();
        if (!isset($slots[$role])) {
            return false;
        }

        $slot = $slots[$role];
        if ($pdo !== null && !self::validateToken($pdo, $role, $slot)) {
            unset($_SESSION[self::STORAGE_KEY][$role]);
            return false;
        }

        $_SESSION['user_id'] = (int)$slot['user_id'];
        $_SESSION['name'] = (string)$slot['name'];
        $_SESSION['role'] = $role;
        $_SESSION['session_token'] = (string)$slot['token'];
        $_SESSION[self::CONTEXT_KEY] = $role;

        if ($pdo !== null) {
            self::touchSession($pdo, $role, $slot);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $user Row from users table
     */
    public static function login(PDO $pdo, array $user): string
    {
        $role = self::normalizeRole((string)($user['role'] ?? 'user'));
        $token = bin2hex(random_bytes(32));

        if (!isset($_SESSION[self::STORAGE_KEY]) || !is_array($_SESSION[self::STORAGE_KEY])) {
            $_SESSION[self::STORAGE_KEY] = [];
        }

        $_SESSION[self::STORAGE_KEY][$role] = [
            'user_id' => (int)$user['id'],
            'name' => (string)($user['name'] ?? $user['full_name'] ?? 'User'),
            'token' => $token,
            'login_time' => time(),
        ];

        self::persistSessionRow($pdo, (int)$user['id'], $role, $token);
        self::activateRole($role, $pdo);

        return $role;
    }

    public static function logoutRole(?string $role = null, ?PDO $pdo = null): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $role = $role !== null
            ? self::normalizeRole($role)
            : self::getCurrentContext();

        if ($role === null || !isset($_SESSION[self::STORAGE_KEY][$role])) {
            return;
        }

        $token = $_SESSION[self::STORAGE_KEY][$role]['token'] ?? '';
        unset($_SESSION[self::STORAGE_KEY][$role]);

        if ($token !== '' && $pdo instanceof PDO) {
            self::deleteSessionRow($pdo, $token);
        }

        $remaining = self::getActiveRoles();
        if ($remaining === []) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();
            return;
        }

        $next = $remaining[0];
        self::activateRole($next);
    }

    public static function roleLabel(string $role): string
    {
        return match (self::normalizeRole($role)) {
            'admin' => 'Admin',
            'rescuer' => 'Rescuer',
            default => 'User',
        };
    }

    public static function getSessionLabel(string $role): string
    {
        $role = self::normalizeRole($role);
        $slots = self::getAllSlots();
        if (!isset($slots[$role])) {
            return self::roleLabel($role);
        }

        $ordered = $slots;
        uasort($ordered, static fn($a, $b) => ($a['login_time'] ?? 0) <=> ($b['login_time'] ?? 0));
        $index = 1;
        foreach (array_keys($ordered) as $r) {
            if ($r === $role) {
                return self::roleLabel($role) . ' (Session ' . $index . ')';
            }
            $index++;
        }

        return self::roleLabel($role) . ' (Session 1)';
    }

    /**
     * @param array{user_id:int,name:string,token:string,login_time:int} $slot
     */
    private static function validateToken(PDO $pdo, string $role, array $slot): bool
    {
        if (empty($slot['token'])) {
            return false;
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT id FROM user_sessions WHERE session_token = ? AND user_id = ? AND role = ? LIMIT 1'
            );
            $stmt->execute([(string)$slot['token'], (int)$slot['user_id'], self::normalizeRole($role)]);
            if ($stmt->fetch()) {
                return true;
            }
            // PHP slot exists but DB row missing (legacy session) — register without invalidating other roles
            self::persistSessionRow($pdo, (int)$slot['user_id'], $role, (string)$slot['token']);
            return true;
        } catch (Throwable $e) {
            return true;
        }
    }

    /**
     * @param array{user_id:int,name:string,token:string,login_time:int} $slot
     */
    private static function touchSession(PDO $pdo, string $role, array $slot): void
    {
        try {
            $pdo->prepare(
                'UPDATE user_sessions SET last_active = NOW(), php_session_id = ? WHERE session_token = ? AND user_id = ? AND role = ?'
            )->execute([
                session_id(),
                (string)$slot['token'],
                (int)$slot['user_id'],
                self::normalizeRole($role),
            ]);
        } catch (Throwable $e) {
        }
    }

    private static function persistSessionRow(PDO $pdo, int $userId, string $role, string $token): void
    {
        try {
            $pdo->prepare(
                'INSERT INTO user_sessions (session_token, user_id, role, php_session_id) VALUES (?, ?, ?, ?)'
            )->execute([$token, $userId, self::normalizeRole($role), session_id()]);
        } catch (Throwable $e) {
        }
    }

    private static function deleteSessionRow(PDO $pdo, string $token): void
    {
        try {
            $pdo->prepare('DELETE FROM user_sessions WHERE session_token = ?')->execute([$token]);
        } catch (Throwable $e) {
        }
    }
}
