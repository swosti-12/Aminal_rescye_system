<?php
/**
 * JSON API: start/update/stop logged-in rescuer live location tracking.
 * Stores location in MySQL. Fault-tolerant — won't fail if rescuers/rescuer_locations tables are missing.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db_config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$role = $_SESSION['role'] ?? '';
if ($role !== 'rescuer') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

$action = (string)($input['action'] ?? 'update');
if (!in_array($action, ['start', 'update', 'stop'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid action']);
    exit;
}

$rescuerId = (int)$_SESSION['user_id'];
$lat = isset($input['latitude']) ? filter_var($input['latitude'], FILTER_VALIDATE_FLOAT) : null;
$lon = isset($input['longitude']) ? filter_var($input['longitude'], FILTER_VALIDATE_FLOAT) : null;

if ($action !== 'stop') {
    if ($lat === false || $lon === false || $lat === null || $lon === null || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid coordinates']);
        exit;
    }
}

try {
    if ($action === 'stop') {
        // Update users table (primary — always exists)
        $pdo->prepare("UPDATE users SET availability_status = 'offline' WHERE id = ?")->execute([$rescuerId]);

        // Optional tables — don't fail if missing
        try {
            $pdo->prepare("UPDATE rescuers SET status = 'offline' WHERE id = ?")->execute([$rescuerId]);
        } catch (Throwable $e) { /* rescuers table may not exist */ }

        try {
            $pdo->prepare("UPDATE rescuer_locations SET status = 'inactive', updated_at = NOW() WHERE rescuer_id = ?")->execute([$rescuerId]);
        } catch (Throwable $e) { /* rescuer_locations table may not exist */ }

        echo json_encode([
            'ok' => true,
            'status' => 'inactive',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        exit;
    }

    // --- Start / Update ---

    // 1. Update users table (primary — always works)
    $pdo->prepare('UPDATE users SET latitude = ?, longitude = ?, availability_status = ? WHERE id = ?')
        ->execute([$lat, $lon, 'available', $rescuerId]);

    // 2. Update rescuers table (optional — may not exist)
    try {
        $pdo->prepare('UPDATE rescuers SET latitude = ?, longitude = ?, status = ? WHERE id = ?')
            ->execute([$lat, $lon, 'available', $rescuerId]);
    } catch (Throwable $e) { /* rescuers table may not exist */ }

    // 3. Upsert rescuer_locations (optional — may not exist)
    try {
        $upsert = $pdo->prepare(
            "INSERT INTO rescuer_locations (rescuer_id, latitude, longitude, status, updated_at)
             VALUES (?, ?, ?, 'active', NOW())
             ON DUPLICATE KEY UPDATE latitude = VALUES(latitude), longitude = VALUES(longitude), status = VALUES(status), updated_at = NOW()"
        );
        $upsert->execute([$rescuerId, $lat, $lon]);
    } catch (Throwable $e) { /* rescuer_locations table may not exist */ }

    echo json_encode([
        'ok' => true,
        'status' => 'active',
        'latitude' => $lat,
        'longitude' => $lon,
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Location update failed: ' . $e->getMessage()]);
}
