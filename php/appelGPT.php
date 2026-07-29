<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

const MODEL_CHOICE_TOGETHER_QWEN = 'together_qwen';
const MODEL_CHOICE_OPENAI_GPT35_INSTRUCT = 'openai_gpt35_instruct';
const DEFAULT_TOGETHER_MODEL = 'Qwen/Qwen3.5-9B';
const DEFAULT_OPENAI_MODEL = 'gpt-3.5-turbo-instruct';
const TOGETHER_COMPLETIONS_ENDPOINT = 'https://api.together.ai/v1/completions';
const OPENAI_COMPLETIONS_ENDPOINT = 'https://api.openai.com/v1/completions';
const MAX_PROMPT_LENGTH = 620;
const COMPLETION_MAX_TOKENS = 10;
const REQUESTED_LOGPROBS = 5;

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
 * Extrait un message d'erreur lisible depuis une réponse d'API.
 */
function extractApiError(
    string $responseBody,
    int $httpStatus,
    string $providerName
): string {
    $decoded = json_decode($responseBody, true);

    if (is_array($decoded)) {
        $message = $decoded['error']['message']
            ?? $decoded['message']
            ?? null;

        if (is_string($message) && trim($message) !== '') {
            return $providerName . ' : ' . trim($message);
        }
    }

    return $providerName . ' a retourné le statut HTTP ' . $httpStatus . '.';
}

/**
 * Exécute une unique requête JSON authentifiée vers un fournisseur de modèle.
 */
function callJsonApi(
    string $endpoint,
    array $payload,
    string $apiKey,
    string $providerName
): string {
    if (!function_exists('curl_init')) {
        throw new RuntimeException(
            "L'extension PHP cURL n'est pas disponible sur le serveur."
        );
    }

    $encodedPayload = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );

    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => $endpoint,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $encodedPayload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 90,
    ]);

    $response = curl_exec($curl);

    if ($response === false) {
        $curlError = curl_error($curl);
        curl_close($curl);

        throw new RuntimeException(
            'Impossible de contacter ' . $providerName . ' : ' . $curlError
        );
    }

    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($httpStatus < 200 || $httpStatus >= 300) {
        throw new RuntimeException(
            extractApiError($response, $httpStatus, $providerName)
        );
    }

    // Vérifie que le fournisseur a bien renvoyé du JSON, puis transmet la
    // réponse telle quelle au navigateur pour permettre son inspection.
    json_decode($response, true, 512, JSON_THROW_ON_ERROR);

    return $response;
}

/**
 * Appelle Qwen avec une seule complétion et un seul appel HTTP Together AI.
 */
function callTogether(string $prompt, string $apiKey, string $model): string
{
    $payload = [
        'model' => $model,
        'prompt' => $prompt,
        'temperature' => 0.7,
        'max_tokens' => COMPLETION_MAX_TOKENS,
        'logprobs' => REQUESTED_LOGPROBS,
        'top_p' => 1.0,
        'n' => 1,
        'stream' => false,
    ];

    return callJsonApi(
        TOGETHER_COMPLETIONS_ENDPOINT,
        $payload,
        $apiKey,
        'Together AI'
    );
}

/**
 * Appelle gpt-3.5-turbo-instruct via l'ancien endpoint Completions d'OpenAI.
 */
function callOpenAI(string $prompt, string $apiKey, string $model): string
{
    $payload = [
        'model' => $model,
        'prompt' => $prompt,
        'temperature' => 0.7,
        'max_tokens' => COMPLETION_MAX_TOKENS,
        'logprobs' => REQUESTED_LOGPROBS,
        'top_p' => 1.0,
        'n' => 1,
        'stream' => false,
    ];

    return callJsonApi(
        OPENAI_COMPLETIONS_ENDPOINT,
        $payload,
        $apiKey,
        'OpenAI'
    );
}

/**
 * Lit une chaîne depuis l'environnement, puis depuis la configuration locale.
 */
function readConfiguredString(
    string $environmentVariable,
    array $localConfig,
    string $configKey,
    string $fallback = ''
): string {
    $value = getenv($environmentVariable);

    if (!is_string($value) || trim($value) === '') {
        $value = $localConfig[$configKey] ?? $fallback;
    }

    return is_string($value) ? trim($value) : trim($fallback);
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
    $modelChoice = $params['modele'] ?? MODEL_CHOICE_TOGETHER_QWEN;

    if (!is_string($prompt) || trim($prompt) === '') {
        sendJsonError('Le prompt est absent ou invalide.', 400);
        exit;
    }

    if (utf8Length($prompt) > MAX_PROMPT_LENGTH) {
        sendJsonError(
            'Le prompt dépasse la longueur maximale de '
                . MAX_PROMPT_LENGTH
                . ' caractères.',
            400
        );
        exit;
    }

    if (!is_string($modelChoice)) {
        sendJsonError('Le modèle sélectionné est invalide.', 400);
        exit;
    }

    $localConfig = loadLocalConfig();

    if ($modelChoice === MODEL_CHOICE_TOGETHER_QWEN) {
        $apiKey = readConfiguredString(
            'TOGETHER_API_KEY',
            $localConfig,
            'together_api_key',
            is_string($localConfig['api_key'] ?? null)
                ? $localConfig['api_key']
                : ''
        );
        $model = readConfiguredString(
            'TOGETHER_MODEL',
            $localConfig,
            'together_model',
            is_string($localConfig['model'] ?? null)
                ? $localConfig['model']
                : DEFAULT_TOGETHER_MODEL
        );

        if ($apiKey === '') {
            sendJsonError(
                'Clé Together AI absente. Définissez TOGETHER_API_KEY '
                    . 'ou together_api_key dans php/config.local.php.',
                500
            );
            exit;
        }

        echo callTogether(
            $prompt,
            $apiKey,
            $model ?: DEFAULT_TOGETHER_MODEL
        );
        exit;
    }

    if ($modelChoice === MODEL_CHOICE_OPENAI_GPT35_INSTRUCT) {
        $apiKey = readConfiguredString(
            'OPENAI_API_KEY',
            $localConfig,
            'openai_api_key'
        );
        $model = readConfiguredString(
            'OPENAI_MODEL',
            $localConfig,
            'openai_model',
            DEFAULT_OPENAI_MODEL
        );

        if ($apiKey === '') {
            sendJsonError(
                'Clé OpenAI absente. Définissez OPENAI_API_KEY '
                    . 'ou openai_api_key dans php/config.local.php.',
                500
            );
            exit;
        }

        echo callOpenAI(
            $prompt,
            $apiKey,
            $model ?: DEFAULT_OPENAI_MODEL
        );
        exit;
    }

    sendJsonError('Le modèle sélectionné n’est pas autorisé.', 400);
} catch (JsonException $exception) {
    sendJsonError('Données JSON invalides.', 400);
} catch (Throwable $exception) {
    sendJsonError($exception->getMessage(), 500);
}
