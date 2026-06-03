<?php

declare(strict_types=1);

/**
 * POST /api/ssa/v1/membership-change-request.php
 *
 * Creates a pending admin approval request for a membership package change.
 * Does not modify ssa_user_memberships or activate the selected plan.
 * Duplicate pending requests for the same target plan are not created.
 *
 * Request body (JSON):
 *   { "planKey": "premium" }
 *
 * Requires a valid Auth0 Bearer token for a linked booking account.
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
$planKey = isset($body['planKey']) ? trim((string)$body['planKey']) : '';

if ($planKey === '') {
    ssaApiJsonResponse(400, [
        'error' => 'missing_plan_key',
        'message' => 'planKey is required.',
    ]);
}

try {
    $pdo = ssaApiCreatePdo();
    $linked = ssaMembershipRequireLinkedUid($pdo, $claims);
    $uid = $linked['uid'];
    $auth0Sub = $linked['auth0Sub'];

    $planStmt = $pdo->prepare(
        'SELECT id, plan_key, name, display_name, monthly_price_pence, currency
         FROM ssa_membership_plans
         WHERE plan_key = :planKey AND is_active = 1 AND is_public = 1
         LIMIT 1'
    );
    $planStmt->execute(['planKey' => $planKey]);
    $targetPlan = $planStmt->fetch(PDO::FETCH_ASSOC);

    if (!$targetPlan) {
        ssaApiJsonResponse(404, [
            'error' => 'plan_not_found',
            'message' => 'The selected membership plan is not currently available.',
        ]);
    }

    $activeStmt = $pdo->prepare(
        'SELECT
            m.id,
            m.plan_id,
            m.status,
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
            'message' => 'A current active membership is required before requesting a package change.',
        ]);
    }

    if ((string)$activeMembership['plan_key'] === (string)$targetPlan['plan_key']) {
        ssaApiJsonResponse(200, [
            'status' => 'ok',
            'action' => 'already_current',
            'requestType' => 'membership_change',
            'requestStatus' => 'none',
            'targetPlanKey' => $planKey,
            'message' => 'You are already on this membership package.',
        ]);
    }

    $targetPlanId = (int)$targetPlan['id'];

    ssaUserNotificationsEnsureTable($pdo);

    $pdo->beginTransaction();

    $existing = ssaMembershipPendingAdminRequest(
        $pdo,
        $uid,
        'membership_change',
        (string)$targetPlan['plan_key'],
        $targetPlanId
    );

    if ($existing) {
        $requestId = (int)$existing['id'];
        $action = 'already_exists';
    } else {
        $payload = [
            'currentMembershipId' => (int)$activeMembership['id'],
            'currentPlanId' => (int)$activeMembership['plan_id'],
            'currentPlanKey' => (string)$activeMembership['plan_key'],
            'currentPlanName' => (string)($activeMembership['display_name'] ?: $activeMembership['name']),
            'targetPlanId' => $targetPlanId,
            'targetPlanKey' => (string)$targetPlan['plan_key'],
            'targetPlanName' => (string)($targetPlan['display_name'] ?: $targetPlan['name']),
            'targetPricePence' => (int)$targetPlan['monthly_price_pence'],
            'targetCurrency' => (string)$targetPlan['currency'],
            'source' => 'ios_app',
        ];

        $requestId = ssaMembershipCreateAdminRequest(
            $pdo,
            $uid,
            $auth0Sub,
            'membership_change',
            (string)$targetPlan['plan_key'],
            $targetPlanId,
            $payload
        );

        ssaUserNotificationsCreate(
            $pdo,
            $uid,
            'membership_change_request_submitted',
            'Surrey Snooker Academy',
            'Your membership change request has been submitted for approval.',
            'membership',
            (string)$requestId,
            array_merge($payload, [
                'adminRequestId' => $requestId,
                'requestType' => 'membership_change',
            ])
        );

        $action = 'requested';
    }

    $pdo->commit();

    ssaApiJsonResponse(200, [
        'status' => 'ok',
        'action' => $action,
        'requestId' => $requestId,
        'requestType' => 'membership_change',
        'requestStatus' => 'pending',
        'targetPlanKey' => (string)$targetPlan['plan_key'],
        'message' => $action === 'already_exists'
            ? 'You already have a pending request for this membership package.'
            : 'Your membership change request has been submitted and is pending review.',
    ]);

} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('SSA API membership change request failed: ' . $exception->getMessage());
    ssaApiJsonResponse(500, [
        'error' => 'membership_change_request_failed',
        'message' => 'Unable to submit your membership change request.',
    ]);
}
