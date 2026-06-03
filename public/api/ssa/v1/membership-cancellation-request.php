<?php

declare(strict_types=1);

/**
 * POST /api/ssa/v1/membership-cancellation-request.php
 *
 * Creates a pending admin approval request for membership cancellation.
 * Does not cancel or otherwise update the active membership immediately.
 * Duplicate pending cancellation requests for the current membership are not created.
 *
 * Request body (JSON):
 *   { "reason": "optional member note" }
 *
 * Requires a valid Auth0 Bearer token for a linked booking account.
 */

require_once __DIR__ . '/_membership_request_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ssaApiJsonResponse(405, [
        'error' => 'method_not_allowed',
        'message' => 'Only POST is allowed for this endpoint.',
    ]);
}

$claims = ssaApiRequireAuth0Claims();
$body = ssaMembershipReadJsonBody();
$reason = isset($body['reason']) ? trim((string)$body['reason']) : '';

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
            [
                'currentMembershipId' => $membershipId,
                'currentPlanId' => (int)$activeMembership['plan_id'],
                'currentPlanKey' => (string)$activeMembership['plan_key'],
                'currentPlanName' => (string)($activeMembership['display_name'] ?: $activeMembership['name']),
                'currentPeriodEnd' => $activeMembership['current_period_ends_at'],
                'cancellationNoticeDeadline' => $activeMembership['cancellation_notice_deadline_at'],
                'reason' => $reason !== '' ? $reason : null,
                'source' => 'ios_app',
            ]
        );
        $action = 'requested';
    }

    $pdo->commit();

    ssaApiJsonResponse(200, [
        'status' => 'ok',
        'action' => $action,
        'requestId' => $requestId,
        'requestType' => 'membership_cancellation',
        'requestStatus' => 'pending',
        'message' => $action === 'already_exists'
            ? 'You already have a pending membership cancellation request.'
            : 'Your membership cancellation request has been submitted and is pending review.',
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
