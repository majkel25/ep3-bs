<?php

declare(strict_types=1);

/**
 * GET /api/ssa/v1/admin/membership-package-history.php?id=N
 *
 * Returns audit history for a membership package.
 *
 * Auth: admin or club_owner user_type.
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

    // Auth
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

    $id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        ssaApiJsonResponse(400, ['error' => 'missing_id', 'message' => 'id is required.']);
    }

    // Verify package exists
    $pkgStmt = $pdo->prepare('SELECT id FROM ssa_membership_plans WHERE id = :id LIMIT 1');
    $pkgStmt->execute(['id' => $id]);
    if (!$pkgStmt->fetch()) {
        ssaApiJsonResponse(404, ['error' => 'not_found', 'message' => 'Package not found.']);
    }

    // Check if audit table exists
    $auditTableStmt = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ssa_membership_package_audit'"
    );
    if ((int)$auditTableStmt->fetchColumn() === 0) {
        // Table doesn't exist yet — return empty history
        ssaApiJsonResponse(200, [
            'status'    => 'ok',
            'packageId' => $id,
            'history'   => [],
        ]);
    }

    $histStmt = $pdo->prepare(
        'SELECT a.id, a.admin_uid, a.admin_name_snapshot, a.action,
                a.changed_fields_json, a.old_values_json, a.new_values_json,
                a.ip_address, a.created_at,
                u.alias AS admin_alias
         FROM ssa_membership_package_audit a
         LEFT JOIN bs_users u ON u.uid = a.admin_uid
         WHERE a.package_id = :packageId
         ORDER BY a.created_at DESC, a.id DESC
         LIMIT 50'
    );
    $histStmt->execute(['packageId' => $id]);
    $rows = $histStmt->fetchAll(PDO::FETCH_ASSOC);

    $history = array_values(array_map(static function (array $row): array {
        $changedFields = [];
        $oldValues     = [];
        $newValues     = [];

        if (isset($row['changed_fields_json']) && $row['changed_fields_json']) {
            $decoded = json_decode((string)$row['changed_fields_json'], true);
            if (is_array($decoded)) $changedFields = $decoded;
        }
        if (isset($row['old_values_json']) && $row['old_values_json']) {
            $decoded = json_decode((string)$row['old_values_json'], true);
            if (is_array($decoded)) $oldValues = $decoded;
        }
        if (isset($row['new_values_json']) && $row['new_values_json']) {
            $decoded = json_decode((string)$row['new_values_json'], true);
            if (is_array($decoded)) $newValues = $decoded;
        }

        // Admin name: prefer live alias, fall back to snapshot
        $adminName = (string)($row['admin_alias'] ?? $row['admin_name_snapshot'] ?? '');

        return [
            'id'            => (int)$row['id'],
            'adminUid'      => (int)$row['admin_uid'],
            'adminName'     => $adminName !== '' ? $adminName : null,
            'action'        => (string)$row['action'],
            'changedFields' => $changedFields,
            'oldValues'     => $oldValues,
            'newValues'     => $newValues,
            'ipAddress'     => isset($row['ip_address']) ? (string)$row['ip_address'] : null,
            'createdAt'     => (string)$row['created_at'],
        ];
    }, $rows));

    ssaApiJsonResponse(200, [
        'status'    => 'ok',
        'packageId' => $id,
        'history'   => $history,
    ]);

} catch (Throwable $e) {
    error_log(sprintf(
        'SSA admin/membership-package-history.php FAILED [%s] %s in %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ssaApiJsonResponse(500, ['error' => 'server_error', 'message' => 'Unable to retrieve package history.']);
}
