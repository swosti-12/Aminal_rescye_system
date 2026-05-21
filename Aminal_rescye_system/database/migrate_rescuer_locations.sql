USE animal_rescue;

CREATE TABLE IF NOT EXISTS rescuer_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rescuer_id INT NOT NULL,
    latitude DECIMAL(10, 8) NOT NULL,
    longitude DECIMAL(11, 8) NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'inactive',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rescuer_locations_rescuer (rescuer_id),
    CONSTRAINT fk_rescuer_locations_rescuer FOREIGN KEY (rescuer_id) REFERENCES rescuers(id) ON DELETE CASCADE
);

CREATE INDEX idx_rescuer_locations_status_time ON rescuer_locations (status, updated_at);

INSERT INTO rescuer_locations (rescuer_id, latitude, longitude, status)
SELECT r.id, COALESCE(r.latitude, 0), COALESCE(r.longitude, 0), 'inactive'
FROM rescuers r
ON DUPLICATE KEY UPDATE rescuer_id = VALUES(rescuer_id);

