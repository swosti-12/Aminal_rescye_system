<?php
/**
 * JSON API: returns nearby rescuers (lat/lon + name) within a given radius (km).
 * Query params: lat, lon, radius (default 25 km)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db_config.php';

$lat = isset($_GET['lat']) ? filter_var($_GET['lat'], FILTER_VALIDATE_FLOAT) : false;
$lon = isset($_GET['lon']) ? filter_var($_GET['lon'], FILTER_VALIDATE_FLOAT) : false;
$radius = isset($_GET['radius']) ? filter_var($_GET['radius'], FILTER_VALIDATE_FLOAT) : 25;

if ($lat === false || $lon === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid coordinates']);
    exit;
}

if ($radius <= 0 || $radius > 100) {
    $radius = 25;
}

/*
 * Haversine formula in SQL to compute distance in km.
 * Returns rescuers with valid coordinates within the given radius.
 */
$sql = "
    SELECT
        id,
        full_name AS name,
        latitude,
        longitude,
        (6371 * ACOS(
            COS(RADIANS(:lat1)) * COS(RADIANS(latitude)) *
            COS(RADIANS(longitude) - RADIANS(:lon1)) +
            SIN(RADIANS(:lat2)) * SIN(RADIANS(latitude))
        )) AS distance_km
    FROM users
    WHERE role = 'rescuer'
      AND latitude IS NOT NULL
      AND longitude IS NOT NULL
      AND latitude BETWEEN -90 AND 90
      AND longitude BETWEEN -180 AND 180
    HAVING distance_km <= :radius
    ORDER BY distance_km ASC
    LIMIT 20
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':lat1'   => $lat,
        ':lon1'   => $lon,
        ':lat2'   => $lat,
        ':radius' => $radius,
    ]);

    $rescuers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* Round coordinates to ~1 km precision for privacy */
    foreach ($rescuers as &$r) {
        $r['latitude']    = round((float)$r['latitude'], 3);
        $r['longitude']   = round((float)$r['longitude'], 3);
        $r['distance_km'] = round((float)$r['distance_km'], 2);
        unset($r['id']); // don't expose internal IDs
    }

    echo json_encode(['ok' => true, 'rescuers' => $rescuers]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
