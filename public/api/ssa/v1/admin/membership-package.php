<?php

declare(strict_types=1);

/**
 * GET  /api/ssa/v1/admin/membership-package.php?id=N  — retrieve one package
 * PATCH /api/ssa/v1/admin/membership-package.php?id=N  — update package
 *
 * Auth: admin or club_owner user_type.
 */

require_once __DIR__ . '/../_auth0.php';
require_once __DIR__ . '/../_db.php';

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'PATCH'], true)) {
    ssaApiJsonResponse(405, ['error' => 'method_not_allowed', 'message' => 'Only GET and PATCH are allowed.']);
}

$claims   = ssaApiRequireAuth0Claims();
$auth0Sub = trim((string)($claims['sub'] ?? ''));

if ($auth0Sub === '') {
    ssaApiJsonResponse(401, ['error' => 'missing_auth0_subject', 'message' => 'Auth0 token missing subject.']);
}

// ── Shared helpers (re-use from membership-packages.php inline) ───────────

function ssaMpaTier(string $planKey, ?string $parentPlanKey = null): string
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

function ssaMpaVariant(string $planKey): string
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

function ssaMpaPriceDisplay(int $pence, string $currency, string $billingType): string
{
    $symbol = strtoupper($currency) === 'GBP' ? '£' : strtoupper($currency) . ' ';
    $pounds = $pence / 100;
    $formatted = ($pounds == floor($pounds))
        ? $symbol . number_format((int)$pounds)
        : $symbol . number_format($pounds, 2);
    return $billingType === 'monthly' ? $formatted . '/month' : $formatted;
}

function ssaMpaRowToApi(array $row): array
{
    $planKey        = (string)$row['plan_key'];
    $parentPlanKey  = isset($row['parent_plan_key']) ? (string)$row['parent_plan_key'] : null;
    $pricePence     = (int)($row['monthly_price_pence'] ?? 0);
    $currency       = (string)($row['currency'] ?? 'GBP');
    $billingType    = (string)($row['billing_type'] ?? 'monthly');
    $isPublic       = (bool)(int)($row['is_public'] ?? 0);
    $isActive       = (bool)(int)($row['is_active'] ?? 1);
    $availableFrom  = isset($row['available_from']) && $row['available_from'] ? (string)$row['available_from'] : null;
    $availableUntil = isset($row['available_until']) && $row['available_until'] ? (string)$row['available_until'] : null;

    $updatedAt = null;
    if (isset($row['updated_at']) && $row['updated_at']) {
        $updatedAt = (string)$row['updated_at'];
    } elseif (isset($row['created_at']) && $row['created_at']) {
        $updatedAt = (string)$row['created_at'];
    }

    $result = [
        'id'              => (int)$row['id'],
        'planKey'         => $planKey,
        'name'            => (string)($row['name'] ?? ''),
        'displayName'     => (string)($row['display_name'] ?? $row['name'] ?? ''),
        'description'     => isset($row['description']) ? (string)$row['description'] : null,
        'tier'            => ssaMpaTier($planKey, $parentPlanKey),
        'variant'         => ssaMpaVariant($planKey),
        'pricePence'      => $pricePence,
        'currency'        => $currency,
        'priceDisplay'    => ssaMpaPriceDisplay($pricePence, $currency, $billingType),
        'billingType'     => $billingType,
        'isPublic'        => $isPublic,
        'visibility'      => $isPublic ? 'public' : 'assigned_only',
        'isActive'        => $isActive,
        'availableFrom'   => $availableFrom,
        'availableUntil'  => $availableUntil,
        'isSeasonal'      => ($availableFrom !== null || $availableUntil !== null),
        'updatedAt'       => $updatedAt,
    ];

    // Extra fields for single-package detail
    if (isset($row['membership_count'])) {
        $result['membershipCount'] = (int)$row['membership_count'];
    }

    return $result;
}

function ssaMpaGetOptionalCols(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ssa_membership_plans'"
    );
    return array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// ── Main ───────────────────────────────────────────────────────────────────

try {
    $pdo = ssaApiCreatePdo();

    // Auth
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

    // Validate id param
    $id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        ssaApiJsonResponse(400, ['error' => 'missing_id', 'message' => 'id is required.']);
    }

    // Check optional columns
    $existingCols   = ssaMpaGetOptionalCols($pdo);
    $hasUpdatedAt   = in_array('updated_at', $existingCols, true);
    $hasBillingType = in_array('billing_type', $existingCols, true);
    $hasAvailFrom   = in_array('available_from', $existingCols, true);
    $hasAvailUntil  = in_array('available_until', $existingCols, true);

    $extraCols = 'p.created_at';
    if ($hasUpdatedAt)   $extraCols .= ', p.updated_at';
    if ($hasBillingType) $extraCols .= ', p.billing_type';
    if ($hasAvailFrom)   $extraCols .= ', p.available_from';
    if ($hasAvailUntil)  $extraCols .= ', p.available_until';

    // ── GET ──────────────────────────────────────────────────────────────────
    if ($method === 'GET') {

        $stmt = $pdo->prepare(
            "SELECT p.id, p.plan_key, p.name, p.display_name, p.description,
                    p.monthly_price_pence, p.currency, p.sort_order,
                    p.is_active, p.is_public, p.parent_plan_id,
                    pp.plan_key AS parent_plan_key,
                    $extraCols,
                    (SELECT COUNT(*) FROM ssa_user_memberships m2
                     WHERE m2.plan_id = p.id AND m2.status = 'active') AS membership_count
             FROM ssa_membership_plans p
             LEFT JOIN ssa_membership_plans pp ON pp.id = p.parent_plan_id
             WHERE p.id = :id
             LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            ssaApiJsonResponse(404, ['error' => 'not_found', 'message' => 'Package not found.']);
        }

        ssaApiJsonResponse(200, [
            'status'  => 'ok',
            'package' => ssaMpaRowToApi($row),
        ]);
    }

    // ── PATCH ─────────────────────────────────────────────────────────────────

    $rawBody = (string)file_get_contents('php://input');
    if (trim($rawBody) === '') {
        ssaApiJsonResponse(400, ['error' => 'empty_body', 'message' => 'Request body must contain JSON.']);
    }
    $body = json_decode($rawBody, true);
    if (!is_array($body)) {
        ssaApiJsonResponse(400, ['error' => 'invalid_json', 'message' => 'Request body must be valid JSON.']);
    }

    // ── Validation ─────────────────────────────────────────────────────────

    $errors = [];

    // displayName: required, max 128
    $displayName = isset($body['displayName']) ? trim(strip_tags((string)$body['displayName'])) : '';
    if ($displayName === '') {
        $errors[] = 'displayName is required.';
    } elseif (mb_strlen($displayName) > 128) {
        $errors[] = 'displayName must be 128 characters or fewer.';
    }

    // description: optional, max 2000
    $description = isset($body['description']) ? trim(strip_tags((string)$body['description'])) : null;
    if ($description !== null && mb_strlen($description) > 2000) {
        $errors[] = 'description must be 2000 characters or fewer.';
    }
    if ($description === '') $description = null;

    // pricePence: required, int >= 0, max 999900
    if (!isset($body['pricePence']) || !is_numeric($body['pricePence'])) {
        $errors[] = 'pricePence is required and must be a number.';
        $pricePence = 0;
    } else {
        $pricePence = (int)$body['pricePence'];
        if ($pricePence < 0 || $pricePence > 999900) {
            $errors[] = 'pricePence must be between 0 and 999900.';
        }
    }

    // currency: optional, defaults GBP, max 3 chars
    $currency = isset($body['currency']) ? strtoupper(trim((string)$body['currency'])) : 'GBP';
    if (mb_strlen($currency) > 3) {
        $errors[] = 'currency must be 3 characters or fewer.';
    }

    // isPublic: bool
    $isPublic = isset($body['isPublic']) ? (bool)$body['isPublic'] : false;

    // isActive: bool
    $isActive = isset($body['isActive']) ? (bool)$body['isActive'] : true;

    // billingType
    $validBillingTypes = ['monthly', 'upfront', 'fixed_term', 'other'];
    $billingType = isset($body['billingType']) ? trim((string)$body['billingType']) : 'monthly';
    if (!in_array($billingType, $validBillingTypes, true)) {
        $errors[] = 'billingType must be one of: ' . implode(', ', $validBillingTypes) . '.';
    }

    // availableFrom / availableUntil: DATE YYYY-MM-DD or null
    $availableFrom = null;
    if (isset($body['availableFrom']) && $body['availableFrom'] !== null && $body['availableFrom'] !== '') {
        $af = trim((string)$body['availableFrom']);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $af)) {
            $errors[] = 'availableFrom must be a date in YYYY-MM-DD format or null.';
        } else {
            $availableFrom = $af;
        }
    }

    $availableUntil = null;
    if (isset($body['availableUntil']) && $body['availableUntil'] !== null && $body['availableUntil'] !== '') {
        $au = trim((string)$body['availableUntil']);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $au)) {
            $errors[] = 'availableUntil must be a date in YYYY-MM-DD format or null.';
        } else {
            $availableUntil = $au;
        }
    }

    if (!empty($errors)) {
        ssaApiJsonResponse(422, ['error' => 'validation_failed', 'message' => implode(' ', $errors), 'errors' => $errors]);
    }

    // ── Concurrency check ──────────────────────────────────────────────────

    $submittedUpdatedAt = isset($body['updatedAt']) ? trim((string)$body['updatedAt']) : '';

    // Fetch current row for concurrency + snapshot
    $curStmt = $pdo->prepare(
        "SELECT p.id, p.plan_key, p.name, p.display_name, p.description,
                p.monthly_price_pence, p.currency, p.sort_order,
                p.is_active, p.is_public, p.parent_plan_id,
                pp.plan_key AS parent_plan_key,
                $extraCols
         FROM ssa_membership_plans p
         LEFT JOIN ssa_membership_plans pp ON pp.id = p.parent_plan_id
         WHERE p.id = :id
         LIMIT 1"
    );
    $curStmt->execute(['id' => $id]);
    $currentRow = $curStmt->fetch(PDO::FETCH_ASSOC);

    if (!$currentRow) {
        ssaApiJsonResponse(404, ['error' => 'not_found', 'message' => 'Package not found.']);
    }

    // Optimistic concurrency: compare updated_at
    if ($hasUpdatedAt && $submittedUpdatedAt !== '') {
        $dbUpdatedAt = (string)($currentRow['updated_at'] ?? '');
        if ($dbUpdatedAt !== $submittedUpdatedAt) {
            ssaApiJsonResponse(409, [
                'error'   => 'conflict',
                'message' => 'This package was modified by someone else. Please reload and try again.',
                'serverUpdatedAt' => $dbUpdatedAt,
            ]);
        }
    }

    // ── Build UPDATE ───────────────────────────────────────────────────────

    $setClauses = [
        'display_name        = :displayName',
        'monthly_price_pence = :pricePence',
        'currency            = :currency',
        'is_public           = :isPublic',
        'is_active           = :isActive',
    ];
    $updateParams = [
        'displayName' => $displayName,
        'pricePence'  => $pricePence,
        'currency'    => $currency,
        'isPublic'    => $isPublic ? 1 : 0,
        'isActive'    => $isActive ? 1 : 0,
        'id'          => $id,
    ];

    // description
    $setClauses[] = 'description = :description';
    $updateParams['description'] = $description;

    if ($hasBillingType) {
        $setClauses[] = 'billing_type = :billingType';
        $updateParams['billingType'] = $billingType;
    }
    if ($hasAvailFrom) {
        $setClauses[] = 'available_from = :availableFrom';
        $updateParams['availableFrom'] = $availableFrom;
    }
    if ($hasAvailUntil) {
        $setClauses[] = 'available_until = :availableUntil';
        $updateParams['availableUntil'] = $availableUntil;
    }
    if ($hasUpdatedAt) {
        $setClauses[] = 'updated_at = UTC_TIMESTAMP()';
    }

    $updateSql = 'UPDATE ssa_membership_plans SET ' . implode(', ', $setClauses) . ' WHERE id = :id';

    $pdo->beginTransaction();
    try {
        $pdo->prepare($updateSql)->execute($updateParams);

        // ── Audit ───────────────────────────────────────────────────────────

        // Determine which fields changed
        $editableFields = [
            'displayName'    => ['display_name', $displayName],
            'description'    => ['description', $description],
            'pricePence'     => ['monthly_price_pence', $pricePence],
            'currency'       => ['currency', $currency],
            'isPublic'       => ['is_public', $isPublic ? 1 : 0],
            'isActive'       => ['is_active', $isActive ? 1 : 0],
        ];
        if ($hasBillingType) {
            $editableFields['billingType'] = ['billing_type', $billingType];
        }
        if ($hasAvailFrom) {
            $editableFields['availableFrom'] = ['available_from', $availableFrom];
        }
        if ($hasAvailUntil) {
            $editableFields['availableUntil'] = ['available_until', $availableUntil];
        }

        $changedFields = [];
        $oldValues     = [];
        $newValues     = [];
        foreach ($editableFields as $apiKey => [$dbCol, $newVal]) {
            $oldVal = $currentRow[$dbCol] ?? null;
            // Normalize bool-like comparisons
            $oldNorm = is_numeric($oldVal) ? (string)(int)$oldVal : (string)($oldVal ?? '');
            $newNorm = is_numeric($newVal) ? (string)(int)$newVal : (string)($newVal ?? '');
            if ($oldNorm !== $newNorm) {
                $changedFields[] = $apiKey;
                $oldValues[$apiKey] = $oldVal;
                $newValues[$apiKey] = $newVal;
            }
        }

        // Caller name
        $callerNameRow = $pdo->prepare('SELECT alias FROM bs_users WHERE uid = :uid LIMIT 1');
        $callerNameRow->execute(['uid' => $callerUid]);
        $callerName = (string)($callerNameRow->fetchColumn() ?: '');

        // Check audit table exists before inserting
        $auditTableStmt = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ssa_membership_package_audit'"
        );
        if ((int)$auditTableStmt->fetchColumn() > 0) {
            $pdo->prepare(
                'INSERT INTO ssa_membership_package_audit
                    (package_id, admin_uid, admin_name_snapshot, action,
                     changed_fields_json, old_values_json, new_values_json, ip_address)
                 VALUES
                    (:packageId, :adminUid, :adminName, :action,
                     :changedFields, :oldValues, :newValues, :ip)'
            )->execute([
                'packageId'     => $id,
                'adminUid'      => $callerUid,
                'adminName'     => $callerName !== '' ? $callerName : null,
                'action'        => 'update',
                'changedFields' => json_encode($changedFields, JSON_UNESCAPED_SLASHES),
                'oldValues'     => json_encode($oldValues, JSON_UNESCAPED_SLASHES),
                'newValues'     => json_encode($newValues, JSON_UNESCAPED_SLASHES),
                'ip'            => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $txEx) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $txEx;
    }

    // Fetch updated row
    $updStmt = $pdo->prepare(
        "SELECT p.id, p.plan_key, p.name, p.display_name, p.description,
                p.monthly_price_pence, p.currency, p.sort_order,
                p.is_active, p.is_public, p.parent_plan_id,
                pp.plan_key AS parent_plan_key,
                $extraCols,
                (SELECT COUNT(*) FROM ssa_user_memberships m2
                 WHERE m2.plan_id = p.id AND m2.status = 'active') AS membership_count
         FROM ssa_membership_plans p
         LEFT JOIN ssa_membership_plans pp ON pp.id = p.parent_plan_id
         WHERE p.id = :id
         LIMIT 1"
    );
    $updStmt->execute(['id' => $id]);
    $updatedRow = $updStmt->fetch(PDO::FETCH_ASSOC);

    ssaApiJsonResponse(200, [
        'status'  => 'ok',
        'package' => ssaMpaRowToApi($updatedRow),
    ]);

} catch (Throwable $e) {
    error_log(sprintf(
        'SSA admin/membership-package.php FAILED [%s] %s in %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ssaApiJsonResponse(500, ['error' => 'server_error', 'message' => 'Unable to process package request.']);
}
