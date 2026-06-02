<?php

declare(strict_types=1);

/**
 * POST /api/ssa/v1/membership-addon-request.php
 *
 * Allows the authenticated user to request a membership add-on.
 * No payment or automatic activation — creates a 'requested' row for
 * admin review. If the user already has an active or pending request for
 * this add-on, returns the existing status without creating a duplicate.
 *
 * Request body (JSON):
 *   { "addonKey": "match_recordings" }
 *
 * Status transitions:
 *   - No row → inserts row with status 'requested'
 *   - Existing status 'requested' or 'active' or 'cancel_pending' → returns existing status
 *   - Existing status 'declined', 'cancelled', or 'expired' → inserts new 'requested' row
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

$addonKey = isset($body['addonKey']) ? trim((string)$body['addonKey']) : '';

if ($addonKey === '') {
    ssaApiJsonResponse(400, [
        'error' => 'missing_addon_key',
        'message' => 'addonKey is required.',
    ]);
}

// Terminal statuses — a new request is allowed after these.
const SSA_ADDON_TERMINAL_STATUSES = ['declined', 'cancelled', 'expired'];

// Non-terminal statuses — return existing status, do not duplicate.
const SSA_ADDON_PENDING_STATUSES = ['requested', 'active', 'cancel_pending'];

try {
    $pdo = ssaApiCreatePdo();

    // Resolve uid from Auth0 subject.
    $linkStmt = $pdo->prepare(
        'SELECT uid FROM ssa_auth0_user_links
         WHERE auth0_sub = :auth0Sub AND revoked_at IS NULL
         LIMIT 1'
    );
    $linkStmt->execute(['auth0Sub' => $auth0Sub]);
    $link = $linkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$link || (int)($link['uid'] ?? 0) <= 0) {
        ssaApiJsonResponse(403, [
            'error' => 'booking_account_not_linked',
            'message' => 'No linked booking account was found for this app login.',
        ]);
    }

    $uid = (int)$link['uid'];

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

    if ($existing) {
        $existingStatus = (string)$existing['status'];

        if (in_array($existingStatus, SSA_ADDON_PENDING_STATUSES, true)) {
            // Already in an active/pending state — return without creating a duplicate.
            ssaApiJsonResponse(200, [
                'status' => 'ok',
                'action' => 'already_exists',
                'addonKey' => $addonKey,
                'addonStatus' => $existingStatus,
                'message' => 'You already have a ' . $existingStatus . ' request for this add-on.',
            ]);
        }
        // Terminal status — fall through to insert a new request row.
    }

    // Insert new 'requested' row.
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
        'addonKey' => $addonKey,
        'addonStatus' => 'requested',
        'message' => 'Your add-on request has been submitted and is pending review.',
    ]);

} catch (Throwable $exception) {
    error_log('SSA API membership addon request failed: ' . $exception->getMessage());
    ssaApiJsonResponse(500, [
        'error' => 'addon_request_failed',
        'message' => 'Unable to process your add-on request.',
    ]);
}
