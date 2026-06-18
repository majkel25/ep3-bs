<?php

declare(strict_types=1);

/**
 * POST /api/ssa/v1/addon-cancellation-request.php
 *
 * Handles:
 *   POST { "action": "request", "userAddonId": 123, "memberNotes": "..." }
 *     — Create a cancellation request for an approved add-on.
 *
 *   POST { "action": "withdraw", "requestId": 456 }
 *     — Withdraw a pending cancellation request.
 *
 * Auth: Auth0 JWT. uid resolved from ssa_auth0_user_links.
 */

require_once __DIR__ . '/_auth0.php';
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_user_notifications.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ssaApiJsonResponse(405, ['error' => 'method_not_allowed', 'message' => 'Only POST is allowed.']);
}

$claims   = ssaApiRequireAuth0Claims();
$auth0Sub = trim((string)($claims['sub'] ?? ''));

if ($auth0Sub === '') {
    ssaApiJsonResponse(401, ['error' => 'missing_auth0_subject', 'message' => 'Auth0 token missing subject.']);
}

// ── Parse body ─────────────────────────────────────────────────────────────

$rawBody = (string)file_get_contents('php://input');
if (trim($rawBody) === '') {
    ssaApiJsonResponse(400, ['error' => 'empty_body', 'message' => 'Request body must contain JSON.']);
}
$body = json_decode($rawBody, true);
if (!is_array($body)) {
    ssaApiJsonResponse(400, ['error' => 'invalid_json', 'message' => 'Request body must be valid JSON.']);
}

$action = isset($body['action']) ? trim((string)$body['action']) : '';
if (!in_array($action, ['request', 'withdraw'], true)) {
    ssaApiJsonResponse(400, ['error' => 'invalid_action', 'message' => 'action must be "request" or "withdraw".']);
}

// ── Helpers ────────────────────────────────────────────────────────────────

function ssaAcrColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = :table
           AND COLUMN_NAME  = :column'
    );
    $stmt->execute(['table' => $table, 'column' => $column]);
    return (int)$stmt->fetchColumn() > 0;
}

// ── Main ───────────────────────────────────────────────────────────────────

try {
    $pdo = ssaApiCreatePdo();

    // Resolve uid
    $linkStmt = $pdo->prepare(
        'SELECT uid FROM ssa_auth0_user_links WHERE auth0_sub = :sub AND revoked_at IS NULL LIMIT 1'
    );
    $linkStmt->execute(['sub' => $auth0Sub]);
    $link = $linkStmt->fetch();

    if (!is_array($link) || (int)($link['uid'] ?? 0) <= 0) {
        ssaApiJsonResponse(403, ['error' => 'not_linked', 'message' => 'No linked booking account found.']);
    }

    $uid = (int)$link['uid'];

    // Check which optional cancellation columns exist on ssa_user_membership_addons
    $hasEffectiveAt  = ssaAcrColumnExists($pdo, 'ssa_user_membership_addons', 'cancellation_effective_at');
    $hasRequestId    = ssaAcrColumnExists($pdo, 'ssa_user_membership_addons', 'cancellation_request_id');
    $hasMemberComment = ssaAcrColumnExists($pdo, 'ssa_admin_requests', 'member_comment');

    // ── ACTION: request ────────────────────────────────────────────────────
    if ($action === 'request') {

        $userAddonId = isset($body['userAddonId']) && is_numeric($body['userAddonId'])
            ? (int)$body['userAddonId'] : 0;
        if ($userAddonId <= 0) {
            ssaApiJsonResponse(400, ['error' => 'invalid_user_addon_id', 'message' => 'userAddonId must be a positive integer.']);
        }

        $memberNotes = isset($body['memberNotes']) ? trim((string)$body['memberNotes']) : '';

        // Look up addon assignment — must belong to this user and be approved
        $addonAssignStmt = $pdo->prepare(
            'SELECT ua.id, ua.uid, ua.addon_id, ua.status,
                    a.name AS addon_name, a.addon_key
             FROM ssa_user_membership_addons ua
             INNER JOIN ssa_membership_addons a ON a.id = ua.addon_id
             WHERE ua.id = :id AND ua.uid = :uid AND ua.status = :status
             LIMIT 1'
        );
        $addonAssignStmt->execute(['id' => $userAddonId, 'uid' => $uid, 'status' => 'approved']);
        $addonAssign = $addonAssignStmt->fetch(PDO::FETCH_ASSOC);

        if (!$addonAssign) {
            ssaApiJsonResponse(404, [
                'error'   => 'addon_not_found',
                'message' => 'Active add-on assignment not found or does not belong to you.',
            ]);
        }

        $addonId   = (int)$addonAssign['addon_id'];
        $addonKey  = (string)($addonAssign['addon_key'] ?? '');
        $addonName = (string)($addonAssign['addon_name'] ?? '');

        // Check no existing pending or approved cancellation request for this userAddonId
        $existingReqStmt = $pdo->prepare(
            "SELECT id FROM ssa_admin_requests
             WHERE uid = :uid
               AND request_type = 'addon_cancellation'
               AND target_id = :targetId
               AND status IN ('pending', 'approved')
             LIMIT 1"
        );
        $existingReqStmt->execute(['uid' => $uid, 'targetId' => $userAddonId]);
        if ($existingReqStmt->fetch()) {
            ssaApiJsonResponse(409, [
                'error'   => 'duplicate_request',
                'message' => 'A cancellation request for this add-on is already pending or approved.',
            ]);
        }

        // Calculate effective date: first day of next calendar month in Europe/London
        $tz            = new DateTimeZone('Europe/London');
        $now           = new DateTimeImmutable('now', $tz);
        $effectiveDate = $now->modify('first day of next month')->format('Y-m-d');
        $lastDayOfCurrentMonth = $now->modify('last day of this month')->format('Y-m-d');

        // Load active membership id
        $membershipRow = null;
        try {
            $memStmt = $pdo->prepare(
                "SELECT id FROM ssa_user_memberships WHERE uid = :uid AND status = 'active' ORDER BY id DESC LIMIT 1"
            );
            $memStmt->execute(['uid' => $uid]);
            $membershipRow = $memStmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $memEx) {
            // Non-fatal if table doesn't exist
        }
        $membershipId = $membershipRow ? (int)$membershipRow['id'] : null;

        $payload = [
            'userAddonId'            => $userAddonId,
            'addonId'                => $addonId,
            'addonKey'               => $addonKey,
            'addonName'              => $addonName,
            'membershipId'           => $membershipId,
            'requestedEffectiveDate' => $effectiveDate,
            'lastDayOfCurrentMonth'  => $lastDayOfCurrentMonth,
            'originalStatus'         => 'approved',
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $pdo->beginTransaction();
        try {
            // Insert admin request
            if ($hasMemberComment) {
                $insertSql = 'INSERT INTO ssa_admin_requests
                    (uid, auth0_sub, request_type, status, target_key, target_id, payload_json, member_comment)
                 VALUES
                    (:uid, :auth0Sub, :requestType, :status, :targetKey, :targetId, :payloadJson, :memberComment)';
                $insertParams = [
                    'uid'           => $uid,
                    'auth0Sub'      => $auth0Sub,
                    'requestType'   => 'addon_cancellation',
                    'status'        => 'pending',
                    'targetKey'     => $addonKey,
                    'targetId'      => $userAddonId,
                    'payloadJson'   => $payloadJson,
                    'memberComment' => $memberNotes !== '' ? $memberNotes : null,
                ];
            } else {
                $insertSql = 'INSERT INTO ssa_admin_requests
                    (uid, auth0_sub, request_type, status, target_key, target_id, payload_json)
                 VALUES
                    (:uid, :auth0Sub, :requestType, :status, :targetKey, :targetId, :payloadJson)';
                $insertParams = [
                    'uid'         => $uid,
                    'auth0Sub'    => $auth0Sub,
                    'requestType' => 'addon_cancellation',
                    'status'      => 'pending',
                    'targetKey'   => $addonKey,
                    'targetId'    => $userAddonId,
                    'payloadJson' => $payloadJson,
                ];
            }

            $pdo->prepare($insertSql)->execute($insertParams);
            $requestId = (int)$pdo->lastInsertId();

            // Update addon assignment with cancellation columns (only if they exist)
            if ($hasEffectiveAt || $hasRequestId) {
                $updateAddonCols = [];
                $updateAddonParams = ['id' => $userAddonId];
                if ($hasEffectiveAt) {
                    $updateAddonCols[] = 'cancellation_effective_at = :effectiveDate';
                    $updateAddonParams['effectiveDate'] = $effectiveDate;
                }
                if ($hasRequestId) {
                    $updateAddonCols[] = 'cancellation_request_id = :requestId';
                    $updateAddonParams['requestId'] = $requestId;
                }
                if (!empty($updateAddonCols)) {
                    $pdo->prepare(
                        'UPDATE ssa_user_membership_addons SET ' . implode(', ', $updateAddonCols) . ' WHERE id = :id'
                    )->execute($updateAddonParams);
                }
            }

            $pdo->commit();
        } catch (Throwable $txEx) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $txEx;
        }

        // Notification (non-fatal)
        try {
            ssaUserNotificationsEnsureTable($pdo);
            ssaUserNotificationsCreate(
                $pdo,
                $uid,
                'addon_cancellation_submitted',
                'Cancellation Request Submitted',
                'Your request to cancel ' . $addonName . ' has been submitted. It will remain active until ' . $lastDayOfCurrentMonth . ' if approved.',
                'membership',
                (string)$requestId,
                ['requestId' => $requestId, 'addonName' => $addonName]
            );
        } catch (Throwable $notifEx) {
            error_log('addon-cancellation-request.php: notification failed: ' . $notifEx->getMessage());
        }

        ssaApiJsonResponse(200, [
            'status'               => 'ok',
            'requestId'            => $requestId,
            'effectiveDate'        => $effectiveDate,
            'lastDayOfCurrentMonth' => $lastDayOfCurrentMonth,
        ]);
    }

    // ── ACTION: withdraw ───────────────────────────────────────────────────
    if ($action === 'withdraw') {

        $requestId = isset($body['requestId']) && is_numeric($body['requestId'])
            ? (int)$body['requestId'] : 0;
        if ($requestId <= 0) {
            ssaApiJsonResponse(400, ['error' => 'invalid_request_id', 'message' => 'requestId must be a positive integer.']);
        }

        // Fetch the pending request
        $reqStmt = $pdo->prepare(
            "SELECT id, uid, target_id, payload_json
             FROM ssa_admin_requests
             WHERE id = :id AND uid = :uid AND request_type = 'addon_cancellation' AND status = 'pending'
             LIMIT 1"
        );
        $reqStmt->execute(['id' => $requestId, 'uid' => $uid]);
        $request = $reqStmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            ssaApiJsonResponse(404, [
                'error'   => 'request_not_found',
                'message' => 'Pending cancellation request not found.',
            ]);
        }

        $userAddonId = isset($request['target_id']) ? (int)$request['target_id'] : null;

        $payload = [];
        if (isset($request['payload_json']) && $request['payload_json']) {
            $decoded = json_decode((string)$request['payload_json'], true);
            if (is_array($decoded)) $payload = $decoded;
        }
        $addonName = (string)($payload['addonName'] ?? 'Add-on');

        $pdo->beginTransaction();
        try {
            // Withdraw the request
            $pdo->prepare(
                "UPDATE ssa_admin_requests
                 SET status = 'withdrawn', actioned_at = UTC_TIMESTAMP()
                 WHERE id = :id AND uid = :uid AND status = 'pending'"
            )->execute(['id' => $requestId, 'uid' => $uid]);

            // Clear cancellation columns on addon assignment
            if ($userAddonId !== null && ($hasEffectiveAt || $hasRequestId)) {
                $clearCols   = [];
                $clearParams = ['id' => $userAddonId];
                if ($hasEffectiveAt) {
                    $clearCols[] = 'cancellation_effective_at = NULL';
                }
                if ($hasRequestId) {
                    $clearCols[] = 'cancellation_request_id = NULL';
                }
                if (!empty($clearCols)) {
                    $pdo->prepare(
                        'UPDATE ssa_user_membership_addons SET ' . implode(', ', $clearCols) . ' WHERE id = :id'
                    )->execute($clearParams);
                }
            }

            $pdo->commit();
        } catch (Throwable $txEx) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $txEx;
        }

        // Notification (non-fatal)
        try {
            ssaUserNotificationsEnsureTable($pdo);
            ssaUserNotificationsCreate(
                $pdo,
                $uid,
                'addon_cancellation_withdrawn',
                'Cancellation Request Withdrawn',
                'Your cancellation request for ' . $addonName . ' has been withdrawn. The add-on remains active.',
                'membership',
                (string)$requestId,
                ['requestId' => $requestId, 'addonName' => $addonName]
            );
        } catch (Throwable $notifEx) {
            error_log('addon-cancellation-request.php: withdraw notification failed: ' . $notifEx->getMessage());
        }

        ssaApiJsonResponse(200, [
            'status'    => 'ok',
            'requestId' => $requestId,
        ]);
    }

} catch (Throwable $e) {
    error_log(sprintf(
        'SSA addon-cancellation-request.php FAILED [%s] %s in %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ssaApiJsonResponse(500, ['error' => 'server_error', 'message' => 'Unable to process cancellation request.']);
}
