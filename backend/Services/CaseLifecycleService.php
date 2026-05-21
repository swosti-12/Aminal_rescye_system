<?php

declare(strict_types=1);

/**
 * Rescue case lifecycle: active queue, archive, notifications, audit.
 */
final class CaseLifecycleService
{
    /** Statuses that stay in the active Rescue Request Queue */
    public const ACTIVE_STATUSES = [
        'pending',
        'under_review',
        'assigned',
        'in_progress',
        'accepted', // legacy → treated as in_progress in UI
    ];

    /** Selecting these archives the case and removes it from the active queue */
    public const ARCHIVE_STATUSES = [
        'completed',
        'closed',
        'rescued',
        'spam',
        'rejected',
        'resolved', // legacy
    ];

    public function __construct(private PDO $pdo) {}

    public static function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return match ($status) {
            'accepted' => 'in_progress',
            'resolved' => 'completed',
            default => $status,
        };
    }

    public function isActiveStatus(string $status): bool
    {
        $n = self::normalizeStatus($status);
        return in_array($n, self::ACTIVE_STATUSES, true)
            || in_array($status, ['accepted'], true);
    }

    public function shouldArchive(string $status): bool
    {
        $n = self::normalizeStatus($status);
        return in_array($n, self::ARCHIVE_STATUSES, true);
    }

    public function statusLabel(string $status): string
    {
        return match (self::normalizeStatus($status)) {
            'pending' => 'Pending',
            'under_review' => 'Under Review',
            'assigned' => 'Assigned',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            'closed' => 'Closed',
            'rescued' => 'Rescued',
            'spam' => 'Spam',
            'rejected' => 'Rejected',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    public function statusBadgeClass(string $status): string
    {
        return match (self::normalizeStatus($status)) {
            'pending' => 'ars-badge--pending',
            'under_review' => 'ars-badge--review',
            'assigned' => 'ars-badge--assigned',
            'in_progress' => 'ars-badge--progress',
            'completed', 'rescued' => 'ars-badge--completed',
            'closed' => 'ars-badge--closed',
            'spam', 'rejected' => 'ars-badge--spam',
            default => 'ars-badge--default',
        };
    }

    /**
     * @return array{ok: bool, archived: bool, case_id: int, previous_status: string, new_status: string, message: string}
     */
    public function updateCaseStatus(int $caseId, string $newStatus, int $adminId): array
    {
        $newStatus = self::normalizeStatus($newStatus);
        $allowed = array_merge(self::ACTIVE_STATUSES, self::ARCHIVE_STATUSES);
        if (!in_array($newStatus, $allowed, true) && !in_array($newStatus, ['accepted', 'resolved'], true)) {
            return ['ok' => false, 'archived' => false, 'case_id' => $caseId, 'previous_status' => '', 'new_status' => $newStatus, 'message' => 'Invalid status.'];
        }

        $st = $this->pdo->prepare('SELECT * FROM rescue_cases WHERE id = ? LIMIT 1');
        $st->execute([$caseId]);
        $case = $st->fetch(PDO::FETCH_ASSOC);
        if (!$case) {
            return ['ok' => false, 'archived' => false, 'case_id' => $caseId, 'previous_status' => '', 'new_status' => $newStatus, 'message' => 'Case not found.'];
        }

        $previous = (string) ($case['status'] ?? 'pending');
        $archive = $this->shouldArchive($newStatus);

        if ($archive) {
            $resolvedAt = in_array($newStatus, ['completed', 'rescued', 'resolved'], true) ? 'NOW()' : 'resolved_at';
            $sql = "UPDATE rescue_cases SET previous_status = ?, status = ?, is_archived = 1, archived_at = NOW(),
                    marked_as_read = 1, resolved_at = COALESCE(resolved_at, NOW()) WHERE id = ?";
            $this->pdo->prepare($sql)->execute([$previous, $newStatus, $caseId]);
            $this->archiveLinkedRequests($caseId);
            $this->notifyCaseArchived($case, $newStatus);
        } else {
            $this->pdo->prepare(
                'UPDATE rescue_cases SET previous_status = ?, status = ?, is_archived = 0, archived_at = NULL WHERE id = ?'
            )->execute([$previous, $newStatus, $caseId]);
            $this->unarchiveLinkedRequests($caseId);
        }

        $this->auditStatusChange($adminId, $caseId, $previous, $newStatus, $archive);

        return [
            'ok' => true,
            'archived' => $archive,
            'case_id' => $caseId,
            'previous_status' => $previous,
            'new_status' => $newStatus,
            'message' => $archive ? 'Case archived and moved to history.' : 'Status updated.',
        ];
    }

    public function archiveCase(int $caseId, int $adminId, string $terminalStatus = 'completed'): array
    {
        return $this->updateCaseStatus($caseId, $terminalStatus, $adminId);
    }

    private function archiveLinkedRequests(int $caseId): void
    {
        try {
            $this->pdo->prepare(
                'UPDATE rescue_requests SET is_archived = 1, archived_at = NOW(), marked_as_read = 1 WHERE case_id = ?'
            )->execute([$caseId]);
        } catch (Throwable $e) {
            // Columns may not exist until migration
        }
    }

    private function unarchiveLinkedRequests(int $caseId): void
    {
        try {
            $this->pdo->prepare(
                'UPDATE rescue_requests SET is_archived = 0, archived_at = NULL WHERE case_id = ?'
            )->execute([$caseId]);
        } catch (Throwable $e) {
        }
    }

    /**
     * @param array<string, mixed> $case
     */
    private function notifyCaseArchived(array $case, string $newStatus): void
    {
        $caseId = (int) $case['id'];
        $reporterId = (int) ($case['reporter_id'] ?? 0);
        $rescuerId = (int) ($case['assigned_rescuer_id'] ?? 0);

        $reporterMsg = match ($newStatus) {
            'rejected', 'spam' => 'Your rescue case has been closed. Contact support if you have questions.',
            default => 'Your rescue case has been completed successfully.',
        };

        if ($reporterId > 0) {
            $this->insertUserNotification($reporterId, $caseId, $reporterMsg, 'resolved');
        }

        if ($rescuerId > 0) {
            $rescuerMsg = sprintf(
                'Case #%d has been marked as %s and archived.',
                $caseId,
                $this->statusLabel($newStatus)
            );
            $this->insertRescuerNotification($rescuerId, $caseId, $rescuerMsg);
        }
    }

    private function insertUserNotification(int $userId, int $caseId, string $message, string $category): void
    {
        try {
            $cols = $this->notificationCaseColumn();
            $this->pdo->prepare(
                "INSERT INTO user_notifications (user_id, {$cols}, message, category, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())"
            )->execute([$userId, $caseId, $message, $category]);
        } catch (Throwable $e) {
            error_log('CaseLifecycle user notification: ' . $e->getMessage());
        }
    }

    private function insertRescuerNotification(int $rescuerId, int $caseId, string $message): void
    {
        try {
            $this->pdo->prepare(
                "INSERT INTO admin_notifications (admin_id, case_id, rescuer_id, message, category, is_read, created_at)
                 VALUES (NULL, ?, ?, ?, 'status_change', 0, NOW())"
            )->execute([$caseId, $rescuerId, $message]);
        } catch (Throwable $e) {
            try {
                $this->pdo->prepare(
                    "INSERT INTO notifications (rescuer_id, message, status, created_at) VALUES (?, ?, 'unread', NOW())"
                )->execute([$rescuerId, $message]);
            } catch (Throwable $e2) {
            }
        }
    }

    private function notificationCaseColumn(): string
    {
        try {
            $q = $this->pdo->query("SHOW COLUMNS FROM user_notifications LIKE 'case_id'");
            if ($q && $q->fetch()) {
                return 'case_id';
            }
        } catch (Throwable $e) {
        }
        return 'rescue_id';
    }

    private function auditStatusChange(int $adminId, int $caseId, string $previous, string $newStatus, bool $archived): void
    {
        $details = json_encode([
            'previous_status' => $previous,
            'new_status' => $newStatus,
            'archived' => $archived,
            'timestamp' => date('c'),
        ], JSON_UNESCAPED_UNICODE);
        try {
            $this->pdo->prepare(
                'INSERT INTO admin_activity_log (admin_id, action_type, target_table, target_id, details) VALUES (?,?,?,?,?)'
            )->execute([$adminId, 'case_status_change', 'rescue_cases', $caseId, $details]);
        } catch (Throwable $e) {
        }
    }

    /**
     * Active queue: non-archived cases with active status (joined with latest request).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActiveQueue(int $limit = 200): array
    {
        $statusList = implode(',', array_map(fn ($s) => $this->pdo->quote($s), self::ACTIVE_STATUSES));
        $sql = "
            SELECT r.*, u.name AS reporter_name, u.email AS reporter_email,
                   c.animal_type, c.status AS case_status, c.id AS linked_case_id,
                   c.is_archived AS case_is_archived, c.marked_as_read AS case_marked_read,
                   c.latitude AS case_latitude, c.longitude AS case_longitude, c.address AS case_address,
                   resc.name AS rescuer_name
            FROM rescue_requests r
            JOIN users u ON r.user_id = u.id
            LEFT JOIN rescue_cases c ON r.case_id = c.id
            LEFT JOIN users resc ON r.rescuer_id = resc.id
            WHERE COALESCE(r.is_archived, 0) = 0
              AND (c.id IS NULL OR (COALESCE(c.is_archived, 0) = 0 AND c.status IN ({$statusList})))
            ORDER BY r.created_at DESC
            LIMIT " . (int) $limit;

        try {
            $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            return $this->getActiveQueueLegacy($limit);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getActiveQueueLegacy(int $limit): array
    {
        $sql = "
            SELECT r.*, u.name AS reporter_name, u.email AS reporter_email,
                   c.animal_type, c.status AS case_status, c.id AS linked_case_id,
                   c.latitude AS case_latitude, c.longitude AS case_longitude, c.address AS case_address,
                   resc.name AS rescuer_name
            FROM rescue_requests r
            JOIN users u ON r.user_id = u.id
            LEFT JOIN rescue_cases c ON r.case_id = c.id
            LEFT JOIN users resc ON r.rescuer_id = resc.id
            WHERE c.id IS NULL OR c.status IN ('pending','accepted')
            ORDER BY r.created_at DESC
            LIMIT " . (int) $limit;
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array{search?: string, status?: string, date_from?: string, date_to?: string, rescuer_id?: int, page?: int, per_page?: int} $filters
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function getArchivedHistory(array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(50, max(10, (int) ($filters['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        $where = ['(COALESCE(c.is_archived, 0) = 1 OR c.status IN (\'completed\',\'closed\',\'rescued\',\'spam\',\'rejected\',\'resolved\'))'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'c.status = ?';
            $params[] = self::normalizeStatus((string) $filters['status']);
        }
        if (!empty($filters['search'])) {
            $where[] = '(c.animal_type LIKE ? OR u.name LIKE ? OR CAST(c.id AS CHAR) LIKE ?)';
            $term = '%' . $filters['search'] . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(COALESCE(c.archived_at, c.resolved_at, c.created_at)) >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(COALESCE(c.archived_at, c.resolved_at, c.created_at)) <= ?';
            $params[] = $filters['date_to'];
        }
        if (!empty($filters['rescuer_id'])) {
            $where[] = 'c.assigned_rescuer_id = ?';
            $params[] = (int) $filters['rescuer_id'];
        }

        $whereSql = implode(' AND ', $where);

        try {
            $countSt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM rescue_cases c
                 JOIN users u ON c.reporter_id = u.id
                 WHERE {$whereSql}"
            );
            $countSt->execute($params);
            $total = (int) $countSt->fetchColumn();

            $sql = "
                SELECT c.*, u.name AS reporter_name, rep.name AS rescuer_name,
                       rr.id AS request_id, rr.ai_result, rr.confidence, rr.status AS request_status
                FROM rescue_cases c
                JOIN users u ON c.reporter_id = u.id
                LEFT JOIN users rep ON c.assigned_rescuer_id = rep.id
                LEFT JOIN (
                    SELECT case_id, MAX(id) AS max_req_id
                    FROM rescue_requests
                    GROUP BY case_id
                ) latest ON latest.case_id = c.id
                LEFT JOIN rescue_requests rr ON rr.id = latest.max_req_id
                WHERE {$whereSql}
                ORDER BY COALESCE(c.archived_at, c.resolved_at, c.created_at) DESC
                LIMIT {$perPage} OFFSET {$offset}
            ";
            $st = $this->pdo->prepare($sql);
            $st->execute($params);
            $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
        } catch (Throwable $e) {
            error_log('getArchivedHistory: ' . $e->getMessage());
            return ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{active_requests: int, completed_today: int, archived_cases: int}
     */
    public function getDashboardCounters(): array
    {
        $activeList = implode(',', array_map(fn ($s) => $this->pdo->quote($s), self::ACTIVE_STATUSES));
        try {
            $active = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM rescue_cases WHERE COALESCE(is_archived,0)=0 AND status IN ({$activeList})"
            )->fetchColumn();
            $completedToday = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM rescue_cases WHERE status IN ('completed','rescued','resolved')
                 AND DATE(COALESCE(archived_at, resolved_at, created_at)) = CURDATE()"
            )->fetchColumn();
            $archived = (int) $this->pdo->query(
                'SELECT COUNT(*) FROM rescue_cases WHERE COALESCE(is_archived,0)=1'
            )->fetchColumn();
        } catch (Throwable $e) {
            $active = (int) $this->pdo->query("SELECT COUNT(*) FROM rescue_cases WHERE status IN ('pending','accepted')")->fetchColumn();
            $completedToday = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM rescue_cases WHERE status='resolved' AND DATE(COALESCE(resolved_at, created_at))=CURDATE()"
            )->fetchColumn();
            $archived = (int) $this->pdo->query("SELECT COUNT(*) FROM rescue_cases WHERE status IN ('resolved','rejected')")->fetchColumn();
        }

        return [
            'active_requests' => $active,
            'completed_today' => $completedToday,
            'archived_cases' => $archived,
        ];
    }
}
