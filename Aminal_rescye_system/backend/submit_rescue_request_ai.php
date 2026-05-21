<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/config.php';

require_login();
require_role('user');

header('Content-Type: application/json');

function json_response(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['ok' => false, 'message' => 'Method not allowed']);
}

$description = trim($_POST['description'] ?? '');
$location = trim($_POST['location'] ?? '');

if ($description === '' || $location === '') {
    json_response(422, ['ok' => false, 'message' => 'Description and location are required']);
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    json_response(422, ['ok' => false, 'message' => 'Valid image is required']);
}

$file = $_FILES['image'];
$allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
$allowedMime = ['image/jpeg', 'image/png', 'image/webp'];

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$mime = mime_content_type($file['tmp_name']) ?: '';

if (!in_array($ext, $allowedExtensions, true) || !in_array($mime, $allowedMime, true)) {
    json_response(422, ['ok' => false, 'message' => 'Only JPG, JPEG, PNG, WEBP images are allowed']);
}

if ((int)$file['size'] > MAX_UPLOAD_SIZE) {
    json_response(422, ['ok' => false, 'message' => 'File too large. Maximum allowed size is 5MB']);
}

$uploadDir = __DIR__ . '/../uploads/requests/';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
    json_response(500, ['ok' => false, 'message' => 'Unable to create upload directory']);
}

$safeName = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$absoluteImagePath = $uploadDir . $safeName;
$relativeImagePath = 'uploads/requests/' . $safeName;

if (!move_uploaded_file($file['tmp_name'], $absoluteImagePath)) {
    json_response(500, ['ok' => false, 'message' => 'Failed to save uploaded image']);
}

$ch = curl_init(FLASK_API_BASE . '/api/v1/injury-check');
$postFields = [
    'image' => new CURLFile($absoluteImagePath, $mime, $safeName),
];

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postFields,
    CURLOPT_HTTPHEADER => [
        'X-API-KEY: ' . FLASK_API_KEY
    ],
    CURLOPT_TIMEOUT => 30
]);

$apiResponseRaw = curl_exec($ch);
$apiHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($apiResponseRaw === false || $curlError) {
    json_response(502, ['ok' => false, 'message' => 'Could not connect to AI service', 'detail' => $curlError]);
}

$apiResponse = json_decode($apiResponseRaw, true);
if ($apiHttpCode !== 200 || !is_array($apiResponse) || !isset($apiResponse['result'], $apiResponse['confidence'])) {
    json_response(502, ['ok' => false, 'message' => 'Invalid AI response', 'detail' => $apiResponseRaw]);
}

$result = $apiResponse['result'] === 'injured' ? 'injured' : 'not injured';
$confidence = (float)$apiResponse['confidence'];

// Required decision logic
$status = ($result === 'injured' && $confidence >= 0.7) ? 'Accepted' : 'Rejected';
$priority = $status === 'Accepted' ? 'High' : 'Low';

// If accepted, assign the first available rescuer
$rescuerId = null;
$rescuerNotified = 0;
if ($status === 'Accepted') {
    $stmtRescuer = $pdo->query("SELECT id FROM users WHERE role='rescuer' AND availability_status='available' ORDER BY id ASC LIMIT 1");
    $rescuer = $stmtRescuer->fetch();
    if ($rescuer && isset($rescuer['id'])) {
        $rescuerId = (int)$rescuer['id'];
        $rescuerNotified = 1;
    }
}

$stmt = $pdo->prepare("
    INSERT INTO rescue_requests
    (user_id, rescuer_id, image, description, location, ai_result, confidence, status, priority, rescuer_notified)
    VALUES
    (:user_id, :rescuer_id, :image, :description, :location, :ai_result, :confidence, :status, :priority, :rescuer_notified)
");

$stmt->execute([
    ':user_id' => (int)$_SESSION['user_id'],
    ':rescuer_id' => $rescuerId,
    ':image' => $relativeImagePath,
    ':description' => $description,
    ':location' => $location,
    ':ai_result' => $result,
    ':confidence' => $confidence,
    ':status' => $status,
    ':priority' => $priority,
    ':rescuer_notified' => $rescuerNotified
]);

json_response(200, [
    'ok' => true,
    'request_id' => (int)$pdo->lastInsertId(),
    'ai' => [
        'result' => $result,
        'confidence' => $confidence
    ],
    'decision' => [
        'status' => $status,
        'priority' => $priority
    ],
    'rescuer_assigned' => $rescuerId !== null,
    'rescuer_id' => $rescuerId
]);
?>
