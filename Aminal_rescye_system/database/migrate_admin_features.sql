-- Run on existing DB (phpMyAdmin → SQL). Skip any line that errors "Duplicate column".
USE animal_rescue;

ALTER TABLE users ADD COLUMN account_status ENUM('active','blocked') DEFAULT 'active';

ALTER TABLE rescue_requests ADD COLUMN case_id INT NULL;
ALTER TABLE rescue_requests ADD COLUMN decision_source ENUM('ai','admin') DEFAULT 'ai';
ALTER TABLE rescue_requests ADD COLUMN override_note VARCHAR(500) NULL;

CREATE TABLE IF NOT EXISTS admin_activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    action_type VARCHAR(80) NOT NULL,
    target_table VARCHAR(64) NULL,
    target_id INT NULL,
    details TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS site_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES
('about_intro', 'The Animal Rescue System connects reporters, rescuers, and administrators to help animals in need.'),
('contact_address', 'Rescue Avenue, Kalanki, 44600'),
('contact_email', 'support@rescuenet.org'),
('contact_phone', '+977 9860345678'),
('announcement', '');
