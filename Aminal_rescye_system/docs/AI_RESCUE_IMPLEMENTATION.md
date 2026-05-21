# Animal Rescue System - AI Auto Accept/Reject Implementation

## 1) System Architecture

```
[User Browser]
    |
    | 1. Form submit (image + description + location)
    v
[PHP: ai_rescue_request.php]
    |
    | 2. POST multipart/form-data
    v
[PHP API: backend/submit_rescue_request_ai.php]
    |-- validates file/type/size
    |-- stores image in uploads/requests/
    |
    | 3. cURL upload + X-API-KEY
    v
[Flask API: /api/v1/injury-check]
    |-- image preprocessing
    |-- dummy/model inference
    |-- returns {result, confidence}
    |
    | 4. JSON response
    v
[PHP Decision Logic]
    |-- if result=injured && confidence>=0.7 => Accepted/High
    |-- else => Rejected/Low
    |
    | 5. save into MySQL rescue_requests
    v
[MySQL Database]
    |
    | 6. if accepted, assign available rescuer
    v
[Rescuer/Admin Dashboards]
```

## 2) Flask API

- New endpoint: `POST /api/v1/injury-check`
- Security: API key in header `X-API-KEY`
- Request: multipart/form-data, key `image`
- Response:

```json
{
  "result": "injured",
  "confidence": 0.8123
}
```

### Dummy vs Real Model

- Dummy logic now in `detect_injury_dummy()` (confidence from image statistics).
- For real model, replace call with `detect_injury_model()`.

## 3) PHP Backend

- New handler: `backend/submit_rescue_request_ai.php`
- Performs:
  - Form validation
  - File MIME + extension + size check
  - Local image storage
  - cURL call to Flask
  - Accept/Reject rule execution
  - DB insert in `rescue_requests`
  - Rescuer assignment for accepted requests

## 4) MySQL Tables

Added table:

```sql
CREATE TABLE IF NOT EXISTS rescue_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    rescuer_id INT NULL,
    image VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    location VARCHAR(255) NOT NULL,
    ai_result ENUM('injured', 'not injured') NOT NULL,
    confidence DECIMAL(5,4) NOT NULL,
    status ENUM('Accepted', 'Rejected') NOT NULL,
    priority ENUM('High', 'Low') NOT NULL,
    rescuer_notified TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (rescuer_id) REFERENCES users(id) ON DELETE SET NULL
);
```

## 5) Folder Structure

### PHP (XAMPP)

```
Animal rescue system/
├── ai_rescue_request.php
├── backend/
│   ├── config.php
│   ├── db_config.php
│   └── submit_rescue_request_ai.php
├── uploads/
│   └── requests/
└── database/
    └── schema.sql
```

### Flask

```
Animal rescue system/
├── app.py
└── models/
```

## 6) Frontend UI

- New page: `ai_rescue_request.php`
- Uses existing header/navbar and adds a clean form:
  - Description
  - Location
  - Image upload
- Uses Fetch API to submit form and render AI result + decision summary.

## 7) Security and Validation

- File extension + MIME check on PHP side.
- 5MB upload limit in PHP.
- 10MB cap in Flask.
- API key validation in Flask endpoint.
- Randomized upload file names.
- Session + role guard using `require_login()` + `require_role('user')`.

## 8) Sample Data

### Example Input

- description: "Dog bleeding near bus stop"
- location: "Koteshwor, Kathmandu"
- image: `injured_dog.jpg`

### Example AI JSON Response

```json
{
  "result": "injured",
  "confidence": 0.84
}
```

### Example Final Decision

```json
{
  "status": "Accepted",
  "priority": "High"
}
```

## 9) Algorithm (Exam-Oriented)

### Request Handling Algorithm

1. User submits image, description, and location.
2. PHP validates required fields.
3. PHP validates image type and size.
4. PHP stores image in uploads folder.
5. PHP sends image to Flask endpoint via cURL.
6. Flask processes image and returns result + confidence.
7. PHP executes decision rule.
8. PHP stores complete record in `rescue_requests`.
9. If accepted, assign available rescuer and mark notification flag.
10. Return success response to frontend.

### Decision Algorithm

1. Read `result` and `confidence` from Flask response.
2. If `result == "injured"` and `confidence >= 0.7`:
   - `status = "Accepted"`
   - `priority = "High"`
3. Else:
   - `status = "Rejected"`
   - `priority = "Low"`
4. Save values in database.

## 10) Viva Explanation

- AI integration is done through a service-based architecture:
  - PHP handles web, user session, and database.
  - Flask handles ML image inference.
- Benefit of accept/reject logic:
  - Reduces manual screening delay.
  - Provides transparent and consistent rules.
  - Enables faster dispatch for urgent cases.

## 11) Advanced Improvements

1. Replace dummy detector with TensorFlow model (MobileNet/EfficientNet).
2. Add confidence calibration and threshold tuning (ROC-based).
3. Add queue (RabbitMQ/Redis) for high traffic asynchronous inference.
4. Add audit logs for explainability (who/when/what decision).
5. Add push notifications (email/SMS/WhatsApp) for accepted cases.

## Run Steps (Local)

1. Import `database/schema.sql` in phpMyAdmin.
2. Start Apache + MySQL in XAMPP.
3. Start Flask:
   - `set ANIMAL_RESCUE_API_KEY=rescue-local-key-2026`
   - `python app.py`
4. Login as user and open:_
   - `http://localhost/Animal rescue system/ai_rescue_request.php`
5. Submit test image and verify DB insertion in `rescue_requests`.
