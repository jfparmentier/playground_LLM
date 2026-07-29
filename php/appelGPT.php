<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

const DEFAULT_TOGETHER_MODEL = 'Qwen/Qwen3.5-9B';
const MAX_PROMPT_LENGTH = 620;

/**
 * Retourne la longueur d'une chaîne UTF-8, avec repli si mbstring est absent.
 */
function utf8Length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    return strlen($value);
}

/**
 * Extrait un message d'erreur lisible depuis la réponse de Together AI.
 */
function extractTogetherError(string $responseBody, int $httpStatus): string
{
    $decoded = json_decode($responseBody, true);

    if (is_array($decoded)) {
        $message = $decoded['error']['message']
            ?? $decoded['message']
            ?? null;

        if (is_string($message) && trim($message) !== '') {
            return 'Together AI : ' . trim($message);
        }
    }

    return 'Together AI a retourné le statut HTTP ' . $httpStatus . '.';
}

/**
 * Appelle l'endpoint moderne de chat de Together AI.
 */
function callTogether(string $prompt, string $apiKey, string $model): string
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException("L'extension PHP cURL n'est pas disponible sur le serveur.");
    }

    $payload = [
        'model' => $model,
        'messages' => [
            [
                'role' => 'system',
                'content' => (
                    'You are a text-completion engine. Continue the exact text supplied by the user. '
                    . 'Return only the continuation, without quotation marks, labels, explanations, '
                    . 'or repetition of the supplied text. Begin immediately with the next characters.'
                ),
            ],
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ],
        'temperature' => 0.7,
        'max_tokens' => 10,
        // L'endpoint de chat renvoie une collection top_logprobs distincte
        // pour chaque token généré. Cinq candidats sont demandés par position.
        'logprobs' => 5,
        'top_p' => 1.0,
        'top_k' => 50,
        // Qwen 3.5 est un modèle hybride : le raisonnement est désactivé afin
        // que la sortie commence directement par la continuation attendue.
        'reasoning' => [
            'enabled' => false,
        ],
    ];

    $encodedPayload = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );

    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://api.together.ai/v1/chat/completions',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $encodedPayload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($curl);

    if ($response === false) {
        $curlError = curl_error($curl);
        curl_close($curl);

        throw new RuntimeException('Impossible de contacter Together AI : ' . $curlError);
    }

    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($httpStatus < 200 || $httpStatus >= 300) {
        throw new RuntimeException(extractTogetherError($response, $httpStatus));
    }

    // Vérifie que l'API a bien renvoyé un document JSON avant de le transmettre.
    json_decode($response, true, 512, JSON_THROW_ON_ERROR);

    return $response;
}

try {
    startApplicationSession();

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        sendJsonError('Méthode HTTP non autorisée.', 405);
        exit;
    }

    if (!isEmailSessionVerified()) {
        sendJsonError('La session utilisateur n’est pas autorisée.', 401);
        exit;
    }

    if (!isset($_POST['params']) || !is_string($_POST['params'])) {
        sendJsonError('Paramètres manquants.', 400);
        exit;
    }

    $params = json_decode($_POST['params'], true, 512, JSON_THROW_ON_ERROR);
    $prompt = $params['prompt'] ?? null;

    if (!is_string($prompt) || trim($prompt) === '') {
        sendJsonError('Le prompt est absent ou invalide.', 400);
        exit;
    }

    if (utf8Length($prompt) > MAX_PROMPT_LENGTH) {
        sendJsonError(
            'Le prompt dépasse la longueur maximale de ' . MAX_PROMPT_LENGTH . ' caractères.',
            400
        );
        exit;
    }

    $localConfig = loadLocalConfig();

    $apiKey = getenv('TOGETHER_API_KEY');
    if (!is_string($apiKey) || trim($apiKey) === '') {
        $apiKey = $localConfig['api_key'] ?? '';
    }

    $model = getenv('TOGETHER_MODEL');
    if (!is_string($model) || trim($model) === '') {
        $model = $localConfig['model'] ?? DEFAULT_TOGETHER_MODEL;
    }

    if (!is_string($apiKey) || trim($apiKey) === '') {
        sendJsonError(
            'Clé Together AI absente. Définissez TOGETHER_API_KEY ou créez php/config.local.php.',
            500
        );
        exit;
    }

    if (!is_string($model) || trim($model) === '') {
        $model = DEFAULT_TOGETHER_MODEL;
    }

    echo callTogether($prompt, trim($apiKey), trim($model));
} catch (JsonException $exception) {
    sendJsonError('Données JSON invalides.', 400);
} catch (Throwable $exception) {
    sendJsonError($exception->getMessage(), 500);
}
