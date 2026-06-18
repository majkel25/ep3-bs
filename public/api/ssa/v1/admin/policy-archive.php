<?php

declare(strict_types=1);

/**
 * POST /api/ssa/v1/admin/policy-archive.php
 *
 * Archive a policy (set is_archived = 1, status = archived). Admin/Owner only.
 * Archived policies are hidden from members but preserved for audit history.
 *
 * Body (JSON):
 *   id int required
 */

require_once __DIR__ . '/../_auth0.php';
require_once __DIR__ . '/../_db.php';
require_once __DIR__ . '/../_policy_schema.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ssaApiJsonResponse(405, ['error' => 'method_not_allowed', 'message' => 'Only POST is allowed.']);
}

$claims   = ssaApiRequireAuth0Claims();
$auth0Sub = trim((string)($claims['sub'] ?? ''));

if ($auth0Sub === '') {
    ssaApiJsonResponse(401, ['error' => 'missing_auth0_subject', 'message' => 'Auth0 token missing subject.']);
}

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    ssaApiJsonResponse(400, ['error' => 'invalid_json', 'message' => 'Request body must be JSON.']);
}

try {
    $pdo = ssaApiCreatePdo();
    ssaApiEnsurePolicySchema($pdo);

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

    $policyId = isset($body['id']) && is_numeric($body['id']) ? (int)$body['id'] : 0;
    if ($policyId <= 0) {
        ssaApiJsonResponse(400, ['error' => 'missing_id', 'message' => 'Policy id is required.']);
    }

    $check = $pdo->prepare('SELECT id, status, current_version_id FROM ssa_policies WHERE id = :id LIMIT 1');
    $check->execute(['id' => $policyId]);
    $existing = $check->fetch();

    if (!$existing) {
        ssaApiJsonResponse(404, ['error' => 'not_found', 'message' => 'Policy not found.']);
    }

    $pdo->prepare(
        "UPDATE ssa_policies SET status = 'archived', is_archived = 1 WHERE id = :id"
    )->execute(['id' => $policyId]);

    ssaApiPolicyAudit($pdo, $policyId, $existing['current_version_id'] ? (int)$existing['current_version_id'] : null, 'archived', [
        'status' => $existing['status'],
    ], ['status' => 'archived', 'is_archived' => true], $callerUid);

    ssaApiJsonResponse(200, [
        'status'  => 'ok',
        'id'      => $policyId,
        'message' => 'Policy archived.',
    ]);
} catch (Throwable $e) {
    error_log('SSA admin/policy-archive.php failed: ' . $e->getMessage());
    ssaApiJsonResponse(500, ['error' => 'server_error', 'message' => 'Unable to archive policy.']);
}
