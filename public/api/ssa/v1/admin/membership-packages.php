<?php

declare(strict_types=1);

/**
 * GET /api/ssa/v1/admin/membership-packages.php
 *
 * Lists all membership packages (including private is_public=0 ones).
 * Admins see everything.
 *
 * Query params:
 *   search     – optional, filter by display_name LIKE or plan_key LIKE
 *   tier       – optional, one of red|pink|black|gold|summer|special
 *   visibility – optional, 'public' or 'assigned_only'
 *   status     – optional, 'active' or 'inactive' (default: all)
 *
 * Auth: admin or club_owner user_type.
 */

require_once __DIR__ . '/../_auth0.php';
require_once __DIR__ . '/../_db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ssaApiJsonResponse(405, ['error' => 'method_not_allowed', 'message' => 'Only GET is allowed.']);
}

$claims   = ssaApiRequireAuth0Claims();
$auth0Sub = trim((string)($claims['sub'] ?? ''));

if ($auth0Sub === '') {
    ssaApiJsonResponse(401, ['error' => 'missing_auth0_subject', 'message' => 'Auth0 token missing subject.']);
}

// ── Helpers ────────────────────────────────────────────────────────────────

function ssaPackageTier(string $planKey, ?string $parentPlanKey = null): string
{
    $ref = strtolower($parentPlanKey ?? $planKey);
    $key = strtolower($planKey);
    if ($ref === 'red' || str_starts_with($key, 'red')) return 'Red';
    if ($ref === 'pink' || str_starts_with($key, 'pink')) return 'Pink';
    if ($ref === 'black' || str_starts_with($key, 'black')) return 'Black';
    if ($ref === 'gold' || $key === 'gold' || $key === 'pro-package') return 'Gold';
    if (str_starts_with($key, 'summer')) return 'Summer';
    return 'Special';
}

function ssaPackageVariant(string $planKey): string
{
    $key = strtolower($planKey);
    if (str_contains($key, '_nhs_upfront')) return 'Upfront NHS';
    if (str_contains($key, '_junior')) return 'Junior';
    if (str_contains($key, '_nhs')) return 'NHS';
    if (str_contains($key, '_police')) return 'Police';
    if (str_contains($key, '_senior')) return 'Senior';
    if (str_contains($key, '_student')) return 'Student';
    if (str_contains($key, '_standard_upfront') || (str_contains($key, '_upfront') && !str_contains($key, '_nhs'))) return 'Upfront';
    if (str_contains($key, '_discounted')) return 'Discounted';
    if ($key === 'concession') return 'Concession';
    if ($key === 'coach') return 'Coach';
    if (str_contains($key, 'special')) return 'Special';
    return 'Standard';
}

function ssaPackagePriceDisplay(int $pence, string $currency, string $billingType): string
{
    $symbol = strtoupper($currency) === 'GBP' ? '£' : strtoupper($currency) . ' ';
    $pounds = $pence / 100;
    $formatted = ($pounds == floor($pounds))
        ? $symbol . number_format((int)$pounds)
        : $symbol . number_format($pounds, 2);
    return $billingType === 'monthly' ? $formatted . '/month' : $formatted;
}

function ssaPackageForApi(array $row): array
{
    $planKey       = (string)$row['plan_key'];
    $parentPlanKey = isset($row['parent_plan_key']) ? (string)$row['parent_plan_key'] : null;
    $pricePence    = (int)($row['monthly_price_pence'] ?? 0);
    $currency      = (string)($row['currency'] ?? 'GBP');
    $billingType   = (string)($row['billing_type'] ?? 'monthly');
    $isPublic      = (bool)(int)($row['is_public'] ?? 0);
    $isActive      = (bool)(int)($row['is_active'] ?? 1);
    $availableFrom = isset($row['available_from']) && $row['available_from'] ? (string)$row['available_from'] : null;
    $availableUntil = isset($row['available_until']) && $row['available_until'] ? (string)$row['available_until'] : null;

    // Graceful fallback: if updated_at doesn't exist, use created_at
    $updatedAt = null;
    if (isset($row['updated_at']) && $row['updated_at']) {
        $updatedAt = (string)$row['updated_at'];
    } elseif (isset($row['created_at']) && $row['created_at']) {
        $updatedAt = (string)$row['created_at'];
    }

    return [
        'id'             => (int)$row['id'],
        'planKey'        => $planKey,
        'name'           => (string)($row['name'] ?? ''),
        'displayName'    => (string)($row['display_name'] ?? $row['name'] ?? ''),
        'description'    => isset($row['description']) ? (string)$row['description'] : null,
        'tier'           => ssaPackageTier($planKey, $parentPlanKey),
        'variant'        => ssaPackageVariant($planKey),
        'pricePence'     => $pricePence,
        'currency'       => $currency,
        'priceDisplay'   => ssaPackagePriceDisplay($pricePence, $currency, $billingType),
        'billingType'    => $billingType,
        'isPublic'       => $isPublic,
        'visibility'     => $isPublic ? 'public' : 'assigned_only',
        'isActive'       => $isActive,
        'availableFrom'  => $availableFrom,
        'availableUntil' => $availableUntil,
        'isSeasonal'     => ($availableFrom !== null || $availableUntil !== null),
        'updatedAt'      => $updatedAt,
    ];
}

// ── Main ───────────────────────────────────────────────────────────────────

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

    // Check which optional columns exist
    $hasUpdatedAt = false;
    $hasBillingType = false;
    $hasAvailableFrom = false;
    $hasAvailableUntil = false;

    $colStmt = $pdo->query(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ssa_membership_plans'"
    );
    $existingCols = array_map('strtolower', $colStmt->fetchAll(PDO::FETCH_COLUMN));
    $hasUpdatedAt     = in_array('updated_at', $existingCols, true);
    $hasBillingType   = in_array('billing_type', $existingCols, true);
    $hasAvailableFrom = in_array('available_from', $existingCols, true);
    $hasAvailableUntil = in_array('available_until', $existingCols, true);

    // Parse query params
    $search     = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
    $tierFilter = isset($_GET['tier'])   ? strtolower(trim((string)$_GET['tier'])) : '';
    $visibility = isset($_GET['visibility']) ? trim((string)$_GET['visibility']) : '';
    $status     = isset($_GET['status'])     ? trim((string)$_GET['status']) : '';

    // Build SELECT columns
    $selectCols = 'p.id, p.plan_key, p.name, p.display_name, p.description,
                   p.monthly_price_pence, p.currency, p.sort_order,
                   p.is_active, p.is_public, p.parent_plan_id, p.created_at,
                   pp.plan_key AS parent_plan_key';
    if ($hasUpdatedAt)      $selectCols .= ', p.updated_at';
    if ($hasBillingType)    $selectCols .= ', p.billing_type';
    if ($hasAvailableFrom)  $selectCols .= ', p.available_from';
    if ($hasAvailableUntil) $selectCols .= ', p.available_until';

    $sql = "SELECT $selectCols
            FROM ssa_membership_plans p
            LEFT JOIN ssa_membership_plans pp ON pp.id = p.parent_plan_id
            WHERE 1=1";

    $params = [];

    if ($search !== '') {
        $sql .= ' AND (p.display_name LIKE :search OR p.plan_key LIKE :search OR p.name LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    if ($status === 'active') {
        $sql .= ' AND p.is_active = 1';
    } elseif ($status === 'inactive') {
        $sql .= ' AND p.is_active = 0';
    }

    if ($visibility === 'public') {
        $sql .= ' AND p.is_public = 1';
    } elseif ($visibility === 'assigned_only') {
        $sql .= ' AND p.is_public = 0';
    }

    $sql .= ' ORDER BY p.sort_order ASC, p.id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Apply tier filter in PHP (derived from plan_key)
    if ($tierFilter !== '') {
        $rows = array_filter($rows, static function (array $row) use ($tierFilter): bool {
            $planKey       = (string)$row['plan_key'];
            $parentPlanKey = isset($row['parent_plan_key']) ? (string)$row['parent_plan_key'] : null;
            $tier          = strtolower(ssaPackageTier($planKey, $parentPlanKey));
            return $tier === $tierFilter;
        });
        $rows = array_values($rows);
    }

    $packages = array_values(array_map('ssaPackageForApi', $rows));

    ssaApiJsonResponse(200, [
        'status'   => 'ok',
        'packages' => $packages,
        'total'    => count($packages),
    ]);

} catch (Throwable $e) {
    error_log(sprintf(
        'SSA admin/membership-packages.php FAILED [%s] %s in %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ssaApiJsonResponse(500, ['error' => 'server_error', 'message' => 'Unable to retrieve packages.']);
}
