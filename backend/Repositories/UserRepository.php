<?php

declare(strict_types=1);

final class UserRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array{password?: string, profile_picture?: string} $updates
     */
    public function updateProfile(int $userId, array $updates): bool
    {
        if ($updates === []) {
            return false;
        }
        $parts = [];
        $params = [];
        if (isset($updates['password'])) {
            $parts[] = 'password = ?';
            $params[] = $updates['password'];
        }
        if (isset($updates['profile_picture'])) {
            $parts[] = 'profile_picture = ?';
            $params[] = $updates['profile_picture'];
        }
        $params[] = $userId;
        $sql = 'UPDATE users SET ' . implode(', ', $parts) . ' WHERE id = ?';
        return $this->pdo->prepare($sql)->execute($params);
    }
}
