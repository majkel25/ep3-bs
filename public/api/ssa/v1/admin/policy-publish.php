<?php

declare(strict_types=1);

/**
 * POST /api/ssa/v1/admin/policy-publish.php
 *
 * Publish a policy (set status = published). Admin/Owner only.
 *
 * Body (JSON):
 *   id         int     required
 *   updated_at string  required  – for concurrency check
 *
 * Response:
 *   status, id, message
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

    $policyId        = isset($body['id']) && is_numeric($body['id']) ? (int)$body['id'] : 0;
    $clientUpdatedAt = trim((string)($body['updated_at'] ?? ''));

    if ($policyId <= 0) {
        ssaApiJsonResponse(400, ['error' => 'missing_id', 'message' => 'Policy id is required.']);
    }

    $pdo->beginTransaction();

    try {
        $lockStmt = $pdo->prepare(
            "SELECT id, status, current_version_id,
                    DATE_FORMAT(updated_at, '%Y-%m-%dT%H:%i:%sZ') AS updated_at
             FROM ssa_policies WHERE id = :id FOR UPDATE"
        );
        $lockStmt->execute(['id' => $policyId]);
        $existing = $lockStmt->fetch();

        if (!$existing) {
            $pdo->rollBack();
            ssaApiJsonResponse(404, ['error' => 'not_found', 'message' => 'Policy not found.']);
        }

        if ($clientUpdatedAt !== '' && (string)$existing['updated_at'] !== $clientUpdatedAt) {
            $pdo->rollBack();
            ssaApiJsonResponse(409, [
                'error'   => 'conflict',
                'message' => 'This policy was changed by another administrator. Reload the latest version before continuing.',
            ]);
        }

        $pubAt = date('Y-m-d H:i:s');

        $pdo->prepare(
            "UPDATE ssa_policies SET status = 'published', is_archived = 0 WHERE id = :id"
        )->execute(['id' => $policyId]);

        if ($existing['current_version_id']) {
            $pdo->prepare(
                "UPDATE ssa_policy_versions SET published_at = :pub WHERE id = :vid"
            )->execute(['pub' => $pubAt, 'vid' => (int)$existing['current_version_id']]);
        }

        ssaApiPolicyAudit($pdo, $policyId, $existing['current_version_id'] ? (int)$existing['current_version_id'] : null, 'published', [
            'status' => $existing['status'],
        ], [
            'status'       => 'published',
            'published_at' => $pubAt,
        ], $callerUid);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    ssaApiJsonResponse(200, [
        'status'  => 'ok',
        'id'      => $policyId,
        'message' => 'Policy published.',
    ]);
} catch (Throwable $e) {
    error_log('SSA admin/policy-publish.php failed: ' . $e->getMessage());
    ssaApiJsonResponse(500, ['error' => 'server_error', 'message' => 'Unable to publish policy.']);
}
