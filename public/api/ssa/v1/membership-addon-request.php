<?php

declare(strict_types=1);

/**
 * POST /api/ssa/v1/membership-addon-request.php
 *
 * Allows the authenticated user to request a membership add-on.
 * No payment or automatic activation — creates/keeps the user add-on row in
 * requested state and creates a pending ssa_admin_requests approval request.
 * Duplicate pending requests are not created.
 *
 * Request body (JSON):
 *   { "addonKey": "match_recordings" }
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
$addonKey = isset($body['addonKey']) ? trim((string)$body['addonKey']) : '';

if ($addonKey === '') {
    ssaApiJsonResponse(400, [
        'error' => 'missing_addon_key',
        'message' => 'addonKey is required.',
    ]);
}

// Terminal statuses — a new request is allowed after these.
const SSA_ADDON_TERMINAL_STATUSES = ['declined', 'cancelled', 'expired'];

// Non-terminal statuses — return existing status, do not duplicate user add-on rows.
const SSA_ADDON_NON_TERMINAL_STATUSES = ['requested', 'active', 'cancel_pending'];

try {
    $pdo = ssaApiCreatePdo();
    $linked = ssaMembershipRequireLinkedUid($pdo, $claims);
    $uid = $linked['uid'];
    $auth0Sub = $linked['auth0Sub'];

    // Look up the add-on by addon_key.
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

    $pdo->beginTransaction();

    // Find the user's most recent row for this add-on (regardless of status).
    $existingStmt = $pdo->prepare(
        'SELECT id, status, requested_at, activated_at
         FROM ssa_user_membership_addons
         WHERE uid = :uid AND addon_id = :addonId
         ORDER BY requested_at DESC
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
        // Terminal status — insert a fresh requested row below.
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
    }

    $adminRequestId = null;

    // Ensure the admin approval queue has exactly one pending request for a requested add-on.
    // Active/cancel_pending add-ons are already past the user-request step and are returned
    // idempotently without creating a fresh approval request.
    if ($addonStatus === 'requested') {
        $pendingAdminRequest = ssaMembershipPendingAdminRequest(
            $pdo,
            $uid,
            'addon_request',
            $addonKey,
            $addonId
        );

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

            ssaUserNotificationsCreate(
                $pdo,
                $uid,
                'membership_addon_request_submitted',
                'Surrey Snooker Academy',
                $addonKey === 'match_recordings'
                    ? 'Your Match Recordings add-on request has been submitted for approval.'
                    : 'Your membership add-on request has been submitted for approval.',
                'membership',
                (string)$adminRequestId,
                [
                    'adminRequestId' => $adminRequestId,
                    'requestType' => 'addon_request',
                    'addonKey' => $addonKey,
                    'addonId' => $addonId,
                    'addonName' => $addonName,
                ]
            );
        }
    }

    $pdo->commit();

    ssaApiJsonResponse(200, [
        'status' => 'ok',
        'action' => $action,
        'requestId' => $adminRequestId,
        'requestStatus' => $addonStatus === 'requested' ? 'pending' : $addonStatus,
        'addonKey' => $addonKey,
        'addonStatus' => $addonStatus,
        'message' => $action === 'already_exists'
            ? 'You already have a pending or active request for this add-on.'
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
