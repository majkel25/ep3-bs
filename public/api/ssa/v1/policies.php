<?php

declare(strict_types=1);

/**
 * GET /api/ssa/v1/policies.php
 *
 * Returns active policies/legal documents from ssa_policies.
 * Auth: Auth0 bearer token required.
 *
 * Response:
 *   status, policies[]
 *     Each policy: id, slug, title, content, version, updated_at
 */

require_once __DIR__ . '/_auth0.php';
require_once __DIR__ . '/_db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ssaApiJsonResponse(405, ['error' => 'method_not_allowed', 'message' => 'Only GET is allowed.']);
}

// Auth required but role check not needed — all linked members can read policies.
$claims   = ssaApiRequireAuth0Claims();
$auth0Sub = trim((string)($claims['sub'] ?? ''));

if ($auth0Sub === '') {
    ssaApiJsonResponse(401, ['error' => 'missing_auth0_subject', 'message' => 'Auth0 token missing subject.']);
}

try {
    $pdo = ssaApiCreatePdo();

    // Ensure the table exists (idempotent).
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

    $stmt = $pdo->query(
        "SELECT id, slug, title, content, version,
                DATE_FORMAT(updated_at, '%d %b %Y') AS updated_at
         FROM ssa_policies
         WHERE active = 1
         ORDER BY title ASC"
    );

    $policies = [];
    foreach ($stmt->fetchAll() as $row) {
        $policies[] = [
            'id'         => (int)$row['id'],
            'slug'       => $row['slug'],
            'title'      => $row['title'],
            'content'    => $row['content'],
            'version'    => $row['version'],
            'updated_at' => $row['updated_at'],
        ];
    }

    ssaApiJsonResponse(200, [
        'status'   => 'ok',
        'policies' => $policies,
    ]);
} catch (Throwable $e) {
    error_log('SSA policies.php failed: ' . $e->getMessage());
    ssaApiJsonResponse(500, ['error' => 'server_error', 'message' => 'Unable to load policies.']);
}
