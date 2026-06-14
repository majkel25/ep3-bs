<?php

declare(strict_types=1);

/**
 * POST /api/ssa/v1/admin/policy.php
 *
 * Create or update a policy document. Admin/Owner only.
 *
 * Body (JSON):
 *   id       int     – if provided, updates that policy; otherwise creates
 *   slug     string  – URL-safe identifier (required for create)
 *   title    string  – display title (required for create)
 *   content  string  – policy body text
 *   version  string  – e.g. "1.0"
 *   active   bool    – defaults true
 *
 * Response:
 *   status, id, slug, title, message
 */

require_once __DIR__ . '/../_auth0.php';
require_once __DIR__ . '/../_db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ssaApiJsonResponse(405, ['error' => 'method_not_allowed', 'message' => 'Only POST is allowed.']);
}

$claims   = ssaApiRequireAuth0Claims();
$auth0Sub = trim((string)($claims['sub'] ?? ''));

if ($auth0Sub === '') {
    ssaApiJsonResponse(401, ['error' => 'missing_auth0_subject', 'message' => 'Auth0 token missing subject.']);
}

$rawBody = (string)file_get_contents('php://input');
$body    = [];
if ($rawBody !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
}

try {
    $pdo = ssaApiCreatePdo();

    // Auth check.
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

    // Ensure table exists.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ssa_policies (
            id         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
            slug       VARCHAR(128)    NOT NULL,
            title      VARCHAR(255)    NOT NULL,
            content    LONGTEXT        NULL,
            version    VARCHAR(32)     NULL,
            active     TINYINT(1)      NOT NULL DEFAULT 1,
            created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $policyId = isset($body['id']) && is_numeric($body['id']) ? (int)$body['id'] : null;
    $slug     = isset($body['slug'])    ? trim((string)$body['slug'])    : null;
    $title    = isset($body['title'])   ? trim((string)$body['title'])   : null;
    $content  = isset($body['content']) ? (string)$body['content']       : null;
    $version  = isset($body['version']) ? trim((string)$body['version']) : null;
    $active   = isset($body['active'])  ? (bool)$body['active']          : true;

    if ($policyId !== null) {
        // Update existing.
        $fields = [];
        $params = ['id' => $policyId];

        if ($slug    !== null) { $fields[] = 'slug = :slug';    $params['slug']    = $slug; }
        if ($title   !== null) { $fields[] = 'title = :title';  $params['title']   = $title; }
        if ($content !== null) { $fields[] = 'content = :content'; $params['content'] = $content; }
        if ($version !== null) { $fields[] = 'version = :version'; $params['version'] = $version; }
        $fields[] = 'active = :active';
        $params['active'] = (int)$active;

        if (empty($fields)) {
            ssaApiJsonResponse(400, ['error' => 'nothing_to_update', 'message' => 'No fields provided.']);
        }

        $upd = $pdo->prepare('UPDATE ssa_policies SET ' . implode(', ', $fields) . ' WHERE id = :id');
        $upd->execute($params);

        $row = $pdo->prepare('SELECT id, slug, title FROM ssa_policies WHERE id = :id LIMIT 1');
        $row->execute(['id' => $policyId]);
        $updated = $row->fetch();

        ssaApiJsonResponse(200, [
            'status'  => 'ok',
            'id'      => $policyId,
            'slug'    => $updated['slug'] ?? $slug,
            'title'   => $updated['title'] ?? $title,
            'message' => 'Policy updated.',
        ]);
    } else {
        // Create.
        if (!$slug || !$title) {
            ssaApiJsonResponse(400, ['error' => 'missing_fields', 'message' => 'slug and title are required for new policies.']);
        }

        $ins = $pdo->prepare(
            'INSERT INTO ssa_policies (slug, title, content, version, active)
             VALUES (:slug, :title, :content, :version, :active)'
        );
        $ins->execute([
            'slug'    => $slug,
            'title'   => $title,
            'content' => $content,
            'version' => $version,
            'active'  => (int)$active,
        ]);

        $newId = (int)$pdo->lastInsertId();

        ssaApiJsonResponse(200, [
            'status'  => 'ok',
            'id'      => $newId,
            'slug'    => $slug,
            'title'   => $title,
            'message' => 'Policy created.',
        ]);
    }
} catch (Throwable $e) {
    error_log('SSA admin/policy.php failed: ' . $e->getMessage());
    ssaApiJsonResponse(500, ['error' => 'server_error', 'message' => 'Unable to save policy.']);
}
