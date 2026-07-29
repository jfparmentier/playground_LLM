<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Normalise et contrôle le domaine défini dans config.local.php.
 * Les formes "ipsa.fr" et "@ipsa.fr" sont toutes deux acceptées.
 */
function getConfiguredEmailDomain(array $config): string
{
    $configuredDomain = $config['email_domain'] ?? '';

    if (!is_string($configuredDomain)) {
        throw new RuntimeException("La valeur 'email_domain' doit être une chaîne de caractères.");
    }

    $domain = strtolower(trim($configuredDomain));
    $domain = ltrim($domain, '@');
    $domain = rtrim($domain, '.');

    if (
        $domain === ''
        || strpos($domain, '@') !== false
        || preg_match('/\s/', $domain) === 1
        || filter_var('validation@' . $domain, FILTER_VALIDATE_EMAIL) === false
    ) {
        throw new RuntimeException(
            "Le domaine autorisé est absent ou invalide dans php/config.local.php."
        );
    }

    return $domain;
}

/**
 * Lit le document JSON transmis dans le champ POST "params".
 */
function readRequestParameters(): array
{
    if (!isset($_POST['params']) || !is_string($_POST['params'])) {
        throw new InvalidArgumentException('Paramètres manquants.');
    }

    $params = json_decode($_POST['params'], true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($params)) {
        throw new InvalidArgumentException('Paramètres invalides.');
    }

    return $params;
}

try {
    startApplicationSession();

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        sendJsonError('Méthode HTTP non autorisée.', 405);
        exit;
    }

    $params = readRequestParameters();
    $action = $params['action'] ?? 'verify';

    if ($action === 'status') {
        if (!isEmailSessionVerified()) {
            sendJsonError('La session utilisateur n’est pas autorisée.', 401);
            exit;
        }

        sendJsonResponse([
            'authenticated' => true,
            'user_uuid' => $_SESSION['user_uuid'],
        ]);
        exit;
    }

    if ($action !== 'verify') {
        sendJsonError('Action inconnue.', 400);
        exit;
    }

    $email = $params['email'] ?? null;

    if (!is_string($email)) {
        clearEmailAuthorization();
        sendJsonError('Adresse e-mail invalide.', 400);
        exit;
    }

    $normalizedEmail = strtolower(trim($email));

    if (filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL) === false) {
        clearEmailAuthorization();
        sendJsonError('Adresse e-mail invalide.', 400);
        exit;
    }

    $separatorPosition = strrpos($normalizedEmail, '@');
    $emailDomain = $separatorPosition === false
        ? ''
        : substr($normalizedEmail, $separatorPosition + 1);

    $configuredDomain = getConfiguredEmailDomain(loadLocalConfig());

    if (!hash_equals($configuredDomain, strtolower($emailDomain))) {
        clearEmailAuthorization();
        sendJsonError('Utilisateur inconnu ou non autorisé.', 403);
        exit;
    }

    session_regenerate_id(true);

    $userUuid = hash('sha256', $normalizedEmail);

    $_SESSION['email_verified'] = true;
    $_SESSION['user_uuid'] = $userUuid;
    $_SESSION['email_domain'] = $configuredDomain;

    sendJsonResponse([
        'authenticated' => true,
        'user_uuid' => $userUuid,
    ]);
} catch (JsonException $exception) {
    clearEmailAuthorization();
    sendJsonError('Données JSON invalides.', 400);
} catch (InvalidArgumentException $exception) {
    clearEmailAuthorization();
    sendJsonError($exception->getMessage(), 400);
} catch (Throwable $exception) {
    sendJsonError($exception->getMessage(), 500);
}
