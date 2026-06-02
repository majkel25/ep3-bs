<?php

declare(strict_types=1);

/**
 * POST /api/ssa/v1/membership-addon-request.php
 *
 * Allows the authenticated user to request an active membership add-on.
 * No payment or activation is performed — creates a 'requested' row for
 * admin review. The user can cancel an existing request by posting the same
 * addonSlug again when their current status is 'requested'.
 *
 * Request body (JSON):
 *   { "addonSlug": "match_recordings" }
 *
 * Idempotency:
 *   - If the user has a 'requested' row for this addon, cancel it (status → 'cancelled').
 *   - If the user has an 'approved' row, return 409 — already active.
 *   - Otherwise, insert a new 'requested' row.
 *
 * Requires a valid Auth0 Bearer token for a linked booking account.
 */

require_once __DIR__ . '/_auth0.php';
require_once __DIR__ . '/_db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ssaApiJsonResponse(405, [
        'error' => 'method_not_allowed',
        'message' => 'Only POST is allowed for this endpoint.',
    ]);
}

$claims = ssaApiRequireAuth0Claims();
$auth0Sub = isset($claims['sub']) ? trim((string)$claims['sub']) : '';

if ($auth0Sub === '') {
    ssaApiJsonResponse(401, [
        'error' => 'missing_auth0_subject',
        'message' => 'Auth0 token does not contain a subject.',
    ]);
}

$rawBody = (string)file_get_contents('php://input');
if (trim($rawBody) === '') {
    ssaApiJsonResponse(400, [
        'error' => 'empty_body',
        'message' => 'Request body must contain JSON.',
    ]);
}

$body = json_decode($rawBody, true);
if (!is_array($body)) {
    ssaApiJsonResponse(400, [
        'error' => 'invalid_json',
        'message' => 'Request body must be valid JSON.',
    ]);
}

$addonSlug = isset($body['addonSlug']) ? trim((string)$body['addonSlug']) : '';

if ($addonSlug === '') {
    ssaApiJsonResponse(400, [
        'error' => 'missing_addon_slug',
        'message' => 'addonSlug is required.',
    ]);
}

try {
    $pdo = ssaApiCreatePdo();

    // Resolve uid.
    $linkRow = $pdo->prepare(
        'SELECT uid FROM ssa_auth0_user_links
         WHERE auth0_sub = :auth0Sub AND revoked_at IS NULL
         LIMIT 1'
    );
    $linkRow->execute(['auth0Sub' => $auth0Sub]);
    $link = $linkRow->fetch(PDO::FETCH_ASSOC);

    if (!$link || (int)($link['uid'] ?? 0) <= 0) {
        ssaApiJsonResponse(403, [
            'error' => 'booking_account_not_linked',
            'message' => 'No linked booking account was found for this app login.',
        ]);
    }

    $uid = (int)$link['uid'];

    // Look up the addon.
    $addonRow = $pdo->prepare(
        'SELECT id, name FROM ssa_membership_addons
         WHERE slug = :slug AND is_active = 1
         LIMIT 1'
    );
    $addonRow->execute(['slug' => $addonSlug]);
    $addon = $addonRow->fetch(PDO::FETCH_ASSOC);

    if (!$addon) {
        ssaApiJsonResponse(404, [
            'error' => 'addon_not_found',
            'message' => 'The requested add-on does not exist or is not currently available.',
        ]);
    }

    $addonId = (int)$addon['id'];

    // Check user's most recent row for this addon.
    $existingRow = $pdo->prepare(
        'SELECT id, status FROM ssa_user_membership_addons
         WHERE uid = :uid AND addon_id = :addonId
         ORDER BY requested_at DESC
         LIMIT 1'
    );
    $existingRow->execute(['uid' => $uid, 'addonId' => $addonId]);
    $existing = $existingRow->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $existingStatus = (string)$existing['status'];

        if ($existingStatus === 'approved') {
            ssaApiJsonResponse(409, [
                'error' => 'addon_already_active',
                'message' => 'This add-on is already active on your account.',
                'addonStatus' => 'approved',
            ]);
        }

        if ($existingStatus === 'requested') {
            // Toggle: cancel the pending request.
            $pdo->prepare(
                'UPDATE ssa_user_membership_addons
                 SET status = :status, resolved_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            )->execute(['status' => 'cancelled', 'id' => (int)$existing['id']]);

            ssaApiJsonResponse(200, [
                'status' => 'ok',
                'action' => 'cancelled',
                'addonSlug' => $addonSlug,
                'addonStatus' => 'cancelled',
                'message' => 'Your add-on request has been cancelled.',
            ]);
        }
    }

    // Insert new request.
    $pdo->prepare(
        'INSERT INTO ssa_user_membership_addons
            (uid, addon_id, status, requested_at)
         VALUES (:uid, :addonId, :status, UTC_TIMESTAMP())'
    )->execute([
        'uid' => $uid,
        'addonId' => $addonId,
        'status' => 'requested',
    ]);

    ssaApiJsonResponse(200, [
        'status' => 'ok',
        'action' => 'requested',
        'addonSlug' => $addonSlug,
        'addonStatus' => 'requested',
        'message' => 'Your add-on request has been submitted and is pending admin review.',
    ]);

} catch (Throwable $exception) {
    error_log('SSA API membership addon request failed: ' . $exception->getMessage());
    ssaApiJsonResponse(500, [
        'error' => 'addon_request_failed',
        'message' => 'Unable to process your add-on request.',
    ]);
}
