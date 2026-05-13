-- Manage Rescuer module schema + sample data
USE animal_rescue;

CREATE TABLE IF NOT EXISTS rescuers (
    id INT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    phone VARCHAR(20) NULL,
    status ENUM('available', 'busy', 'offline') NOT NULL DEFAULT 'offline',
    latitude DECIMAL(10, 8) NULL,
    longitude DECIMAL(11, 8) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rescuers_user FOREIGN KEY (id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS rescue_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_name VARCHAR(120) NOT NULL,
    latitude DECIMAL(10, 8) NOT NULL,
    longitude DECIMAL(11, 8) NOT NULL,
    image VARCHAR(255) NULL,
    status ENUM('pending', 'assigned', 'completed') NOT NULL DEFAULT 'pending',
    assigned_rescuer_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rescue_requests_rescuer FOREIGN KEY (assigned_rescuer_id) REFERENCES rescuers(id) ON DELETE SET NULL
);

ALTER TABLE rescue_requests
    ADD COLUMN IF NOT EXISTS user_name VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS latitude DECIMAL(10, 8) NULL,
    ADD COLUMN IF NOT EXISTS longitude DECIMAL(11, 8) NULL,
    ADD COLUMN IF NOT EXISTS assigned_rescuer_id INT NULL;

ALTER TABLE rescue_requests
    MODIFY COLUMN status ENUM('pending', 'assigned', 'completed', 'Accepted', 'Rejected') NOT NULL DEFAULT 'pending';

CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rescuer_id INT NOT NULL,
    message VARCHAR(255) NOT NULL,
    status ENUM('unread', 'read') NOT NULL DEFAULT 'unread',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_rescuer FOREIGN KEY (rescuer_id) REFERENCES rescuers(id) ON DELETE CASCADE
);

CREATE INDEX idx_rescuers_status ON rescuers (status);
CREATE INDEX idx_requests_status ON rescue_requests (status);
CREATE INDEX idx_notifications_rescuer_status ON notifications (rescuer_id, status);

-- Seed rescuer profile rows from existing users with role=rescuer.
INSERT INTO rescuers (id, name, phone, status, latitude, longitude)
SELECT
    u.id,
    u.name,
    u.phone,
    CASE
        WHEN u.availability_status = 'available' THEN 'available'
        WHEN u.availability_status = 'busy' THEN 'busy'
        ELSE 'offline'
    END AS mapped_status,
    u.latitude,
    u.longitude
FROM users u
WHERE u.role = 'rescuer'
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    phone = VALUES(phone),
    latitude = VALUES(latitude),
    longitude = VALUES(longitude);

-- Sample pending requests
INSERT INTO rescue_requests (user_name, latitude, longitude, image, status)
VALUES
('Ali Khan', 23.7808875, 90.2792371, 'uploads/requests/sample_dog_1.jpg', 'pending'),
('Sara Ahmed', 23.7456600, 90.4034400, 'uploads/requests/sample_cat_2.jpg', 'pending');

