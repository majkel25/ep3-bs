<?php

declare(strict_types=1);

/**
 * GET /api/ssa/v1/admin/members-search.php
 *
 * Search booking-system users for package assignment.
 * Searches bs_users (not just Auth0-linked users).
 * Returns each user's current active membership so the admin can see
 * what package they are on before assigning.
 *
 * Query params:
 *   query      string  required, min 2 chars
 *   packageId  int     optional – sets isAlreadyAssigned on results
 *   limit      int     optional, default 50, max 100
 *
 * Auth: admin or club_owner only.
 *
 * Response: { status, query, packageId, results: [...], total }
 *   Each result: uid, name, email, accountNumber,
 *                currentMembershipId, currentPlanId, currentPlanKey,
 *                currentPlanDisplayName, currentStatus, isAlreadyAssigned
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
    $query     = isset($_GET['query']) ? trim((string)$_GET['query']) : '';
    $packageId = isset($_GET['packageId']) && is_numeric($_GET['packageId']) ? (int)$_GET['packageId'] : null;
    $limit     = min(100, max(1, (int)($_GET['limit'] ?? 50)));

    if (mb_strlen($query) < 2) {
        ssaApiJsonResponse(400, [
            'error'   => 'query_too_short',
            'message' => 'Search query must be at least 2 characters.',
        ]);
    }

    // ── Build WHERE ─────────────────────────────────────────────────────────
    $where  = [];
    $params = [];

    if (ctype_digit($query)) {
        // Pure numeric — search uid exactly and alias/email as LIKE
        $where[]            = '(u.uid = :exactUid OR u.alias LIKE :qAlias OR u.email LIKE :qEmail)';
        $params['exactUid'] = (int)$query;
        $like               = '%' . $query . '%';
        $params['qAlias']   = $like;
        $params['qEmail']   = $like;
    } else {
        $like             = '%' . $query . '%';
        $params['qAlias'] = $like;
        $params['qEmail'] = $like;
        $params['qPhone'] = $like;
        $where[]          = '(u.alias LIKE :qAlias OR u.email LIKE :qEmail
                               OR EXISTS (
                                   SELECT 1 FROM bs_users_meta pm
                                   WHERE pm.uid = u.uid AND pm.`key` = \'phone\' AND pm.value LIKE :qPhone
                               ))';
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    // ── Query ────────────────────────────────────────────────────────────────
    // LEFT JOIN to current active membership using a correlated subquery to
    // pick the single most-recent active row, avoiding duplicates.
    $sql = "SELECT
                u.uid,
                u.alias,
                u.email,
                m.id    AS membership_id,
                m.plan_id AS current_plan_id,
                m.status  AS membership_status,
                p.plan_key  AS current_plan_key,
                COALESCE(p.display_name, p.name) AS current_plan_display_name
            FROM bs_users u
            LEFT JOIN ssa_user_memberships m
                   ON m.uid = u.uid
                  AND m.status = 'active'
                  AND m.id = (
                          SELECT id FROM ssa_user_memberships m2
                          WHERE m2.uid = u.uid AND m2.status = 'active'
                          ORDER BY m2.started_at DESC, m2.id DESC
                          LIMIT 1
                      )
            LEFT JOIN ssa_membership_plans p ON p.id = m.plan_id
            {$whereClause}
            ORDER BY u.alias ASC
            LIMIT :lim";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = array_values(array_map(static function (array $r) use ($packageId): array {
        $currentPlanId    = isset($r['current_plan_id']) && $r['current_plan_id'] !== null
            ? (int)$r['current_plan_id'] : null;
        $isAlreadyAssigned = ($packageId !== null && $currentPlanId !== null && $currentPlanId === $packageId);

        return [
            'uid'                    => (int)$r['uid'],
            'name'                   => (isset($r['alias']) && $r['alias'] !== '') ? (string)$r['alias'] : null,
            'email'                  => (isset($r['email']) && $r['email'] !== '') ? (string)$r['email'] : null,
            'accountNumber'          => (string)$r['uid'],
            'currentMembershipId'    => isset($r['membership_id']) && $r['membership_id'] !== null ? (int)$r['membership_id'] : null,
            'currentPlanId'          => $currentPlanId,
            'currentPlanKey'         => isset($r['current_plan_key']) ? (string)$r['current_plan_key'] : null,
            'currentPlanDisplayName' => isset($r['current_plan_display_name']) ? (string)$r['current_plan_display_name'] : null,
            'currentStatus'          => isset($r['membership_status']) ? (string)$r['membership_status'] : null,
            'isAlreadyAssigned'      => $isAlreadyAssigned,
        ];
    }, $rows));

    ssaApiJsonResponse(200, [
        'status'    => 'ok',
        'query'     => $query,
        'packageId' => $packageId,
        'results'   => $results,
        'total'     => count($results),
    ]);

} catch (Throwable $e) {
    error_log(sprintf(
        'SSA admin/members-search.php FAILED [%s] %s in %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ssaApiJsonResponse(500, ['error' => 'server_error', 'message' => 'Unable to search members.']);
}
