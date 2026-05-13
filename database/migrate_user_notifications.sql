USE animal_rescue;

CREATE TABLE IF NOT EXISTS user_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    case_id INT NULL,
    message VARCHAR(255) NOT NULL,
    category ENUM('in_progress', 'arrived', 'resolved', 'reassigned', 'status_update') DEFAULT 'status_update',
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (case_id) REFERENCES rescue_cases(id) ON DELETE SET NULL,
    INDEX idx_user_notifications_user_read (user_id, is_read),
    INDEX idx_user_notifications_case (case_id)
);

-- Sample notification seed (safe when matching user/case exists)
INSERT INTO user_notifications (user_id, case_id, message, category, is_read)
SELECT c.reporter_id, c.id, 'Request in progress: rescuer is moving to your pinned location.', 'in_progress', 0
FROM rescue_cases c
WHERE c.status IN ('pending', 'accepted')
LIMIT 1;

