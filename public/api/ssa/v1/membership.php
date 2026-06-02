<?php

declare(strict_types=1);

/**
 * GET /api/ssa/v1/membership.php
 *
 * Returns the authenticated user's current membership, membership history,
 * available plans with benefits, and add-on request states.
 *
 * Requires a valid Auth0 Bearer token for a linked booking account.
 */

require_once __DIR__ . '/_auth0.php';
require_once __DIR__ . '/_db.php';

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
// Helpers
// -------------------------------------------------------------------------

function ssaMembershipCurrentPeriod(string $startedAt, string $today): array
{
    $tz = new DateTimeZone(SSA_API_TIMEZONE);
    $start = new DateTimeImmutable($startedAt . ' 00:00:00', $tz);
    $now = new DateTimeImmutable($today . ' 00:00:00', $tz);

    $diff = $start->diff($now);
    $monthsElapsed = $diff->y * 12 + $diff->m;

    $periodStart = $start->modify('+' . $monthsElapsed . ' months');

    // Guard: if rounding pushed periodStart past today, step back one month.
    if ($periodStart > $now) {
        $periodStart = $start->modify('+' . max(0, $monthsElapsed - 1) . ' months');
    }

    $periodEnd = $periodStart->modify('+1 month');
    $cancellationDeadline = $periodEnd->modify('-1 day');

    return [
        'currentPeriodStart' => $periodStart->format('Y-m-d'),
        'currentPeriodEnd' => $periodEnd->format('Y-m-d'),
        'cancellationDeadline' => $cancellationDeadline->format('Y-m-d'),
    ];
}

// -------------------------------------------------------------------------
// Main
// -------------------------------------------------------------------------

try {
    $pdo = ssaApiCreatePdo();

    // Resolve uid from the Auth0 subject.
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
    $today = (new DateTimeImmutable('now', new DateTimeZone(SSA_API_TIMEZONE)))->format('Y-m-d');

    // -------------------------------------------------------------------------
    // Current membership
    // -------------------------------------------------------------------------
    $currentRow = $pdo->prepare(
        'SELECT
            m.id,
            m.started_at,
            m.ended_at,
            p.slug AS plan_slug,
            p.name AS plan_name,
            p.description AS plan_description,
            p.price_pence
         FROM ssa_user_memberships m
         INNER JOIN ssa_membership_plans p ON p.id = m.plan_id
         WHERE m.uid = :uid AND m.ended_at IS NULL
         ORDER BY m.started_at DESC
         LIMIT 1'
    );
    $currentRow->execute(['uid' => $uid]);
    $current = $currentRow->fetch(PDO::FETCH_ASSOC);

    // Member since = earliest started_at across all history rows.
    $memberSinceRow = $pdo->prepare(
        'SELECT MIN(started_at) FROM ssa_user_memberships WHERE uid = :uid'
    );
    $memberSinceRow->execute(['uid' => $uid]);
    $memberSince = $memberSinceRow->fetchColumn() ?: null;

    $membership = null;

    if ($current) {
        $period = ssaMembershipCurrentPeriod((string)$current['started_at'], $today);

        // Benefits for current plan.
        $benefitsStmt = $pdo->prepare(
            'SELECT benefit_key, label
             FROM ssa_membership_plan_benefits
             INNER JOIN ssa_membership_plans ON ssa_membership_plans.id = ssa_membership_plan_benefits.plan_id
             WHERE ssa_membership_plans.slug = :slug
             ORDER BY ssa_membership_plan_benefits.priority ASC, benefit_key ASC'
        );
        $benefitsStmt->execute(['slug' => $current['plan_slug']]);
        $benefits = array_values(array_map(static fn (array $r): array => [
            'benefitKey' => $r['benefit_key'],
            'label' => $r['label'],
        ], $benefitsStmt->fetchAll(PDO::FETCH_ASSOC)));

        $membership = [
            'id' => (int)$current['id'],
            'planSlug' => $current['plan_slug'],
            'planName' => $current['plan_name'],
            'memberSince' => $memberSince,
            'currentPeriodStart' => $period['currentPeriodStart'],
            'currentPeriodEnd' => $period['currentPeriodEnd'],
            'cancellationDeadline' => $period['cancellationDeadline'],
            'benefits' => $benefits,
        ];
    }

    // -------------------------------------------------------------------------
    // Available plans (active, with benefits)
    // -------------------------------------------------------------------------
    $plansStmt = $pdo->query(
        'SELECT id, slug, name, description, price_pence, priority
         FROM ssa_membership_plans
         WHERE is_active = 1
         ORDER BY priority ASC, id ASC'
    );
    $plans = $plansStmt->fetchAll(PDO::FETCH_ASSOC);

    $allBenefitsStmt = $pdo->query(
        'SELECT plan_id, benefit_key, label, priority
         FROM ssa_membership_plan_benefits
         ORDER BY plan_id ASC, priority ASC, benefit_key ASC'
    );
    $allBenefits = $allBenefitsStmt->fetchAll(PDO::FETCH_ASSOC);

    $benefitsByPlanId = [];
    foreach ($allBenefits as $b) {
        $benefitsByPlanId[(int)$b['plan_id']][] = [
            'benefitKey' => $b['benefit_key'],
            'label' => $b['label'],
        ];
    }

    $currentPlanSlug = $current['plan_slug'] ?? null;
    $availablePlans = array_values(array_map(static function (array $plan) use ($benefitsByPlanId, $currentPlanSlug): array {
        $pid = (int)$plan['id'];
        return [
            'slug' => $plan['slug'],
            'name' => $plan['name'],
            'description' => $plan['description'],
            'pricePence' => (int)$plan['price_pence'],
            'isCurrent' => $plan['slug'] === $currentPlanSlug,
            'benefits' => $benefitsByPlanId[$pid] ?? [],
        ];
    }, $plans));

    // -------------------------------------------------------------------------
    // Add-ons: list all active addons with user's request status
    // -------------------------------------------------------------------------
    $addonsStmt = $pdo->query(
        'SELECT id, slug, name, description
         FROM ssa_membership_addons
         WHERE is_active = 1
         ORDER BY id ASC'
    );
    $addons = $addonsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch user's most recent request per addon (newest first).
    $userAddonStmt = $pdo->prepare(
        'SELECT addon_id, status, requested_at, resolved_at
         FROM ssa_user_membership_addons
         WHERE uid = :uid
         ORDER BY requested_at DESC'
    );
    $userAddonStmt->execute(['uid' => $uid]);
    $userAddonRows = $userAddonStmt->fetchAll(PDO::FETCH_ASSOC);

    $userAddonByAddonId = [];
    foreach ($userAddonRows as $row) {
        $aid = (int)$row['addon_id'];
        if (!isset($userAddonByAddonId[$aid])) {
            $userAddonByAddonId[$aid] = $row;
        }
    }

    $addonList = array_values(array_map(static function (array $addon) use ($userAddonByAddonId): array {
        $aid = (int)$addon['id'];
        $userRow = $userAddonByAddonId[$aid] ?? null;
        return [
            'addonSlug' => $addon['slug'],
            'name' => $addon['name'],
            'description' => $addon['description'],
            'userStatus' => $userRow ? (string)$userRow['status'] : 'not_requested',
            'requestedAt' => $userRow ? $userRow['requested_at'] : null,
            'resolvedAt' => $userRow ? $userRow['resolved_at'] : null,
        ];
    }, $addons));

    // -------------------------------------------------------------------------
    // Membership history (newest first)
    // -------------------------------------------------------------------------
    $historyStmt = $pdo->prepare(
        'SELECT
            m.started_at,
            m.ended_at,
            m.notes,
            p.slug AS plan_slug,
            p.name AS plan_name
         FROM ssa_user_memberships m
         INNER JOIN ssa_membership_plans p ON p.id = m.plan_id
         WHERE m.uid = :uid
         ORDER BY m.started_at DESC, m.id DESC'
    );
    $historyStmt->execute(['uid' => $uid]);
    $history = array_values(array_map(static fn (array $r): array => [
        'planSlug' => $r['plan_slug'],
        'planName' => $r['plan_name'],
        'startedAt' => $r['started_at'],
        'endedAt' => $r['ended_at'],
    ], $historyStmt->fetchAll(PDO::FETCH_ASSOC)));

    ssaApiJsonResponse(200, [
        'status' => 'ok',
        'membership' => $membership,
        'availablePlans' => $availablePlans,
        'addons' => $addonList,
        'history' => $history,
    ]);

} catch (Throwable $exception) {
    error_log('SSA API membership endpoint failed: ' . $exception->getMessage());
    ssaApiJsonResponse(500, [
        'error' => 'membership_fetch_failed',
        'message' => 'Unable to retrieve membership data.',
    ]);
}
