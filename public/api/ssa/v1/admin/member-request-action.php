<?php

declare(strict_types=1);

/**
 * POST /api/ssa/v1/admin/member-request-action.php
 *
 * Approve or decline a member admin request.
 *
 * Body (JSON):
 *   requestId    int     required
 *   action       string  required – "approve" | "decline"
 *   adminComment string  optional (required when action=decline)
 *
 * Auth: Admin or Club Owner only.
 *
 * Response:
 *   { status: "ok", requestId: 123, newStatus: "approved"|"declined" }
 */

require_once __DIR__ . '/../_auth0.php';
require_once __DIR__ . '/../_db.php';
require_once __DIR__ . '/../_user_notifications.php';
require_once __DIR__ . '/../_push_apns.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ssaApiJsonResponse(405, ['error' => 'method_not_allowed', 'message' => 'Only POST is allowed.']);
}

$claims   = ssaApiRequireAuth0Claims();
$auth0Sub = trim((string)($claims['sub'] ?? ''));

if ($auth0Sub === '') {
    ssaApiJsonResponse(401, ['error' => 'missing_auth0_subject', 'message' => 'Auth0 token missing subject.']);
}

// ── Helpers ────────────────────────────────────────────────────────────────────

function ssaActionEnsureAdminRequestsTable(PDO $pdo): void
{
    // Creates ssa_admin_requests if it doesn't exist, then idempotently adds
    // the admin-action columns introduced by this endpoint.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ssa_admin_requests (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          uid INT UNSIGNED NOT NULL,
          auth0_sub VARCHAR(128) NOT NULL,
          request_type VARCHAR(64) NOT NULL,
          status VARCHAR(32) NOT NULL DEFAULT 'pending',
          target_key VARCHAR(128) NULL,
          target_id INT UNSIGNED NULL,
          payload_json JSON NULL,
          requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          actioned_at DATETIME NULL,
          actioned_by_uid INT UNSIGNED NULL,
          admin_comment TEXT NULL,
          PRIMARY KEY (id),
          KEY idx_ssa_admin_requests_uid (uid),
          KEY idx_ssa_admin_requests_status (status),
          KEY idx_ssa_admin_requests_type (request_type),
          KEY idx_ssa_admin_requests_requested_at (requested_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Idempotently add columns for existing tables that pre-date this endpoint.
    $stmt = $pdo->query(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ssa_admin_requests'"
    );
    $cols = array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));

    if (!in_array('actioned_at', $cols, true)) {
        $pdo->exec('ALTER TABLE ssa_admin_requests ADD COLUMN actioned_at DATETIME NULL');
    }
    if (!in_array('actioned_by_uid', $cols, true)) {
        $pdo->exec('ALTER TABLE ssa_admin_requests ADD COLUMN actioned_by_uid INT UNSIGNED NULL');
    }
    if (!in_array('admin_comment', $cols, true)) {
        $pdo->exec('ALTER TABLE ssa_admin_requests ADD COLUMN admin_comment TEXT NULL');
    }
}

function ssaActionEnsureAuditLogTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ssa_member_request_audit_log (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          request_id BIGINT UNSIGNED NOT NULL,
          request_type VARCHAR(64) NOT NULL,
          member_uid INT UNSIGNED NOT NULL,
          member_name_snapshot VARCHAR(255) NULL,
          previous_status VARCHAR(32) NOT NULL,
          new_status VARCHAR(32) NOT NULL,
          change_summary TEXT NOT NULL,
          admin_uid INT UNSIGNED NOT NULL,
          admin_name_snapshot VARCHAR(255) NULL,
          admin_comment TEXT NULL,
          actioned_at DATETIME NOT NULL,
          effective_date DATETIME NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_audit_request_id (request_id),
          KEY idx_audit_member_uid (member_uid),
          KEY idx_audit_admin_uid (admin_uid),
          KEY idx_audit_actioned_at (actioned_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ssaActionBuildChangeSummary(string $requestType, array $payload): string
{
    switch ($requestType) {
        case 'membership_change':
            $from = $payload['currentPlanName'] ?? $payload['currentPlanKey'] ?? 'Unknown';
            $to   = $payload['targetPlanName']  ?? $payload['targetPlanKey']  ?? 'Unknown';
            return 'Change membership from ' . $from . ' to ' . $to;

        case 'addon_request':
            $addonName = $payload['addonName'] ?? $payload['addonKey'] ?? 'Unknown';
            return 'Add ' . $addonName;

        case 'membership_cancellation':
            $planName = $payload['currentPlanName'] ?? $payload['currentPlanKey'] ?? 'Unknown';
            return 'Cancel membership (' . $planName . ')';

        case 'addon_cancellation':
            $addonName = $payload['addonName'] ?? $payload['addonKey'] ?? 'Unknown';
            return 'Cancel add-on ' . $addonName;

        default:
            return 'Unknown change';
    }
}

function ssaActionCheckPushTokenColumn(PDO $pdo): bool
{
    $stmt = $pdo->query(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'ssa_push_tokens'
           AND COLUMN_NAME = 'revoked_at'"
    );
    return $stmt->fetchColumn() !== false;
}

// ── Main ───────────────────────────────────────────────────────────────────────

try {
    $pdo = ssaApiCreatePdo();

    // Resolve caller uid and check role.
    $linkStmt = $pdo->prepare(
        'SELECT uid FROM ssa_auth0_user_links WHERE auth0_sub = :sub AND revoked_at IS NULL LIMIT 1'
    );
    $linkStmt->execute(['sub' => $auth0Sub]);
    $link = $linkStmt->fetch();

    if (!is_array($link) || (int)($link['uid'] ?? 0) <= 0) {
        ssaApiJsonResponse(403, ['error' => 'not_linked', 'message' => 'No linked account found.']);
    }

    $callerUid  = (int)$link['uid'];
    $callerType = ssaApiGetUserMetaValue($pdo, $callerUid, 'ssa.user_type') ?? 'member';

    if (!in_array($callerType, ['admin', 'club_owner'], true)) {
        ssaApiJsonResponse(403, ['error' => 'forbidden', 'message' => 'Admin or Owner access required.']);
    }

    // Ensure ssa_admin_requests table and required columns exist.
    ssaActionEnsureAdminRequestsTable($pdo);

    // Parse body.
    $rawBody = (string)file_get_contents('php://input');
    if (trim($rawBody) === '') {
        ssaApiJsonResponse(400, ['error' => 'empty_body', 'message' => 'Request body must contain JSON.']);
    }
    $body = json_decode($rawBody, true);
    if (!is_array($body)) {
        ssaApiJsonResponse(400, ['error' => 'invalid_json', 'message' => 'Request body must be valid JSON.']);
    }

    $requestId    = isset($body['requestId']) && is_numeric($body['requestId']) ? (int)$body['requestId'] : null;
    $action       = isset($body['action']) ? trim((string)$body['action']) : '';
    $adminComment = isset($body['adminComment']) ? trim((string)$body['adminComment']) : '';

    if ($requestId === null || $requestId <= 0) {
        ssaApiJsonResponse(400, ['error' => 'missing_request_id', 'message' => 'requestId is required.']);
    }

    if (!in_array($action, ['approve', 'decline'], true)) {
        ssaApiJsonResponse(400, ['error' => 'invalid_action', 'message' => 'action must be "approve" or "decline".']);
    }

    if ($action === 'decline' && $adminComment === '') {
        ssaApiJsonResponse(400, [
            'error'   => 'decline_comment_required',
            'message' => 'adminComment is required when declining a request.',
        ]);
    }

    // Fetch caller name for notifications.
    $callerNameStmt = $pdo->prepare('SELECT alias FROM bs_users WHERE uid = :uid LIMIT 1');
    $callerNameStmt->execute(['uid' => $callerUid]);
    $callerRow  = $callerNameStmt->fetch();
    $callerName = $callerRow ? (string)($callerRow['alias'] ?? '') : '';

    // ── Transaction ────────────────────────────────────────────────────────────

    $pdo->beginTransaction();

    try {
        // Fetch and lock the request.
        $reqStmt = $pdo->prepare(
            'SELECT r.id, r.uid AS member_uid, r.request_type, r.status, r.payload_json,
                    u.alias AS member_name, u.email AS member_email
             FROM ssa_admin_requests r
             INNER JOIN bs_users u ON u.uid = r.uid
             WHERE r.id = :id
             LIMIT 1'
        );
        $reqStmt->execute(['id' => $requestId]);
        $request = $reqStmt->fetch();

        if (!is_array($request)) {
            $pdo->rollBack();
            ssaApiJsonResponse(404, ['error' => 'request_not_found', 'message' => 'Request not found.']);
        }

        if ((string)$request['status'] !== 'pending') {
            $pdo->rollBack();
            ssaApiJsonResponse(409, [
                'error'   => 'request_already_actioned',
                'message' => 'This request has already been actioned and cannot be changed.',
            ]);
        }

        $memberUid   = (int)$request['member_uid'];
        $requestType = (string)$request['request_type'];
        $memberName  = (string)($request['member_name'] ?? '');

        $payload = [];
        if (isset($request['payload_json']) && $request['payload_json'] !== null && $request['payload_json'] !== '') {
            $decoded = json_decode((string)$request['payload_json'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $changeSummary = ssaActionBuildChangeSummary($requestType, $payload);
        $newStatus     = ($action === 'approve') ? 'approved' : 'declined';

        if ($action === 'approve') {
            // ── Approve-specific logic ─────────────────────────────────────────

            switch ($requestType) {
                case 'membership_change':
                    $targetPlanId = isset($payload['targetPlanId']) ? (int)$payload['targetPlanId'] : null;

                    if ($targetPlanId !== null && $targetPlanId > 0) {
                        // Load plan and capture snapshot fields.
                        $planFetch = $pdo->prepare(
                            'SELECT id, monthly_price_pence, currency,
                                    COALESCE(display_name, name) AS plan_name_snapshot
                             FROM ssa_membership_plans
                             WHERE id = :planId AND is_active = 1
                             LIMIT 1'
                        );
                        $planFetch->execute(['planId' => $targetPlanId]);
                        $planRow = $planFetch->fetch();

                        if (!$planRow) {
                            $pdo->rollBack();
                            ssaApiJsonResponse(400, [
                                'error'   => 'invalid_membership_plan',
                                'message' => 'The requested membership plan is no longer available.',
                            ]);
                        }

                        // Lock current active row and read all required period fields.
                        // current_period_ends_at / cancellation_notice_deadline_at are NOT NULL
                        // in the production schema and have no defaults – they must be inherited
                        // from the old row (pro-rata plan change preserves the billing period).
                        $curMembershipStmt = $pdo->prepare(
                            'SELECT id,
                                    current_period_ends_at,
                                    cancellation_notice_deadline_at
                             FROM ssa_user_memberships
                             WHERE uid = :uid AND status = :active
                             ORDER BY started_at DESC, id DESC
                             LIMIT 1
                             FOR UPDATE'
                        );
                        $curMembershipStmt->execute(['uid' => $memberUid, 'active' => 'active']);
                        $curMembership   = $curMembershipStmt->fetch();
                        $oldMembershipId = $curMembership ? (int)$curMembership['id'] : null;

                        $oldPeriodEndsAt   = $curMembership['current_period_ends_at']      ?? null;
                        $oldCancelDeadline = $curMembership['cancellation_notice_deadline_at'] ?? null;

                        // Both period columns are NOT NULL in the schema.
                        // Return a clean error if the existing active membership is malformed.
                        if ($oldPeriodEndsAt === null || $oldPeriodEndsAt === '') {
                            $pdo->rollBack();
                            ssaApiJsonResponse(400, [
                                'error'   => 'membership_period_invalid',
                                'message' => 'The current membership billing period could not be determined.',
                            ]);
                        }
                        if ($oldCancelDeadline === null || $oldCancelDeadline === '') {
                            $pdo->rollBack();
                            ssaApiJsonResponse(400, [
                                'error'   => 'membership_period_invalid',
                                'message' => 'The current membership billing period could not be determined.',
                            ]);
                        }

                        // Supersede old row first so the STORED GENERATED active_uid column
                        // becomes NULL and the unique constraint is released before the new
                        // active row is inserted.
                        if ($oldMembershipId !== null) {
                            $pdo->prepare(
                                'UPDATE ssa_user_memberships
                                 SET status = :superseded
                                 WHERE id = :id'
                            )->execute(['superseded' => 'superseded', 'id' => $oldMembershipId]);
                        }

                        // Insert new active membership.
                        // Confirmed NOT NULL / no-default columns that must be supplied:
                        //   started_at, current_period_starts_at, current_period_ends_at,
                        //   cancellation_notice_deadline_at, plan_name_snapshot.
                        // active_uid is STORED GENERATED – never set it manually.
                        // created_at / updated_at default to CURRENT_TIMESTAMP.
                        $pdo->prepare(
                            'INSERT INTO ssa_user_memberships
                                (uid, plan_id, status, started_at,
                                 current_period_starts_at,
                                 current_period_ends_at,
                                 cancellation_notice_deadline_at,
                                 source,
                                 price_snapshot_pence, currency_snapshot, plan_name_snapshot,
                                 notes)
                             VALUES
                                (:uid, :planId, :active, UTC_TIMESTAMP(),
                                 UTC_TIMESTAMP(),
                                 :periodEndsAt,
                                 :cancelDeadline,
                                 :source,
                                 :pricePence, :currency, :planName,
                                 :notes)'
                        )->execute([
                            'uid'           => $memberUid,
                            'planId'        => $targetPlanId,
                            'active'        => 'active',
                            'periodEndsAt'  => $oldPeriodEndsAt,
                            'cancelDeadline'=> $oldCancelDeadline,
                            'source'        => 'admin',
                            'pricePence'    => (int)$planRow['monthly_price_pence'],
                            'currency'      => (string)$planRow['currency'],
                            'planName'      => (string)$planRow['plan_name_snapshot'],
                            'notes'         => 'Admin-approved membership change (request #' . $requestId . ')',
                        ]);

                        $newMembershipId = (int)$pdo->lastInsertId();

                        // Back-fill the link from old row to new row.
                        if ($oldMembershipId !== null && $newMembershipId > 0) {
                            $pdo->prepare(
                                'UPDATE ssa_user_memberships
                                 SET superseded_by_membership_id = :newId
                                 WHERE id = :oldId'
                            )->execute(['newId' => $newMembershipId, 'oldId' => $oldMembershipId]);
                        }
                    }
                    break;

                case 'addon_request':
                    $addonId = isset($payload['addonId']) ? (int)$payload['addonId'] : null;

                    if ($addonId !== null && $addonId > 0) {
                        // Verify addon exists.
                        $addonCheck = $pdo->prepare('SELECT id FROM ssa_membership_addons WHERE id = :addonId LIMIT 1');
                        $addonCheck->execute(['addonId' => $addonId]);
                        if (!$addonCheck->fetch()) {
                            $pdo->rollBack();
                            ssaApiJsonResponse(400, [
                                'error'   => 'invalid_addon',
                                'message' => 'The requested add-on is no longer available.',
                            ]);
                        }

                        // Approve the addon by updating status to 'approved'.
                        $pdo->prepare(
                            'UPDATE ssa_user_membership_addons
                             SET status = :approved, resolved_at = UTC_TIMESTAMP()
                             WHERE uid = :uid AND addon_id = :addonId AND status = :requested'
                        )->execute([
                            'approved'  => 'approved',
                            'uid'       => $memberUid,
                            'addonId'   => $addonId,
                            'requested' => 'requested',
                        ]);
                    }
                    break;

                case 'membership_cancellation':
                    $pdo->prepare(
                        'UPDATE ssa_user_memberships
                         SET status                    = :cancelled,
                             cancelled_at              = UTC_TIMESTAMP(),
                             cancellation_effective_at = UTC_TIMESTAMP(),
                             updated_at                = UTC_TIMESTAMP()
                         WHERE uid = :uid AND status = :active'
                    )->execute([
                        'cancelled' => 'cancelled',
                        'uid'       => $memberUid,
                        'active'    => 'active',
                    ]);
                    break;

                case 'addon_cancellation':
                    $userAddonId  = isset($payload['userAddonId']) ? (int)$payload['userAddonId'] : null;
                    $effectiveDate = $payload['requestedEffectiveDate'] ?? null;
                    if ($userAddonId && $effectiveDate) {
                        // Verify addon still active and belongs to member
                        $addonCheck = $pdo->prepare('SELECT id, uid, status FROM ssa_user_membership_addons WHERE id = :id FOR UPDATE');
                        $addonCheck->execute(['id' => $userAddonId]);
                        $addonRow = $addonCheck->fetch();
                        if (!$addonRow || (int)$addonRow['uid'] !== $memberUid || $addonRow['status'] !== 'approved') {
                            $pdo->rollBack();
                            ssaApiJsonResponse(409, ['error' => 'addon_not_active', 'message' => 'The add-on is no longer active or does not belong to this member.']);
                        }
                        // Set scheduled cancellation date on the addon assignment
                        // Check if columns exist before updating
                        $addonColCheckStmt = $pdo->query(
                            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE()
                               AND TABLE_NAME = 'ssa_user_membership_addons'
                               AND COLUMN_NAME IN ('cancellation_effective_at', 'cancellation_request_id')"
                        );
                        $addonCols = array_map('strtolower', $addonColCheckStmt->fetchAll(PDO::FETCH_COLUMN));
                        if (in_array('cancellation_effective_at', $addonCols, true) || in_array('cancellation_request_id', $addonCols, true)) {
                            $setClauses = [];
                            $setParams  = ['id' => $userAddonId];
                            if (in_array('cancellation_effective_at', $addonCols, true)) {
                                $setClauses[] = 'cancellation_effective_at = :effectiveDate';
                                $setParams['effectiveDate'] = $effectiveDate;
                            }
                            if (in_array('cancellation_request_id', $addonCols, true)) {
                                $setClauses[] = 'cancellation_request_id = :requestId';
                                $setParams['requestId'] = $requestId;
                            }
                            $pdo->prepare(
                                'UPDATE ssa_user_membership_addons SET ' . implode(', ', $setClauses) . ' WHERE id = :id'
                            )->execute($setParams);
                        }
                    }
                    break;
            }
        }

        // Update the admin request row (both approve and decline).
        $pdo->prepare(
            'UPDATE ssa_admin_requests
             SET status          = :newStatus,
                 actioned_at     = UTC_TIMESTAMP(),
                 actioned_by_uid = :actionedByUid,
                 admin_comment   = :adminComment
             WHERE id = :id AND status = :pending'
        )->execute([
            'newStatus'      => $newStatus,
            'actionedByUid'  => $callerUid,
            'adminComment'   => $adminComment !== '' ? $adminComment : null,
            'id'             => $requestId,
            'pending'        => 'pending',
        ]);

        $pdo->commit();

    } catch (Throwable $txEx) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $txEx;
    }

    // ── Post-transaction: audit log (non-fatal) ────────────────────────────────

    try {
        ssaActionEnsureAuditLogTable($pdo);

        $pdo->prepare(
            'INSERT INTO ssa_member_request_audit_log
                (request_id, request_type, member_uid, member_name_snapshot,
                 previous_status, new_status, change_summary,
                 admin_uid, admin_name_snapshot, admin_comment, actioned_at, effective_date)
             VALUES
                (:requestId, :requestType, :memberUid, :memberNameSnapshot,
                 :previousStatus, :newStatus, :changeSummary,
                 :adminUid, :adminNameSnapshot, :adminComment, UTC_TIMESTAMP(),
                 CASE :isApproved WHEN 1 THEN UTC_TIMESTAMP() ELSE NULL END)'
        )->execute([
            'requestId'          => $requestId,
            'requestType'        => $requestType,
            'memberUid'          => $memberUid,
            'memberNameSnapshot' => $memberName !== '' ? $memberName : null,
            'previousStatus'     => 'pending',
            'newStatus'          => $newStatus,
            'changeSummary'      => $changeSummary,
            'adminUid'           => $callerUid,
            'adminNameSnapshot'  => $callerName !== '' ? $callerName : null,
            'adminComment'       => $adminComment !== '' ? $adminComment : null,
            'isApproved'         => $action === 'approve' ? 1 : 0,
        ]);
    } catch (Throwable $auditEx) {
        error_log(sprintf(
            'SSA admin/member-request-action.php: audit log failed [%s] %s in %s:%d',
            get_class($auditEx), $auditEx->getMessage(), $auditEx->getFile(), $auditEx->getLine()
        ));
    }

    // ── Post-transaction: in-app notification for member (non-fatal) ──────────

    try {
        ssaUserNotificationsEnsureTable($pdo);

        if ($requestType === 'addon_cancellation' && $action === 'approve') {
            $addonName    = $payload['addonName'] ?? 'Add-on';
            $effectiveDate = $payload['requestedEffectiveDate'] ?? '';
            $lastDay      = $payload['lastDayOfCurrentMonth'] ?? '';
            ssaUserNotificationsCreate(
                $pdo,
                $memberUid,
                'addon_cancellation_approved',
                'Add-on Cancellation Approved',
                'Your ' . $addonName . ' cancellation request has been approved. The add-on will remain active until ' . $lastDay . ' and will be cancelled from ' . $effectiveDate . '.',
                'membership',
                (string)$requestId,
                ['requestId' => $requestId, 'addonName' => $addonName]
            );
        } elseif ($requestType === 'addon_cancellation' && $action === 'decline') {
            $addonName = $payload['addonName'] ?? 'Add-on';
            ssaUserNotificationsCreate(
                $pdo,
                $memberUid,
                'addon_cancellation_rejected',
                'Add-on Cancellation Request Declined',
                'Your ' . $addonName . ' cancellation request was not approved. The add-on remains active.' . ($adminComment ? ' Reason: ' . $adminComment : ''),
                'membership',
                (string)$requestId,
                ['requestId' => $requestId, 'addonName' => $addonName]
            );
            // Clear scheduled cancellation if it was set (non-fatal)
            $declineUserAddonId = isset($payload['userAddonId']) ? (int)$payload['userAddonId'] : null;
            if ($declineUserAddonId) {
                try {
                    $clearColStmt = $pdo->query(
                        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME = 'ssa_user_membership_addons'
                           AND COLUMN_NAME IN ('cancellation_effective_at', 'cancellation_request_id')"
                    );
                    $clearCols = array_map('strtolower', $clearColStmt->fetchAll(PDO::FETCH_COLUMN));
                    if (!empty($clearCols)) {
                        $clearSet    = [];
                        if (in_array('cancellation_effective_at', $clearCols, true)) $clearSet[] = 'cancellation_effective_at = NULL';
                        if (in_array('cancellation_request_id',   $clearCols, true)) $clearSet[] = 'cancellation_request_id = NULL';
                        $pdo->prepare(
                            'UPDATE ssa_user_membership_addons SET ' . implode(', ', $clearSet) . ' WHERE id = :id'
                        )->execute(['id' => $declineUserAddonId]);
                    }
                } catch (Throwable $clearEx) {
                    error_log('SSA admin/member-request-action.php: clear addon cancel cols failed: ' . $clearEx->getMessage());
                }
            }
        } elseif ($action === 'approve') {
            $notifTitle   = 'Membership request approved';
            $notifMessage = 'Your request to ' . lcfirst($changeSummary) . ' has been approved. The new rate starts pro-rata from today.';
            ssaUserNotificationsCreate(
                $pdo,
                $memberUid,
                'membership_request_' . $newStatus,
                $notifTitle,
                $notifMessage,
                'membership',
                (string)$requestId
            );
        } else {
            $notifTitle   = 'Membership request declined';
            $notifMessage = 'Your request to ' . lcfirst($changeSummary) . ' was declined. Reason: ' . $adminComment;
            ssaUserNotificationsCreate(
                $pdo,
                $memberUid,
                'membership_request_' . $newStatus,
                $notifTitle,
                $notifMessage,
                'membership',
                (string)$requestId
            );
        }
    } catch (Throwable $notifEx) {
        error_log(sprintf(
            'SSA admin/member-request-action.php: member notification failed [%s] %s',
            get_class($notifEx), $notifEx->getMessage()
        ));
    }

    // ── Post-transaction: in-app notifications for other admins (non-fatal) ───

    try {
        $adminListStmt = $pdo->prepare(
            "SELECT DISTINCT u.uid
             FROM bs_users u
             INNER JOIN bs_users_meta m ON m.uid = u.uid AND m.`key` = 'ssa.user_type'
             WHERE m.value IN ('admin', 'club_owner')
               AND u.uid != :callerUid"
        );
        $adminListStmt->execute(['callerUid' => $callerUid]);
        $otherAdminUids = $adminListStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($otherAdminUids)) {
            if ($action === 'approve') {
                $adminNotifTitle   = 'Member request approved';
                $adminNotifMessage = $callerName . ' approved ' . $memberName . '\'s request: ' . $changeSummary . '.';
            } else {
                $adminNotifTitle   = 'Member request declined';
                $adminNotifMessage = $callerName . ' declined ' . $memberName . '\'s request: ' . $changeSummary . '.';
            }

            foreach ($otherAdminUids as $adminUid) {
                try {
                    ssaUserNotificationsCreate(
                        $pdo,
                        (int)$adminUid,
                        'admin_member_request_' . $newStatus,
                        $adminNotifTitle,
                        $adminNotifMessage,
                        'admin_member_requests',
                        (string)$requestId
                    );
                } catch (Throwable $adminNotifEx) {
                    error_log(sprintf(
                        'SSA admin/member-request-action.php: admin notification failed for uid=%d [%s] %s',
                        (int)$adminUid, get_class($adminNotifEx), $adminNotifEx->getMessage()
                    ));
                }
            }
        }
    } catch (Throwable $adminNotifListEx) {
        error_log(sprintf(
            'SSA admin/member-request-action.php: admin list notification failed [%s] %s',
            get_class($adminNotifListEx), $adminNotifListEx->getMessage()
        ));
    }

    // ── Post-transaction: push notifications (non-fatal) ──────────────────────

    try {
        $hasRevokedAt = ssaActionCheckPushTokenColumn($pdo);
        $pushWhere    = $hasRevokedAt ? 'AND revoked_at IS NULL' : '';

        $memberTokenStmt = $pdo->prepare(
            "SELECT device_token, environment FROM ssa_push_tokens WHERE uid = :uid {$pushWhere}"
        );
        $memberTokenStmt->execute(['uid' => $memberUid]);
        $memberTokens = $memberTokenStmt->fetchAll();

        if (!empty($memberTokens)) {
            if ($action === 'approve') {
                $pushTitle = 'Membership request approved';
                $pushBody  = 'Your request to ' . lcfirst($changeSummary) . ' has been approved.';
            } else {
                $pushTitle = 'Membership request declined';
                $pushBody  = 'Your request to ' . lcfirst($changeSummary) . ' was declined.';
            }
            ssaPushSendToTokenRows($memberTokens, $pushTitle, $pushBody, ['requestId' => $requestId]);
        }
    } catch (Throwable $memberPushEx) {
        error_log(sprintf(
            'SSA admin/member-request-action.php: member push failed [%s] %s',
            get_class($memberPushEx), $memberPushEx->getMessage()
        ));
    }

    try {
        $adminListStmt2 = $pdo->prepare(
            "SELECT DISTINCT u.uid
             FROM bs_users u
             INNER JOIN bs_users_meta m ON m.uid = u.uid AND m.`key` = 'ssa.user_type'
             WHERE m.value IN ('admin', 'club_owner')
               AND u.uid != :callerUid"
        );
        $adminListStmt2->execute(['callerUid' => $callerUid]);
        $otherAdminUids2 = $adminListStmt2->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($otherAdminUids2)) {
            $hasRevokedAt2 = ssaActionCheckPushTokenColumn($pdo);
            $pushWhere2    = $hasRevokedAt2 ? 'AND revoked_at IS NULL' : '';

            if ($action === 'approve') {
                $adminPushTitle = 'Member request approved';
                $adminPushBody  = $callerName . ' approved ' . $memberName . '\'s request: ' . $changeSummary . '.';
            } else {
                $adminPushTitle = 'Member request declined';
                $adminPushBody  = $callerName . ' declined ' . $memberName . '\'s request: ' . $changeSummary . '.';
            }

            foreach ($otherAdminUids2 as $adminUid) {
                try {
                    $adminTokenStmt = $pdo->prepare(
                        "SELECT device_token, environment FROM ssa_push_tokens WHERE uid = :uid {$pushWhere2}"
                    );
                    $adminTokenStmt->execute(['uid' => (int)$adminUid]);
                    $adminTokens = $adminTokenStmt->fetchAll();

                    if (!empty($adminTokens)) {
                        ssaPushSendToTokenRows($adminTokens, $adminPushTitle, $adminPushBody, ['requestId' => $requestId]);
                    }
                } catch (Throwable $adminPushEx) {
                    error_log(sprintf(
                        'SSA admin/member-request-action.php: admin push failed for uid=%d [%s] %s',
                        (int)$adminUid, get_class($adminPushEx), $adminPushEx->getMessage()
                    ));
                }
            }
        }
    } catch (Throwable $adminPushListEx) {
        error_log(sprintf(
            'SSA admin/member-request-action.php: admin push list failed [%s] %s',
            get_class($adminPushListEx), $adminPushListEx->getMessage()
        ));
    }

    ssaApiJsonResponse(200, [
        'status'    => 'ok',
        'requestId' => $requestId,
        'newStatus' => $newStatus,
    ]);

} catch (Throwable $e) {
    $sqlState = ($e instanceof \PDOException && is_array($e->errorInfo))
        ? ($e->errorInfo[0] ?? 'unknown')
        : 'n/a';
    error_log(sprintf(
        'SSA admin/member-request-action.php FAILED [%s] SQLSTATE=%s message=%s in %s:%d',
        get_class($e), $sqlState, $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ssaApiJsonResponse(500, ['error' => 'server_error', 'message' => 'Unable to process request action.']);
}
