<?php
/**
 * JSON API: reverse geocode latitude/longitude to a human-readable address.
 * GET ?lat=...&lng=...&case_id=... (case_id optional, enables MySQL cache)
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/Services/GeocodingService.php';

require_any_role(['rescuer', 'admin']);

$lat = filter_var($_GET['lat'] ?? $_GET['latitude'] ?? '', FILTER_VALIDATE_FLOAT);
$lng = filter_var($_GET['lng'] ?? $_GET['lon'] ?? $_GET['longitude'] ?? '', FILTER_VALIDATE_FLOAT);
$caseId = filter_var($_GET['case_id'] ?? '', FILTER_VALIDATE_INT);
$caseId = $caseId !== false && $caseId > 0 ? (int)$caseId : null;

if ($lat === false || $lng === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid coordinates']);
    exit;
}

$lat = (float)$lat;
$lng = (float)$lng;

if (!GeocodingService::isValidCoordinates($lat, $lng)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Coordinates out of range']);
    exit;
}

$cachedBefore = null;
if ($caseId !== null) {
    try {
        $st = $pdo->prepare(
            'SELECT address FROM rescue_cases WHERE id = ? AND address IS NOT NULL AND address != \'\' LIMIT 1'
        );
        $st->execute([$caseId]);
        $row = $st->fetch();
        if ($row && !empty($row['address'])) {
            $cachedBefore = trim((string)$row['address']);
        }
    } catch (Throwable $e) {
    }
}

$address = GeocodingService::reverseGeocode($pdo, $lat, $lng, $caseId);

if ($address === null) {
    echo json_encode([
        'ok' => false,
        'error' => 'Geocoding unavailable',
        'fallback' => GeocodingService::coordinateFallback($lat, $lng),
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'address' => $address,
    'cached' => $cachedBefore !== null,
    'fallback' => GeocodingService::coordinateFallback($lat, $lng),
]);
