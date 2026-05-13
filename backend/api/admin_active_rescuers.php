<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_login();
require_role('admin');

header('Content-Type: application/json; charset=utf-8');

$reqLat = isset($_GET['lat']) ? filter_var($_GET['lat'], FILTER_VALIDATE_FLOAT) : null;
$reqLon = isset($_GET['lon']) ? filter_var($_GET['lon'], FILTER_VALIDATE_FLOAT) : null;

/**
 * @return float
 */
function haversineDistanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $radius = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $radius * (2 * atan2(sqrt($a), sqrt(1 - $a)));
}

try {
    $st = $pdo->query("
        SELECT r.id, r.name, r.phone, r.status, rl.latitude, rl.longitude, rl.updated_at
        FROM rescuers r
        INNER JOIN rescuer_locations rl ON rl.rescuer_id = r.id
        WHERE rl.status = 'active'
        ORDER BY rl.updated_at DESC
    ");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($reqLat !== null && $reqLon !== null && $reqLat !== false && $reqLon !== false) {
        foreach ($rows as &$row) {
            $row['distance_km'] = haversineDistanceKm(
                (float)$reqLat,
                (float)$reqLon,
                (float)$row['latitude'],
                (float)$row['longitude']
            );
        }
        unset($row);
        usort($rows, static fn(array $a, array $b): int => ($a['distance_km'] <=> $b['distance_km']));
    }

    echo json_encode([
        'ok' => true,
        'rescuers' => $rows,
        'nearest_rescuer' => $rows[0] ?? null,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to fetch active rescuer locations']);
}

