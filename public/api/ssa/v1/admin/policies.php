<?php

declare(strict_types=1);

/**
 * GET /api/ssa/v1/admin/policies.php
 *
 * List all policies (draft, published, unpublished, archived). Admin/Owner only.
 *
 * Query params:
 *   status   string  – filter by status (draft|published|unpublished|archived)
 *   category string  – filter by category code
 *   q        string  – search by name
 *
 * Response:
 *   status, policies[]
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

try {
    $pdo = ssaApiCreatePdo();

    ssaApiEnsurePolicySchema($pdo);

    // Verify admin role.
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

    $statusFilter   = isset($_GET['status'])   ? trim((string)$_GET['status'])   : null;
    $categoryFilter = isset($_GET['category']) ? trim((string)$_GET['category']) : null;
    $search         = isset($_GET['q'])        ? trim((string)$_GET['q'])        : null;

    $where  = [];
    $params = [];

    if ($statusFilter && in_array($statusFilter, ['draft', 'published', 'unpublished', 'archived'], true)) {
        $where[]            = 'p.status = :status';
        $params['status']   = $statusFilter;
    }
    if ($categoryFilter) {
        $where[]              = 'p.category = :category';
        $params['category']   = $categoryFilter;
    }
    if ($search) {
        $where[]          = 'p.name LIKE :q';
        $params['q']      = '%' . $search . '%';
    }

    $sql = "
        SELECT
            p.id,
            p.slug,
            p.name,
            p.category,
            p.audience,
            p.status,
            p.display_order,
            p.is_archived,
            p.created_by_uid,
            DATE_FORMAT(p.created_at, '%Y-%m-%dT%H:%i:%sZ') AS created_at,
            DATE_FORMAT(p.updated_at, '%Y-%m-%dT%H:%i:%sZ') AS updated_at,
            v.version_number,
            DATE_FORMAT(v.effective_from, '%Y-%m-%d') AS effective_from,
            DATE_FORMAT(v.published_at, '%Y-%m-%dT%H:%i:%sZ') AS published_at
        FROM ssa_policies p
        LEFT JOIN ssa_policy_versions v ON v.id = p.current_version_id
    ";

    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY p.category ASC, p.display_order ASC, p.name ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $policies = [];
    foreach ($stmt->fetchAll() as $row) {
        $policies[] = [
            'id'             => (int)$row['id'],
            'slug'           => $row['slug'],
            'name'           => $row['name'],
            'category'       => $row['category'],
            'audience'       => $row['audience'],
            'status'         => $row['status'],
            'display_order'  => (int)$row['display_order'],
            'is_archived'    => (bool)$row['is_archived'],
            'version_number' => $row['version_number'] ? (int)$row['version_number'] : null,
            'effective_from' => $row['effective_from'],
            'published_at'   => $row['published_at'],
            'created_at'     => $row['created_at'],
            'updated_at'     => $row['updated_at'],
        ];
    }

    ssaApiJsonResponse(200, [
        'status'   => 'ok',
        'policies' => $policies,
    ]);
} catch (Throwable $e) {
    error_log('SSA admin/policies.php failed: ' . $e->getMessage());
    ssaApiJsonResponse(500, ['error' => 'server_error', 'message' => 'Unable to load policies.']);
}
