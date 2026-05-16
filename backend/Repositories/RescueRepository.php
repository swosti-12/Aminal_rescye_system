<?php

declare(strict_types=1);

final class RescueRepository
{
    private ?bool $hasAddressColumn = null;

    public function __construct(private PDO $pdo) {}

    public function hasAddressColumn(): bool
    {
        if ($this->hasAddressColumn !== null) {
            return $this->hasAddressColumn;
        }
        try {
            $st = $this->pdo->query("SHOW COLUMNS FROM rescue_cases LIKE 'address'");
            $this->hasAddressColumn = (bool) $st->fetch();
        } catch (Throwable $e) {
            $this->hasAddressColumn = false;
        }

        return $this->hasAddressColumn;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findCasesByReporter(int $userId): array
    {
        $st = $this->pdo->prepare(
            'SELECT c.*, r.id AS request_row_id, r.status AS request_ai_status, r.rescuer_id AS req_rescuer_id,
                    r.animal_detected, r.animal_confidence
             FROM rescue_cases c
             LEFT JOIN rescue_requests r ON r.id = (
                 SELECT MAX(rr.id) FROM rescue_requests rr WHERE rr.case_id = c.id
             )
             WHERE c.reporter_id = ?
             ORDER BY c.created_at DESC'
        );
        $st->execute([$userId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array{total: int, active: int, completed: int}
     */
    public function getReporterStats(int $userId): array
    {
        $st = $this->pdo->prepare(
            'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status IN (\'pending\',\'accepted\') THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN status = \'resolved\' THEN 1 ELSE 0 END) AS completed
             FROM rescue_cases WHERE reporter_id = ?'
        );
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'completed' => (int) ($row['completed'] ?? 0),
        ];
    }

    /**
     * @return array<int, array{message: string, time: string, type: string, case_id: int|null}>
     */
    public function buildActivityFeed(int $userId, int $limit = 12): array
    {
        $st = $this->pdo->prepare(
            'SELECT c.id, c.animal_type, c.status, c.created_at, c.priority_level, r.status AS ai_status
             FROM rescue_cases c
             LEFT JOIN rescue_requests r ON r.case_id = c.id
             WHERE c.reporter_id = ?
             ORDER BY c.created_at DESC
             LIMIT ' . (int) $limit
        );
        $st->execute([$userId]);
        $cases = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $feed = [];
        foreach ($cases as $c) {
            $feed[] = [
                'case_id' => (int) $c['id'],
                'message' => sprintf(
                    '%s report — %s',
                    ucfirst((string) $c['animal_type']),
                    $this->timelineLabel($c)
                ),
                'time' => (string) $c['created_at'],
                'type' => (string) $c['status'],
            ];
        }
        return $feed;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getUserNotifications(int $userId, int $limit = 20): array
    {
        try {
            $st = $this->pdo->prepare(
                'SELECT id, rescue_id AS case_id, message, category, is_read, created_at
                 FROM user_notifications
                 WHERE user_id = ?
                 ORDER BY created_at DESC
                 LIMIT ' . (int) $limit
            );
            $st->execute([$userId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public function getUnreadNotificationCount(int $userId): int
    {
        try {
            $st = $this->pdo->prepare('SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND is_read = 0');
            $st->execute([$userId]);
            return (int) $st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    public function markNotificationsRead(int $userId): void
    {
        try {
            $st = $this->pdo->prepare('UPDATE user_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
            $st->execute([$userId]);
        } catch (Throwable $e) {
            // Ignore if table not migrated yet.
        }
    }

    private function timelineLabel(array $c): string
    {
        $ai = $c['ai_status'] ?? '';
        if ($ai === 'Rejected') {
            return 'Not dispatched (AI)';
        }
        return match ($c['status']) {
            'resolved' => 'Rescue completed',
            'accepted' => 'Rescuer in progress',
            'pending' => 'Awaiting assignment / in queue',
            'rejected' => 'Closed',
            default => (string) $c['status'],
        };
    }

    /**
     * @param array{reporter_id: int, animal_type: string, description: string, image_path: ?string, lat: ?float, lon: ?float, severity: string, priority: string, rescuer_id: ?int, status: string} $case
     */
    public function insertCase(array $case): int
    {
        $address = isset($case['address']) ? trim((string) $case['address']) : '';
        if ($this->hasAddressColumn() && $address !== '') {
            $sql = 'INSERT INTO rescue_cases (reporter_id, animal_type, description, image_path, latitude, longitude, address, detected_injury_severity, priority_level, assigned_rescuer_id, status) VALUES (?,?,?,?,?,?,?,?,?,?,?)';
            $st = $this->pdo->prepare($sql);
            $st->execute([
                $case['reporter_id'],
                $case['animal_type'],
                $case['description'],
                $case['image_path'],
                $case['lat'],
                $case['lon'],
                $address,
                $case['severity'],
                $case['priority'],
                $case['rescuer_id'],
                $case['status'],
            ]);
        } else {
            $sql = 'INSERT INTO rescue_cases (reporter_id, animal_type, description, image_path, latitude, longitude, detected_injury_severity, priority_level, assigned_rescuer_id, status) VALUES (?,?,?,?,?,?,?,?,?,?)';
            $st = $this->pdo->prepare($sql);
            $st->execute([
                $case['reporter_id'],
                $case['animal_type'],
                $case['description'],
                $case['image_path'],
                $case['lat'],
                $case['lon'],
                $case['severity'],
                $case['priority'],
                $case['rescuer_id'],
                $case['status'],
            ]);
        }

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insertRescueRequest(array $row): void
    {
        $hasAnimalCols = $this->hasRescueRequestAnimalColumns();
        if ($hasAnimalCols) {
            $sql = 'INSERT INTO rescue_requests (user_id, case_id, rescuer_id, image, description, location, ai_result, confidence, status, priority, rescuer_notified, animal_detected, animal_confidence) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)';
            $st = $this->pdo->prepare($sql);
            $st->execute([
                $row['user_id'],
                $row['case_id'],
                $row['rescuer_id'],
                $row['image'],
                $row['description'],
                $row['location'],
                $row['ai_result'],
                $row['confidence'],
                $row['status'],
                $row['priority'],
                $row['rescuer_notified'],
                $row['animal_detected'],
                $row['animal_confidence'],
            ]);
            return;
        }

        $sql = 'INSERT INTO rescue_requests (user_id, case_id, rescuer_id, image, description, location, ai_result, confidence, status, priority, rescuer_notified) VALUES (?,?,?,?,?,?,?,?,?,?,?)';
        $st = $this->pdo->prepare($sql);
        $st->execute([
            $row['user_id'],
            $row['case_id'],
            $row['rescuer_id'],
            $row['image'],
            $row['description'],
            $row['location'],
            $row['ai_result'],
            $row['confidence'],
            $row['status'],
            $row['priority'],
            $row['rescuer_notified'],
        ]);
    }

    private function hasRescueRequestAnimalColumns(): bool
    {
        try {
            $q = $this->pdo->query("SHOW COLUMNS FROM rescue_requests LIKE 'animal_detected'");
            return $q && $q->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}
