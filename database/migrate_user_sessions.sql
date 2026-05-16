-- Optional: server-side session audit for multi-role login (not required for basic demo mode).
USE animal_rescue;

CREATE TABLE IF NOT EXISTS user_sessions (
    session_id VARCHAR(128) NOT NULL PRIMARY KEY,
    user_id INT NOT NULL,
    role ENUM('admin', 'rescuer', 'user') NOT NULL,
    last_active TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_sessions_user (user_id),
    INDEX idx_user_sessions_role (role)
);
