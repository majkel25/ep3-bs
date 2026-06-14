<?php

declare(strict_types=1);

/**
 * GET /api/ssa/v1/admin/users.php
 *
 * List members with optional search. Admin/Owner access only.
 *
 * Query params:
 *   search  string  – partial alias, email, or "uid:<int>" exact match
 *   limit   int     – max results (default 50, max 200)
 *
 * Response:
 *   status, users[], total
 *   Each user: uid, alias, email, phone, userType, scoreboardMemberId, profilePhotoUrl
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

    // Resolve caller uid and check role.
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

    $search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
    $limit  = min(200, max(1, (int)($_GET['limit'] ?? 50)));

    // Build WHERE clause. Note: `key` is a MySQL reserved word — always backtick-quoted.
    $where  = [];
    $params = [];

    if ($search !== '') {
        if (preg_match('/^uid:(\d+)$/', $search, $m)) {
            $where[]              = 'u.uid = :exactUid';
            $params['exactUid']   = (int)$m[1];
        } else {
            // Native prepared statements (EMULATE_PREPARES=false) require unique param names
            // for each occurrence — use :qAlias, :qEmail, :qPhone.
            $where[] = '(u.alias LIKE :qAlias OR u.email LIKE :qEmail OR EXISTS (
                            SELECT 1 FROM bs_users_meta pm
                            WHERE pm.uid = u.uid AND pm.`key` = \'phone\' AND pm.value LIKE :qPhone
                        ))';
            $like                = '%' . $search . '%';
            $params['qAlias']    = $like;
            $params['qEmail']    = $like;
            $params['qPhone']    = $like;
        }
    }

    $whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // LEFT JOIN bs_users_meta three times — once per meta key.
    // IMPORTANT: `key` is a MySQL reserved word and MUST be backtick-quoted in all SQL.
    $sql = "
        SELECT
            u.uid,
            u.alias,
            u.email,
            meta_phone.value  AS phone,
            meta_type.value   AS user_type,
            meta_sb.value     AS scoreboard_member_id
        FROM bs_users u
        LEFT JOIN bs_users_meta meta_phone
               ON meta_phone.uid = u.uid AND meta_phone.`key` = 'phone'
        LEFT JOIN bs_users_meta meta_type
               ON meta_type.uid  = u.uid AND meta_type.`key`  = 'ssa.user_type'
        LEFT JOIN bs_users_meta meta_sb
               ON meta_sb.uid    = u.uid AND meta_sb.`key`    = 'scoreboard.member_id'
        {$whereClause}
        ORDER BY u.alias ASC
        LIMIT :lim
    ";

    $stmt = $pdo->prepare($sql);

    foreach ($params as $k => $v) {
        if (is_int($v)) {
            $stmt->bindValue($k, $v, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
    }
    $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows             = $stmt->fetchAll();
    $scoreboardBase   = ssaApiGetScoreboardBaseUrl();
    $users            = [];

    foreach ($rows as $row) {
        $sbId = (isset($row['scoreboard_member_id']) && $row['scoreboard_member_id'] !== '')
            ? (int)$row['scoreboard_member_id']
            : null;

        $profilePhotoUrl = null;
        if ($sbId !== null && $scoreboardBase !== null) {
            $profilePhotoUrl = $scoreboardBase . '/api/player-photos/' . $sbId . '/processed';
        }

        $users[] = [
            'uid'                => (int)$row['uid'],
            'alias'              => (isset($row['alias']) && $row['alias'] !== '') ? $row['alias'] : null,
            'email'              => (isset($row['email']) && $row['email'] !== '') ? $row['email'] : null,
            'phone'              => $row['phone'] ?? null,
            'userType'           => $row['user_type'] ?? 'member',
            'scoreboardMemberId' => $sbId,
            'profilePhotoUrl'    => $profilePhotoUrl,
        ];
    }

    ssaApiJsonResponse(200, [
        'status' => 'ok',
        'users'  => $users,
        'total'  => count($users),
    ]);
} catch (Throwable $e) {
    $sqlState = ($e instanceof \PDOException && is_array($e->errorInfo))
        ? ($e->errorInfo[0] ?? 'unknown')
        : 'n/a';

    error_log(sprintf(
        'SSA admin/users.php FAILED [%s] SQLSTATE=%s message=%s',
        get_class($e),
        $sqlState,
        $e->getMessage()
    ));

    ssaApiJsonResponse(500, ['error' => 'server_error', 'message' => 'Unable to list users.']);
}
