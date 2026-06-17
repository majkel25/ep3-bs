<?php

declare(strict_types=1);

/**
 * GET  /api/ssa/v1/admin/user-detail.php?uid=123
 * POST /api/ssa/v1/admin/user-detail.php
 *
 * GET  – returns full member detail for admin panel.
 * POST – updates fullName, phone, and/or accountStatus.
 *        Email changes are rejected (email_change_not_supported) because
 *        the Auth0 identity email is managed in the Auth0 dashboard and
 *        no Auth0 Management API is configured on this backend.
 *
 * Auth: Admin or Club Owner only. Member/Coach receive 403.
 *
 * GET response shape:
 *   uid, fullName, email, phone, userType, accountStatus, accountStatusLabel,
 *   scoreboardMemberId, scoreboardMemberName, scoreboardMemberRef,
 *   profilePhotoUrl, hasProfilePhoto,
 *   profileCompleteness { hasPhone, hasEmail, hasScoreboardMapping, hasProfilePhoto },
 *   emailChangeSupported (always false)
 *
 * POST body (JSON):
 *   uid           int     required
 *   fullName      string  optional
 *   phone         string  optional (empty string = clear phone)
 *   accountStatus string  optional – 'enabled' | 'disabled'
 *   email         string  optional – always rejected
 *
 * Scoreboard member name is fetched from the scoreboard internal API when
 * SSA_SCOREBOARD_INTERNAL_URL and SSA_INTERNAL_API_KEY env vars are set.
 * If they are not set (or the call fails), scoreboardMemberName is null.
 */

require_once __DIR__ . '/../_auth0.php';
require_once __DIR__ . '/../_db.php';
require_once __DIR__ . '/../_mail.php';
require_once __DIR__ . '/../_user_notifications.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET' && $method !== 'POST') {
    ssaApiJsonResponse(405, ['error' => 'method_not_allowed', 'message' => 'Only GET and POST are allowed.']);
}

$claims   = ssaApiRequireAuth0Claims();
$auth0Sub = trim((string)($claims['sub'] ?? ''));

if ($auth0Sub === '') {
    ssaApiJsonResponse(401, ['error' => 'missing_auth0_subject', 'message' => 'Auth0 token missing subject.']);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Fetch member info from scoreboard internal API.
 * Returns null silently if not configured or call fails.
 */
function ssaAdminFetchScoreboardMember(int $scoreboardMemberId): ?array
{
    $internalUrl = (string)getenv('SSA_SCOREBOARD_INTERNAL_URL');
    $apiKey      = (string)getenv('SSA_INTERNAL_API_KEY');

    if ($internalUrl === '' || $apiKey === '') {
        return null;
    }

    $url = rtrim($internalUrl, '/') . '/api/internal/members/' . $scoreboardMemberId;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['X-Internal-Api-Key: ' . $apiKey, 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_TIMEOUT_MS     => 5000,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_NOSIGNAL       => 1, // required for PHP-FPM
    ]);

    $body    = curl_exec($ch);
    $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err     = curl_error($ch);
    curl_close($ch);

    if ($err !== '' || $code !== 200 || $body === false) {
        return null;
    }

    $decoded = json_decode((string)$body, true);
    return is_array($decoded) ? $decoded : null;
}

function ssaAdminEnsureAuditLogTable(PDO $pdo): void
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
          PRIMARY KEY (id),
          KEY idx_audit_request_id (request_id),
          KEY idx_audit_member_uid (member_uid),
          KEY idx_audit_admin_uid (admin_uid),
          KEY idx_audit_actioned_at (actioned_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ssaAdminFetchCurrentMembershipPlan(PDO $pdo, int $uid): ?array
{
    $stmt = $pdo->prepare(
        'SELECT p.plan_key, p.name, p.display_name, p.monthly_price_pence, p.currency
         FROM ssa_user_memberships m
         INNER JOIN ssa_membership_plans p ON p.id = m.plan_id
         WHERE m.uid = :uid AND m.status = :active
         ORDER BY m.started_at DESC, m.id DESC
         LIMIT 1'
    );
    $stmt->execute(['uid' => $uid, 'active' => 'active']);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    return [
        'planKey'     => (string)$row['plan_key'],
        'planName'    => (string)$row['name'],
        'displayName' => $row['display_name'] !== null ? (string)$row['display_name'] : (string)$row['name'],
        'pricePence'  => (int)$row['monthly_price_pence'],
        'currency'    => (string)$row['currency'],
    ];
}

function ssaAdminFetchAvailableMembershipPlans(PDO $pdo, ?string $currentPlanKey): array
{
    // Check if sort_order column exists.
    $colStmt = $pdo->query(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'ssa_membership_plans'
           AND COLUMN_NAME = 'sort_order'"
    );
    $hasSortOrder = $colStmt->fetchColumn() !== false;
    $orderBy      = $hasSortOrder ? 'ORDER BY sort_order ASC, id ASC' : 'ORDER BY id ASC';

    $stmt = $pdo->query(
        "SELECT plan_key, name, display_name, monthly_price_pence, currency
         FROM ssa_membership_plans
         WHERE is_active = 1 AND is_public = 1
         {$orderBy}"
    );
    $rows  = $stmt->fetchAll();
    $plans = [];

    foreach ($rows as $row) {
        $planKey   = (string)$row['plan_key'];
        $plans[] = [
            'planKey'     => $planKey,
            'name'        => (string)$row['name'],
            'displayName' => $row['display_name'] !== null ? (string)$row['display_name'] : (string)$row['name'],
            'pricePence'  => (int)$row['monthly_price_pence'],
            'currency'    => (string)$row['currency'],
            'isCurrent'   => $currentPlanKey !== null && $planKey === $currentPlanKey,
        ];
    }

    return $plans;
}

function ssaAdminBuildUserDetail(PDO $pdo, array $userRow, int $callerUid): array
{
    $uid      = (int)$userRow['uid'];
    $fullName = isset($userRow['alias']) && $userRow['alias'] !== '' ? (string)$userRow['alias'] : null;
    $email    = isset($userRow['email']) && $userRow['email'] !== '' ? (string)$userRow['email'] : null;
    $status   = isset($userRow['status']) ? (string)$userRow['status'] : 'enabled';

    $statusLabelMap = [
        'enabled'  => 'Active',
        'disabled' => 'Disabled',
    ];
    $statusLabel = $statusLabelMap[$status] ?? ucfirst($status);

    $phone    = ssaApiGetUserMetaValue($pdo, $uid, 'phone');
    $phone    = ($phone !== null && trim($phone) !== '') ? trim($phone) : null;
    $userType = ssaApiGetUserMetaValue($pdo, $uid, 'ssa.user_type') ?? 'member';

    $rawSbId            = ssaApiGetUserMetaValue($pdo, $uid, 'scoreboard.member_id');
    $scoreboardMemberId = ($rawSbId !== null && ctype_digit($rawSbId) && (int)$rawSbId > 0)
        ? (int)$rawSbId
        : null;

    $scoreboardBase      = ssaApiGetScoreboardBaseUrl();
    $profilePhotoUrl     = null;
    $scoreboardMemberName = null;
    $scoreboardMemberRef  = null;

    if ($scoreboardMemberId !== null) {
        if ($scoreboardBase !== null) {
            $profilePhotoUrl = $scoreboardBase . '/api/player-photos/' . $scoreboardMemberId . '/processed';
        }
        $sbMember = ssaAdminFetchScoreboardMember($scoreboardMemberId);
        if ($sbMember !== null) {
            $scoreboardMemberName = $sbMember['name'] ?? null;
            $scoreboardMemberRef  = $sbMember['accountingRef'] ?? null;
        }
    }

    $hasProfilePhoto = $profilePhotoUrl !== null;

    $currentMembershipPlan    = ssaAdminFetchCurrentMembershipPlan($pdo, $uid);
    $currentPlanKey           = $currentMembershipPlan !== null ? $currentMembershipPlan['planKey'] : null;
    $availableMembershipPlans = ssaAdminFetchAvailableMembershipPlans($pdo, $currentPlanKey);

    return [
        'uid'                      => $uid,
        'fullName'                 => $fullName,
        'email'                    => $email,
        'phone'                    => $phone,
        'userType'                 => $userType,
        'accountStatus'            => $status,
        'accountStatusLabel'       => $statusLabel,
        'scoreboardMemberId'       => $scoreboardMemberId,
        'scoreboardMemberName'     => $scoreboardMemberName,
        'scoreboardMemberRef'      => $scoreboardMemberRef,
        'profilePhotoUrl'          => $profilePhotoUrl,
        'hasProfilePhoto'          => $hasProfilePhoto,
        'emailChangeSupported'     => false,
        'profileCompleteness'      => [
            'hasPhone'             => $phone !== null,
            'hasEmail'             => $email !== null,
            'hasScoreboardMapping' => $scoreboardMemberId !== null,
            'hasProfilePhoto'      => $hasProfilePhoto,
        ],
        'currentMembershipPlan'    => $currentMembershipPlan,
        'availableMembershipPlans' => $availableMembershipPlans,
    ];
}

// ── Main ──────────────────────────────────────────────────────────────────────

try {
    $pdo = ssaApiCreatePdo();

    // Resolve caller uid and role.
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

    // ── GET ───────────────────────────────────────────────────────────────────

    if ($method === 'GET') {
        $targetUid = isset($_GET['uid']) && is_numeric($_GET['uid']) ? (int)$_GET['uid'] : null;

        if ($targetUid === null || $targetUid <= 0) {
            ssaApiJsonResponse(400, ['error' => 'missing_uid', 'message' => 'uid query parameter is required.']);
        }

        $userStmt = $pdo->prepare(
            'SELECT uid, alias, email, status FROM bs_users WHERE uid = :uid LIMIT 1'
        );
        $userStmt->execute(['uid' => $targetUid]);
        $userRow = $userStmt->fetch();

        if (!is_array($userRow)) {
            ssaApiJsonResponse(404, ['error' => 'user_not_found', 'message' => 'User not found.']);
        }

        $detail = ssaAdminBuildUserDetail($pdo, $userRow, $callerUid);

        ssaApiJsonResponse(200, array_merge(['status' => 'ok'], $detail));
    }

    // ── POST (update) ─────────────────────────────────────────────────────────

    $rawBody = (string)file_get_contents('php://input');
    $body    = [];
    if ($rawBody !== '') {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $body = $decoded;
        }
    }

    // Always reject email changes.
    if (array_key_exists('email', $body)) {
        ssaApiJsonResponse(400, [
            'error'   => 'email_change_not_supported',
            'message' => 'Email addresses cannot be changed here. Use the Auth0 dashboard to update the Auth0 login email, and the booking admin to update the booking email.',
        ]);
    }

    $targetUid = isset($body['uid']) && is_numeric($body['uid']) ? (int)$body['uid'] : null;

    if ($targetUid === null || $targetUid <= 0) {
        ssaApiJsonResponse(400, ['error' => 'missing_uid', 'message' => 'uid is required.']);
    }

    // Load current user row.
    $userStmt = $pdo->prepare('SELECT uid, alias, email, status FROM bs_users WHERE uid = :uid LIMIT 1');
    $userStmt->execute(['uid' => $targetUid]);
    $userRow = $userStmt->fetch();

    if (!is_array($userRow)) {
        ssaApiJsonResponse(404, ['error' => 'user_not_found', 'message' => 'User not found.']);
    }

    $currentAlias  = (string)($userRow['alias'] ?? '');
    $currentEmail  = (string)($userRow['email'] ?? '');
    $currentStatus = (string)($userRow['status'] ?? 'enabled');

    $changedFields = [];

    // Validate and stage fullName.
    $newFullName = null;
    if (array_key_exists('fullName', $body)) {
        $raw = trim((string)($body['fullName'] ?? ''));
        if ($raw === '') {
            ssaApiJsonResponse(400, ['error' => 'invalid_full_name', 'message' => 'Full name cannot be empty.']);
        }
        if (strlen($raw) < 2 || strlen($raw) > 64) {
            ssaApiJsonResponse(400, ['error' => 'invalid_full_name', 'message' => 'Full name must be between 2 and 64 characters.']);
        }
        if ($raw !== $currentAlias) {
            $newFullName     = $raw;
            $changedFields[] = 'fullName';
        }
    }

    // Validate and stage phone.
    $newPhone = null;
    if (array_key_exists('phone', $body)) {
        $raw = trim((string)($body['phone'] ?? ''));
        if ($raw !== '') {
            if (strlen($raw) < 3 || strlen($raw) > 30) {
                ssaApiJsonResponse(400, ['error' => 'invalid_phone', 'message' => 'Phone must be between 3 and 30 characters.']);
            }
            if (!preg_match('/^([ +\/\(\)\-0-9])+$/u', $raw)) {
                ssaApiJsonResponse(400, ['error' => 'invalid_phone', 'message' => 'Phone contains invalid characters.']);
            }
        }
        $currentPhone = ssaApiGetUserMetaValue($pdo, $targetUid, 'phone') ?? '';
        if ($raw !== $currentPhone) {
            $newPhone        = $raw;
            $changedFields[] = 'phone';
        }
    }

    // Validate and stage accountStatus.
    $newStatus = null;
    if (array_key_exists('accountStatus', $body)) {
        $raw = trim((string)($body['accountStatus'] ?? ''));
        if (!in_array($raw, ['enabled', 'disabled'], true)) {
            ssaApiJsonResponse(400, ['error' => 'invalid_status', 'message' => 'accountStatus must be "enabled" or "disabled".']);
        }
        // Guard: cannot disable yourself.
        if ($targetUid === $callerUid && $raw === 'disabled') {
            ssaApiJsonResponse(400, ['error' => 'cannot_disable_self', 'message' => 'You cannot disable your own account.']);
        }
        if ($raw !== $currentStatus) {
            $newStatus       = $raw;
            $changedFields[] = 'accountStatus';
        }
    }

    // Handle membershipPlanKey change (admin direct change).
    $newMembershipPlanKey = null;
    $membershipChangeMeta = null; // holds ['oldPlan', 'newPlan', 'newPlanId', 'newPlanName']

    if (array_key_exists('membershipPlanKey', $body)) {
        $rawPlanKey = trim((string)($body['membershipPlanKey'] ?? ''));

        if ($rawPlanKey === '') {
            ssaApiJsonResponse(400, ['error' => 'invalid_membership_plan_key', 'message' => 'membershipPlanKey cannot be empty.']);
        }

        // Validate plan exists and is active.
        $planCheckStmt = $pdo->prepare(
            'SELECT id, plan_key, name, display_name, monthly_price_pence, currency
             FROM ssa_membership_plans
             WHERE plan_key = :planKey AND is_active = 1
             LIMIT 1'
        );
        $planCheckStmt->execute(['planKey' => $rawPlanKey]);
        $newPlanRow = $planCheckStmt->fetch();

        if (!$newPlanRow) {
            ssaApiJsonResponse(400, [
                'error'   => 'membership_plan_not_found',
                'message' => 'The specified membership plan does not exist or is inactive.',
            ]);
        }

        // Check if member already has this plan active.
        $curPlanStmt = $pdo->prepare(
            'SELECT m.id AS membership_id, p.plan_key, p.name AS plan_name, p.display_name
             FROM ssa_user_memberships m
             INNER JOIN ssa_membership_plans p ON p.id = m.plan_id
             WHERE m.uid = :uid AND m.status = :active
             ORDER BY m.started_at DESC, m.id DESC
             LIMIT 1'
        );
        $curPlanStmt->execute(['uid' => $targetUid, 'active' => 'active']);
        $curPlanRow = $curPlanStmt->fetch();

        $currentlyOnThisPlan = $curPlanRow && (string)$curPlanRow['plan_key'] === $rawPlanKey;

        if ($currentlyOnThisPlan) {
            $detail = ssaAdminBuildUserDetail($pdo, $userRow, $callerUid);
            ssaApiJsonResponse(200, array_merge(['status' => 'ok', 'message' => 'Already on that plan.'], $detail));
        }

        $newMembershipPlanKey = $rawPlanKey;
        $membershipChangeMeta = [
            'oldPlanName' => $curPlanRow
                ? (string)($curPlanRow['display_name'] ?? $curPlanRow['plan_name'] ?? 'Unknown')
                : 'None',
            'newPlanId'   => (int)$newPlanRow['id'],
            'newPlanName' => (string)($newPlanRow['display_name'] ?? $newPlanRow['name']),
            'newPricePence' => (int)$newPlanRow['monthly_price_pence'],
            'newCurrency'   => (string)$newPlanRow['currency'],
        ];
        $changedFields[] = 'membershipPlanKey';
    }

    if (empty($changedFields)) {
        $detail = ssaAdminBuildUserDetail($pdo, $userRow, $callerUid);
        ssaApiJsonResponse(200, array_merge(['status' => 'ok', 'message' => 'No changes detected.'], $detail));
    }

    // Apply fullName.
    if ($newFullName !== null) {
        $pdo->prepare('UPDATE bs_users SET alias = :alias WHERE uid = :uid')
            ->execute(['alias' => $newFullName, 'uid' => $targetUid]);

        // Keep ssa_auth0_user_links.linked_alias in sync.
        $pdo->prepare(
            'UPDATE ssa_auth0_user_links SET linked_alias = :alias, updated_at = NOW()
             WHERE uid = :uid AND revoked_at IS NULL'
        )->execute(['alias' => $newFullName, 'uid' => $targetUid]);
    }

    // Apply phone.
    if ($newPhone !== null) {
        $existingRow = ssaApiGetUserMetaValue($pdo, $targetUid, 'phone');
        if ($existingRow !== null) {
            if ($newPhone === '') {
                $pdo->prepare("DELETE FROM bs_users_meta WHERE uid = :uid AND `key` = 'phone'")
                    ->execute(['uid' => $targetUid]);
            } else {
                $pdo->prepare("UPDATE bs_users_meta SET value = :val WHERE uid = :uid AND `key` = 'phone'")
                    ->execute(['val' => $newPhone, 'uid' => $targetUid]);
            }
        } elseif ($newPhone !== '') {
            $pdo->prepare("INSERT INTO bs_users_meta (uid, `key`, value) VALUES (:uid, 'phone', :val)")
                ->execute(['uid' => $targetUid, 'val' => $newPhone]);
        }
    }

    // Apply accountStatus.
    if ($newStatus !== null) {
        $pdo->prepare('UPDATE bs_users SET status = :status WHERE uid = :uid')
            ->execute(['status' => $newStatus, 'uid' => $targetUid]);
        $userRow['status'] = $newStatus;
    }

    // Update userRow for response.
    if ($newFullName !== null) {
        $userRow['alias'] = $newFullName;
    }

    // Apply membershipPlanKey (admin direct change) in a transaction.
    if ($newMembershipPlanKey !== null && $membershipChangeMeta !== null) {
        $pdo->beginTransaction();
        try {
            // Cancel any current active membership.
            // active_uid is STORED GENERATED — setting status clears it automatically.
            $pdo->prepare(
                'UPDATE ssa_user_memberships
                 SET status = :cancelled, cancelled_at = UTC_TIMESTAMP()
                 WHERE uid = :uid AND status = :active'
            )->execute(['cancelled' => 'cancelled', 'uid' => $targetUid, 'active' => 'active']);

            // Insert new membership.
            // current_period_starts_at / current_period_ends_at / cancellation_notice_deadline_at
            // are NOT NULL with no schema default and must always be supplied.
            // For a direct admin plan change the new billing period starts today; deadline is
            // one day before the period ends (mirrors existing active-row convention).
            $pdo->prepare(
                'INSERT INTO ssa_user_memberships
                    (uid, plan_id, status, started_at,
                     current_period_starts_at,
                     current_period_ends_at,
                     cancellation_notice_deadline_at,
                     price_snapshot_pence, currency_snapshot, plan_name_snapshot)
                 SELECT :uid, id, :active, UTC_TIMESTAMP(),
                        UTC_TIMESTAMP(),
                        DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 MONTH),
                        DATE_ADD(DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 MONTH), INTERVAL -1 DAY),
                        monthly_price_pence, currency, COALESCE(display_name, name)
                 FROM ssa_membership_plans
                 WHERE id = :planId'
            )->execute([
                'uid'    => $targetUid,
                'active' => 'active',
                'planId' => $membershipChangeMeta['newPlanId'],
            ]);

            $pdo->commit();
        } catch (Throwable $memTxEx) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $memTxEx;
        }

        // Audit log (non-fatal).
        try {
            ssaAdminEnsureAuditLogTable($pdo);

            $callerNameStmt = $pdo->prepare('SELECT alias FROM bs_users WHERE uid = :uid LIMIT 1');
            $callerNameStmt->execute(['uid' => $callerUid]);
            $callerRow   = $callerNameStmt->fetch();
            $callerAlias = $callerRow ? (string)($callerRow['alias'] ?? '') : '';

            $memberAlias = isset($userRow['alias']) ? (string)$userRow['alias'] : '';

            $changeSummary = 'Admin changed membership from '
                . $membershipChangeMeta['oldPlanName']
                . ' to ' . $membershipChangeMeta['newPlanName'];

            $pdo->prepare(
                'INSERT INTO ssa_member_request_audit_log
                    (request_id, request_type, member_uid, member_name_snapshot,
                     previous_status, new_status, change_summary,
                     admin_uid, admin_name_snapshot, admin_comment, actioned_at, effective_date)
                 VALUES
                    (0, :requestType, :memberUid, :memberName,
                     :previousStatus, :newStatus, :changeSummary,
                     :adminUid, :adminName, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            )->execute([
                'requestType'    => 'direct_admin_change',
                'memberUid'      => $targetUid,
                'memberName'     => $memberAlias !== '' ? $memberAlias : null,
                'previousStatus' => 'active',
                'newStatus'      => 'active',
                'changeSummary'  => $changeSummary,
                'adminUid'       => $callerUid,
                'adminName'      => $callerAlias !== '' ? $callerAlias : null,
            ]);
        } catch (Throwable $auditEx) {
            error_log('SSA admin/user-detail.php: membership audit log failed: ' . $auditEx->getMessage());
        }

        // In-app notification for member (non-fatal).
        try {
            ssaUserNotificationsEnsureTable($pdo);

            ssaUserNotificationsCreate(
                $pdo,
                $targetUid,
                'membership_updated_by_admin',
                'Membership updated',
                'Your membership has been changed to '
                    . $membershipChangeMeta['newPlanName']
                    . ' by an administrator. The new rate starts pro-rata from today.',
                'membership',
                null
            );
        } catch (Throwable $memNotifEx) {
            error_log('SSA admin/user-detail.php: member membership notification failed: ' . $memNotifEx->getMessage());
        }

        // In-app notifications for other admins (non-fatal).
        try {
            $callerNameStmt2 = $pdo->prepare('SELECT alias FROM bs_users WHERE uid = :uid LIMIT 1');
            $callerNameStmt2->execute(['uid' => $callerUid]);
            $callerRow2   = $callerNameStmt2->fetch();
            $callerAlias2 = $callerRow2 ? (string)($callerRow2['alias'] ?? '') : '';

            $memberAlias2 = isset($userRow['alias']) ? (string)$userRow['alias'] : 'Member';

            $adminListStmt = $pdo->prepare(
                "SELECT DISTINCT u.uid
                 FROM bs_users u
                 INNER JOIN bs_users_meta m ON m.uid = u.uid AND m.`key` = 'ssa.user_type'
                 WHERE m.value IN ('admin', 'club_owner')
                   AND u.uid != :callerUid"
            );
            $adminListStmt->execute(['callerUid' => $callerUid]);
            $otherAdminUids = $adminListStmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($otherAdminUids as $adminUid) {
                try {
                    ssaUserNotificationsCreate(
                        $pdo,
                        (int)$adminUid,
                        'admin_membership_updated',
                        'Membership updated by admin',
                        $callerAlias2 . ' changed ' . $memberAlias2 . '\'s membership to '
                            . $membershipChangeMeta['newPlanName'] . '.',
                        'admin_member_requests',
                        null
                    );
                } catch (Throwable $adminMemNotifEx) {
                    error_log('SSA admin/user-detail.php: other admin membership notification failed for uid=' . $adminUid . ': ' . $adminMemNotifEx->getMessage());
                }
            }
        } catch (Throwable $adminMemListEx) {
            error_log('SSA admin/user-detail.php: admin list membership notification failed: ' . $adminMemListEx->getMessage());
        }
    }

    // Send notification email (non-fatal).
    if ($currentEmail !== '' && !empty($changedFields)) {
        try {
            $recipientName = $newFullName ?? ($currentAlias !== '' ? $currentAlias : 'Member');
            $lines         = ["Your Surrey Snooker Academy account details were updated by a club administrator:\n"];

            if (in_array('fullName', $changedFields, true)) {
                $lines[] = '  Full name: ' . $newFullName;
            }
            if (in_array('phone', $changedFields, true)) {
                $lines[] = '  Phone: ' . ($newPhone !== '' ? $newPhone : '(removed)');
            }
            if (in_array('accountStatus', $changedFields, true)) {
                $statusLabelMap = ['enabled' => 'Active', 'disabled' => 'Disabled'];
                $lines[] = '  Account status: ' . ($statusLabelMap[$newStatus] ?? $newStatus);
            }
            if (in_array('membershipPlanKey', $changedFields, true) && $membershipChangeMeta !== null) {
                $lines[] = '  Membership plan: ' . $membershipChangeMeta['newPlanName'];
            }

            $lines[] = "\nIf you did not authorise this change, please contact Surrey Snooker Academy.";

            ssaApiSendMail(
                $currentEmail,
                $recipientName,
                'Surrey Snooker Academy account updated by administrator',
                implode("\n", $lines)
            );
        } catch (Throwable $mailEx) {
            error_log('SSA admin/user-detail POST: notification email failed: ' . $mailEx->getMessage());
        }
    }

    $detail = ssaAdminBuildUserDetail($pdo, $userRow, $callerUid);
    ssaApiJsonResponse(200, array_merge(['status' => 'ok'], $detail));
} catch (Throwable $e) {
    $sqlState = ($e instanceof \PDOException && is_array($e->errorInfo))
        ? ($e->errorInfo[0] ?? 'unknown')
        : 'n/a';
    error_log(sprintf('SSA admin/user-detail.php FAILED [%s] SQLSTATE=%s message=%s',
        get_class($e), $sqlState, $e->getMessage()));
    ssaApiJsonResponse(500, ['error' => 'server_error', 'message' => 'Unable to process request.']);
}
