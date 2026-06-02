<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth0.php';
require_once __DIR__ . '/_db.php';

function ssaPushReadJsonBody(): array
{
    $rawBody = file_get_contents('php://input');

    if ($rawBody === false || trim($rawBody) === '') {
        ssaApiJsonResponse(400, [
            'error' => 'empty_body',
            'message' => 'Request body must contain JSON.',
        ]);
    }

    $decoded = json_decode($rawBody, true);

    if (!is_array($decoded)) {
        ssaApiJsonResponse(400, [
            'error' => 'invalid_json',
            'message' => 'Request body must be valid JSON.',
        ]);
    }

    return $decoded;
}

function ssaPushStringValue(array $body, string $key, int $maxLength, ?string $default = null): ?string
{
    if (!array_key_exists($key, $body) || $body[$key] === null) {
        return $default;
    }

    $value = trim((string)$body[$key]);

    if ($value === '') {
        return $default;
    }

    if (mb_strlen($value, 'UTF-8') > $maxLength) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }

    return $value;
}

function ssaPushNormaliseDeviceToken(?string $token): string
{
    $token = strtolower(trim((string)$token));
    $token = preg_replace('/[^0-9a-f]/', '', $token) ?? '';

    if ($token === '') {
        ssaApiJsonResponse(400, [
            'error' => 'missing_device_token',
            'message' => 'A device token is required.',
        ]);
    }

    if (strlen($token) < 32 || strlen($token) > 512) {
        ssaApiJsonResponse(400, [
            'error' => 'invalid_device_token',
            'message' => 'The device token format is invalid.',
        ]);
    }

    return $token;
}

function ssaPushNormaliseEnvironment(?string $environment): string
{
    $environment = strtolower(trim((string)$environment));

    if ($environment === '') {
        return 'development';
    }

    if (!in_array($environment, ['development', 'production'], true)) {
        ssaApiJsonResponse(400, [
            'error' => 'invalid_environment',
            'message' => 'Environment must be development or production.',
        ]);
    }

    return $environment;
}

function ssaPushGetLinkedUser(PDO $pdo, string $auth0Sub): ?array
{
    $statement = $pdo->prepare(
        'SELECT
            id,
            uid,
            linked_email,
            linked_alias
         FROM ssa_auth0_user_links
         WHERE auth0_sub = :auth0Sub
           AND revoked_at IS NULL
         LIMIT 1'
    );

    $statement->execute([
        'auth0Sub' => $auth0Sub,
    ]);

    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function ssaPushMaskedToken(string $token): string
{
    if (strlen($token) <= 12) {
        return '***';
    }

    return substr($token, 0, 6) . '...' . substr($token, -6);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if (!in_array($method, ['POST', 'DELETE'], true)) {
    ssaApiJsonResponse(405, [
        'error' => 'method_not_allowed',
        'message' => 'Use POST to register a push token or DELETE to unregister it.',
    ]);
}

$claims = ssaApiRequireAuth0Claims();
$auth0Sub = isset($claims['sub']) ? trim((string)$claims['sub']) : '';

if ($auth0Sub === '') {
    ssaApiJsonResponse(401, [
        'error' => 'missing_auth0_subject',
        'message' => 'Auth0 token does not contain a subject.',
    ]);
}

try {
    $pdo = ssaApiCreatePdo();
    $linkedUser = ssaPushGetLinkedUser($pdo, $auth0Sub);

    if ($linkedUser === null) {
        ssaApiJsonResponse(409, [
            'error' => 'booking_account_not_linked',
            'message' => 'Push token registration requires a linked booking account.',
        ]);
    }

    $uid = (int)$linkedUser['uid'];
    $body = ssaPushReadJsonBody();

    if ($method === 'POST') {
        $deviceToken = ssaPushNormaliseDeviceToken($body['deviceToken'] ?? null);
        $deviceTokenHash = hash('sha256', $deviceToken);

        $platform = strtolower(ssaPushStringValue($body, 'platform', 20, 'ios') ?? 'ios');

        if ($platform !== 'ios') {
            ssaApiJsonResponse(400, [
                'error' => 'invalid_platform',
                'message' => 'Only ios push tokens are currently supported.',
            ]);
        }

        $environment = ssaPushNormaliseEnvironment($body['environment'] ?? null);
        $appVersion = ssaPushStringValue($body, 'appVersion', 64, null);
        $deviceName = ssaPushStringValue($body, 'deviceName', 255, null);

        $statement = $pdo->prepare(
            'INSERT INTO ssa_push_tokens
                (
                    auth0_sub,
                    uid,
                    device_token,
                    device_token_hash,
                    platform,
                    environment,
                    app_version,
                    device_name,
                    enabled,
                    created_at,
                    updated_at,
                    last_seen_at,
                    disabled_at
                )
             VALUES
                (
                    :auth0Sub,
                    :uid,
                    :deviceToken,
                    :deviceTokenHash,
                    :platform,
                    :environment,
                    :appVersion,
                    :deviceName,
                    1,
                    NOW(),
                    NOW(),
                    NOW(),
                    NULL
                )
             ON DUPLICATE KEY UPDATE
                    auth0_sub = VALUES(auth0_sub),
                    uid = VALUES(uid),
                    device_token = VALUES(device_token),
                    platform = VALUES(platform),
                    environment = VALUES(environment),
                    app_version = VALUES(app_version),
                    device_name = VALUES(device_name),
                    enabled = 1,
                    updated_at = NOW(),
                    last_seen_at = NOW(),
                    disabled_at = NULL'
        );

        $statement->execute([
            'auth0Sub' => $auth0Sub,
            'uid' => $uid,
            'deviceToken' => $deviceToken,
            'deviceTokenHash' => $deviceTokenHash,
            'platform' => $platform,
            'environment' => $environment,
            'appVersion' => $appVersion,
            'deviceName' => $deviceName,
        ]);

        ssaApiJsonResponse(200, [
            'status' => 'ok',
            'registered' => true,
            'platform' => $platform,
            'environment' => $environment,
            'uid' => $uid,
            'token' => [
                'masked' => ssaPushMaskedToken($deviceToken),
                'hash' => $deviceTokenHash,
            ],
        ]);
    }

    if ($method === 'DELETE') {
        $deviceToken = ssaPushNormaliseDeviceToken($body['deviceToken'] ?? null);
        $deviceTokenHash = hash('sha256', $deviceToken);

        $statement = $pdo->prepare(
            'UPDATE ssa_push_tokens
             SET enabled = FALSE,
                 updated_at = NOW(),
                 disabled_at = NOW()
             WHERE auth0_sub = :auth0Sub
               AND device_token_hash = :deviceTokenHash'
        );

        $statement->execute([
            'auth0Sub' => $auth0Sub,
            'deviceTokenHash' => $deviceTokenHash,
        ]);

        ssaApiJsonResponse(200, [
            'status' => 'ok',
            'unregistered' => $statement->rowCount() > 0,
            'token' => [
                'masked' => ssaPushMaskedToken($deviceToken),
                'hash' => $deviceTokenHash,
            ],
        ]);
    }
} catch (Throwable $exception) {
    error_log('SSA API push token endpoint failed: ' . $exception->getMessage());

    ssaApiJsonResponse(500, [
        'error' => 'push_token_failed',
        'message' => 'Unable to update push notification registration.',
    ]);
}