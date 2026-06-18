<?php

declare(strict_types=1);

/**
 * POST /api/ssa/v1/admin/policy-create.php
 *
 * Create a new policy. Admin/Owner only.
 *
 * Body (JSON):
 *   name          string  required
 *   content       string  required
 *   category      string  required  (privacy|terms|membership|bookings|recording_streaming|junior_privacy|account_data|general)
 *   audience      string  optional  (all_members|adult_members|junior_members|admins_only)  default: all_members
 *   status        string  optional  (draft|published)  default: draft
 *   display_order int     optional  default: 100
 *   effective_from string optional  YYYY-MM-DD
 *
 * Response:
 *   status, id, slug, name, version_id, message
 */

require_once __DIR__ . '/../_auth0.php';
require_once __DIR__ . '/../_db.php';
require_once __DIR__ . '/../_policy_schema.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ssaApiJsonResponse(405, ['error' => 'method_not_allowed', 'message' => 'Only POST is allowed.']);
}

$claims   = ssaApiRequireAuth0Claims();
$auth0Sub = trim((string)($claims['sub'] ?? ''));

if ($auth0Sub === '') {
    ssaApiJsonResponse(401, ['error' => 'missing_auth0_subject', 'message' => 'Auth0 token missing subject.']);
}

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    ssaApiJsonResponse(400, ['error' => 'invalid_json', 'message' => 'Request body must be JSON.']);
}

$allowedCategories = ['privacy','terms','membership','bookings','recording_streaming','junior_privacy','account_data','general'];
$allowedAudiences  = ['all_members','adult_members','junior_members','admins_only'];
$allowedStatuses   = ['draft','published'];

try {
    $pdo = ssaApiCreatePdo();
    ssaApiEnsurePolicySchema($pdo);

    // Admin check.
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

    // Validate required fields.
    $name    = trim((string)($body['name']    ?? ''));
    $content = trim((string)($body['content'] ?? ''));
    $cat     = trim((string)($body['category'] ?? ''));

    if ($name === '') {
        ssaApiJsonResponse(422, ['error' => 'validation_error', 'field' => 'name', 'message' => 'Policy name is required.']);
    }
    if (mb_strlen($name) > 255) {
        ssaApiJsonResponse(422, ['error' => 'validation_error', 'field' => 'name', 'message' => 'Policy name must not exceed 255 characters.']);
    }
    if ($content === '') {
        ssaApiJsonResponse(422, ['error' => 'validation_error', 'field' => 'content', 'message' => 'Policy content is required.']);
    }
    if (mb_strlen($content) > 500000) {
        ssaApiJsonResponse(422, ['error' => 'validation_error', 'field' => 'content', 'message' => 'Policy content is too large.']);
    }
    if ($cat === '' || !in_array($cat, $allowedCategories, true)) {
        ssaApiJsonResponse(422, ['error' => 'validation_error', 'field' => 'category', 'message' => 'A valid category is required.']);
    }

    $audience      = (string)($body['audience'] ?? 'all_members');
    $audience      = in_array($audience, $allowedAudiences, true) ? $audience : 'all_members';

    $status        = (string)($body['status'] ?? 'draft');
    $status        = in_array($status, $allowedStatuses, true) ? $status : 'draft';

    $displayOrder  = isset($body['display_order']) && is_numeric($body['display_order'])
                     ? max(0, (int)$body['display_order'])
                     : 100;

    $effectiveFrom = null;
    if (!empty($body['effective_from'])) {
        $ef = trim((string)$body['effective_from']);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ef)) {
            $effectiveFrom = $ef;
        }
    }

    $cleanContent  = ssaApiSanitisePolicyContent($content);
    $slug          = ssaApiGeneratePolicySlug($pdo, $name);

    $pdo->beginTransaction();

    try {
        // Insert policy.
        $ins = $pdo->prepare(
            "INSERT INTO ssa_policies (slug, name, category, audience, status, display_order, created_by_uid)
             VALUES (:slug, :name, :category, :audience, :status, :display_order, :uid)"
        );
        $ins->execute([
            'slug'          => $slug,
            'name'          => $name,
            'category'      => $cat,
            'audience'      => $audience,
            'status'        => $status,
            'display_order' => $displayOrder,
            'uid'           => $callerUid,
        ]);
        $policyId = (int)$pdo->lastInsertId();

        // Insert version 1.
        $pubAt = $status === 'published' ? date('Y-m-d H:i:s') : null;
        $vIns  = $pdo->prepare(
            "INSERT INTO ssa_policy_versions (policy_id, version_number, content, effective_from, published_at, created_by_uid)
             VALUES (:pid, 1, :content, :ef, :pub, :uid)"
        );
        $vIns->execute([
            'pid'     => $policyId,
            'content' => $cleanContent,
            'ef'      => $effectiveFrom,
            'pub'     => $pubAt,
            'uid'     => $callerUid,
        ]);
        $versionId = (int)$pdo->lastInsertId();

        // Link version to policy.
        $pdo->prepare(
            "UPDATE ssa_policies SET current_version_id = :vid WHERE id = :id"
        )->execute(['vid' => $versionId, 'id' => $policyId]);

        // Audit.
        ssaApiPolicyAudit($pdo, $policyId, $versionId, 'created', null, [
            'name'     => $name,
            'category' => $cat,
            'audience' => $audience,
            'status'   => $status,
        ], $callerUid);

        if ($status === 'published') {
            ssaApiPolicyAudit($pdo, $policyId, $versionId, 'published', null, [
                'version_number' => 1,
                'published_at'   => $pubAt,
            ], $callerUid);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    ssaApiJsonResponse(200, [
        'status'     => 'ok',
        'id'         => $policyId,
        'slug'       => $slug,
        'name'       => $name,
        'version_id' => $versionId,
        'message'    => $status === 'published' ? 'Policy published.' : 'Policy saved as draft.',
    ]);
} catch (Throwable $e) {
    error_log('SSA admin/policy-create.php failed: ' . $e->getMessage());
    ssaApiJsonResponse(500, ['error' => 'server_error', 'message' => 'Unable to create policy.']);
}
