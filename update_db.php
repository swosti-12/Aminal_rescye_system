<?php
require_once __DIR__ . '/backend/db_config.php';

try {
    $pdo->query("ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) NULL");
    echo "Column profile_picture added successfully.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "Column already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
?>
