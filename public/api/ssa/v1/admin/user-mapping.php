<?php

declare(strict_types=1);

/**
 * POST /api/ssa/v1/admin/user-mapping.php
 *
 * Set or clear the scoreboard.member_id meta value for a member.
 * Admin/Owner only. Returns 409 if the scoreboardMemberId is already
 * mapped to a different uid.
 *
 * Body (JSON):
 *   uid                 int       – target user
 *   scoreboardMemberId  int|null  – numeric scoreboard ID, or null to clear
 *
 * Response:
 *   status, uid, scoreboardMemberId, message
 */

require_once __DIR__ . '/../_auth0.php';
require_once __DIR__ . '/../_db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ssaApiJsonResponse(405, ['error' => 'method_not_allowed', 'message' => 'Only POST is allowed.']);
}

$claims   = ssaApiRequireAuth0Claims();
$auth0Sub = trim((string)($claims['sub'] ?? ''));

if ($auth0Sub === '') {
    ssaApiJsonResponse(401, ['error' => 'missing_auth0_subject', 'message' => 'Auth0 token missing subject.']);
}

$rawBody = (string)file_get_contents('php://input');
$body    = [];
if ($rawBody !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
}

$targetUid = isset($body['uid']) && is_numeric($body['uid']) ? (int)$body['uid'] : null;

if ($targetUid === null || $targetUid <= 0) {
    ssaApiJsonResponse(400, ['error' => 'missing_uid', 'message' => 'uid is required.']);
}

// scoreboardMemberId may be null (clear) or a positive int.
$sbIdRaw           = $body['scoreboardMemberId'] ?? false;
$scoreboardMemberId = null;

if ($sbIdRaw !== null && $sbIdRaw !== false) {
    if (!is_numeric($sbIdRaw) || (int)$sbIdRaw <= 0) {
        ssaApiJsonResponse(400, ['error' => 'invalid_mapping', 'message' => 'scoreboardMemberId must be a positive integer or null.']);
    }
    $scoreboardMemberId = (int)$sbIdRaw;
}

try {
    $pdo = ssaApiCreatePdo();

    // Resolve caller and check permission.
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

    // Verify target exists.
    $userStmt = $pdo->prepare('SELECT uid FROM bs_users WHERE uid = :uid LIMIT 1');
    $userStmt->execute(['uid' => $targetUid]);
    if (!$userStmt->fetch()) {
        ssaApiJsonResponse(404, ['error' => 'user_not_found', 'message' => 'Target user not found.']);
    }

    // Conflict check: is this scoreboard ID already used by someone else?
    if ($scoreboardMemberId !== null) {
        $conflictStmt = $pdo->prepare(
            "SELECT uid FROM bs_users_meta WHERE `key` = 'scoreboard.member_id' AND value = :sbId LIMIT 1"
        );
        $conflictStmt->execute(['sbId' => (string)$scoreboardMemberId]);
        $conflict = $conflictStmt->fetch();

        if (is_array($conflict) && (int)$conflict['uid'] !== $targetUid) {
            ssaApiJsonResponse(409, [
                'error'   => 'scoreboard_id_conflict',
                'message' => 'Scoreboard ID ' . $scoreboardMemberId . ' is already mapped to another member.',
            ]);
        }
    }

    $existingVal = ssaApiGetUserMetaValue($pdo, $targetUid, 'scoreboard.member_id');

    if ($scoreboardMemberId === null) {
        // Clear mapping.
        if ($existingVal !== null) {
            $del = $pdo->prepare(
                "DELETE FROM bs_users_meta WHERE uid = :uid AND `key` = 'scoreboard.member_id'"
            );
            $del->execute(['uid' => $targetUid]);
        }
        ssaApiJsonResponse(200, [
            'status'             => 'ok',
            'uid'                => $targetUid,
            'scoreboardMemberId' => null,
            'message'            => 'Scoreboard mapping cleared.',
        ]);
    } else {
        // Set mapping.
        if ($existingVal !== null) {
            $upd = $pdo->prepare(
                "UPDATE bs_users_meta SET value = :val WHERE uid = :uid AND `key` = 'scoreboard.member_id'"
            );
            $upd->execute(['val' => (string)$scoreboardMemberId, 'uid' => $targetUid]);
        } else {
            $ins = $pdo->prepare(
                "INSERT INTO bs_users_meta (uid, `key`, value) VALUES (:uid, 'scoreboard.member_id', :val)"
            );
            $ins->execute(['uid' => $targetUid, 'val' => (string)$scoreboardMemberId]);
        }

        ssaApiJsonResponse(200, [
            'status'             => 'ok',
            'uid'                => $targetUid,
            'scoreboardMemberId' => $scoreboardMemberId,
            'message'            => 'Scoreboard mapping updated.',
        ]);
    }
} catch (Throwable $e) {
    error_log('SSA admin/user-mapping.php failed: ' . $e->getMessage());
    ssaApiJsonResponse(500, ['error' => 'server_error', 'message' => 'Unable to update mapping.']);
}
