<?php

declare(strict_types=1);

/**
 * POST /api/ssa/v1/admin/user-role.php
 *
 * Set the ssa.user_type meta value for a member. Admin/Owner only.
 *
 * Body (JSON):
 *   uid   int    – target user
 *   role  string – member | coach | club_owner | admin
 *
 * Response:
 *   status, uid, role, message
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
$role      = isset($body['role']) ? trim((string)$body['role']) : '';

if ($targetUid === null || $targetUid <= 0) {
    ssaApiJsonResponse(400, ['error' => 'missing_uid', 'message' => 'uid is required.']);
}

$allowedRoles = ['member', 'coach', 'club_owner', 'admin'];
if (!in_array($role, $allowedRoles, true)) {
    ssaApiJsonResponse(400, [
        'error'   => 'invalid_role',
        'message' => 'role must be one of: ' . implode(', ', $allowedRoles),
    ]);
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

    // Upsert ssa.user_type.
    $existingVal = ssaApiGetUserMetaValue($pdo, $targetUid, 'ssa.user_type');

    if ($existingVal !== null) {
        $upd = $pdo->prepare(
            "UPDATE bs_users_meta SET value = :val WHERE uid = :uid AND `key` = 'ssa.user_type'"
        );
        $upd->execute(['val' => $role, 'uid' => $targetUid]);
    } else {
        $ins = $pdo->prepare(
            "INSERT INTO bs_users_meta (uid, `key`, value) VALUES (:uid, 'ssa.user_type', :val)"
        );
        $ins->execute(['uid' => $targetUid, 'val' => $role]);
    }

    ssaApiJsonResponse(200, [
        'status'  => 'ok',
        'uid'     => $targetUid,
        'role'    => $role,
        'message' => 'Role updated.',
    ]);
} catch (Throwable $e) {
    error_log('SSA admin/user-role.php failed: ' . $e->getMessage());
    ssaApiJsonResponse(500, ['error' => 'server_error', 'message' => 'Unable to update role.']);
}
