<?php

declare(strict_types=1);

use Firebase\JWT\JWT;

require_once __DIR__ . '/../../../../vendor/autoload.php';

function ssaPushEnvValue(string $name): ?string
{
    $value = getenv($name);

    if ($value === false) {
        return null;
    }

    $value = trim((string)$value);

    return $value === '' ? null : $value;
}

function ssaPushEnvFlag(string $name): bool
{
    $value = strtolower((string)(ssaPushEnvValue($name) ?? ''));

    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

if (!function_exists('ssaPushMaskedToken')) {
    function ssaPushMaskedToken(string $token): string
    {
        if (strlen($token) <= 12) {
            return '***';
        }

        return substr($token, 0, 6) . '...' . substr($token, -6);
    }
}

function ssaPushLoadApnsConfig(): array
{
    $teamId = ssaPushEnvValue('APNS_TEAM_ID');
    $keyId = ssaPushEnvValue('APNS_KEY_ID');
    $topic = ssaPushEnvValue('APNS_TOPIC');
    $defaultEnvironment = strtolower((string)(ssaPushEnvValue('APNS_DEFAULT_ENV') ?? 'development'));
    $keyP8 = ssaPushEnvValue('APNS_KEY_P8');
    $keyP8Base64 = ssaPushEnvValue('APNS_KEY_P8_BASE64');

    if ($keyP8 === null && $keyP8Base64 !== null) {
        $decoded = base64_decode($keyP8Base64, true);

        if ($decoded === false || trim($decoded) === '') {
            throw new RuntimeException('APNS_KEY_P8_BASE64 is not valid base64.');
        }

        $keyP8 = $decoded;
    }

    if ($keyP8 !== null) {
        $keyP8 = str_replace('\\n', "\n", $keyP8);
    }

    $missing = [];

    foreach ([
        'APNS_TEAM_ID' => $teamId,
        'APNS_KEY_ID' => $keyId,
        'APNS_TOPIC' => $topic,
    ] as $name => $value) {
        if ($value === null) {
            $missing[] = $name;
        }
    }

    if ($keyP8 === null) {
        $missing[] = 'APNS_KEY_P8 or APNS_KEY_P8_BASE64';
    }

    if (!in_array($defaultEnvironment, ['development', 'production'], true)) {
        $missing[] = 'APNS_DEFAULT_ENV must be development or production';
    }

    if ($missing !== []) {
        throw new RuntimeException('Missing APNs configuration: ' . implode(', ', $missing));
    }

    return [
        'teamId' => $teamId,
        'keyId' => $keyId,
        'topic' => $topic,
        'privateKey' => $keyP8,
        'defaultEnvironment' => $defaultEnvironment,
    ];
}

function ssaPushApnsEndpointForEnvironment(string $environment): string
{
    $environment = strtolower(trim($environment));

    if ($environment === '') {
        $environment = (string)(ssaPushLoadApnsConfig()['defaultEnvironment'] ?? 'development');
    }

    return $environment === 'production'
        ? 'https://api.push.apple.com/3/device/'
        : 'https://api.sandbox.push.apple.com/3/device/';
}

function ssaPushCreateApnsJwt(array $config): string
{
    return JWT::encode(
        [
            'iss' => $config['teamId'],
            'iat' => time(),
        ],
        $config['privateKey'],
        'ES256',
        $config['keyId']
    );
}

function ssaPushSendApnsNotification(
    string $deviceToken,
    string $environment,
    string $title,
    string $body,
    array $customPayload = []
): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required for APNs sending.');
    }

    $config = ssaPushLoadApnsConfig();
    $jwt = ssaPushCreateApnsJwt($config);
    $url = ssaPushApnsEndpointForEnvironment($environment) . $deviceToken;

    $payload = [
        'aps' => [
            'alert' => [
                'title' => $title,
                'body' => $body,
            ],
            'sound' => 'default',
        ],
    ];

    foreach ($customPayload as $key => $value) {
        if ($key !== 'aps') {
            $payload[$key] = $value;
        }
    }

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);

    if ($jsonPayload === false) {
        throw new RuntimeException('Unable to encode APNs payload.');
    }

    $curl = curl_init($url);

    if ($curl === false) {
        throw new RuntimeException('Unable to initialise APNs request.');
    }

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTP_VERSION => defined('CURL_HTTP_VERSION_2_0') ? CURL_HTTP_VERSION_2_0 : CURL_HTTP_VERSION_NONE,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'authorization: bearer ' . $jwt,
            'apns-topic: ' . $config['topic'],
            'apns-push-type: alert',
            'apns-priority: 10',
            'content-type: application/json',
        ],
    ]);

    $response = curl_exec($curl);
    $curlError = curl_error($curl);
    $statusCode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    curl_close($curl);

    if ($response === false) {
        return [
            'success' => false,
            'statusCode' => 0,
            'reason' => $curlError !== '' ? $curlError : 'APNs request failed.',
        ];
    }

    $responseBody = substr((string)$response, $headerSize);
    $decodedBody = json_decode($responseBody, true);
    $reason = is_array($decodedBody) && isset($decodedBody['reason'])
        ? (string)$decodedBody['reason']
        : trim($responseBody);

    return [
        'success' => $statusCode >= 200 && $statusCode < 300,
        'statusCode' => $statusCode,
        'reason' => $reason !== '' ? $reason : null,
    ];
}

function ssaPushSendToTokenRows(array $tokenRows, string $title, string $body, array $customPayload = []): array
{
    $results = [];
    $successCount = 0;
    $failureCount = 0;

    foreach ($tokenRows as $row) {
        $token = isset($row['device_token']) ? (string)$row['device_token'] : '';
        $environment = isset($row['environment']) ? (string)$row['environment'] : '';

        if ($token === '') {
            $failureCount++;
            $results[] = [
                'success' => false,
                'maskedToken' => '***',
                'environment' => $environment,
                'statusCode' => 0,
                'reason' => 'Missing device token.',
            ];
            continue;
        }

        $result = ssaPushSendApnsNotification($token, $environment, $title, $body, $customPayload);

        if ($result['success']) {
            $successCount++;
        } else {
            $failureCount++;
        }

        $results[] = [
            'success' => (bool)$result['success'],
            'maskedToken' => ssaPushMaskedToken($token),
            'environment' => $environment,
            'statusCode' => $result['statusCode'],
            'reason' => $result['reason'] ?? null,
        ];
    }

    return [
        'tokenCount' => count($tokenRows),
        'successCount' => $successCount,
        'failureCount' => $failureCount,
        'results' => $results,
    ];
}
