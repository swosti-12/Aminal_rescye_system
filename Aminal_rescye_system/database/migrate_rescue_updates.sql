-- Rescue Updates (progress notes) table
USE animal_rescue;

CREATE TABLE IF NOT EXISTS rescue_updates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    case_id INT NOT NULL,
    rescuer_id INT NOT NULL,
    note TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES rescue_cases(id) ON DELETE CASCADE,
    FOREIGN KEY (rescuer_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX idx_rescue_updates_case 
ON rescue_updates (case_id, created_at DESC);