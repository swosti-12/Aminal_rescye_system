-- User dashboard enhancements (run once in phpMyAdmin; skip lines that error as "Duplicate column")
USE animal_rescue;

ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) NULL;

ALTER TABLE rescue_cases ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE rescue_requests ADD COLUMN animal_detected TINYINT(1) NULL DEFAULT NULL;
ALTER TABLE rescue_requests ADD COLUMN animal_confidence DECIMAL(6,4) NULL DEFAULT NULL;
ALTER TABLE users 
ADD specialization VARCHAR(100) DEFAULT 'General Rescue';