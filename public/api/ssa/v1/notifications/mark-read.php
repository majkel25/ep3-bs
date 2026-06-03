<?php

declare(strict_types=1);

/**
 * POST /api/ssa/v1/notifications/mark-read.php
 *
 * Body: { "notificationId": 123 }
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
$body = ssaMembershipReadJsonBody();
$notificationId = isset($body['notificationId']) ? (int)$body['notificationId'] : 0;

if ($notificationId <= 0) {
    ssaApiJsonResponse(400, [
        'error' => 'missing_notification_id',
        'message' => 'notificationId is required.',
    ]);
}

try {
    $pdo = ssaApiCreatePdo();
    $linked = ssaMembershipRequireLinkedUid($pdo, $claims);
    $uid = $linked['uid'];

    ssaUserNotificationsEnsureTable($pdo);

    $stmt = $pdo->prepare(
        'UPDATE ssa_user_notifications
         SET read_at = COALESCE(read_at, UTC_TIMESTAMP())
         WHERE id = :id AND uid = :uid'
    );
    $stmt->execute([
        'id' => $notificationId,
        'uid' => $uid,
    ]);

    ssaApiJsonResponse(200, [
        'status' => 'ok',
        'message' => 'Notification marked read.',
        'updated_count' => $stmt->rowCount(),
    ]);

} catch (Throwable $exception) {
    error_log('SSA API notification mark-read failed: ' . $exception->getMessage());
    ssaApiJsonResponse(500, [
        'error' => 'notification_mark_read_failed',
        'message' => 'Unable to mark notification read.',
    ]);
}
