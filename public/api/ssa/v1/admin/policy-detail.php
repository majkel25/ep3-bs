<?php

declare(strict_types=1);

/**
 * GET /api/ssa/v1/admin/policy-detail.php?id=<id>
 *
 * Retrieve a single policy with its current version content and full version history. Admin/Owner only.
 *
 * Response:
 *   status, policy{}, versions[]
 */

require_once __DIR__ . '/../_auth0.php';
require_once __DIR__ . '/../_db.php';
require_once __DIR__ . '/../_policy_schema.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ssaApiJsonResponse(405, ['error' => 'method_not_allowed', 'message' => 'Only GET is allowed.']);
}

$claims   = ssaApiRequireAuth0Claims();
$auth0Sub = trim((string)($claims['sub'] ?? ''));

if ($auth0Sub === '') {
    ssaApiJsonResponse(401, ['error' => 'missing_auth0_subject', 'message' => 'Auth0 token missing subject.']);
}

$policyId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
if ($policyId <= 0) {
    ssaApiJsonResponse(400, ['error' => 'missing_id', 'message' => 'Policy id is required.']);
}

try {
    $pdo = ssaApiCreatePdo();
    ssaApiEnsurePolicySchema($pdo);

    // Admin check.
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

    // Fetch policy.
    $pStmt = $pdo->prepare("
        SELECT
            p.id, p.slug, p.name, p.category, p.audience, p.status,
            p.display_order, p.is_archived, p.current_version_id,
            p.created_by_uid,
            DATE_FORMAT(p.created_at, '%Y-%m-%dT%H:%i:%sZ') AS created_at,
            DATE_FORMAT(p.updated_at, '%Y-%m-%dT%H:%i:%sZ') AS updated_at,
            v.version_number,
            v.content,
            DATE_FORMAT(v.effective_from, '%Y-%m-%d') AS effective_from,
            DATE_FORMAT(v.published_at, '%Y-%m-%dT%H:%i:%sZ') AS published_at
        FROM ssa_policies p
        LEFT JOIN ssa_policy_versions v ON v.id = p.current_version_id
        WHERE p.id = :id
        LIMIT 1
    ");
    $pStmt->execute(['id' => $policyId]);
    $row = $pStmt->fetch();

    if (!$row) {
        ssaApiJsonResponse(404, ['error' => 'not_found', 'message' => 'Policy not found.']);
    }

    // Fetch all versions.
    $vStmt = $pdo->prepare("
        SELECT
            id, version_number,
            content,
            DATE_FORMAT(effective_from, '%Y-%m-%d') AS effective_from,
            DATE_FORMAT(published_at, '%Y-%m-%dT%H:%i:%sZ') AS published_at,
            created_by_uid,
            DATE_FORMAT(created_at, '%Y-%m-%dT%H:%i:%sZ') AS created_at
        FROM ssa_policy_versions
        WHERE policy_id = :pid
        ORDER BY version_number DESC
    ");
    $vStmt->execute(['pid' => $policyId]);
    $versions = [];
    foreach ($vStmt->fetchAll() as $v) {
        $versions[] = [
            'id'             => (int)$v['id'],
            'version_number' => (int)$v['version_number'],
            'content'        => $v['content'],
            'effective_from' => $v['effective_from'],
            'published_at'   => $v['published_at'],
            'created_by_uid' => $v['created_by_uid'] ? (int)$v['created_by_uid'] : null,
            'created_at'     => $v['created_at'],
        ];
    }

    ssaApiJsonResponse(200, [
        'status' => 'ok',
        'policy' => [
            'id'                 => (int)$row['id'],
            'slug'               => $row['slug'],
            'name'               => $row['name'],
            'category'           => $row['category'],
            'audience'           => $row['audience'],
            'status'             => $row['status'],
            'display_order'      => (int)$row['display_order'],
            'is_archived'        => (bool)$row['is_archived'],
            'current_version_id' => $row['current_version_id'] ? (int)$row['current_version_id'] : null,
            'version_number'     => $row['version_number'] ? (int)$row['version_number'] : null,
            'content'            => $row['content'],
            'effective_from'     => $row['effective_from'],
            'published_at'       => $row['published_at'],
            'created_by_uid'     => $row['created_by_uid'] ? (int)$row['created_by_uid'] : null,
            'created_at'         => $row['created_at'],
            'updated_at'         => $row['updated_at'],
        ],
        'versions' => $versions,
    ]);
} catch (Throwable $e) {
    error_log('SSA admin/policy-detail.php failed: ' . $e->getMessage());
    ssaApiJsonResponse(500, ['error' => 'server_error', 'message' => 'Unable to load policy.']);
}
