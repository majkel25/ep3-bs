<?php

declare(strict_types=1);

/**
 * POST /api/ssa/v1/admin/member-package-assign.php
 *
 * Directly assigns a membership package to a member as an administrative action.
 * Does not require member approval.  Preserves full membership history.
 *
 * Body (JSON):
 *   memberUid                   int      required
 *   packageId                   int      required
 *   expectedCurrentMembershipId int|null optional – 409 if actual differs
 *
 * Auth: admin or club_owner only.
 *
 * Response: { status, memberUid, newMembershipId, newMembershipPlanKey, packageId, actionType }
 *
 * Error codes:
 *   400  missing/invalid fields
 *   403  not admin
 *   404  member or package not found
 *   409  same package already assigned, or membership changed (stale expectedId)
 *   422  package is inactive
 *   500  server error
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

// ── Period-date helper ──────────────────────────────────────────────────────

function ssaAssignCalcFreshPeriodDates(string $billingType, ?string $availableUntil): array
{
    $tz      = new DateTimeZone('Europe/London');
    $utcZone = new DateTimeZone('UTC');
    $nowLon  = new DateTimeImmutable('now', $tz);

    if (in_array($billingType, ['upfront', 'fixed_term', 'one_time'], true) && $availableUntil) {
        try {
            $endLon = new DateTimeImmutable($availableUntil . ' 23:59:59', $tz);
        } catch (Throwable $ignored) {
            $endLon = $nowLon->modify('+1 year')->setTime(23, 59, 59);
        }
    } elseif (in_array($billingType, ['upfront', 'fixed_term', 'one_time'], true)) {
        // No configured end: end of current calendar year; if already past, +1 year
        $endLon = new DateTimeImmutable($nowLon->format('Y') . '-12-31 23:59:59', $tz);
        if ($endLon <= $nowLon) {
            $endLon = $endLon->modify('+1 year');
        }
    } else {
        // Monthly: end of next calendar month in London time
        $firstOfThisMonth = new DateTimeImmutable($nowLon->format('Y-m') . '-01 00:00:00', $tz);
        $firstOfNextMonth = $firstOfThisMonth->modify('+1 month');
        $endLon           = $firstOfNextMonth->modify('last day of this month')->setTime(23, 59, 59);
    }

    $periodEndsAt = $endLon->setTimezone($utcZone)->format('Y-m-d H:i:s');
    return [
        'current_period_ends_at'          => $periodEndsAt,
        'cancellation_notice_deadline_at' => $periodEndsAt,
    ];
}

// ── Main ────────────────────────────────────────────────────────────────────

try {
    $pdo = ssaApiCreatePdo();

    // ── Auth ─────────────────────────────────────────────────────────────────
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
        ssaApiJsonResponse(403, [
            'error'   => 'forbidden',
            'message' => 'Administrator access is required to assign packages.',
        ]);
    }

    // ── Parse body ───────────────────────────────────────────────────────────
    $rawBody = (string)file_get_contents('php://input');
    if (trim($rawBody) === '') {
        ssaApiJsonResponse(400, ['error' => 'empty_body', 'message' => 'Request body must contain JSON.']);
    }
    $body = json_decode($rawBody, true);
    if (!is_array($body)) {
        ssaApiJsonResponse(400, ['error' => 'invalid_json', 'message' => 'Request body must be valid JSON.']);
    }

    $memberUid = isset($body['memberUid']) && is_numeric($body['memberUid']) ? (int)$body['memberUid'] : 0;
    $packageId = isset($body['packageId']) && is_numeric($body['packageId']) ? (int)$body['packageId'] : 0;
    $expectedCurrentMembershipId = null;
    if (array_key_exists('expectedCurrentMembershipId', $body)) {
        $raw = $body['expectedCurrentMembershipId'];
        $expectedCurrentMembershipId = ($raw !== null && is_numeric($raw)) ? (int)$raw : null;
    }

    if ($memberUid <= 0) {
        ssaApiJsonResponse(400, ['error' => 'missing_member_uid', 'message' => 'memberUid is required.']);
    }
    if ($packageId <= 0) {
        ssaApiJsonResponse(400, ['error' => 'missing_package_id', 'message' => 'packageId is required.']);
    }

    // ── Load target package ──────────────────────────────────────────────────
    $planColStmt    = $pdo->query(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ssa_membership_plans'"
    );
    $planCols       = array_map('strtolower', $planColStmt->fetchAll(PDO::FETCH_COLUMN));
    $hasBillingType = in_array('billing_type', $planCols, true);
    $hasAvailUntil  = in_array('available_until', $planCols, true);
    $planExtra      = ($hasBillingType ? ', billing_type' : '') . ($hasAvailUntil ? ', available_until' : '');

    $planStmt = $pdo->prepare(
        "SELECT id, plan_key, name, display_name, monthly_price_pence, currency, is_active, is_public{$planExtra}
         FROM ssa_membership_plans WHERE id = :id LIMIT 1"
    );
    $planStmt->execute(['id' => $packageId]);
    $planRow = $planStmt->fetch(PDO::FETCH_ASSOC);
    if (!$planRow) {
        ssaApiJsonResponse(404, ['error' => 'package_not_found', 'message' => 'Package not found.']);
    }
    if (!(bool)(int)($planRow['is_active'] ?? 0)) {
        ssaApiJsonResponse(422, [
            'error'   => 'package_inactive',
            'message' => 'This package is inactive and cannot be assigned to new members.',
        ]);
    }

    // ── Load member ──────────────────────────────────────────────────────────
    $memberStmt = $pdo->prepare('SELECT uid, alias, email FROM bs_users WHERE uid = :uid LIMIT 1');
    $memberStmt->execute(['uid' => $memberUid]);
    $memberRow = $memberStmt->fetch(PDO::FETCH_ASSOC);
    if (!$memberRow) {
        ssaApiJsonResponse(404, ['error' => 'member_not_found', 'message' => 'Member not found.']);
    }
    $memberName = (string)($memberRow['alias'] ?? '');

    // ── Caller name (for audit) ──────────────────────────────────────────────
    $callerNameStmt = $pdo->prepare('SELECT alias FROM bs_users WHERE uid = :uid LIMIT 1');
    $callerNameStmt->execute(['uid' => $callerUid]);
    $callerName = (string)($callerNameStmt->fetchColumn() ?: '');

    // ── Inspect ssa_user_memberships columns ─────────────────────────────────
    $memColStmt  = $pdo->query(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ssa_user_memberships'"
    );
    $memCols            = array_map('strtolower', $memColStmt->fetchAll(PDO::FETCH_COLUMN));
    $hasSupersededBy    = in_array('superseded_by_membership_id', $memCols, true);
    $hasCancelEffective = in_array('cancellation_effective_at', $memCols, true);
    $hasMemNotes        = in_array('notes', $memCols, true);
    $hasMemSource       = in_array('source', $memCols, true);
    $hasMemUpdatedAt    = in_array('updated_at', $memCols, true);

    // ── Transaction ──────────────────────────────────────────────────────────
    $pdo->beginTransaction();

    try {
        // Lock the member's current active membership row
        $lockStmt = $pdo->prepare(
            "SELECT id, plan_id, current_period_ends_at, cancellation_notice_deadline_at
             FROM ssa_user_memberships
             WHERE uid = :uid AND status = 'active'
             ORDER BY started_at DESC, id DESC
             LIMIT 1
             FOR UPDATE"
        );
        $lockStmt->execute(['uid' => $memberUid]);
        $currentMembership = $lockStmt->fetch(PDO::FETCH_ASSOC);
        $oldMembershipId   = $currentMembership ? (int)$currentMembership['id']      : null;
        $currentPlanId     = $currentMembership ? (int)$currentMembership['plan_id'] : null;

        // ── Optimistic concurrency ────────────────────────────────────────────
        // expectedCurrentMembershipId:
        //   int  → client expected a specific active membership
        //   null → client expected no active membership
        if ($expectedCurrentMembershipId !== null) {
            if ($oldMembershipId !== $expectedCurrentMembershipId) {
                $pdo->rollBack();
                ssaApiJsonResponse(409, [
                    'error'   => 'membership_changed',
                    'message' => "This member's membership changed before the assignment was completed. Reload the member and try again.",
                ]);
            }
        } else {
            if ($oldMembershipId !== null) {
                $pdo->rollBack();
                ssaApiJsonResponse(409, [
                    'error'   => 'membership_changed',
                    'message' => "This member's membership changed before the assignment was completed. Reload the member and try again.",
                ]);
            }
        }

        // ── Duplicate package check ───────────────────────────────────────────
        if ($currentPlanId === $packageId) {
            $pdo->rollBack();
            ssaApiJsonResponse(409, [
                'error'   => 'already_assigned',
                'message' => 'This member is already assigned to this package.',
            ]);
        }

        // ── Period dates ──────────────────────────────────────────────────────
        if ($currentMembership) {
            // Inherit the existing billing period (pro-rata plan change)
            $periodEndsAt   = $currentMembership['current_period_ends_at'];
            $cancelDeadline = $currentMembership['cancellation_notice_deadline_at'];
            if (empty($periodEndsAt) || empty($cancelDeadline)) {
                $pdo->rollBack();
                ssaApiJsonResponse(400, [
                    'error'   => 'membership_period_invalid',
                    'message' => 'The current membership billing period could not be determined.',
                ]);
            }
        } else {
            // Fresh assignment — calculate dates from package billing rules
            $billingType    = $hasBillingType ? strtolower((string)($planRow['billing_type'] ?? 'monthly')) : 'monthly';
            $availableUntil = $hasAvailUntil  ? ($planRow['available_until'] ?? null) : null;
            $dates          = ssaAssignCalcFreshPeriodDates($billingType, $availableUntil);
            $periodEndsAt   = $dates['current_period_ends_at'];
            $cancelDeadline = $dates['cancellation_notice_deadline_at'];
        }

        $planNameSnapshot = (string)(($planRow['display_name'] ?: $planRow['name']) ?? '');
        $priceSnapshot    = (int)($planRow['monthly_price_pence'] ?? 0);
        $currencySnapshot = (string)($planRow['currency'] ?? 'GBP');

        // ── Supersede old membership first (releases the active_uid unique constraint) ──
        if ($oldMembershipId !== null) {
            $supersedeSql = "UPDATE ssa_user_memberships SET status = 'superseded'";
            if ($hasCancelEffective) $supersedeSql .= ', cancellation_effective_at = UTC_TIMESTAMP()';
            if ($hasMemUpdatedAt)    $supersedeSql .= ', updated_at = UTC_TIMESTAMP()';
            $supersedeSql .= ' WHERE id = :id';
            $pdo->prepare($supersedeSql)->execute(['id' => $oldMembershipId]);
        }

        // ── Insert new active membership ──────────────────────────────────────
        $insertCols = 'uid, plan_id, status, started_at, current_period_starts_at,'
                    . ' current_period_ends_at, cancellation_notice_deadline_at,'
                    . ' price_snapshot_pence, currency_snapshot, plan_name_snapshot';
        $insertVals = ':uid, :planId, :active, UTC_TIMESTAMP(), UTC_TIMESTAMP(),'
                    . ' :periodEndsAt, :cancelDeadline,'
                    . ' :pricePence, :currency, :planName';
        $insertParams = [
            'uid'            => $memberUid,
            'planId'         => $packageId,
            'active'         => 'active',
            'periodEndsAt'   => $periodEndsAt,
            'cancelDeadline' => $cancelDeadline,
            'pricePence'     => $priceSnapshot,
            'currency'       => $currencySnapshot,
            'planName'       => $planNameSnapshot,
        ];
        if ($hasMemSource) {
            $insertCols   .= ', source';
            $insertVals   .= ', :source';
            $insertParams['source'] = 'admin_assignment';
        }
        if ($hasMemNotes) {
            $insertCols   .= ', notes';
            $insertVals   .= ', :notes';
            $insertParams['notes'] = 'Direct admin package assignment by uid=' . $callerUid;
        }

        $pdo->prepare("INSERT INTO ssa_user_memberships ({$insertCols}) VALUES ({$insertVals})")
            ->execute($insertParams);
        $newMembershipId = (int)$pdo->lastInsertId();

        // ── Back-fill superseded_by_membership_id ─────────────────────────────
        if ($oldMembershipId !== null && $newMembershipId > 0 && $hasSupersededBy) {
            $pdo->prepare(
                'UPDATE ssa_user_memberships SET superseded_by_membership_id = :newId WHERE id = :oldId'
            )->execute(['newId' => $newMembershipId, 'oldId' => $oldMembershipId]);
        }

        // ── Audit in ssa_membership_package_audit ─────────────────────────────
        $oldPlanKey = null;
        if ($currentPlanId) {
            $oldPlanKey = $pdo->prepare('SELECT plan_key FROM ssa_membership_plans WHERE id = :id LIMIT 1')
                              ->execute(['id' => $currentPlanId]) ? null : null;
            $oldPkStmt = $pdo->prepare('SELECT plan_key FROM ssa_membership_plans WHERE id = :id LIMIT 1');
            $oldPkStmt->execute(['id' => $currentPlanId]);
            $oldPlanKey = $oldPkStmt->fetchColumn() ?: null;
        }

        $actionType = $oldMembershipId ? 'membership_package_replacement' : 'new_membership_assignment';

        $auditCheck = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ssa_membership_package_audit'"
        );
        if ((int)$auditCheck->fetchColumn() > 0) {
            $pdo->prepare(
                'INSERT INTO ssa_membership_package_audit
                    (package_id, admin_uid, admin_name_snapshot, action,
                     changed_fields_json, old_values_json, new_values_json, ip_address)
                 VALUES
                    (:packageId, :adminUid, :adminName, :action,
                     :changedFields, :oldValues, :newValues, :ip)'
            )->execute([
                'packageId'    => $packageId,
                'adminUid'     => $callerUid,
                'adminName'    => $callerName !== '' ? $callerName : null,
                'action'       => 'admin_assignment',
                'changedFields'=> json_encode(['memberUid', 'membershipId'], JSON_UNESCAPED_SLASHES),
                'oldValues'    => json_encode([
                    'memberUid'       => $memberUid,
                    'memberName'      => $memberName,
                    'oldMembershipId' => $oldMembershipId,
                    'oldPlanId'       => $currentPlanId,
                    'oldPlanKey'      => $oldPlanKey,
                    'actionType'      => $actionType,
                ], JSON_UNESCAPED_SLASHES),
                'newValues'    => json_encode([
                    'memberUid'       => $memberUid,
                    'newMembershipId' => $newMembershipId,
                    'newPlanId'       => $packageId,
                    'newPlanKey'      => (string)$planRow['plan_key'],
                    'pricePence'      => $priceSnapshot,
                    'currency'        => $currencySnapshot,
                ], JSON_UNESCAPED_SLASHES),
                'ip'           => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        }

        $pdo->commit();

    } catch (Throwable $txEx) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $txEx;
    }

    // ── Post-transaction: member in-app notification (non-fatal) ────────────
    $notifMsg = '';
    try {
        ssaUserNotificationsEnsureTable($pdo);

        $newPlanName = $planRow['display_name'] ?: $planRow['name'] ?? 'your new package';

        if ($oldMembershipId !== null && $currentPlanId) {
            $oldPlanNameStmt = $pdo->prepare(
                'SELECT COALESCE(display_name, name) FROM ssa_membership_plans WHERE id = :id LIMIT 1'
            );
            $oldPlanNameStmt->execute(['id' => $currentPlanId]);
            $oldPlanName = (string)($oldPlanNameStmt->fetchColumn() ?: 'your previous package');
            $notifMsg    = "Your membership package has been changed from {$oldPlanName} to {$newPlanName}.";
        } else {
            $notifMsg = "You have been assigned the {$newPlanName} membership package.";
        }

        ssaUserNotificationsCreate(
            $pdo,
            $memberUid,
            'admin_package_assignment',
            'Membership Package Updated',
            $notifMsg,
            'membership',
            (string)$newMembershipId
        );
    } catch (Throwable $notifEx) {
        error_log('SSA member-package-assign.php: notification failed: ' . $notifEx->getMessage());
    }

    // ── Post-transaction: push notification (non-fatal) ──────────────────────
    if ($notifMsg !== '') {
        try {
            $hasRevokedAt = (bool)$pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ssa_push_tokens' AND COLUMN_NAME = 'revoked_at'"
            )->fetchColumn();
            $pushWhere = $hasRevokedAt ? 'AND revoked_at IS NULL' : '';

            $tokenStmt = $pdo->prepare(
                "SELECT device_token, environment FROM ssa_push_tokens WHERE uid = :uid {$pushWhere}"
            );
            $tokenStmt->execute(['uid' => $memberUid]);
            $tokens = $tokenStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($tokens)) {
                ssaPushSendToTokenRows($tokens, 'Membership Package Updated', $notifMsg, []);
            }
        } catch (Throwable $pushEx) {
            error_log('SSA member-package-assign.php: push failed: ' . $pushEx->getMessage());
        }
    }

    ssaApiJsonResponse(200, [
        'status'               => 'ok',
        'memberUid'            => $memberUid,
        'newMembershipId'      => $newMembershipId,
        'newMembershipPlanKey' => (string)$planRow['plan_key'],
        'packageId'            => $packageId,
        'actionType'           => $actionType,
    ]);

} catch (Throwable $e) {
    $sqlState = ($e instanceof \PDOException && is_array($e->errorInfo))
        ? ($e->errorInfo[0] ?? 'unknown') : 'n/a';
    error_log(sprintf(
        'SSA admin/member-package-assign.php FAILED [%s] SQLSTATE=%s message=%s in %s:%d',
        get_class($e), $sqlState, $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ssaApiJsonResponse(500, ['error' => 'server_error', 'message' => 'Unable to process package assignment.']);
}
