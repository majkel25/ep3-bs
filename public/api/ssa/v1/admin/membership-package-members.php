<?php

declare(strict_types=1);

/**
 * GET /api/ssa/v1/admin/membership-package-members.php
 *
 * Returns members with an active membership on the given package.
 *
 * Query params:
 *   packageId  int     required
 *   search     string  optional – filter by alias or email (client-side safe)
 *   limit      int     optional, default 200, max 500
 *
 * Auth: admin or club_owner only.
 *
 * Response: { status, packageId, members: [...], total }
 *   Each member: id (membershipId), uid, name, email, accountNumber,
 *                membershipStatus, startedAt, currentPeriodEndsAt,
 *                planKey, planName, isPrivatePackage
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

    // ── Auth ────────────────────────────────────────────────────────────────
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

    // ── Params ──────────────────────────────────────────────────────────────
    $packageId = isset($_GET['packageId']) && is_numeric($_GET['packageId']) ? (int)$_GET['packageId'] : 0;
    if ($packageId <= 0) {
        ssaApiJsonResponse(400, ['error' => 'missing_package_id', 'message' => 'packageId is required.']);
    }
    $search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
    $limit  = min(500, max(1, (int)($_GET['limit'] ?? 200)));

    // ── Verify package ───────────────────────────────────────────────────────
    $pkgStmt = $pdo->prepare(
        'SELECT id, plan_key, is_public FROM ssa_membership_plans WHERE id = :id LIMIT 1'
    );
    $pkgStmt->execute(['id' => $packageId]);
    $pkg = $pkgStmt->fetch();
    if (!$pkg) {
        ssaApiJsonResponse(404, ['error' => 'package_not_found', 'message' => 'Package not found.']);
    }
    $isPrivatePackage = !(bool)(int)($pkg['is_public'] ?? 0);

    // ── Query ───────────────────────────────────────────────────────────────
    $whereClauses = ["m.plan_id = :packageId", "m.status = 'active'"];
    $params       = ['packageId' => $packageId];

    if ($search !== '') {
        $whereClauses[] = '(u.alias LIKE :search OR u.email LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    $whereStr = implode(' AND ', $whereClauses);

    $sql = "SELECT
                m.id                    AS membership_id,
                m.uid,
                m.status                AS membership_status,
                m.started_at,
                m.current_period_ends_at,
                m.plan_name_snapshot,
                p.plan_key,
                COALESCE(p.display_name, p.name) AS plan_display_name,
                p.is_public,
                u.alias                 AS user_alias,
                u.email
            FROM ssa_user_memberships m
            INNER JOIN ssa_membership_plans p ON p.id = m.plan_id
            INNER JOIN bs_users u ON u.uid = m.uid
            WHERE {$whereStr}
            ORDER BY u.alias ASC, m.id ASC
            LIMIT :lim";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $members = array_values(array_map(static function (array $r) use ($isPrivatePackage): array {
        $startedRaw  = isset($r['started_at'])              ? (string)$r['started_at']              : null;
        $periodRaw   = isset($r['current_period_ends_at'])  ? (string)$r['current_period_ends_at']  : null;
        return [
            'id'                  => (int)$r['membership_id'],
            'uid'                 => (int)$r['uid'],
            'name'                => (string)($r['user_alias'] ?? ''),
            'email'               => (isset($r['email']) && $r['email'] !== '') ? (string)$r['email'] : null,
            'accountNumber'       => (string)$r['uid'],
            'membershipStatus'    => (string)$r['membership_status'],
            'startedAt'           => $startedRaw  ? substr($startedRaw,  0, 10) : null,
            'currentPeriodEndsAt' => $periodRaw   ? substr($periodRaw,   0, 10) : null,
            'planKey'             => (string)$r['plan_key'],
            'planName'            => (string)($r['plan_display_name'] ?? $r['plan_name_snapshot'] ?? ''),
            'isPrivatePackage'    => $isPrivatePackage,
        ];
    }, $rows));

    ssaApiJsonResponse(200, [
        'status'    => 'ok',
        'packageId' => $packageId,
        'members'   => $members,
        'total'     => count($members),
    ]);

} catch (Throwable $e) {
    error_log(sprintf(
        'SSA admin/membership-package-members.php FAILED [%s] %s in %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ssaApiJsonResponse(500, ['error' => 'server_error', 'message' => 'Unable to retrieve package members.']);
}
