CREATE DATABASE IF NOT EXISTS animal_rescue;
USE animal_rescue;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('user', 'rescuer', 'admin') DEFAULT 'user',
    account_status ENUM('active', 'blocked') DEFAULT 'active',
    phone VARCHAR(15),
    latitude DECIMAL(10, 8) NULL,
    longitude DECIMAL(11, 8) NULL,
    availability_status ENUM('available', 'busy') DEFAULT 'available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE rescue_cases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reporter_id INT NOT NULL,
    animal_type VARCHAR(50) NOT NULL,
    description TEXT,
    image_path VARCHAR(255) NULL,
    latitude DECIMAL(10, 8) NOT NULL,
    longitude DECIMAL(11, 8) NOT NULL,
    assigned_rescuer_id INT NULL,
    
    -- AI Generated Fields
    detected_injury_severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'low',
    priority_level ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'low',
    
    status ENUM('pending', 'accepted', 'resolved', 'rejected') DEFAULT 'pending',
    proof_image_path VARCHAR(255) NULL,
    resolution_notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    
    FOREIGN KEY (reporter_id) REFERENCES users(id),
    FOREIGN KEY (assigned_rescuer_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE adoption_predictions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    case_id INT NOT NULL,
    adoption_probability DECIMAL(5, 2) NOT NULL,
    adoption_category ENUM('low', 'medium', 'high') NOT NULL,
    features_json TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES rescue_cases(id) ON DELETE CASCADE
);

-- AI auto-decision workflow table requested for final year project
CREATE TABLE IF NOT EXISTS rescue_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    case_id INT NULL,
    rescuer_id INT NULL,
    image VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    location VARCHAR(255) NOT NULL,
    ai_result ENUM('injured', 'not injured') NOT NULL,
    confidence DECIMAL(5,4) NOT NULL,
    status ENUM('Accepted', 'Rejected') NOT NULL,
    priority ENUM('High', 'Low') NOT NULL,
    rescuer_notified TINYINT(1) DEFAULT 0,
    decision_source ENUM('ai', 'admin') DEFAULT 'ai',
    override_note VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (case_id) REFERENCES rescue_cases(id) ON DELETE SET NULL,
    FOREIGN KEY (rescuer_id) REFERENCES users(id) ON DELETE SET NULL
);

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

CREATE TABLE IF NOT EXISTS user_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    case_id INT NULL,
    message VARCHAR(255) NOT NULL,
    category ENUM('in_progress', 'arrived', 'resolved', 'reassigned', 'status_update') DEFAULT 'status_update',
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (case_id) REFERENCES rescue_cases(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS rescuer_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rescuer_id INT NOT NULL,
    latitude DECIMAL(10, 8) NOT NULL,
    longitude DECIMAL(11, 8) NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'inactive',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rescuer_locations_rescuer (rescuer_id),
    FOREIGN KEY (rescuer_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Default Admin user (Password is 'password')
INSERT INTO users (name, email, password, role) 
VALUES ('System Admin', 'admin@rescue.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');



-- fo app.py we have different libraries like from flask import Flask
-- from PIL import Image
-- import numpy as np
-- from sklearn.tree import DecisionTreeClassifier
-- from sklearn.preprocessing import LabelEncoder
-- import joblib

-- so to run this application, you will need to install the following Python libraries:
-- 1. Flask: A micro web framework for Python.
--    You can install it using pip:
--    ```
--    pip install Flask
--    ```
-- 2. Pillow: A Python Imaging Library (PIL) fork that adds image processing capabilities.
--     ```
--     pip install Pillow
--     ```
-- 3. NumPy: A library for numerical computing in Python.
--     ```
--     pip install numpy
--     ```
-- 4. scikit-learn: A machine learning library for Python.
--     ```
--     pip install scikit-learn
--     ```
-- 5. joblib: A library for saving and loading Python objects, often used for machine learning
--     models.
--     ```
--     pip install joblib
--     ```

-- create virtual environment
-- python -m venv venv
-- On the same machine as XAMPP, from the project folder, with your venv active:

-- Install image stack: pip install -r requirements-ai.txt
-- Start the API: python app.py
-- Keep that terminal open while you submit reports. If the engine is off, Flask returns 503 with a hint about missing deps—PHP will surface that text now.