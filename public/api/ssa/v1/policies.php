<?php

declare(strict_types=1);

/**
 * GET /api/ssa/v1/policies.php
 *
 * Returns published, non-archived, currently-effective policies for the authenticated member,
 * grouped by category.
 *
 * Auth: Auth0 bearer token required.
 *
 * Response:
 *   status, categories[]
 *     Each category: code, name, policies[]
 *       Each policy: id, slug, name, content, version, effectiveFrom, displayOrder, updatedAt
 *
 * Legacy flat format still available via ?format=flat  (for backward compat only).
 */

require_once __DIR__ . '/_auth0.php';
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_policy_schema.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ssaApiJsonResponse(405, ['error' => 'method_not_allowed', 'message' => 'Only GET is allowed.']);
}

$claims   = ssaApiRequireAuth0Claims();
$auth0Sub = trim((string)($claims['sub'] ?? ''));

if ($auth0Sub === '') {
    ssaApiJsonResponse(401, ['error' => 'missing_auth0_subject', 'message' => 'Auth0 token missing subject.']);
}

$flatFormat = isset($_GET['format']) && $_GET['format'] === 'flat';

// Category code → display label mapping (deterministic order).
const SSA_POLICY_CATEGORIES = [
    'privacy'              => 'Privacy',
    'terms'                => 'Terms & Conditions',
    'membership'           => 'Membership',
    'bookings'             => 'Bookings',
    'recording_streaming'  => 'Recording & Streaming',
    'junior_privacy'       => 'Junior Members',
    'account_data'         => 'Account & Data',
    'general'              => 'General Legal',
];

try {
    $pdo = ssaApiCreatePdo();
    ssaApiEnsurePolicySchema($pdo);

    // Determine member's audience eligibility.
    $linkStmt = $pdo->prepare(
        'SELECT uid FROM ssa_auth0_user_links WHERE auth0_sub = :sub AND revoked_at IS NULL LIMIT 1'
    );
    $linkStmt->execute(['sub' => $auth0Sub]);
    $link = $linkStmt->fetch();

    if (!is_array($link) || (int)($link['uid'] ?? 0) <= 0) {
        ssaApiJsonResponse(403, ['error' => 'not_linked', 'message' => 'No linked account found.']);
    }

    $memberUid  = (int)$link['uid'];
    $memberType = ssaApiGetUserMetaValue($pdo, $memberUid, 'ssa.user_type') ?? 'member';
    $isJunior   = ssaApiGetUserMetaValue($pdo, $memberUid, 'ssa.membership_package_type') === 'junior';

    // Admins/owners can see all published policies.
    $isAdmin = in_array($memberType, ['admin', 'club_owner'], true);

    // Build audience filter: members see 'all_members' + 'adult_members' (unless junior) + 'junior_members' (if junior).
    // Admins see everything except 'admins_only' is returned only to admins.
    if ($isAdmin) {
        $audienceValues = "('all_members','adult_members','junior_members','admins_only')";
    } elseif ($isJunior) {
        $audienceValues = "('all_members','junior_members')";
    } else {
        $audienceValues = "('all_members','adult_members')";
    }

    $today = date('Y-m-d');

    $stmt = $pdo->prepare("
        SELECT
            p.id,
            p.slug,
            p.name,
            p.category,
            p.audience,
            p.display_order,
            v.version_number,
            v.content,
            DATE_FORMAT(v.effective_from, '%Y-%m-%d') AS effective_from,
            DATE_FORMAT(p.updated_at, '%d %b %Y') AS updated_at
        FROM ssa_policies p
        LEFT JOIN ssa_policy_versions v ON v.id = p.current_version_id
        WHERE p.status = 'published'
          AND p.is_archived = 0
          AND p.audience IN $audienceValues
          AND (v.effective_from IS NULL OR v.effective_from <= :today)
        ORDER BY p.category ASC, p.display_order ASC, p.name ASC
    ");
    $stmt->execute(['today' => $today]);

    $rows = $stmt->fetchAll();

    if ($flatFormat) {
        // Legacy flat response for old app versions.
        $policies = [];
        foreach ($rows as $row) {
            $policies[] = [
                'id'         => (int)$row['id'],
                'slug'       => $row['slug'],
                'title'      => $row['name'],
                'content'    => $row['content'],
                'version'    => $row['version_number'] ? (string)$row['version_number'] : null,
                'updated_at' => $row['updated_at'],
            ];
        }
        ssaApiJsonResponse(200, ['status' => 'ok', 'policies' => $policies]);
    }

    // Group by category.
    $grouped = [];
    foreach ($rows as $row) {
        $code = (string)$row['category'];
        if (!isset($grouped[$code])) {
            $grouped[$code] = [];
        }
        $grouped[$code][] = [
            'id'           => (int)$row['id'],
            'slug'         => $row['slug'],
            'name'         => $row['name'],
            'content'      => $row['content'],
            'version'      => $row['version_number'] ? (int)$row['version_number'] : null,
            'effectiveFrom' => $row['effective_from'],
            'displayOrder' => (int)$row['display_order'],
            'updatedAt'    => $row['updated_at'],
        ];
    }

    // Build ordered category list — only include categories that have policies.
    $categories = [];
    foreach (SSA_POLICY_CATEGORIES as $code => $label) {
        if (isset($grouped[$code]) && !empty($grouped[$code])) {
            $categories[] = [
                'code'     => $code,
                'name'     => $label,
                'policies' => $grouped[$code],
            ];
        }
    }
    // Any uncategorised/custom codes not in the constant go at the end.
    foreach ($grouped as $code => $policies) {
        if (!isset(SSA_POLICY_CATEGORIES[$code])) {
            $categories[] = [
                'code'     => $code,
                'name'     => ucfirst(str_replace('_', ' ', $code)),
                'policies' => $policies,
            ];
        }
    }

    ssaApiJsonResponse(200, [
        'status'     => 'ok',
        'categories' => $categories,
    ]);
} catch (Throwable $e) {
    error_log('SSA policies.php failed: ' . $e->getMessage());
    ssaApiJsonResponse(500, ['error' => 'server_error', 'message' => 'Unable to load policies.']);
}
