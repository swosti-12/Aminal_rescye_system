<?php
/**
 * Loads site_settings row by key. Returns $default if table missing or key absent.
 */
function get_site_setting(PDO $pdo, string $key, string $default = ''): string {
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $st = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1');
        $st->execute([$key]);
        $row = $st->fetch();
        $cache[$key] = $row && isset($row['setting_value']) ? (string)$row['setting_value'] : $default;
    } catch (Throwable $e) {
        $cache[$key] = $default;
    }
    return $cache[$key];
}

function save_site_setting(PDO $pdo, string $key, string $value): bool {
    try {
        $sql = 'INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)';
        return $pdo->prepare($sql)->execute([$key, $value]);
    } catch (Throwable $e) {
        return false;
    }
}
