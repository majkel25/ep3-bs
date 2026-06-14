<?php

declare(strict_types=1);

/**
 * GET /api/ssa/v1/admin/recordings-status.php
 *
 * Returns YouTube recordings integration status for the admin panel.
 * Admin/Owner only. Never exposes the raw API key or playlist ID.
 *
 * Response:
 *   status, configured, playlistIdPrefix, cacheFile, cacheAge,
 *   cacheItemCount, cacheLastFetched, cacheTtl
 */

require_once __DIR__ . '/../_auth0.php';
require_once __DIR__ . '/../_db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ssaApiJsonResponse(405, ['error' => 'method_not_allowed', 'message' => 'Only GET is allowed.']);
}

$claims   = ssaApiRequireAuth0Claims();
$auth0Sub = trim((string)($claims['sub'] ?? ''));

if ($auth0Sub === '') {
    ssaApiJsonResponse(401, ['error' => 'missing_auth0_subject', 'message' => 'Auth0 token missing subject.']);
}

try {
    $pdo = ssaApiCreatePdo();

    $linkStmt = $pdo->prepare(
        'SELECT uid FROM ssa_auth0_user_links WHERE auth0_sub = :sub AND revoked_at IS NULL LIMIT 1'
    );
    $linkStmt->execute(['sub' => $auth0Sub]);
    $link = $linkStmt->fetch();

    if (!is_array($link) || (int)($link['uid'] ?? 0) <= 0) {
        ssaApiJsonResponse(403, ['error' => 'not_linked', 'message' => 'No linked account found.']);
    }

    $callerUid  = (int)$link['uid'];
    $callerType = ssaApiGetUserMetaValue($pdo, $callerUid, 'ssa.user_type') ?? 'member';

    if (!in_array($callerType, ['admin', 'club_owner'], true)) {
        ssaApiJsonResponse(403, ['error' => 'forbidden', 'message' => 'Admin or Owner access required.']);
    }

    $apiKey     = (string)getenv('YOUTUBE_API_KEY');
    $playlistId = (string)getenv('YOUTUBE_RECORDINGS_PLAYLIST_ID');
    $configured = $apiKey !== '' && $playlistId !== '';

    // Mask playlist ID: show first 4 chars + '...' only.
    $playlistIdPrefix = ($playlistId !== '' && strlen($playlistId) > 4)
        ? substr($playlistId, 0, 4) . '...'
        : ($playlistId !== '' ? '***' : null);

    // Cache info.
    $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ssa_youtube_recordings_v1.json';
    $cacheExists = is_file($cacheFile);
    $cacheAge    = null;
    $cacheCount  = null;
    $cacheLastFetched = null;

    if ($cacheExists) {
        $age      = time() - (int)filemtime($cacheFile);
        $cacheAge = $age;
        $cacheLastFetched = date('Y-m-d H:i:s', (int)filemtime($cacheFile));

        $cached  = @file_get_contents($cacheFile);
        $decoded = $cached !== false ? json_decode($cached, true) : null;
        if (is_array($decoded) && isset($decoded['videos']) && is_array($decoded['videos'])) {
            $cacheCount = count($decoded['videos']);
        }
    }

    ssaApiJsonResponse(200, [
        'status'           => 'ok',
        'configured'       => $configured,
        'playlistIdPrefix' => $playlistIdPrefix,
        'cacheExists'      => $cacheExists,
        'cacheAge'         => $cacheAge,
        'cacheTtl'         => 600,
        'cacheItemCount'   => $cacheCount,
        'cacheLastFetched' => $cacheLastFetched,
        'source'           => $configured ? 'youtube_api' : 'not_configured',
    ]);
} catch (Throwable $e) {
    error_log('SSA admin/recordings-status.php failed: ' . $e->getMessage());
    ssaApiJsonResponse(500, ['error' => 'server_error', 'message' => 'Unable to get recordings status.']);
}
