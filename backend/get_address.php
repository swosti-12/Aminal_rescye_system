<?php

declare(strict_types=1);

/**
 * JSON reverse-geocoding proxy (Nominatim via GeocodingService).
 * GET: lat, lon — optional case_id to cache address on rescue_cases.
 */
require_once __DIR__ . '/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/Services/GeocodingService.php';

$lat = isset($_GET['lat']) ? filter_var($_GET['lat'], FILTER_VALIDATE_FLOAT) : false;
$lon = isset($_GET['lon']) ? filter_var($_GET['lon'], FILTER_VALIDATE_FLOAT) : false;
$caseId = isset($_GET['case_id']) ? filter_var($_GET['case_id'], FILTER_VALIDATE_INT) : 0;

if ($lat === false || $lon === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid latitude or longitude']);
    exit;
}

$address = GeocodingService::reverseGeocode((float) $lat, (float) $lon);

if ($address !== null && $caseId > 0) {
    GeocodingService::saveCaseAddress($pdo, $caseId, $address);
}

echo json_encode([
    'ok' => $address !== null,
    'address' => $address,
    'latitude' => (float) $lat,
    'longitude' => (float) $lon,
    'fallback' => GeocodingService::coordinateFallback((float) $lat, (float) $lon),
], JSON_UNESCAPED_UNICODE);
