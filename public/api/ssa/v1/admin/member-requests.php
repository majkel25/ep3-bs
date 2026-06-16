<?php

declare(strict_types=1);

/**
 * GET /api/ssa/v1/admin/member-requests.php
 *
 * List admin/member requests for admin panel.
 *
 * Query params:
 *   status  string  – pending|approved|declined|all (default: pending)
 *   uid     int     – optional filter by member uid
 *   type    string  – optional filter by request_type
 *
 * Excludes cancelled (member-cancelled) requests.
 * Auth: Admin or Club Owner only.
 *
 * Response:
 *   { status: "ok", requests: [...], total: N }
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

// ── Helpers ────────────────────────────────────────────────────────────────────

function ssaAdminRequestsEnsureColumns(PDO $pdo): void
{
    $stmt = $pdo->query(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ssa_admin_requests'"
    );
    $cols = array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));

    if (!in_array('actioned_at', $cols, true)) {
        $pdo->exec('ALTER TABLE ssa_admin_requests ADD COLUMN actioned_at DATETIME NULL');
    }
    if (!in_array('actioned_by_uid', $cols, true)) {
        $pdo->exec('ALTER TABLE ssa_admin_requests ADD COLUMN actioned_by_uid INT NULL');
    }
    if (!in_array('admin_comment', $cols, true)) {
        $pdo->exec('ALTER TABLE ssa_admin_requests ADD COLUMN admin_comment TEXT NULL');
    }
}

function ssaAdminRequestsRequestTypeLabel(string $requestType): string
{
    $map = [
        'membership_change'        => 'Membership tier change',
        'addon_request'            => 'Add-on request',
        'membership_cancellation'  => 'Membership cancellation',
    ];
    return $map[$requestType] ?? $requestType;
}

function ssaAdminRequestsBuildChangeSummary(string $requestType, array $payload): string
{
    switch ($requestType) {
        case 'membership_change':
            $from = $payload['currentPlanName'] ?? $payload['currentPlanKey'] ?? 'Unknown';
            $to   = $payload['targetPlanName']  ?? $payload['targetPlanKey']  ?? 'Unknown';
            return 'Change membership from ' . $from . ' to ' . $to;

        case 'addon_request':
            $addonName = $payload['addonName'] ?? $payload['addonKey'] ?? 'Unknown';
            return 'Add ' . $addonName;

        case 'membership_cancellation':
            $planName = $payload['currentPlanName'] ?? $payload['currentPlanKey'] ?? 'Unknown';
            return 'Cancel membership (' . $planName . ')';

        default:
            return 'Unknown change';
    }
}

// ── Main ───────────────────────────────────────────────────────────────────────

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

    // Idempotent column migration.
    ssaAdminRequestsEnsureColumns($pdo);

    // Parse query params.
    $statusParam = isset($_GET['status']) ? trim((string)$_GET['status']) : 'pending';
    $allowedStatuses = ['pending', 'approved', 'declined', 'all'];
    if (!in_array($statusParam, $allowedStatuses, true)) {
        ssaApiJsonResponse(400, [
            'error'   => 'invalid_status',
            'message' => 'status must be one of: pending, approved, declined, all.',
        ]);
    }

    $filterUid  = isset($_GET['uid']) && is_numeric($_GET['uid']) && (int)$_GET['uid'] > 0
        ? (int)$_GET['uid']
        : null;

    $allowedTypes = ['membership_change', 'addon_request', 'membership_cancellation'];
    $filterType   = isset($_GET['type']) && in_array(trim((string)$_GET['type']), $allowedTypes, true)
        ? trim((string)$_GET['type'])
        : null;

    // Build WHERE clauses. Always exclude cancelled (member-cancelled).
    $where  = ["r.status != 'cancelled'"];
    $params = [];

    if ($statusParam !== 'all') {
        $where[]           = 'r.status = :status';
        $params['status']  = $statusParam;
    }

    if ($filterUid !== null) {
        $where[]         = 'r.uid = :filterUid';
        $params['filterUid'] = $filterUid;
    }

    if ($filterType !== null) {
        $where[]              = 'r.request_type = :filterType';
        $params['filterType'] = $filterType;
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    $sql = "
        SELECT
            r.id,
            r.uid           AS member_uid,
            r.request_type,
            r.status,
            r.payload_json,
            r.requested_at,
            r.actioned_at,
            r.actioned_by_uid,
            r.admin_comment,
            u.alias         AS member_name,
            u.email         AS member_email
        FROM ssa_admin_requests r
        INNER JOIN bs_users u ON u.uid = r.uid
        {$whereClause}
        ORDER BY
            CASE r.status WHEN 'pending' THEN 0 ELSE 1 END ASC,
            r.requested_at DESC,
            r.id DESC
        LIMIT 100
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        if (is_int($v)) {
            $stmt->bindValue($k, $v, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
    }
    $stmt->execute();
    $rows = $stmt->fetchAll();

    // Batch-fetch admin names for actioned_by_uid values.
    $adminUids = array_values(array_unique(array_filter(
        array_map(static fn($r) => isset($r['actioned_by_uid']) ? (int)$r['actioned_by_uid'] : null, $rows),
        static fn($v) => $v !== null && $v > 0
    )));

    $adminNames = [];
    if (!empty($adminUids)) {
        $placeholders = implode(',', array_fill(0, count($adminUids), '?'));
        $nameStmt     = $pdo->prepare(
            "SELECT uid, alias FROM bs_users WHERE uid IN ({$placeholders})"
        );
        $nameStmt->execute($adminUids);
        foreach ($nameStmt->fetchAll() as $adminRow) {
            $adminNames[(int)$adminRow['uid']] = (string)($adminRow['alias'] ?? '');
        }
    }

    // Build response objects.
    $requests = [];
    foreach ($rows as $row) {
        $payload = [];
        if (isset($row['payload_json']) && $row['payload_json'] !== null && $row['payload_json'] !== '') {
            $decoded = json_decode((string)$row['payload_json'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $requestType = (string)$row['request_type'];
        $changeSummary = ssaAdminRequestsBuildChangeSummary($requestType, $payload);

        $actionedByUid  = isset($row['actioned_by_uid']) && $row['actioned_by_uid'] !== null
            ? (int)$row['actioned_by_uid']
            : null;
        $actionedByName = $actionedByUid !== null ? ($adminNames[$actionedByUid] ?? null) : null;

        $requests[] = [
            'requestId'              => (int)$row['id'],
            'requestType'            => $requestType,
            'requestTypeLabel'       => ssaAdminRequestsRequestTypeLabel($requestType),
            'status'                 => (string)$row['status'],
            'memberUid'              => (int)$row['member_uid'],
            'memberName'             => $row['member_name'] ?? null,
            'memberEmail'            => $row['member_email'] ?? null,
            'currentPlanKey'         => $payload['currentPlanKey'] ?? null,
            'currentPlanName'        => $payload['currentPlanName'] ?? null,
            'targetPlanKey'          => $payload['targetPlanKey'] ?? null,
            'targetPlanName'         => $payload['targetPlanName'] ?? null,
            'addonKey'               => $payload['addonKey'] ?? null,
            'addonName'              => $payload['addonName'] ?? null,
            'requestedChangeSummary' => $changeSummary,
            'memberComment'          => $payload['memberComment'] ?? null,
            'createdAt'              => $row['requested_at'] ?? null,
            'actionedAt'             => $row['actioned_at'] ?? null,
            'actionedByUid'          => $actionedByUid,
            'actionedByName'         => $actionedByName,
            'adminComment'           => $row['admin_comment'] ?? null,
        ];
    }

    ssaApiJsonResponse(200, [
        'status'   => 'ok',
        'requests' => $requests,
        'total'    => count($requests),
    ]);
} catch (Throwable $e) {
    $sqlState = ($e instanceof \PDOException && is_array($e->errorInfo))
        ? ($e->errorInfo[0] ?? 'unknown')
        : 'n/a';
    error_log(sprintf(
        'SSA admin/member-requests.php FAILED [%s] SQLSTATE=%s message=%s',
        get_class($e), $sqlState, $e->getMessage()
    ));
    ssaApiJsonResponse(500, ['error' => 'server_error', 'message' => 'Unable to list member requests.']);
}
