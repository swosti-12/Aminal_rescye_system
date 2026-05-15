<?php
/**
 * Reverse geocoding via OpenStreetMap Nominatim with optional rescue_cases.address cache.
 */
class GeocodingService
{
    private const NOMINATIM_REVERSE = 'https://nominatim.openstreetmap.org/reverse';
    private const USER_AGENT = 'AnimalRescueSystem/1.0 (rescuer-dashboard)';

    public static function isValidCoordinates(float $lat, float $lon): bool
    {
        return $lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180;
    }

    public static function coordinateFallback(float $lat, float $lon): string
    {
        return sprintf('%.4f, %.4f', $lat, $lon);
    }

    /**
     * @return string|null Human-readable address or null on failure
     */
    public static function reverseGeocode(PDO $pdo, float $lat, float $lon, ?int $caseId = null): ?string
    {
        if (!self::isValidCoordinates($lat, $lon)) {
            return null;
        }

        if ($caseId !== null) {
            $cached = self::readCachedAddress($pdo, $caseId);
            if ($cached !== null) {
                return $cached;
            }
        }

        $address = self::fetchFromNominatim($lat, $lon);
        if ($address !== null && $caseId !== null) {
            self::writeCachedAddress($pdo, $caseId, $address);
        }

        return $address;
    }

    private static function readCachedAddress(PDO $pdo, int $caseId): ?string
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT address FROM rescue_cases WHERE id = ? AND address IS NOT NULL AND address != \'\' LIMIT 1'
            );
            $stmt->execute([$caseId]);
            $row = $stmt->fetch();
            if ($row && !empty($row['address'])) {
                return trim((string)$row['address']);
            }
        } catch (Throwable $e) {
            // address column may not exist until migration is applied
        }
        return null;
    }

    private static function writeCachedAddress(PDO $pdo, int $caseId, string $address): void
    {
        $address = trim($address);
        if ($address === '') {
            return;
        }
        try {
            $pdo->prepare('UPDATE rescue_cases SET address = ? WHERE id = ?')
                ->execute([$address, $caseId]);
        } catch (Throwable $e) {
            // Column missing or DB error — geocoding still works without cache
        }
    }

    private static function fetchFromNominatim(float $lat, float $lon): ?string
    {
        $url = self::NOMINATIM_REVERSE . '?' . http_build_query([
            'lat' => round($lat, 6),
            'lon' => round($lon, 6),
            'format' => 'json',
            'addressdetails' => '1',
        ]);

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: ' . self::USER_AGENT,
            ],
        ]);

        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $httpCode < 200 || $httpCode >= 300) {
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }

        if (!empty($data['display_name']) && is_string($data['display_name'])) {
            return trim($data['display_name']);
        }

        if (!empty($data['address']) && is_array($data['address'])) {
            return self::formatAddressParts($data['address']);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $parts
     */
    private static function formatAddressParts(array $parts): ?string
    {
        $keys = [
            'house_number', 'road', 'neighbourhood', 'suburb',
            'city', 'town', 'village', 'county', 'state', 'postcode', 'country',
        ];
        $segments = [];
        foreach ($keys as $key) {
            if (!empty($parts[$key]) && is_string($parts[$key])) {
                $val = trim($parts[$key]);
                if ($val !== '' && !in_array($val, $segments, true)) {
                    $segments[] = $val;
                }
            }
        }
        if ($segments === []) {
            return null;
        }
        return implode(', ', $segments);
    }
}
