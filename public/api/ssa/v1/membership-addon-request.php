<?php

declare(strict_types=1);

/**
 * POST /api/ssa/v1/membership-addon-request.php
 *
 * Allows the authenticated user to request or rescind a membership add-on
 * approval request. No payment or automatic activation happens here.
 *
 * Request body (JSON):
 *   { "addonKey": "match_recordings", "action": "request" }
 *   { "addonKey": "match_recordings", "action": "rescind" }
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
$addonKey = isset($body['addonKey']) ? trim((string)$body['addonKey']) : '';
$actionInput = isset($body['action']) ? trim((string)$body['action']) : 'request';
$actionInput = $actionInput === '' ? 'request' : $actionInput;

if ($addonKey === '') {
    ssaApiJsonResponse(400, [
        'error' => 'missing_addon_key',
        'message' => 'addonKey is required.',
    ]);
}

if (!in_array($actionInput, ['request', 'rescind', 'cancel'], true)) {
    ssaApiJsonResponse(400, [
        'error' => 'invalid_action',
        'message' => 'action must be request or rescind.',
    ]);
}

const SSA_ADDON_TERMINAL_STATUSES = ['declined', 'cancelled', 'expired'];
const SSA_ADDON_NON_TERMINAL_STATUSES = ['requested', 'active', 'cancel_pending'];

try {
    $pdo = ssaApiCreatePdo();
    $linked = ssaMembershipRequireLinkedUid($pdo, $claims);
    $uid = $linked['uid'];
    $auth0Sub = $linked['auth0Sub'];

    $addonStmt = $pdo->prepare(
        'SELECT id, addon_key, name FROM ssa_membership_addons
         WHERE addon_key = :addonKey AND is_active = 1
         LIMIT 1'
    );
    $addonStmt->execute(['addonKey' => $addonKey]);
    $addon = $addonStmt->fetch(PDO::FETCH_ASSOC);

    if (!$addon) {
        ssaApiJsonResponse(404, [
            'error' => 'addon_not_found',
            'message' => 'The requested add-on does not exist or is not currently available.',
        ]);
    }

    $addonId = (int)$addon['id'];
    $addonName = (string)$addon['name'];
    ssaUserNotificationsEnsureTable($pdo);

    if ($actionInput === 'rescind' || $actionInput === 'cancel') {
        $pdo->beginTransaction();

        $existing = ssaMembershipPendingAdminRequest($pdo, $uid, 'addon_request', $addonKey, $addonId);
        if (!$existing) {
            $pdo->commit();
            ssaApiJsonResponse(200, [
                'status' => 'ok',
                'action' => 'none_pending',
                'requestType' => 'addon_request',
                'requestStatus' => 'none',
                'addonKey' => $addonKey,
                'addonStatus' => 'not_requested',
                'message' => 'No pending add-on request was found.',
            ]);
        }

        $requestId = (int)$existing['id'];
        ssaMembershipCancelPendingAdminRequest($pdo, $requestId);

        $pdo->prepare(
            'UPDATE ssa_user_membership_addons
             SET status = :cancelled
             WHERE uid = :uid AND addon_id = :addonId AND status = :requested'
        )->execute([
            'uid' => $uid,
            'addonId' => $addonId,
            'requested' => 'requested',
            'cancelled' => 'cancelled',
        ]);

        $cancelledMessage = $addonKey === 'match_recordings'
            ? 'Match Recordings add-on request cancelled by member.'
            : 'Membership add-on request cancelled by member.';

        ssaUserNotificationsCreateOrUpdateForRequest(
            $pdo,
            $uid,
            'membership_addon_request_submitted',
            $requestId,
            $cancelledMessage,
            [
                'adminRequestId' => $requestId,
                'requestType' => 'addon_request',
                'requestStatus' => 'cancelled',
                'addonKey' => $addonKey,
                'addonId' => $addonId,
                'addonName' => $addonName,
            ],
            false,
            true
        );

        $pdo->commit();

        ssaApiJsonResponse(200, [
            'status' => 'ok',
            'action' => 'rescinded',
            'requestId' => $requestId,
            'requestType' => 'addon_request',
            'requestStatus' => 'cancelled',
            'addonKey' => $addonKey,
            'addonStatus' => 'not_requested',
            'message' => $cancelledMessage,
        ]);
    }

    $pdo->beginTransaction();

    $existingStmt = $pdo->prepare(
        'SELECT id, status, requested_at, activated_at
         FROM ssa_user_membership_addons
         WHERE uid = :uid AND addon_id = :addonId
         ORDER BY requested_at DESC, id DESC
         LIMIT 1'
    );
    $existingStmt->execute(['uid' => $uid, 'addonId' => $addonId]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

    $addonStatus = 'requested';
    $action = 'requested';

    if ($existing) {
        $existingStatus = (string)$existing['status'];
        if (in_array($existingStatus, SSA_ADDON_NON_TERMINAL_STATUSES, true)) {
            $addonStatus = $existingStatus;
            $action = 'already_exists';
        }
    }

    if (!$existing || in_array((string)$existing['status'], SSA_ADDON_TERMINAL_STATUSES, true)) {
        $pdo->prepare(
            'INSERT INTO ssa_user_membership_addons
                (uid, addon_id, status, requested_at)
             VALUES (:uid, :addonId, :status, UTC_TIMESTAMP())'
        )->execute([
            'uid' => $uid,
            'addonId' => $addonId,
            'status' => 'requested',
        ]);
        $addonStatus = 'requested';
        $action = 'requested';
    }

    $adminRequestId = null;

    if ($addonStatus === 'requested') {
        $pendingAdminRequest = ssaMembershipPendingAdminRequest($pdo, $uid, 'addon_request', $addonKey, $addonId);

        if ($pendingAdminRequest) {
            $adminRequestId = (int)$pendingAdminRequest['id'];
        } else {
            $adminRequestId = ssaMembershipCreateAdminRequest(
                $pdo,
                $uid,
                $auth0Sub,
                'addon_request',
                $addonKey,
                $addonId,
                [
                    'addonKey' => $addonKey,
                    'addonId' => $addonId,
                    'addonName' => $addonName,
                    'source' => 'ios_app',
                ]
            );
        }

        ssaUserNotificationsCreateOrUpdateForRequest(
            $pdo,
            $uid,
            'membership_addon_request_submitted',
            $adminRequestId,
            $addonKey === 'match_recordings'
                ? 'Match Recordings add-on requested.'
                : $addonName . ' add-on requested.',
            [
                'adminRequestId' => $adminRequestId,
                'requestType' => 'addon_request',
                'requestStatus' => 'pending',
                'addonKey' => $addonKey,
                'addonId' => $addonId,
                'addonName' => $addonName,
            ]
        );
    }

    $pdo->commit();

    ssaApiJsonResponse(200, [
        'status' => 'ok',
        'action' => $action,
        'requestId' => $adminRequestId,
        'requestType' => 'addon_request',
        'requestStatus' => $addonStatus === 'requested' ? 'pending' : $addonStatus,
        'addonKey' => $addonKey,
        'addonStatus' => $addonStatus,
        'message' => $addonKey === 'match_recordings'
            ? 'Match Recordings add-on requested.'
            : 'Your add-on request has been submitted and is pending review.',
    ]);

} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('SSA API membership addon request failed: ' . $exception->getMessage());
    ssaApiJsonResponse(500, [
        'error' => 'addon_request_failed',
        'message' => 'Unable to process your add-on request.',
    ]);
}
