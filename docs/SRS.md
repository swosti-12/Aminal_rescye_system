# System Requirements Specification (SRS) - Animal Rescue System

## 1. Introduction
The Animal Rescue System is an intelligent orchestration platform designed to streamline and automate the process of rescuing endangered or injured animals. It integrates a frontend built with HTML, CSS, and JS (coordinated with PHP) and an independent Machine Learning API (Flask) for analytical prediction and algorithmic matching.

## 2. Overall Description
The system caters to three main entities:
- **Users**: Citizens who witness injured animals and use the mobile-responsive portal to report incidents, uploading images and granting geolocation access.
- **Rescuers**: Trained volunteers who declare their availability and geographic coordinates to receive automated pings based on closest proximity.
- **Administrators**: Central monitoring staff accessing an aggregated dashboard to view AI metrics, override priorities if necessary, and track active resolution rates.

## 3. Machine Learning & Algorithms Integration

### 3.1. Haversine Algorithm Implementation
The system calculates the shortest distance over the earth's surface between two points (Reporter and Rescuers) using latitude and longitude:
```python
def haversine(lat1, lon1, lat2, lon2):
    R = 6371.0  # Earth radius in kilometers
    lat1, lon1, lat2, lon2 = map(math.radians, [lat1, lon1, lat2, lon2])
    dlat = lat2 - lat1
    dlon = lon2 - lon1
    a = math.sin(dlat / 2)**2 + math.cos(lat1) * math.cos(lat2) * math.sin(dlon / 2)**2
    c = 2 * math.asin(math.sqrt(a))
    return R * c
```
**Application**: Iterate through all available rescuer locations and return the ID associated with the smallest computed distance to the reporter's exact coordinates.

### 3.2. Automated Priority Classification
The system utilizes a heuristic priority assignment logic based on AI image processing outputs (mocked via randomized weighting in this prototype but extensible to Convolutional Neural Networks).
- *Critical* Injury -> **Urgent** Priority
- *High* Injury -> **High** Priority
- *Medium* Injury -> **Medium** Priority
- *Low* Injury -> **Low** Priority

### 3.3. Adoption Rate Prediction
Using Scikit-Learn's `DecisionTreeClassifier`, the system trains on historically synthetic data evaluating `animal_type`, `age_months`, `health_condition`, and `injury_severity`.
The output classifies the likelihood of the animal being adopted post-rescue:
- **Low**: `< 40% probability`
- **Medium**: `40% - 70% probability`
- **High**: `> 70% probability`

## 4. UI/UX & Non-Functional Requirements
- **Responsive Architecture**: Flexbox and Grid based components.
- **Design System**: Glassmorphism attributes (blur filtering, RGBA backgrounds), CSS gradients, Inter & Poppins typography integrations.
- **Security**: Password hashing using bcrypt (`password_hash`), CSRF protections natively bounded to PDO parameterized statements preventing SQL Injection (SQLi).
- **Scalable Modularity**: API interactions via asynchronous REST (cURL in PHP), decoupled monolithic frontend structuring.

## 5. Deployment Guide
1. Create a MySQL DB named `animal_rescue`.
2. Run `database/schema.sql`.
3. Start the PHP server at project root: `php -S 127.0.0.1:8000`
4. Navigate to `/api/` and run `pip install -r requirements.txt`.
5. Execute `python model_trainer.py` to create the predictive model pickling schemas.
6. Start the API using `python app.py` (running on port 5000).
7. Visit `http://127.0.0.1:8000` inside your browser.
