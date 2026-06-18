<?php

declare(strict_types=1);

/**
 * GET /api/ssa/v1/membership.php
 *
 * Returns the authenticated user's current membership, available plans with
 * benefits, add-on catalogue with user request state, and membership history.
 *
 * If the linked user has no active membership row the response includes
 * currentMembership: null and membershipStatus: "not_configured".
 * No membership rows are created automatically.
 *
 * Requires a valid Auth0 Bearer token for a linked booking account.
 */

require_once __DIR__ . '/_auth0.php';
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_membership_request_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ssaApiJsonResponse(405, [
        'error' => 'method_not_allowed',
        'message' => 'Only GET is allowed for this endpoint.',
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

// -------------------------------------------------------------------------
// Helper: format pence as display price string e.g. 12900 → "£129/month"
// -------------------------------------------------------------------------
function ssaMembershipPriceDisplay(int $pence, string $currency): string
{
    $symbol = strtoupper($currency) === 'GBP' ? '£' : strtoupper($currency) . ' ';
    $pounds = $pence / 100;
    $formatted = ($pounds == floor($pounds))
        ? $symbol . number_format((int)$pounds)
        : $symbol . number_format($pounds, 2);
    return $formatted . '/month';
}

// -------------------------------------------------------------------------
// Helper: extract Y-m-d from a DATETIME string (or return null)
// -------------------------------------------------------------------------
function ssaMembershipDateOnly(?string $datetime): ?string
{
    if ($datetime === null || $datetime === '') {
        return null;
    }
    return substr($datetime, 0, 10);
}

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

    // -------------------------------------------------------------------------
    // Current active membership
    // -------------------------------------------------------------------------
    $activeMembershipStmt = $pdo->prepare(
        'SELECT
            m.id,
            m.uid,
            m.plan_id,
            m.status,
            m.started_at,
            m.current_period_starts_at,
            m.current_period_ends_at,
            m.cancellation_notice_deadline_at,
            m.cancelled_at,
            m.cancellation_effective_at,
            m.price_snapshot_pence,
            m.currency_snapshot,
            m.plan_name_snapshot,
            p.plan_key,
            p.name AS plan_name,
            p.display_name,
            p.monthly_price_pence,
            p.currency,
            p.table_access_summary,
            p.coaching_summary,
            p.is_public
         FROM ssa_user_memberships m
         INNER JOIN ssa_membership_plans p ON p.id = m.plan_id
         WHERE m.uid = :uid AND m.status = :status
         ORDER BY m.started_at DESC
         LIMIT 1'
    );
    $activeMembershipStmt->execute(['uid' => $uid, 'status' => 'active']);
    $activeMembership = $activeMembershipStmt->fetch(PDO::FETCH_ASSOC);

    // Member since = earliest started_at across all history rows.
    $memberSinceStmt = $pdo->prepare(
        'SELECT MIN(started_at) FROM ssa_user_memberships WHERE uid = :uid'
    );
    $memberSinceStmt->execute(['uid' => $uid]);
    $memberSince = $memberSinceStmt->fetchColumn() ?: null;

    if ($activeMembership) {
        $pricePence = (int)$activeMembership['price_snapshot_pence'];
        $currency = (string)$activeMembership['currency_snapshot'];
        $membershipStatus = 'active';

        // Benefits for current plan.
        $benefitsStmt = $pdo->prepare(
            'SELECT benefit_key, title, description, is_included, sort_order
             FROM ssa_membership_plan_benefits
             WHERE plan_id = :planId AND is_included = 1
             ORDER BY sort_order ASC, benefit_key ASC'
        );
        $benefitsStmt->execute(['planId' => (int)$activeMembership['plan_id']]);
        $benefits = array_values(array_map(static fn (array $r): array => [
            'benefitKey' => $r['benefit_key'],
            'title' => $r['title'],
            'description' => $r['description'],
        ], $benefitsStmt->fetchAll(PDO::FETCH_ASSOC)));

        // Derive tier/variant/billingType for currentMembership
        $cmPlanKey = (string)$activeMembership['plan_key'];
        $cmBillingType = 'monthly';
        // billing_type may be on the plan row if the column exists (we don't JOIN it here but we can detect from plan_key)
        // Use a simple check: upfront plans
        if (str_contains(strtolower($cmPlanKey), '_upfront')) {
            $cmBillingType = 'upfront';
        } elseif ($cmPlanKey === 'PINK_STANDARD_UPFRONT') {
            $cmBillingType = 'fixed_term';
        }

        $cmTier = 'Special';
        $cmKey = strtolower($cmPlanKey);
        if (str_starts_with($cmKey, 'red'))    $cmTier = 'Red';
        elseif (str_starts_with($cmKey, 'pink'))  $cmTier = 'Pink';
        elseif (str_starts_with($cmKey, 'black')) $cmTier = 'Black';
        elseif (str_starts_with($cmKey, 'gold') || $cmKey === 'pro-package') $cmTier = 'Gold';
        elseif (str_starts_with($cmKey, 'summer')) $cmTier = 'Summer';

        $cmVariant = 'Standard';
        if (str_contains($cmKey, '_nhs_upfront'))   $cmVariant = 'Upfront NHS';
        elseif (str_contains($cmKey, '_junior'))     $cmVariant = 'Junior';
        elseif (str_contains($cmKey, '_nhs'))        $cmVariant = 'NHS';
        elseif (str_contains($cmKey, '_police'))     $cmVariant = 'Police';
        elseif (str_contains($cmKey, '_senior'))     $cmVariant = 'Senior';
        elseif (str_contains($cmKey, '_student'))    $cmVariant = 'Student';
        elseif (str_contains($cmKey, '_standard_upfront') || (str_contains($cmKey, '_upfront') && !str_contains($cmKey, '_nhs'))) $cmVariant = 'Upfront';
        elseif (str_contains($cmKey, '_discounted')) $cmVariant = 'Discounted';
        elseif ($cmKey === 'concession')             $cmVariant = 'Concession';
        elseif ($cmKey === 'coach')                  $cmVariant = 'Coach';
        elseif (str_contains($cmKey, 'special'))     $cmVariant = 'Special';

        $currentMembership = [
            'id' => (int)$activeMembership['id'],
            'planKey' => $activeMembership['plan_key'],
            'name' => $activeMembership['plan_name_snapshot'],
            'displayName' => $activeMembership['display_name'],
            'pricePence' => $pricePence,
            'currency' => $currency,
            'priceDisplay' => ssaMembershipPriceDisplay($pricePence, $currency),
            'tier' => $cmTier,
            'variant' => $cmVariant,
            'billingType' => $cmBillingType,
            'visibility' => (bool)(int)($activeMembership['is_public'] ?? 0) ? 'public' : 'assigned_only',
            'memberSince' => ssaMembershipDateOnly($memberSince),
            'currentPeriodStart' => ssaMembershipDateOnly($activeMembership['current_period_starts_at']),
            'currentPeriodEnd' => ssaMembershipDateOnly($activeMembership['current_period_ends_at']),
            'cancellationNoticeDeadline' => ssaMembershipDateOnly($activeMembership['cancellation_notice_deadline_at']),
            'cancelledAt' => ssaMembershipDateOnly($activeMembership['cancelled_at']),
            'cancellationEffectiveAt' => ssaMembershipDateOnly($activeMembership['cancellation_effective_at']),
            'status' => $activeMembership['status'],
            'renewsMonthly' => $cmBillingType === 'monthly',
            'tableAccessSummary' => $activeMembership['table_access_summary'],
            'coachingSummary' => $activeMembership['coaching_summary'],
            'benefits' => $benefits,
        ];
    } else {
        $membershipStatus = 'not_configured';
        $currentMembership = null;
    }

    // -------------------------------------------------------------------------
    // Available plans (active + public, with benefits)
    // -------------------------------------------------------------------------
    // Catalogue: public plans only. A member's own private plan is returned via
    // currentMembership (direct JOIN on plan_id, no is_public filter) not via this catalogue.
    // Rule A (private plan visibility): satisfied by the INNER JOIN above in $activeMembershipStmt —
    //   that query joins ssa_membership_plans without an is_public filter, so a member whose
    //   active membership uses a private plan (e.g. RED_JUNIOR) will still see their plan
    //   details in currentMembership.planKey, .displayName, etc.
    // Rule B (catalogue never exposes private plans): enforced by is_public = 1 below.

    // Detect optional columns
    $planColStmt = $pdo->query(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ssa_membership_plans'"
    );
    $planExistingCols   = array_map('strtolower', $planColStmt->fetchAll(PDO::FETCH_COLUMN));
    $planHasBillingType = in_array('billing_type', $planExistingCols, true);
    $planHasAvailFrom   = in_array('available_from', $planExistingCols, true);
    $planHasAvailUntil  = in_array('available_until', $planExistingCols, true);
    $planHasUpdatedAt   = in_array('updated_at', $planExistingCols, true);

    $planExtraCols = '';
    if ($planHasBillingType) $planExtraCols .= ', billing_type';
    if ($planHasAvailFrom)   $planExtraCols .= ', available_from';
    if ($planHasAvailUntil)  $planExtraCols .= ', available_until';

    $availabilityFilter = '';
    if ($planHasAvailFrom)  $availabilityFilter .= "\n         AND (available_from IS NULL OR available_from <= CURDATE())";
    if ($planHasAvailUntil) $availabilityFilter .= "\n         AND (available_until IS NULL OR available_until >= CURDATE())";

    $plansStmt = $pdo->query(
        'SELECT id, plan_key, name, display_name, description,
                monthly_price_pence, currency,
                table_access_summary, coaching_summary, sort_order,
                parent_plan_id' . $planExtraCols . '
         FROM ssa_membership_plans
         WHERE is_active = 1 AND is_public = 1' . $availabilityFilter . '
         ORDER BY sort_order ASC, id ASC'
    );
    $plans = $plansStmt->fetchAll(PDO::FETCH_ASSOC);

    // Batch-load all benefits for these plans.
    $planIds = array_column($plans, 'id');
    $benefitsByPlanId = [];

    if (!empty($planIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($planIds), '?'));
        $allBenefitsStmt = $pdo->prepare(
            'SELECT plan_id, benefit_key, title, description, is_included, sort_order
             FROM ssa_membership_plan_benefits
             WHERE plan_id IN (' . $inPlaceholders . ') AND is_included = 1
             ORDER BY plan_id ASC, sort_order ASC, benefit_key ASC'
        );
        $allBenefitsStmt->execute(array_values($planIds));
        foreach ($allBenefitsStmt->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $benefitsByPlanId[(int)$b['plan_id']][] = [
                'benefitKey' => $b['benefit_key'],
                'title' => $b['title'],
                'description' => $b['description'],
            ];
        }
    }

    // Helper: derive tier from plan_key / parent_plan_id
    $parentPlanKeyMap = [];
    foreach ($plans as $p) {
        $parentPlanKeyMap[(int)$p['id']] = (string)$p['plan_key'];
    }

    $currentPlanKey = $activeMembership['plan_key'] ?? null;
    $availablePlans = array_values(array_map(
        static function (array $plan) use ($benefitsByPlanId, $currentPlanKey, $parentPlanKeyMap, $planHasBillingType, $planHasAvailFrom, $planHasAvailUntil): array {
            $pid = (int)$plan['id'];
            $pence = (int)$plan['monthly_price_pence'];
            $cur = (string)$plan['currency'];
            $billingType = $planHasBillingType ? (string)$plan['billing_type'] : 'monthly';
            $isPublic = true; // catalogue only shows public plans
            $availableFrom  = ($planHasAvailFrom  && isset($plan['available_from']))  ? $plan['available_from']  : null;
            $availableUntil = ($planHasAvailUntil && isset($plan['available_until'])) ? $plan['available_until'] : null;

            // Resolve parent plan key for tier
            $parentPlanId  = isset($plan['parent_plan_id']) ? (int)$plan['parent_plan_id'] : null;
            $parentPlanKey = ($parentPlanId && isset($parentPlanKeyMap[$parentPlanId]))
                ? $parentPlanKeyMap[$parentPlanId] : null;

            return [
                'planKey'            => $plan['plan_key'],
                'name'               => $plan['name'],
                'displayName'        => $plan['display_name'],
                'description'        => $plan['description'],
                'pricePence'         => $pence,
                'currency'           => $cur,
                'priceDisplay'       => ssaMembershipPriceDisplay($pence, $cur),
                'billingType'        => $billingType,
                'visibility'         => 'public',
                'availableFrom'      => $availableFrom ? substr((string)$availableFrom, 0, 10) : null,
                'availableUntil'     => $availableUntil ? substr((string)$availableUntil, 0, 10) : null,
                'isSeasonal'         => ($availableFrom !== null || $availableUntil !== null),
                'tableAccessSummary' => $plan['table_access_summary'],
                'coachingSummary'    => $plan['coaching_summary'],
                'isCurrent'          => $plan['plan_key'] === $currentPlanKey,
                'benefits'           => $benefitsByPlanId[$pid] ?? [],
            ];
        },
        $plans
    ));

    // -------------------------------------------------------------------------
    // Add-ons: catalogue + user request state
    // -------------------------------------------------------------------------
    $addonsStmt = $pdo->query(
        'SELECT id, addon_key, name, description, monthly_price_pence, currency, sort_order
         FROM ssa_membership_addons
         WHERE is_active = 1
         ORDER BY sort_order ASC, id ASC'
    );
    $addons = $addonsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Check which optional columns exist on ssa_user_membership_addons
    $addonColStmt = $pdo->query(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ssa_user_membership_addons'"
    );
    $addonExistingCols       = array_map('strtolower', $addonColStmt->fetchAll(PDO::FETCH_COLUMN));
    $addonHasCancelEffective = in_array('cancellation_effective_at', $addonExistingCols, true);
    $addonHasCancelReqId     = in_array('cancellation_request_id', $addonExistingCols, true);

    $addonSelectExtra = '';
    if ($addonHasCancelEffective) $addonSelectExtra .= ', cancellation_effective_at';
    if ($addonHasCancelReqId)     $addonSelectExtra .= ', cancellation_request_id';

    // Most recent row per addon for this user (keyed by addon_id, also track userAddon id)
    $userAddonStmt = $pdo->prepare(
        'SELECT id AS user_addon_id, addon_id, status, requested_at, activated_at, cancelled_at' . $addonSelectExtra . '
         FROM ssa_user_membership_addons
         WHERE uid = :uid
         ORDER BY requested_at DESC'
    );
    $userAddonStmt->execute(['uid' => $uid]);
    $userAddonByAddonId = [];
    foreach ($userAddonStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $aid = (int)$row['addon_id'];
        if (!isset($userAddonByAddonId[$aid])) {
            $userAddonByAddonId[$aid] = $row;
        }
    }

    // Look up pending/approved addon_cancellation requests for this user
    $cancelReqByUserAddonId = [];
    try {
        $cancelReqStmt = $pdo->prepare(
            "SELECT id AS request_id, target_id AS user_addon_id, status, payload_json
             FROM ssa_admin_requests
             WHERE uid = :uid
               AND request_type = 'addon_cancellation'
               AND status IN ('pending', 'approved')
             ORDER BY requested_at DESC"
        );
        $cancelReqStmt->execute(['uid' => $uid]);
        foreach ($cancelReqStmt->fetchAll(PDO::FETCH_ASSOC) as $cr) {
            $uaid = (int)$cr['user_addon_id'];
            if (!isset($cancelReqByUserAddonId[$uaid])) {
                $crPayload = [];
                if (isset($cr['payload_json']) && $cr['payload_json']) {
                    $decoded = json_decode((string)$cr['payload_json'], true);
                    if (is_array($decoded)) $crPayload = $decoded;
                }
                $cancelReqByUserAddonId[$uaid] = [
                    'requestId'              => (int)$cr['request_id'],
                    'status'                 => (string)$cr['status'],
                    'requestedEffectiveDate' => $crPayload['requestedEffectiveDate'] ?? null,
                    'lastDayOfCurrentMonth'  => $crPayload['lastDayOfCurrentMonth'] ?? null,
                ];
            }
        }
    } catch (Throwable $cancelReqEx) {
        // Non-fatal: table may not exist yet
        error_log('membership.php: addon cancellation request lookup failed: ' . $cancelReqEx->getMessage());
    }

    $addonList = array_values(array_map(
        static function (array $addon) use ($userAddonByAddonId, $cancelReqByUserAddonId, $addonHasCancelEffective): array {
            $aid = (int)$addon['id'];
            $userRow = $userAddonByAddonId[$aid] ?? null;
            $pence = (int)$addon['monthly_price_pence'];
            $cur = (string)$addon['currency'];
            $userAddonId = $userRow ? (int)$userRow['user_addon_id'] : null;
            $cancelRequest = ($userAddonId && isset($cancelReqByUserAddonId[$userAddonId]))
                ? $cancelReqByUserAddonId[$userAddonId]
                : null;
            $cancelEffective = null;
            if ($userRow && $addonHasCancelEffective && isset($userRow['cancellation_effective_at'])) {
                $cancelEffective = $userRow['cancellation_effective_at']
                    ? substr((string)$userRow['cancellation_effective_at'], 0, 10)
                    : null;
            }
            return [
                'addonKey'              => $addon['addon_key'],
                'name'                  => $addon['name'],
                'description'           => $addon['description'],
                'pricePence'            => $pence,
                'currency'              => $cur,
                'priceDisplay'          => $pence > 0 ? ssaMembershipPriceDisplay($pence, $cur) : 'Included',
                'userStatus'            => $userRow ? (string)$userRow['status'] : 'not_requested',
                'userAddonId'           => $userAddonId,
                'requestedAt'           => $userRow ? $userRow['requested_at'] : null,
                'activatedAt'           => $userRow ? $userRow['activated_at'] : null,
                'cancellationEffectiveAt' => $cancelEffective,
                'cancellationRequest'   => $cancelRequest,
            ];
        },
        $addons
    ));

    // -------------------------------------------------------------------------
    // Pending admin requests: backend source of truth for in-app lifecycle state
    // -------------------------------------------------------------------------
    $pendingChangeStmt = $pdo->prepare(
        'SELECT
            r.id,
            r.status,
            r.target_key,
            r.target_id,
            r.payload_json,
            r.requested_at,
            p.plan_key,
            p.name AS plan_name,
            p.display_name AS plan_display_name
         FROM ssa_admin_requests r
         LEFT JOIN ssa_membership_plans p ON p.id = r.target_id
         WHERE r.uid = :uid
           AND r.request_type = :requestType
           AND r.status = :status
         ORDER BY r.requested_at DESC, r.id DESC
         LIMIT 1'
    );
    $pendingChangeStmt->execute([
        'uid' => $uid,
        'requestType' => 'membership_change',
        'status' => 'pending',
    ]);
    $pendingChangeRow = $pendingChangeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $pendingCancellationStmt = $pdo->prepare(
        'SELECT id, status, target_key, target_id, payload_json, requested_at
         FROM ssa_admin_requests
         WHERE uid = :uid
           AND request_type = :requestType
           AND status = :status
         ORDER BY requested_at DESC, id DESC
         LIMIT 1'
    );
    $pendingCancellationStmt->execute([
        'uid' => $uid,
        'requestType' => 'membership_cancellation',
        'status' => 'pending',
    ]);
    $pendingCancellationRow = $pendingCancellationStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $pendingAddonStmt = $pdo->prepare(
        'SELECT
            r.id,
            r.status,
            r.target_key,
            r.target_id,
            r.payload_json,
            r.requested_at,
            a.addon_key,
            a.name AS addon_name
         FROM ssa_admin_requests r
         LEFT JOIN ssa_membership_addons a ON a.id = r.target_id
         WHERE r.uid = :uid
           AND r.request_type = :requestType
           AND r.status = :status
         ORDER BY r.requested_at DESC, r.id DESC'
    );
    $pendingAddonStmt->execute([
        'uid' => $uid,
        'requestType' => 'addon_request',
        'status' => 'pending',
    ]);

    $pendingMembershipChange = null;
    if ($pendingChangeRow) {
        $targetPlanKey = $pendingChangeRow['plan_key'] ?: $pendingChangeRow['target_key'];
        $targetPlanName = $pendingChangeRow['plan_display_name'] ?: $pendingChangeRow['plan_name'];
        $pendingMembershipChange = ssaMembershipRequestForApi($pendingChangeRow, [
            'targetPlanKey' => $targetPlanKey !== null ? (string)$targetPlanKey : null,
            'targetPlanName' => $targetPlanName !== null ? (string)$targetPlanName : null,
        ]);
    }

    $pendingMembershipCancellation = ssaMembershipRequestForApi($pendingCancellationRow);

    $pendingAddonRequests = [];
    foreach ($pendingAddonStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $addonKeyValue = $row['addon_key'] ?: $row['target_key'];
        $pendingAddonRequests[] = ssaMembershipRequestForApi($row, [
            'addonKey' => $addonKeyValue !== null ? (string)$addonKeyValue : null,
            'addonName' => $row['addon_name'] !== null ? (string)$row['addon_name'] : null,
        ]);
    }

    $pendingRequests = [
        'membershipChange' => $pendingMembershipChange,
        'membershipCancellation' => $pendingMembershipCancellation,
        'addons' => array_values(array_filter($pendingAddonRequests)),
    ];

    // -------------------------------------------------------------------------
    // Membership history (all rows, newest first)
    // -------------------------------------------------------------------------
    $historyStmt = $pdo->prepare(
        'SELECT
            m.id,
            m.status,
            m.started_at,
            m.current_period_starts_at,
            m.current_period_ends_at,
            m.cancelled_at,
            m.cancellation_effective_at,
            m.price_snapshot_pence,
            m.currency_snapshot,
            m.plan_name_snapshot,
            p.plan_key,
            p.display_name
         FROM ssa_user_memberships m
         INNER JOIN ssa_membership_plans p ON p.id = m.plan_id
         WHERE m.uid = :uid
         ORDER BY m.started_at DESC, m.id DESC'
    );
    $historyStmt->execute(['uid' => $uid]);
    $membershipHistory = array_values(array_map(static fn (array $r): array => [
        'id' => (int)$r['id'],
        'planKey' => $r['plan_key'],
        'planName' => $r['plan_name_snapshot'],
        'displayName' => $r['display_name'],
        'pricePence' => (int)$r['price_snapshot_pence'],
        'currency' => $r['currency_snapshot'],
        'status' => $r['status'],
        'startedAt' => ssaMembershipDateOnly($r['started_at']),
        'currentPeriodStart' => ssaMembershipDateOnly($r['current_period_starts_at']),
        'currentPeriodEnd' => ssaMembershipDateOnly($r['current_period_ends_at']),
        'cancelledAt' => ssaMembershipDateOnly($r['cancelled_at']),
        'cancellationEffectiveAt' => ssaMembershipDateOnly($r['cancellation_effective_at']),
    ], $historyStmt->fetchAll(PDO::FETCH_ASSOC)));

    ssaApiJsonResponse(200, [
        'status' => 'ok',
        'membershipStatus' => $membershipStatus,
        'currentMembership' => $currentMembership,
        'availablePlans' => $availablePlans,
        'addons' => $addonList,
        'pendingRequests' => $pendingRequests,
        'membershipHistory' => $membershipHistory,
    ]);

} catch (Throwable $exception) {
    error_log('SSA API membership endpoint failed: ' . $exception->getMessage());
    ssaApiJsonResponse(500, [
        'error' => 'membership_fetch_failed',
        'message' => 'Unable to retrieve membership data.',
    ]);
}
