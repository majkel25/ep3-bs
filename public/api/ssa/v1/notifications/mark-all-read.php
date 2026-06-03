<?php

declare(strict_types=1);

/**
 * POST /api/ssa/v1/notifications/mark-all-read.php
 */

require_once dirname(__DIR__) . '/_membership_request_helpers.php';
require_once dirname(__DIR__) . '/_user_notifications.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ssaApiJsonResponse(405, [
        'error' => 'method_not_allowed',
        'message' => 'Only POST is allowed for this endpoint.',
    ]);
}

$claims = ssaApiRequireAuth0Claims();

try {
    $pdo = ssaApiCreatePdo();
    $linked = ssaMembershipRequireLinkedUid($pdo, $claims);
    $uid = $linked['uid'];

    ssaUserNotificationsEnsureTable($pdo);

    $stmt = $pdo->prepare(
        'UPDATE ssa_user_notifications
         SET read_at = UTC_TIMESTAMP()
         WHERE uid = :uid AND read_at IS NULL'
    );
    $stmt->execute(['uid' => $uid]);

    ssaApiJsonResponse(200, [
        'status' => 'ok',
        'message' => 'Notifications marked read.',
        'updated_count' => $stmt->rowCount(),
    ]);

} catch (Throwable $exception) {
    error_log('SSA API notification mark-all-read failed: ' . $exception->getMessage());
    ssaApiJsonResponse(500, [
        'error' => 'notification_mark_all_read_failed',
        'message' => 'Unable to mark notifications read.',
    ]);
}
