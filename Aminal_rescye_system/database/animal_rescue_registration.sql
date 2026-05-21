CREATE DATABASE IF NOT EXISTS animal_rescue;
USE animal_rescue;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    username VARCHAR(120) NOT NULL UNIQUE,
    email VARCHAR(190) UNIQUE,
    name VARCHAR(120) NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('user','rescuer','admin') NOT NULL DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
