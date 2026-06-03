<?php

declare(strict_types=1);

/**
 * POST /api/ssa/v1/membership-cancellation-request.php
 *
 * Creates or rescinds a pending admin approval request for membership
 * cancellation. Does not cancel or otherwise update the active membership.
 *
 * Request body (JSON):
 *   { "action": "request", "reason": "optional member note" }
 *   { "action": "rescind" }
 */

require_once __DIR__ . '/_membership_request_helpers.php';
require_once __DIR__ . '/_user_notifications.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ssaApiJsonResponse(405, [
        'error' => 'method_not_allowed',
        'message' => 'Only POST is allowed for this endpoint.',
    ]);
}

$claims = ssaApiRequireAuth0Claims();
$body = ssaMembershipReadJsonBody();
$actionInput = isset($body['action']) ? trim((string)$body['action']) : 'request';
$actionInput = $actionInput === '' ? 'request' : $actionInput;
$reason = isset($body['reason']) ? trim((string)$body['reason']) : '';

if (!in_array($actionInput, ['request', 'rescind', 'cancel'], true)) {
    ssaApiJsonResponse(400, [
        'error' => 'invalid_action',
        'message' => 'action must be request or rescind.',
    ]);
}

try {
    $pdo = ssaApiCreatePdo();
    $linked = ssaMembershipRequireLinkedUid($pdo, $claims);
    $uid = $linked['uid'];
    $auth0Sub = $linked['auth0Sub'];

    $activeStmt = $pdo->prepare(
        'SELECT
            m.id,
            m.plan_id,
            m.status,
            m.started_at,
            m.current_period_ends_at,
            m.cancellation_notice_deadline_at,
            p.plan_key,
            p.name,
            p.display_name
         FROM ssa_user_memberships m
         INNER JOIN ssa_membership_plans p ON p.id = m.plan_id
         WHERE m.uid = :uid AND m.status = :status
         ORDER BY m.started_at DESC, m.id DESC
         LIMIT 1'
    );
    $activeStmt->execute(['uid' => $uid, 'status' => 'active']);
    $activeMembership = $activeStmt->fetch(PDO::FETCH_ASSOC);

    if (!$activeMembership) {
        ssaApiJsonResponse(400, [
            'error' => 'no_active_membership',
            'message' => 'A current active membership is required before requesting cancellation.',
        ]);
    }

    $membershipId = (int)$activeMembership['id'];
    ssaUserNotificationsEnsureTable($pdo);

    if ($actionInput === 'rescind' || $actionInput === 'cancel') {
        $pdo->beginTransaction();

        $existing = ssaMembershipPendingAdminRequest(
            $pdo,
            $uid,
            'membership_cancellation',
            null,
            null,
            false
        );
        if (!$existing) {
            $pdo->commit();
            ssaApiJsonResponse(200, [
                'status' => 'ok',
                'action' => 'none_pending',
                'requestType' => 'membership_cancellation',
                'requestStatus' => 'none',
                'message' => 'No pending membership cancellation request was found.',
            ]);
        }

        $requestId = (int)$existing['id'];
        ssaMembershipCancelPendingAdminRequest($pdo, $requestId);
        ssaUserNotificationsCreateOrUpdateForRequest(
            $pdo,
            $uid,
            'membership_cancellation_request_submitted',
            $requestId,
            'Membership cancellation request rescinded by member.',
            [
                'adminRequestId' => $requestId,
                'requestType' => 'membership_cancellation',
                'requestStatus' => 'cancelled',
            ],
            false,
            true
        );

        $pdo->commit();

        ssaApiJsonResponse(200, [
            'status' => 'ok',
            'action' => 'rescinded',
            'requestId' => $requestId,
            'requestType' => 'membership_cancellation',
            'requestStatus' => 'cancelled',
            'message' => 'Membership cancellation request rescinded by member.',
        ]);
    }

    $payload = [
        'currentMembershipId' => $membershipId,
        'currentPlanId' => (int)$activeMembership['plan_id'],
        'currentPlanKey' => (string)$activeMembership['plan_key'],
        'currentPlanName' => (string)($activeMembership['display_name'] ?: $activeMembership['name']),
        'currentPeriodEnd' => $activeMembership['current_period_ends_at'],
        'cancellationNoticeDeadline' => $activeMembership['cancellation_notice_deadline_at'],
        'reason' => $reason !== '' ? $reason : null,
        'source' => 'ios_app',
    ];

    $pdo->beginTransaction();

    $existing = ssaMembershipPendingAdminRequest(
        $pdo,
        $uid,
        'membership_cancellation',
        (string)$activeMembership['plan_key'],
        $membershipId
    );

    if ($existing) {
        $requestId = (int)$existing['id'];
        $action = 'already_exists';
    } else {
        $requestId = ssaMembershipCreateAdminRequest(
            $pdo,
            $uid,
            $auth0Sub,
            'membership_cancellation',
            (string)$activeMembership['plan_key'],
            $membershipId,
            $payload
        );
        $action = 'requested';
    }

    ssaUserNotificationsCreateOrUpdateForRequest(
        $pdo,
        $uid,
        'membership_cancellation_request_submitted',
        $requestId,
        'Membership cancellation requested.',
        array_merge($payload, [
            'adminRequestId' => $requestId,
            'requestType' => 'membership_cancellation',
            'requestStatus' => 'pending',
        ])
    );

    $pdo->commit();

    ssaApiJsonResponse(200, [
        'status' => 'ok',
        'action' => $action,
        'requestId' => $requestId,
        'requestType' => 'membership_cancellation',
        'requestStatus' => 'pending',
        'message' => 'Membership cancellation requested.',
    ]);

} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('SSA API membership cancellation request failed: ' . $exception->getMessage());
    ssaApiJsonResponse(500, [
        'error' => 'membership_cancellation_request_failed',
        'message' => 'Unable to submit your membership cancellation request.',
    ]);
}
