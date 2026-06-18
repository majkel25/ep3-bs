<?php

declare(strict_types=1);

/**
 * POST /api/ssa/v1/admin/policy-update.php
 *
 * Update an existing policy. Admin/Owner only.
 * Supports optimistic concurrency via updated_at timestamp.
 *
 * Body (JSON):
 *   id              int     required
 *   updated_at      string  required  – client's last-known updated_at (ISO 8601); used for conflict detection
 *   name            string  optional
 *   content         string  optional  – if provided, creates a new version
 *   category        string  optional
 *   audience        string  optional
 *   display_order   int     optional
 *   effective_from  string  optional  YYYY-MM-DD
 *
 * Response:
 *   status, id, version_id, version_number, message
 *
 * Errors:
 *   409 – stale update (another admin changed the policy)
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

    $policyId        = isset($body['id']) && is_numeric($body['id']) ? (int)$body['id'] : 0;
    $clientUpdatedAt = trim((string)($body['updated_at'] ?? ''));

    if ($policyId <= 0) {
        ssaApiJsonResponse(400, ['error' => 'missing_id', 'message' => 'Policy id is required.']);
    }
    if ($clientUpdatedAt === '') {
        ssaApiJsonResponse(400, ['error' => 'missing_updated_at', 'message' => 'updated_at is required for conflict detection.']);
    }

    $pdo->beginTransaction();

    try {
        // Lock row and check conflict.
        $lockStmt = $pdo->prepare(
            'SELECT id, name, category, audience, status, display_order, current_version_id, is_archived,
                    DATE_FORMAT(updated_at, \'%Y-%m-%dT%H:%i:%sZ\') AS updated_at
             FROM ssa_policies WHERE id = :id FOR UPDATE'
        );
        $lockStmt->execute(['id' => $policyId]);
        $existing = $lockStmt->fetch();

        if (!$existing) {
            $pdo->rollBack();
            ssaApiJsonResponse(404, ['error' => 'not_found', 'message' => 'Policy not found.']);
        }

        if ((string)$existing['updated_at'] !== $clientUpdatedAt) {
            $pdo->rollBack();
            ssaApiJsonResponse(409, [
                'error'   => 'conflict',
                'message' => 'This policy was changed by another administrator. Reload the latest version before continuing.',
            ]);
        }

        $oldValues = [
            'name'          => $existing['name'],
            'category'      => $existing['category'],
            'audience'      => $existing['audience'],
            'display_order' => $existing['display_order'],
        ];

        $fields = [];
        $params = ['id' => $policyId];

        if (isset($body['name'])) {
            $name = trim((string)$body['name']);
            if ($name === '') {
                $pdo->rollBack();
                ssaApiJsonResponse(422, ['error' => 'validation_error', 'field' => 'name', 'message' => 'Policy name is required.']);
            }
            $fields[] = 'name = :name';
            $params['name'] = $name;
        }
        if (isset($body['category']) && in_array($body['category'], $allowedCategories, true)) {
            $fields[] = 'category = :category';
            $params['category'] = $body['category'];
        }
        if (isset($body['audience']) && in_array($body['audience'], $allowedAudiences, true)) {
            $fields[] = 'audience = :audience';
            $params['audience'] = $body['audience'];
        }
        if (isset($body['display_order']) && is_numeric($body['display_order'])) {
            $fields[] = 'display_order = :display_order';
            $params['display_order'] = max(0, (int)$body['display_order']);
        }

        $newVersionId     = null;
        $newVersionNumber = null;

        // If content provided, create a new version.
        if (isset($body['content'])) {
            $newContent = trim((string)$body['content']);
            if ($newContent === '') {
                $pdo->rollBack();
                ssaApiJsonResponse(422, ['error' => 'validation_error', 'field' => 'content', 'message' => 'Policy content is required.']);
            }

            $cleanContent = ssaApiSanitisePolicyContent($newContent);

            // Get current version number.
            $curVerStmt = $pdo->prepare(
                'SELECT COALESCE(MAX(version_number), 0) AS max_ver FROM ssa_policy_versions WHERE policy_id = :pid'
            );
            $curVerStmt->execute(['pid' => $policyId]);
            $maxVer = (int)($curVerStmt->fetchColumn() ?: 0);
            $newVersionNumber = $maxVer + 1;

            $effectiveFrom = null;
            if (!empty($body['effective_from'])) {
                $ef = trim((string)$body['effective_from']);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ef)) {
                    $effectiveFrom = $ef;
                }
            }

            $vIns = $pdo->prepare(
                "INSERT INTO ssa_policy_versions (policy_id, version_number, content, effective_from, created_by_uid)
                 VALUES (:pid, :vnum, :content, :ef, :uid)"
            );
            $vIns->execute([
                'pid'     => $policyId,
                'vnum'    => $newVersionNumber,
                'content' => $cleanContent,
                'ef'      => $effectiveFrom,
                'uid'     => $callerUid,
            ]);
            $newVersionId = (int)$pdo->lastInsertId();

            $fields[] = 'current_version_id = :current_version_id';
            $params['current_version_id'] = $newVersionId;
        }

        if (!empty($fields)) {
            $upd = $pdo->prepare(
                'UPDATE ssa_policies SET ' . implode(', ', $fields) . ' WHERE id = :id'
            );
            $upd->execute($params);
        }

        $newValues = array_intersect_key($params, array_flip(['name','category','audience','display_order']));
        ssaApiPolicyAudit($pdo, $policyId, $newVersionId, 'updated', $oldValues, $newValues ?: null, $callerUid);

        if ($newVersionId) {
            ssaApiPolicyAudit($pdo, $policyId, $newVersionId, 'content_changed', null, [
                'version_number' => $newVersionNumber,
            ], $callerUid);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    ssaApiJsonResponse(200, [
        'status'         => 'ok',
        'id'             => $policyId,
        'version_id'     => $newVersionId,
        'version_number' => $newVersionNumber,
        'message'        => 'Policy updated.',
    ]);
} catch (Throwable $e) {
    error_log('SSA admin/policy-update.php failed: ' . $e->getMessage());
    ssaApiJsonResponse(500, ['error' => 'server_error', 'message' => 'Unable to update policy.']);
}
