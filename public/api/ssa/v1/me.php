<?php
require_once __DIR__ . '/_auth0.php';
require_once __DIR__ . '/_db.php';

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

    $statement = $pdo->prepare(
        'SELECT
            id,
            uid,
            linked_email,
            linked_alias,
            created_at,
            updated_at,
            last_seen_at,
            revoked_at
         FROM ssa_auth0_user_links
         WHERE auth0_sub = :auth0Sub
           AND revoked_at IS NULL
         LIMIT 1'
    );

    $statement->execute([
        'auth0Sub' => $auth0Sub,
    ]);

    $link = $statement->fetch();

    if ($link) {
        $updateStatement = $pdo->prepare(
            'UPDATE ssa_auth0_user_links
             SET last_seen_at = NOW()
             WHERE id = :id'
        );

        $updateStatement->execute([
            'id' => (int)$link['id'],
        ]);

        $uid = isset($link['uid']) ? (int)$link['uid'] : null;

        $scoreboardMemberId    = null;
        $profilePhotoUrl       = null;
        $recordingPlayerAlias  = null;

        if ($uid !== null && $uid > 0) {
            $rawMemberId = ssaApiGetUserMetaValue($pdo, $uid, 'scoreboard.member_id');
            if ($rawMemberId !== null && ctype_digit($rawMemberId) && (int)$rawMemberId > 0) {
                $scoreboardMemberId = (int)$rawMemberId;
                $scoreboardBaseUrl = ssaApiGetScoreboardBaseUrl();
                if ($scoreboardBaseUrl !== null) {
                    $profilePhotoUrl = $scoreboardBaseUrl . '/api/player-photos/' . $scoreboardMemberId . '/processed';
                }
            }

            // Short player name used in YouTube recording titles (e.g. "Michael M").
            // Stored in bs_users_meta; never auto-generated or inferred.
            $rawAlias = ssaApiGetUserMetaValue($pdo, $uid, 'recording.player_alias');
            if ($rawAlias !== null && trim($rawAlias) !== '') {
                $recordingPlayerAlias = trim($rawAlias);
            }
        }

        ssaApiJsonResponse(200, [
            'status' => 'ok',
            'linked' => true,
            'auth0' => [
                'sub' => $auth0Sub,
                'email' => $claims['email'] ?? null,
                'email_verified' => $claims['email_verified'] ?? null,
                'scope' => $claims['scope'] ?? null,
            ],
            'user' => [
                'uid' => $uid,
                'alias' => $link['linked_alias'] ?? null,
                'email' => $link['linked_email'] ?? null,
            ],
            'link' => [
                'createdAt' => $link['created_at'] ?? null,
                'updatedAt' => $link['updated_at'] ?? null,
                'lastSeenAt' => gmdate('Y-m-d H:i:s'),
            ],
            'scoreboardMemberId'   => $scoreboardMemberId,
            'profilePhotoUrl'      => $profilePhotoUrl,
            'recordingPlayerAlias' => $recordingPlayerAlias,
        ]);
    }

    ssaApiJsonResponse(200, [
        'status' => 'ok',
        'linked' => false,
        'auth0' => [
            'sub' => $auth0Sub,
            'email' => $claims['email'] ?? null,
            'email_verified' => $claims['email_verified'] ?? null,
            'scope' => $claims['scope'] ?? null,
        ],
        'message' => 'Auth0 token is valid, but no booking account is linked yet.',
    ]);
} catch (Throwable $exception) {
    error_log('SSA API me endpoint failed: ' . $exception->getMessage());

    ssaApiJsonResponse(500, [
        'error' => 'me_lookup_failed',
        'message' => 'Unable to read linked account status.',
    ]);
}