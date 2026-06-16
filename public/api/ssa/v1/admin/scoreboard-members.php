<?php

declare(strict_types=1);

/**
 * GET /api/ssa/v1/admin/scoreboard-members.php
 *
 * Search scoreboard members for admin mapping UI.
 * Admin/Owner only. Proxies to scoreboard internal API.
 *
 * Query params:
 *   q     string  – name, accounting ref, or numeric ID
 *   limit int     – max results (default 50, max 100)
 *
 * Response per member:
 *   scoreboardMemberId, fullName, accountingRef, active,
 *   profilePhotoUrl,
 *   alreadyLinkedUid, alreadyLinkedName (if mapped in booking metadata)
 *
 * Env vars required:
 *   SSA_SCOREBOARD_INTERNAL_URL  – internal base URL of scoreboard app
 *   SSA_INTERNAL_API_KEY         – shared API key
 *   SSA_SCOREBOARD_BASE_URL      – public base URL for profile photo URLs
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

    // Auth check.
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

    // Proxy to scoreboard internal API.
    $internalUrl = (string)getenv('SSA_SCOREBOARD_INTERNAL_URL');
    $apiKey      = (string)getenv('SSA_INTERNAL_API_KEY');

    if ($internalUrl === '' || $apiKey === '') {
        ssaApiJsonResponse(503, [
            'error'   => 'scoreboard_not_configured',
            'message' => 'Scoreboard integration is not configured on this server.',
        ]);
    }

    $q     = isset($_GET['q'])     ? trim((string)$_GET['q'])          : '';
    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;

    $queryString = http_build_query(array_filter(['q' => $q ?: null, 'limit' => $limit]));
    $url         = rtrim($internalUrl, '/') . '/api/internal/members' . ($queryString ? '?' . $queryString : '');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER      => ['X-Internal-Api-Key: ' . $apiKey, 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_TIMEOUT         => 8,
        CURLOPT_TIMEOUT_MS      => 8000,
        CURLOPT_CONNECTTIMEOUT  => 3,
        CURLOPT_NOSIGNAL        => 1, // required for PHP-FPM: disables SIGALRM-based timeouts
    ]);

    $body      = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr   = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);

    if ($curlErrno === CURLE_OPERATION_TIMEDOUT || $curlErrno === CURLE_COULDNT_CONNECT) {
        error_log(sprintf(
            'SSA admin/scoreboard-members: scoreboard timeout/connection-refused (errno=%d) url_path=/api/internal/members',
            $curlErrno
        ));
        ssaApiJsonResponse(504, ['error' => 'scoreboard_lookup_timeout', 'message' => 'Scoreboard lookup timed out. Please try again.']);
    }

    if ($curlErr !== '' || $body === false) {
        error_log(sprintf('SSA admin/scoreboard-members: curl error errno=%d: %s', $curlErrno, $curlErr));
        ssaApiJsonResponse(502, ['error' => 'scoreboard_unreachable', 'message' => 'Scoreboard is not reachable.']);
    }

    if ($httpCode === 401 || $httpCode === 403) {
        error_log('SSA admin/scoreboard-members: scoreboard rejected internal key (HTTP ' . $httpCode . ')');
        ssaApiJsonResponse(503, ['error' => 'scoreboard_auth_failed', 'message' => 'Scoreboard integration error.']);
    }

    if ($httpCode === 404) {
        error_log('SSA admin/scoreboard-members: scoreboard /api/internal/members not found – endpoint may not be deployed yet');
        ssaApiJsonResponse(503, ['error' => 'scoreboard_endpoint_unavailable', 'message' => 'Scoreboard member lookup is not yet available.']);
    }

    if ($httpCode !== 200) {
        error_log('SSA admin/scoreboard-members: scoreboard HTTP ' . $httpCode);
        ssaApiJsonResponse(502, ['error' => 'scoreboard_error', 'message' => 'Scoreboard returned an unexpected response.']);
    }

    $decoded = json_decode((string)$body, true);
    if (!is_array($decoded) || !isset($decoded['members']) || !is_array($decoded['members'])) {
        error_log('SSA admin/scoreboard-members: unexpected scoreboard response shape');
        ssaApiJsonResponse(502, ['error' => 'scoreboard_error', 'message' => 'Unexpected scoreboard response.']);
    }

    // Build existing mapping lookup: scoreboardMemberId → [uid, alias].
    $mappedStmt = $pdo->query(
        "SELECT uid, value AS sb_id FROM bs_users_meta WHERE `key` = 'scoreboard.member_id'"
    );
    $existingMappings = [];
    foreach ($mappedStmt->fetchAll() as $row) {
        $sbId = (int)$row['sb_id'];
        $existingMappings[$sbId] = (int)$row['uid'];
    }

    // Build uid → alias lookup for already-linked members.
    $linkedUids = array_unique(array_values($existingMappings));
    $uidNames   = [];
    if (!empty($linkedUids)) {
        $placeholders = implode(',', array_fill(0, count($linkedUids), '?'));
        $namesStmt    = $pdo->prepare("SELECT uid, alias FROM bs_users WHERE uid IN ({$placeholders})");
        $namesStmt->execute($linkedUids);
        foreach ($namesStmt->fetchAll() as $row) {
            $uidNames[(int)$row['uid']] = (string)$row['alias'];
        }
    }

    $scoreboardBase = ssaApiGetScoreboardBaseUrl();
    $members        = [];

    foreach ($decoded['members'] as $m) {
        $sbId           = isset($m['id']) ? (int)$m['id'] : null;
        if ($sbId === null || $sbId <= 0) {
            continue;
        }

        $alreadyLinkedUid  = $existingMappings[$sbId] ?? null;
        $alreadyLinkedName = ($alreadyLinkedUid !== null) ? ($uidNames[$alreadyLinkedUid] ?? null) : null;
        $profilePhotoUrl   = ($scoreboardBase !== null) ? $scoreboardBase . '/api/player-photos/' . $sbId . '/processed' : null;

        $members[] = [
            'scoreboardMemberId' => $sbId,
            'fullName'           => $m['name'] ?? null,
            'accountingRef'      => $m['accountingRef'] ?? null,
            'active'             => isset($m['active']) ? (bool)$m['active'] : true,
            'profilePhotoUrl'    => $profilePhotoUrl,
            'alreadyLinkedUid'   => $alreadyLinkedUid,
            'alreadyLinkedName'  => $alreadyLinkedName,
        ];
    }

    ssaApiJsonResponse(200, [
        'status'  => 'ok',
        'members' => $members,
        'total'   => count($members),
    ]);
} catch (Throwable $e) {
    $sqlState = ($e instanceof \PDOException && is_array($e->errorInfo)) ? ($e->errorInfo[0] ?? 'n/a') : 'n/a';
    error_log(sprintf('SSA admin/scoreboard-members FAILED [%s] SQLSTATE=%s msg=%s', get_class($e), $sqlState, $e->getMessage()));
    ssaApiJsonResponse(500, ['error' => 'server_error', 'message' => 'Unable to search scoreboard members.']);
}
