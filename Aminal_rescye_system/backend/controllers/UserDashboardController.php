<?php

declare(strict_types=1);

require_once __DIR__ . '/../Services/AiImageAnalysisService.php';
require_once __DIR__ . '/../Services/RescueSubmissionService.php';
require_once __DIR__ . '/../Services/UserCaseTracking.php';
require_once __DIR__ . '/../Repositories/RescueRepository.php';
require_once __DIR__ . '/../Repositories/UserRepository.php';

final class UserDashboardController
{
    private RescueRepository $rescues;
    private UserRepository $users;
    private RescueSubmissionService $submission;

    public function __construct(private PDO $pdo)
    {
        $this->rescues = new RescueRepository($pdo);
        $this->users = new UserRepository($pdo);
        $this->submission = new RescueSubmissionService($pdo, $this->rescues, new AiImageAnalysisService());
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @return array{message: string, type: string}
     */
    public function handleReportSubmit(int $userId, array $post, array $files): array
    {
        $r = $this->submission->submitFromUser($userId, $post, $files);
        return ['message' => $r['message'], 'type' => $r['type']];
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function handleProfileUpdate(int $userId, array $post, array $files): string
    {
        $updates = [];
        $newPass = trim((string) ($post['new_password'] ?? ''));
        $confirmPass = trim((string) ($post['confirm_password'] ?? ''));

        if ($newPass !== '') {
            if (strlen($newPass) < 6) {
                return 'Password must be at least 6 characters.';
            }
            if ($newPass !== $confirmPass) {
                return 'Passwords do not match. Please re-enter.';
            }
            $updates['password'] = password_hash($newPass, PASSWORD_DEFAULT);
        }

        if (isset($files['profile_picture']) && ($files['profile_picture']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $dir = __DIR__ . '/../../uploads/profiles/';
            if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
                return 'Could not create profile upload folder.';
            }
            $name = time() . '_' . basename((string) $files['profile_picture']['name']);
            $target = $dir . $name;
            if (move_uploaded_file((string) $files['profile_picture']['tmp_name'], $target)) {
                $updates['profile_picture'] = 'uploads/profiles/' . $name;
            }
        }

        if ($updates === []) {
            return '';
        }

        return $this->users->updateProfile($userId, $updates) ? 'Profile updated successfully.' : 'Failed to update profile.';
    }

    /**
     * @return array{
     *   user_data: array<string, mixed>|null,
     *   reports: array<int, array<string, mixed>>,
     *   stats: array{total: int, active: int, completed: int},
     *   activity: array<int, array<string, mixed>>,
 *   notifications: array<int, array<string, mixed>>,
 *   unread_notifications: int,
     *   reports_json: string
     * }
     */
    public function getDashboardData(int $userId): array
    {
        $user_data = $this->users->findById($userId);
        $reports = $this->rescues->findCasesByReporter($userId);
        $stats = $this->rescues->getReporterStats($userId);
        $activity = $this->rescues->buildActivityFeed($userId);
        $notifications = $this->rescues->getUserNotifications($userId);
        $unreadNotifications = $this->rescues->getUnreadNotificationCount($userId);

        $forJson = [];
        foreach ($reports as $rep) {
            $track = UserCaseTracking::fromRow($rep);
            $prio = UserCaseTracking::priorityBadge($rep);
            $forJson[] = [
                'id' => (int) $rep['id'],
                'status' => (string) $rep['status'],
                'priority_level' => (string) ($rep['priority_level'] ?? 'low'),
                'assigned' => !empty($rep['assigned_rescuer_id']),
                'tracking' => $track,
                'priority_badge' => $prio,
            ];
        }

        return [
            'user_data' => $user_data,
            'reports' => $reports,
            'stats' => $stats,
            'activity' => $activity,
            'notifications' => $notifications,
            'unread_notifications' => $unreadNotifications,
            'reports_json' => json_encode($forJson) ?: '[]',
        ];
    }
}
