<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth0.php';
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_push_apns.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ssaApiJsonResponse(405, [
        'error' => 'method_not_allowed',
        'message' => 'Only POST is allowed for this endpoint.',
    ]);
}

if (!ssaPushEnvFlag('SSA_ENABLE_DEBUG_PUSH_ENDPOINT')) {
    ssaApiJsonResponse(403, [
        'error' => 'debug_push_endpoint_disabled',
        'message' => 'The debug push endpoint is not enabled on this backend.',
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

    $linkStatement = $pdo->prepare(
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

    $linkStatement->execute([
        'auth0Sub' => $auth0Sub,
    ]);

    $link = $linkStatement->fetch(PDO::FETCH_ASSOC);

    if (!is_array($link) || (int)($link['uid'] ?? 0) <= 0) {
        ssaApiJsonResponse(403, [
            'error' => 'booking_account_not_linked',
            'message' => 'No linked booking account was found for this app login.',
        ]);
    }

    $uid = (int)$link['uid'];

    $tokenStatement = $pdo->prepare(
        'SELECT
            id,
            uid,
            auth0_sub,
            device_token,
            device_token_hash,
            platform,
            environment,
            app_version,
            device_name,
            last_seen_at
         FROM ssa_push_tokens
         WHERE auth0_sub = :auth0Sub
           AND uid = :uid
           AND platform = :platform
           AND enabled = 1
         ORDER BY last_seen_at DESC, updated_at DESC, id DESC'
    );

    $tokenStatement->execute([
        'auth0Sub' => $auth0Sub,
        'uid' => $uid,
        'platform' => 'ios',
    ]);

    $tokens = $tokenStatement->fetchAll(PDO::FETCH_ASSOC);

    if (!is_array($tokens) || count($tokens) === 0) {
        ssaApiJsonResponse(200, [
            'status' => 'ok',
            'sent' => false,
            'tokenCount' => 0,
            'successCount' => 0,
            'failureCount' => 0,
            'message' => 'No enabled iOS push tokens are registered for this user.',
        ]);
    }

    $sendResult = ssaPushSendToTokenRows(
        $tokens,
        'SSA Booking Reminder',
        'Test push: My Bookings notifications are working.',
        [
            'screen' => 'myBookings',
            'type' => 'booking_reminder',
            'debug' => true,
        ]
    );

    ssaApiJsonResponse(200, [
        'status' => 'ok',
        'sent' => $sendResult['successCount'] > 0,
        'uid' => $uid,
        'tokenCount' => $sendResult['tokenCount'],
        'successCount' => $sendResult['successCount'],
        'failureCount' => $sendResult['failureCount'],
        'results' => $sendResult['results'],
    ]);
} catch (RuntimeException $exception) {
    error_log('SSA API debug push endpoint configuration failed: ' . $exception->getMessage());

    ssaApiJsonResponse(500, [
        'error' => 'push_configuration_failed',
        'message' => $exception->getMessage(),
    ]);
} catch (Throwable $exception) {
    error_log('SSA API debug push endpoint failed: ' . $exception->getMessage());

    ssaApiJsonResponse(500, [
        'error' => 'debug_push_failed',
        'message' => 'Unable to send the debug booking push notification.',
    ]);
}
