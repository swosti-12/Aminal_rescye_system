<?php

declare(strict_types=1);

require_once __DIR__ . '/../api_service.php';
require_once __DIR__ . '/GeocodingService.php';

final class RescueSubmissionService
{
    public function __construct(
        private PDO $pdo,
        private RescueRepository $rescues,
        private AiImageAnalysisService $ai
    ) {}

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @return array{success: bool, message: string, type: string}
     */
    public function submitFromUser(int $userId, array $post, array $files): array
    {
        $animalTypeRaw = trim((string) ($post['animal_type'] ?? ''));
        $animalType = $this->sanitizeAnimalType($animalTypeRaw);
        $description = trim((string) ($post['description'] ?? ''));
        $locationText = trim((string) ($post['location_text'] ?? ''));
        $lat = isset($post['latitude']) && $post['latitude'] !== '' ? (float) $post['latitude'] : null;
        $lon = isset($post['longitude']) && $post['longitude'] !== '' ? (float) $post['longitude'] : null;
        $userPriority = (string) ($post['report_priority'] ?? 'normal');
        $priorityLevel = $this->mapUserPriority($userPriority);

        if ($animalType === '' || $description === '') {
            return ['success' => false, 'message' => 'Animal type and description are required.', 'type' => 'error'];
        }

        if (!$this->isAnimalTypeValid($animalType)) {
            return [
                'success' => false,
                'message' => 'Animal type must be 2-60 characters and contain only letters, numbers, spaces, and basic punctuation.',
                'type' => 'error',
            ];
        }

        if ($lat === null || $lon === null) {
            return ['success' => false, 'message' => 'Please set location on the map or use “Use my location”.', 'type' => 'error'];
        }

        $geocodedAddress = GeocodingService::reverseGeocode($lat, $lon);
        $resolvedAddress = $locationText !== ''
            ? $locationText
            : ($geocodedAddress ?? GeocodingService::coordinateFallback($lat, $lon));

        $upload = $this->saveUploadedImage($files['image'] ?? null);
        if ($upload['error']) {
            return ['success' => false, 'message' => $upload['error'], 'type' => 'error'];
        }

        $imagePath = $upload['relative'];
        $absolute = $upload['absolute'];
        $mime = $upload['mime'];
        $safeName = $upload['safe_name'];

        if ($absolute === null || $mime === null || $safeName === null) {
            return ['success' => false, 'message' => 'A photo is required for AI validation.', 'type' => 'error'];
        }

        $analysis = $this->ai->analyzeRescueImage($absolute, $mime, $safeName);
        if (!$analysis['ok']) {
            return ['success' => false, 'message' => (string) ($analysis['error'] ?? 'Analysis failed.'), 'type' => 'error'];
        }

        $animalDetected = isset($analysis['animal']['contains_animal']) && $analysis['animal']['contains_animal'];
        $animalConf = isset($analysis['animal']['confidence']) ? (float) $analysis['animal']['confidence'] : 0.0;
        $rejection = $analysis['rejection'] ?? null;

        if ($rejection === 'no_animal') {
            return $this->persistRejectedCase(
                $userId,
                $animalType,
                $description,
                $imagePath,
                $lat,
                $lon,
                $locationText,
                $priorityLevel,
                'not injured',
                0.0,
                $animalDetected ? 1 : 0,
                $animalConf,
                'The image does not appear to contain an animal. Please upload a clear photo of the animal.'
            );
        }

        if ($rejection === 'animal_low_confidence') {
            $pct = round($animalConf * 100, 1);
            return $this->persistRejectedCase(
                $userId,
                $animalType,
                $description,
                $imagePath,
                $lat,
                $lon,
                $locationText,
                $priorityLevel,
                'not injured',
                0.0,
                1,
                $animalConf,
                'The automatic detector only reached ' . $pct . '% confidence on the animal. '
                . 'Try a closer, well-lit photo with the full animal in frame (avoid extreme angles).'
            );
        }

        $aiResult = (string) $analysis['ai_result'];
        $injuryConf = (float) $analysis['injury_confidence'];
        $isAccepted = !empty($analysis['accepted']);

        $severity = $isAccepted ? 'high' : 'low';
        $status = $isAccepted ? 'pending' : 'rejected';

        // No auto-assignment — admin manually assigns via Rescuer Directory
        // This ensures blocked/offline rescuers are never auto-assigned
        $assignedId = null;

        $caseId = $this->rescues->insertCase([
            'reporter_id' => $userId,
            'animal_type' => $animalType,
            'description' => $description,
            'image_path' => $imagePath,
            'lat' => $lat,
            'lon' => $lon,
            'address' => $geocodedAddress ?? '',
            'severity' => $severity,
            'priority' => $priorityLevel,
            'rescuer_id' => $assignedId,
            'status' => $status,
        ]);

        $humanLocation = $resolvedAddress;
        $this->rescues->insertRescueRequest([
            'user_id' => $userId,
            'case_id' => $caseId,
            'rescuer_id' => $assignedId,
            'image' => $imagePath,
            'description' => $description,
            'location' => $humanLocation,
            'ai_result' => $aiResult,
            'confidence' => $injuryConf,
            'status' => $isAccepted ? 'Accepted' : 'Rejected',
            'priority' => $isAccepted ? 'High' : 'Low',
            'rescuer_notified' => $assignedId ? 1 : 0,
            'animal_detected' => 1,
            'animal_confidence' => $animalConf,
        ]);

        if ($isAccepted) {
            $this->maybeInsertAdoptionPrediction($caseId, $animalType, $severity);
        }

        if ($isAccepted) {
            $msg = sprintf(
                'Request accepted. Animal detected (%.0f%%). Injury signal: %s (%.1f%%). %s',
                $animalConf * 100,
                strtoupper(str_replace('_', ' ', $aiResult)),
                $injuryConf * 100,
                $assignedId ? 'A rescuer has been assigned.' : 'Your case is queued — a rescuer will be assigned soon.'
            );
            return ['success' => true, 'message' => $msg, 'type' => 'success'];
        }

        return [
            'success' => true,
            'message' => sprintf(
                'Animal confirmed (%.0f%%), but the case was not auto-dispatched (injury confidence %.1f%%). Only high-confidence injury signals are auto-accepted.',
                $animalConf * 100,
                $injuryConf * 100
            ),
            'type' => 'warning',
        ];
    }

    /**
     * @return array{success: bool, message: string, type: string}
     */
    private function persistRejectedCase(
        int $userId,
        string $animalType,
        string $description,
        ?string $imagePath,
        float $lat,
        float $lon,
        string $locationText,
        string $priorityLevel,
        string $aiResult,
        float $confidence,
        int $animalDetected,
        float $animalConf,
        string $userMessage
    ): array {
        $geocodedAddress = GeocodingService::reverseGeocode($lat, $lon);
        $resolvedAddress = $locationText !== ''
            ? $locationText
            : ($geocodedAddress ?? GeocodingService::coordinateFallback($lat, $lon));

        $caseId = $this->rescues->insertCase([
            'reporter_id' => $userId,
            'animal_type' => $animalType,
            'description' => $description,
            'image_path' => $imagePath,
            'lat' => $lat,
            'lon' => $lon,
            'address' => $geocodedAddress ?? '',
            'severity' => 'low',
            'priority' => $priorityLevel,
            'rescuer_id' => null,
            'status' => 'rejected',
        ]);

        $humanLocation = $resolvedAddress;
        $img = $imagePath ?? '';
        $this->rescues->insertRescueRequest([
            'user_id' => $userId,
            'case_id' => $caseId,
            'rescuer_id' => null,
            'image' => $img,
            'description' => $description,
            'location' => $humanLocation,
            'ai_result' => $aiResult,
            'confidence' => $confidence,
            'status' => 'Rejected',
            'priority' => 'Low',
            'rescuer_notified' => 0,
            'animal_detected' => $animalDetected,
            'animal_confidence' => $animalConf,
        ]);

        return ['success' => true, 'message' => $userMessage, 'type' => 'error'];
    }

    private function mapUserPriority(string $key): string
    {
        return match ($key) {
            'critical' => 'urgent',
            'medium' => 'medium',
            default => 'low',
        };
    }

    /**
     * @param array<string, mixed>|null $file
     * @return array{error: ?string, relative: ?string, absolute: ?string, mime: ?string, safe_name: ?string}
     */
    private function saveUploadedImage(?array $file): array
    {
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['error' => 'Please upload an image.', 'relative' => null, 'absolute' => null, 'mime' => null, 'safe_name' => null];
        }

        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        $type = (string) ($file['type'] ?? '');
        if (!in_array($type, $allowed, true)) {
            return ['error' => 'Invalid file type. Use JPG, PNG, or WebP.', 'relative' => null, 'absolute' => null, 'mime' => null, 'safe_name' => null];
        }

        if ((int) $file['size'] > MAX_UPLOAD_SIZE) {
            return ['error' => 'File too large (max 5MB).', 'relative' => null, 'absolute' => null, 'mime' => null, 'safe_name' => null];
        }

        $dir = __DIR__ . '/../../uploads/';
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            return ['error' => 'Could not create upload folder.', 'relative' => null, 'absolute' => null, 'mime' => null, 'safe_name' => null];
        }

        $ext = pathinfo((string) $file['name'], PATHINFO_EXTENSION);
        $safe = time() . '_' . uniqid('', true) . '.' . $ext;
        $target = $dir . $safe;
        if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
            return ['error' => 'Failed to save upload.', 'relative' => null, 'absolute' => null, 'mime' => null, 'safe_name' => null];
        }

        return [
            'error' => null,
            'relative' => 'uploads/' . $safe,
            'absolute' => $target,
            'mime' => $type,
            'safe_name' => $safe,
        ];
    }

    private function maybeInsertAdoptionPrediction(int $caseId, string $animalType, string $severity): void
    {
        $adoption = call_ml_api('/predict-adoption', [
            'age_years' => random_int(1, 12),
            'gender' => 'unknown',
            'health_score' => $severity === 'high' ? 4 : 7,
            'injury_score' => $severity === 'high' ? 4 : 2,
            'breed_popularity' => 3,
            'location_demand' => 3,
        ]);
        if ($adoption && isset($adoption['adoption_probability'])) {
            $this->pdo->prepare('INSERT INTO adoption_predictions (case_id, adoption_probability, adoption_category) VALUES (?, ?, ?)')
                ->execute([
                    $caseId,
                    $adoption['adoption_probability'],
                    $adoption['adoption_category'] ?? 'medium',
                ]);
        }
    }

    private function sanitizeAnimalType(string $value): string
    {
        $clean = preg_replace('/\s+/', ' ', $value);
        return trim((string) $clean);
    }

    private function isAnimalTypeValid(string $value): bool
    {
        $len = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($len < 2 || $len > 60) {
            return false;
        }
        return (bool) preg_match('/^[a-zA-Z0-9\s\-\',\.]+$/', $value);
    }
}
