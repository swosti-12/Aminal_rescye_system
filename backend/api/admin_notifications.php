<?php
/**
 * Admin Notification Polling API
 * GET  → fetch latest admin notifications + unread count
 * GET  ?mark_read=1 → mark all as read
 * GET  ?rescuer_locations=1 → include live rescuer locations
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db_config.php';

require_login();
require_role('admin');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$adminId = (int)$_SESSION['user_id'];

try {
    // ─── Notifications ───────────────────────────────────────────────
    $notifications = [];
    $unreadCount = 0;

    try {
        // Fetch notifications for this admin or global (admin_id IS NULL)
        $stmt = $pdo->prepare(
            "SELECT id, case_id, rescuer_id, message, category, is_read, created_at
             FROM admin_notifications
             WHERE admin_id = ? OR admin_id IS NULL
             ORDER BY created_at DESC
             LIMIT 30"
        );
        $stmt->execute([$adminId]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $countStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM admin_notifications WHERE (admin_id = ? OR admin_id IS NULL) AND is_read = 0"
        );
        $countStmt->execute([$adminId]);
        $unreadCount = (int)$countStmt->fetchColumn();

        // Mark all as read if requested
        if (isset($_GET['mark_read']) && $_GET['mark_read'] === '1') {
            $pdo->prepare(
                "UPDATE admin_notifications SET is_read = 1 WHERE (admin_id = ? OR admin_id IS NULL) AND is_read = 0"
            )->execute([$adminId]);
            $unreadCount = 0;
        }
    } catch (Throwable $e) {
        // admin_notifications table may not exist yet
    }

    // ─── Live Rescuer Locations ──────────────────────────────────────
    $rescuerLocations = [];
    if (isset($_GET['rescuer_locations']) && $_GET['rescuer_locations'] === '1') {
        try {
            $locStmt = $pdo->query(
                "SELECT u.id, u.name, u.latitude, u.longitude, u.availability_status,
                        COALESCE(rl.updated_at, u.updated_at) AS last_updated
                 FROM users u
                 LEFT JOIN rescuer_locations rl ON rl.rescuer_id = u.id
                 WHERE u.role = 'rescuer'
                   AND u.availability_status = 'available'
                   AND u.latitude IS NOT NULL
                   AND u.longitude IS NOT NULL
                 ORDER BY u.name ASC"
            );
            $rescuerLocations = $locStmt ? $locStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {
            // Missing columns — fall through
        }
    }

    echo json_encode([
        'ok' => true,
        'notifications' => $notifications,
        'unread_count' => $unreadCount,
        'rescuer_locations' => $rescuerLocations,
        'server_time' => date(DATE_ATOM),
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => 'Could not fetch admin notifications',
    ]);
}
