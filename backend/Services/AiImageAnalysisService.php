<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

/**
 * Calls Flask API: animal presence gate, then injury check.
 */
final class AiImageAnalysisService
{
    /** Minimum detector score to treat as “animal present” (YOLO often 0.33–0.55 on lying animals / blood / odd fur). */
    private const ANIMAL_CONFIDENCE_MIN = 0.34;
    private const INJURY_ACCEPT_MIN = 0.7;

    /**
     * @return array{
     *   ok: bool,
     *   error?: string,
     *   animal?: array{contains_animal: bool, confidence: float},
     *   injury?: array{result: string, confidence: float},
     *   accepted: bool,
     *   ai_result: string,
     *   injury_confidence: float
     * }
     */
    public function analyzeRescueImage(string $absolutePath, string $mime, string $safeName): array
    {
        $animalResp = $this->postMultipart(FLASK_API_BASE . '/api/v1/animal-check', $absolutePath, $mime, $safeName);
        if (!$animalResp['ok']) {
            return [
                'ok' => false,
                'error' => $animalResp['error'],
                'accepted' => false,
                'ai_result' => 'not injured',
                'injury_confidence' => 0.0,
            ];
        }

        $animal = $animalResp['data'];
        $contains = !empty($animal['contains_animal']);
        $animalConf = isset($animal['confidence']) ? (float) $animal['confidence'] : 0.0;

        if (!$contains) {
            return [
                'ok' => true,
                'animal' => ['contains_animal' => false, 'confidence' => $animalConf],
                'accepted' => false,
                'ai_result' => 'not injured',
                'injury_confidence' => 0.0,
                'rejection' => 'no_animal',
            ];
        }

        if ($animalConf < self::ANIMAL_CONFIDENCE_MIN) {
            return [
                'ok' => true,
                'animal' => ['contains_animal' => true, 'confidence' => $animalConf],
                'accepted' => false,
                'ai_result' => 'not injured',
                'injury_confidence' => 0.0,
                'rejection' => 'animal_low_confidence',
            ];
        }

        $injuryResp = $this->postMultipart(FLASK_API_BASE . '/api/v1/injury-check', $absolutePath, $mime, $safeName);
        if (!$injuryResp['ok']) {
            return [
                'ok' => false,
                'error' => $injuryResp['error'],
                'accepted' => false,
                'ai_result' => 'not injured',
                'injury_confidence' => 0.0,
                'animal' => ['contains_animal' => true, 'confidence' => $animalConf],
            ];
        }

        $injury = $injuryResp['data'];
        $result = ($injury['result'] ?? '') === 'injured' ? 'injured' : 'not injured';
        $conf = isset($injury['confidence']) ? (float) $injury['confidence'] : 0.0;
        $accepted = ($result === 'injured' && $conf >= self::INJURY_ACCEPT_MIN);

        return [
            'ok' => true,
            'animal' => ['contains_animal' => true, 'confidence' => $animalConf],
            'injury' => ['result' => $result, 'confidence' => $conf],
            'accepted' => $accepted,
            'ai_result' => $result,
            'injury_confidence' => $conf,
        ];
    }

    /**
     * @return array{ok: bool, data: ?array<string, mixed>, error: string}
     */
    private function postMultipart(string $url, string $absolutePath, string $mime, string $safeName): array
    {
        if (!is_readable($absolutePath)) {
            return ['ok' => false, 'data' => null, 'error' => 'Photo file could not be read after upload.'];
        }

        $ch = curl_init($url);
        $postFields = [
            'image' => new CURLFile($absolutePath, $mime, $safeName),
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_HTTPHEADER => ['X-API-KEY: ' . FLASK_API_KEY],
            CURLOPT_TIMEOUT => 45,
        ]);

        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            $detail = $curlErr !== '' ? $curlErr : 'No response from server.';
            return ['ok' => false, 'data' => null, 'error' => $this->connectionErrorMessage($detail)];
        }

        if ($code !== 200) {
            return ['ok' => false, 'data' => null, 'error' => $this->httpErrorMessage($code, is_string($raw) ? $raw : '')];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'data' => null, 'error' => 'AI service returned an invalid response.'];
        }

        return ['ok' => true, 'data' => $decoded, 'error' => ''];
    }

    private function connectionErrorMessage(string $curlErr): string
    {
        $base = FLASK_API_BASE;
        return 'Cannot reach the AI service at ' . $base . '. '
            . 'On this PC, open a terminal in the project folder, install deps (e.g. pip install flask flask-cors '
            . 'scikit-learn numpy joblib pillow, then pip install -r requirements-ai.txt), then run: python app.py. '
            . 'Details: ' . $curlErr;
    }

    private function httpErrorMessage(int $code, string $rawBody): string
    {
        $decoded = json_decode($rawBody, true);
        $detail = '';
        if (is_array($decoded)) {
            $detail = trim((string) ($decoded['detail'] ?? '') ?: (string) ($decoded['error'] ?? ''));
        }

        if ($code === 401) {
            return 'AI API rejected the request (401). Match FLASK_API_KEY in backend/config.php with ANIMAL_RESCUE_API_KEY in app.py.';
        }
        if ($code === 503) {
            $hint = $detail !== '' ? $detail : 'Install image AI dependencies.';
            return 'AI image engine is off-line (503): ' . $hint;
        }
        if ($code === 400 || $code === 422) {
            return $detail !== '' ? 'AI could not read the image: ' . $detail : 'AI rejected the image upload (HTTP ' . $code . ').';
        }

        $suffix = $detail !== '' ? ' — ' . $detail : '';
        return 'AI service error (HTTP ' . $code . ').' . $suffix;
    }
}
