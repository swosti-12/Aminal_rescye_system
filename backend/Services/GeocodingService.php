<?php

declare(strict_types=1);

/**
 * Reverse geocoding via OpenStreetMap Nominatim (free, no API key).
 * Respects Nominatim usage policy: max ~1 request/second, identifiable User-Agent.
 */
final class GeocodingService
{
    private const NOMINATIM_REVERSE = 'https://nominatim.openstreetmap.org/reverse';
    private const USER_AGENT = 'RescueNet-AnimalRescue/1.0 (localhost; educational project)';

    /** @var array<string, string> */
    private static array $memoryCache = [];

    public static function coordinateFallback(float $lat, float $lon): string
    {
        return sprintf('%.5f, %.5f', $lat, $lon);
    }

    public static function reverseGeocode(float $lat, float $lon): ?string
    {
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            return null;
        }

        $key = round($lat, 5) . ',' . round($lon, 5);
        if (isset(self::$memoryCache[$key])) {
            return self::$memoryCache[$key];
        }

        self::throttleNominatim();

        $url = self::NOMINATIM_REVERSE . '?' . http_build_query([
            'lat' => $lat,
            'lon' => $lon,
            'format' => 'json',
            'addressdetails' => 1,
            'zoom' => 18,
        ]);

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'User-Agent: ' . self::USER_AGENT . "\r\nAccept: application/json\r\n",
                'timeout' => 10,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        $name = trim((string) ($data['display_name'] ?? ''));
        if ($name === '') {
            return null;
        }

        self::$memoryCache[$key] = $name;

        return $name;
    }

    private static function throttleNominatim(): void
    {
        $dir = __DIR__ . '/../cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $file = $dir . '/nominatim_throttle.txt';
        $last = is_file($file) ? (float) file_get_contents($file) : 0.0;
        $now = microtime(true);
        $wait = 1.05 - ($now - $last);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        file_put_contents($file, (string) microtime(true));
    }

    public static function hasAddressColumn(PDO $pdo): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        try {
            $st = $pdo->query("SHOW COLUMNS FROM rescue_cases LIKE 'address'");
            $cached = (bool) $st->fetch();
        } catch (Throwable $e) {
            $cached = false;
        }

        return $cached;
    }

    public static function saveCaseAddress(PDO $pdo, int $caseId, string $address): void
    {
        $address = trim($address);
        if ($address === '' || !self::hasAddressColumn($pdo)) {
            return;
        }
        $pdo->prepare(
            'UPDATE rescue_cases SET address = ? WHERE id = ? AND (address IS NULL OR TRIM(address) = \'\')'
        )->execute([$address, $caseId]);
    }
}
