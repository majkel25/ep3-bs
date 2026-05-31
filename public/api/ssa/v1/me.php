<?php
require_once __DIR__ . '/../../../../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\JWK;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const AUTH0_ISSUER = 'https://dev-dnsh1c7b5xtrsxdd.uk.auth0.com/';
const AUTH0_AUDIENCE = 'https://ssabookings-api-development-iuenr.ondigitalocean.app/api';
const AUTH0_JWKS_URL = 'https://dev-dnsh1c7b5xtrsxdd.uk.auth0.com/.well-known/jwks.json';

function jsonResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function getAuthorizationHeader(): ?string
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

function getBearerToken(): ?string
{
    $header = getAuthorizationHeader();

    if (!$header) {
        return null;
    }

    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        return null;
    }

    return trim($matches[1]);
}

function getJwks(): array
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

    $jwksJson = file_get_contents(AUTH0_JWKS_URL, false, $context);

    if ($jwksJson === false) {
        jsonResponse(500, [
            'error' => 'jwks_fetch_failed',
            'message' => 'Unable to fetch Auth0 signing keys.',
        ]);
    }

    $jwks = json_decode($jwksJson, true);

    if (!is_array($jwks) || !isset($jwks['keys'])) {
        jsonResponse(500, [
            'error' => 'jwks_invalid',
            'message' => 'Auth0 signing keys response was invalid.',
        ]);
    }

    file_put_contents($cacheFile, $jwksJson);

    return $jwks;
}

function audienceMatches($audienceClaim, string $expectedAudience): bool
{
    if (is_string($audienceClaim)) {
        return $audienceClaim === $expectedAudience;
    }

    if (is_array($audienceClaim)) {
        return in_array($expectedAudience, $audienceClaim, true);
    }

    return false;
}

$token = getBearerToken();

if (!$token) {
    jsonResponse(401, [
        'error' => 'missing_token',
        'message' => 'Authorization header must contain a Bearer token.',
    ]);
}

try {
    JWT::$leeway = 60;

    $jwks = getJwks();
    $keys = JWK::parseKeySet($jwks);

    $decoded = JWT::decode($token, $keys);
    $claims = json_decode(json_encode($decoded), true);

    if (!isset($claims['iss']) || $claims['iss'] !== AUTH0_ISSUER) {
        jsonResponse(401, [
            'error' => 'invalid_issuer',
            'message' => 'Token issuer is invalid.',
        ]);
    }

    if (!isset($claims['aud']) || !audienceMatches($claims['aud'], AUTH0_AUDIENCE)) {
        jsonResponse(401, [
            'error' => 'invalid_audience',
            'message' => 'Token audience is invalid.',
        ]);
    }

    jsonResponse(200, [
        'status' => 'token_valid',
        'linked' => false,
        'auth0' => [
            'sub' => $claims['sub'] ?? null,
            'email' => $claims['email'] ?? null,
            'email_verified' => $claims['email_verified'] ?? null,
            'scope' => $claims['scope'] ?? null,
        ],
        'message' => 'Auth0 token is valid. Booking-account linking has not been implemented yet.',
    ]);
} catch (Throwable $exception) {
    jsonResponse(401, [
        'error' => 'invalid_token',
        'message' => $exception->getMessage(),
    ]);
}