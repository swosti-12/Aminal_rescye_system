-- Admin Notifications table + update user_notifications ENUM
USE animal_rescue;

-- 1. Admin notifications table
CREATE TABLE IF NOT EXISTS admin_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NULL COMMENT 'NULL = all admins',
    case_id INT NULL,
    rescuer_id INT NULL,
    message VARCHAR(500) NOT NULL,
    category ENUM('assignment','progress_update','location_update','status_change','system') DEFAULT 'system',
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin_notif_read (admin_id, is_read),
    INDEX idx_admin_notif_case (case_id),
    INDEX idx_admin_notif_created (created_at DESC)
);

-- 2. Add 'progress_update' to user_notifications category if missing
ALTER TABLE user_notifications
MODIFY COLUMN category ENUM('in_progress','arrived','resolved','reassigned','status_update','progress_update') DEFAULT 'status_update';
