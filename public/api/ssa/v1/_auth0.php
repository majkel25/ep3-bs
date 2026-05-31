<?php
require_once __DIR__ . '/../../../../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\JWK;

define('SSA_API_AUTH0_ISSUER', 'https://dev-dnsh1c7b5xtrsxdd.uk.auth0.com/');
define('SSA_API_AUTH0_AUDIENCE', 'https://ssabookings-api-development-iuenr.ondigitalocean.app/api');
define('SSA_API_AUTH0_JWKS_URL', 'https://dev-dnsh1c7b5xtrsxdd.uk.auth0.com/.well-known/jwks.json');

function ssaApiJsonResponse(int $statusCode, array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    http_response_code($statusCode);

    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function ssaApiGetAuthorizationHeader(): ?string
{
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        return trim($_SERVER['HTTP_AUTHORIZATION']);
    }

    if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();

        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'authorization') {
                return trim($value);
            }
        }
    }

    return null;
}

function ssaApiGetBearerToken(): ?string
{
    $header = ssaApiGetAuthorizationHeader();

    if (!$header) {
        return null;
    }

    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        return null;
    }

    return trim($matches[1]);
}

function ssaApiGetJwks(): array
{
    $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ssa_auth0_jwks_cache.json';
    $cacheTtlSeconds = 3600;

    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtlSeconds) {
        $cached = file_get_contents($cacheFile);
        $decoded = json_decode($cached, true);

        if (is_array($decoded) && isset($decoded['keys'])) {
            return $decoded;
        }
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);

    $jwksJson = file_get_contents(SSA_API_AUTH0_JWKS_URL, false, $context);

    if ($jwksJson === false) {
        ssaApiJsonResponse(500, [
            'error' => 'jwks_fetch_failed',
            'message' => 'Unable to fetch Auth0 signing keys.',
        ]);
    }

    $jwks = json_decode($jwksJson, true);

    if (!is_array($jwks) || !isset($jwks['keys'])) {
        ssaApiJsonResponse(500, [
            'error' => 'jwks_invalid',
            'message' => 'Auth0 signing keys response was invalid.',
        ]);
    }

    file_put_contents($cacheFile, $jwksJson);

    return $jwks;
}

function ssaApiAudienceMatches($audienceClaim, string $expectedAudience): bool
{
    if (is_string($audienceClaim)) {
        return $audienceClaim === $expectedAudience;
    }

    if (is_array($audienceClaim)) {
        return in_array($expectedAudience, $audienceClaim, true);
    }

    return false;
}

function ssaApiRequireAuth0Claims(): array
{
    $token = ssaApiGetBearerToken();

    if (!$token) {
        ssaApiJsonResponse(401, [
            'error' => 'missing_token',
            'message' => 'Authorization header must contain a Bearer token.',
        ]);
    }

    try {
        JWT::$leeway = 60;

        $jwks = ssaApiGetJwks();
        $keys = JWK::parseKeySet($jwks);

        $decoded = JWT::decode($token, $keys);
        $claims = json_decode(json_encode($decoded), true);

        if (!isset($claims['iss']) || $claims['iss'] !== SSA_API_AUTH0_ISSUER) {
            ssaApiJsonResponse(401, [
                'error' => 'invalid_issuer',
                'message' => 'Token issuer is invalid.',
            ]);
        }

        if (!isset($claims['aud']) || !ssaApiAudienceMatches($claims['aud'], SSA_API_AUTH0_AUDIENCE)) {
            ssaApiJsonResponse(401, [
                'error' => 'invalid_audience',
                'message' => 'Token audience is invalid.',
            ]);
        }

        return $claims;
    } catch (Throwable $exception) {
        ssaApiJsonResponse(401, [
            'error' => 'invalid_token',
            'message' => $exception->getMessage(),
        ]);
    }
}