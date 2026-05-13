USE animal_rescue;

CREATE TABLE IF NOT EXISTS rescuer_activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rescuer_id INT NOT NULL,
    case_id INT NULL,
    action_type VARCHAR(80) NOT NULL,
    details TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (rescuer_id) REFERENCES users(id),
    FOREIGN KEY (case_id) REFERENCES rescue_cases(id) ON DELETE SET NULL
);
