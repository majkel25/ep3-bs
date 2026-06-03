<?php

declare(strict_types=1);

/**
 * GET /api/ssa/v1/notifications.php
 *
 * Returns in-app notifications for the Auth0-linked booking account.
 */

require_once __DIR__ . '/_membership_request_helpers.php';
require_once __DIR__ . '/_user_notifications.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ssaApiJsonResponse(405, [
        'error' => 'method_not_allowed',
        'message' => 'Only GET is allowed for this endpoint.',
    ]);
}

$claims = ssaApiRequireAuth0Claims();

try {
    $pdo = ssaApiCreatePdo();
    $linked = ssaMembershipRequireLinkedUid($pdo, $claims);
    $uid = $linked['uid'];

    ssaUserNotificationsEnsureTable($pdo);

    $limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 50;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

    $countStmt = $pdo->prepare(
        'SELECT COUNT(*) AS total,
                SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) AS unread
         FROM ssa_user_notifications
         WHERE uid = :uid'
    );
    $countStmt->execute(['uid' => $uid]);
    $counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'unread' => 0];

    $listStmt = $pdo->prepare(
        "SELECT id,
                uid,
                type,
                title,
                message,
                screen,
                entity_id,
                read_at,
                created_at,
                DATE_FORMAT(created_at, '%Y-%m-%dT%H:%i:%sZ') AS created_at_iso,
                CASE
                    WHEN read_at IS NULL THEN NULL
                    ELSE DATE_FORMAT(read_at, '%Y-%m-%dT%H:%i:%sZ')
                END AS read_at_iso
         FROM ssa_user_notifications
         WHERE uid = :uid
         ORDER BY created_at DESC, id DESC
         LIMIT :limit OFFSET :offset"
    );
    $listStmt->bindValue('uid', $uid, PDO::PARAM_INT);
    $listStmt->bindValue('limit', $limit, PDO::PARAM_INT);
    $listStmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $listStmt->execute();

    $notifications = array_map(
        'ssaUserNotificationsRowForApi',
        $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: []
    );

    ssaApiJsonResponse(200, [
        'status' => 'ok',
        'message' => null,
        'count' => (int)$counts['total'],
        'unread_count' => (int)($counts['unread'] ?? 0),
        'notifications' => $notifications,
    ]);

} catch (Throwable $exception) {
    error_log('SSA API notifications fetch failed: ' . $exception->getMessage());
    ssaApiJsonResponse(500, [
        'error' => 'notifications_fetch_failed',
        'message' => 'Unable to fetch notifications.',
    ]);
}
