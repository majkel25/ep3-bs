<?php

declare(strict_types=1);

/**
 * Idempotent schema migration for the dynamic policy system.
 *
 * Call ssaApiEnsurePolicySchema($pdo) at the start of any policy endpoint.
 * Each ALTER is guarded by an existence check so it is safe to call repeatedly.
 */

function ssaApiEnsurePolicySchema(PDO $pdo): void
{
    // ------------------------------------------------------------------ base table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ssa_policies (
            id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            slug             VARCHAR(128)  NOT NULL,
            name             VARCHAR(255)  NOT NULL,
            category         VARCHAR(64)   NOT NULL DEFAULT 'general',
            audience         VARCHAR(32)   NOT NULL DEFAULT 'all_members',
            status           ENUM('draft','published','unpublished','archived')
                                           NOT NULL DEFAULT 'draft',
            display_order    INT           NOT NULL DEFAULT 100,
            current_version_id INT UNSIGNED NULL,
            is_archived      TINYINT(1)    NOT NULL DEFAULT 0,
            created_by_uid   INT           NULL,
            created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                    ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_slug (slug),
            KEY idx_status_cat_order (status, category, display_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // ------------------------------------------------------------------ add columns if missing (safe for existing installs)
    $existingCols = [];
    $cols = $pdo->query("SHOW COLUMNS FROM ssa_policies");
    foreach ($cols->fetchAll() as $col) {
        $existingCols[] = strtolower((string)$col['Field']);
    }

    if (!in_array('name', $existingCols, true)) {
        // Rename legacy 'title' to 'name' when migrating old schema
        if (in_array('title', $existingCols, true)) {
            $pdo->exec("ALTER TABLE ssa_policies CHANGE COLUMN title name VARCHAR(255) NOT NULL");
        } else {
            $pdo->exec("ALTER TABLE ssa_policies ADD COLUMN name VARCHAR(255) NOT NULL DEFAULT '' AFTER slug");
        }
    }

    if (!in_array('category', $existingCols, true)) {
        $pdo->exec("ALTER TABLE ssa_policies ADD COLUMN category VARCHAR(64) NOT NULL DEFAULT 'general' AFTER name");
    }
    if (!in_array('audience', $existingCols, true)) {
        $pdo->exec("ALTER TABLE ssa_policies ADD COLUMN audience VARCHAR(32) NOT NULL DEFAULT 'all_members' AFTER category");
    }
    if (!in_array('status', $existingCols, true)) {
        // Migrate legacy 'active' column
        if (in_array('active', $existingCols, true)) {
            $pdo->exec("ALTER TABLE ssa_policies ADD COLUMN status ENUM('draft','published','unpublished','archived') NOT NULL DEFAULT 'draft' AFTER audience");
            $pdo->exec("UPDATE ssa_policies SET status = CASE WHEN active = 1 THEN 'published' ELSE 'unpublished' END");
        } else {
            $pdo->exec("ALTER TABLE ssa_policies ADD COLUMN status ENUM('draft','published','unpublished','archived') NOT NULL DEFAULT 'draft' AFTER audience");
        }
    }
    if (!in_array('display_order', $existingCols, true)) {
        $pdo->exec("ALTER TABLE ssa_policies ADD COLUMN display_order INT NOT NULL DEFAULT 100 AFTER status");
    }
    if (!in_array('current_version_id', $existingCols, true)) {
        $pdo->exec("ALTER TABLE ssa_policies ADD COLUMN current_version_id INT UNSIGNED NULL AFTER display_order");
    }
    if (!in_array('is_archived', $existingCols, true)) {
        $pdo->exec("ALTER TABLE ssa_policies ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER current_version_id");
    }
    if (!in_array('created_by_uid', $existingCols, true)) {
        $pdo->exec("ALTER TABLE ssa_policies ADD COLUMN created_by_uid INT NULL AFTER is_archived");
    }

    // ------------------------------------------------------------------ versions table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ssa_policy_versions (
            id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            policy_id        INT UNSIGNED  NOT NULL,
            version_number   INT UNSIGNED  NOT NULL DEFAULT 1,
            content          LONGTEXT      NOT NULL,
            effective_from   DATE          NULL,
            published_at     DATETIME      NULL,
            created_by_uid   INT           NULL,
            created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_policy_version (policy_id, version_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // ------------------------------------------------------------------ audit table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ssa_policy_audit (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            policy_id    INT UNSIGNED NOT NULL,
            version_id   INT UNSIGNED NULL,
            action       VARCHAR(64)  NOT NULL,
            old_values   JSON         NULL,
            new_values   JSON         NULL,
            admin_uid    INT          NOT NULL,
            created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_policy_id (policy_id),
            KEY idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // ------------------------------------------------------------------ migrate existing content rows to versions table
    // For old policies that have content but no current_version_id, create version 1.
    $unversioned = $pdo->query(
        "SELECT p.id, p.content, p.version AS old_version, p.created_by_uid, p.created_at
         FROM ssa_policies p
         WHERE p.current_version_id IS NULL
           AND p.content IS NOT NULL
           AND p.content <> ''"
    );

    foreach ($unversioned->fetchAll() as $row) {
        $pdo->prepare(
            "INSERT INTO ssa_policy_versions (policy_id, version_number, content, created_by_uid, created_at)
             VALUES (:pid, 1, :content, :uid, :ca)"
        )->execute([
            'pid'     => (int)$row['id'],
            'content' => $row['content'],
            'uid'     => $row['created_by_uid'],
            'ca'      => $row['created_at'],
        ]);

        $versionId = (int)$pdo->lastInsertId();

        $pdo->prepare(
            "UPDATE ssa_policies SET current_version_id = :vid WHERE id = :id"
        )->execute(['vid' => $versionId, 'id' => (int)$row['id']]);
    }
}

/**
 * Sanitise policy content — strips executable HTML/script tags, preserves Markdown-safe text.
 */
function ssaApiSanitisePolicyContent(string $raw): string
{
    // Strip script/style tags and their content.
    $cleaned = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $raw);
    $cleaned = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $cleaned ?? '');
    // Strip all remaining HTML tags (Markdown plain text is fine).
    $cleaned = strip_tags($cleaned ?? '');
    // Normalise line endings.
    $cleaned = str_replace(["\r\n", "\r"], "\n", $cleaned);
    return trim($cleaned);
}

/**
 * Generate a slug from a name.  Output is lowercase alphanumeric + hyphens, max 128 chars.
 */
function ssaApiGeneratePolicySlug(PDO $pdo, string $name): string
{
    $base = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($name)));
    $base = trim($base ?? '', '-');
    $base = substr($base ?: 'policy', 0, 100);

    $slug      = $base;
    $suffix    = 2;
    while (true) {
        $check = $pdo->prepare('SELECT id FROM ssa_policies WHERE slug = :slug LIMIT 1');
        $check->execute(['slug' => $slug]);
        if (!$check->fetch()) {
            break;
        }
        $slug = $base . '-' . $suffix;
        $suffix++;
    }
    return $slug;
}

/**
 * Write an audit record.
 */
function ssaApiPolicyAudit(
    PDO     $pdo,
    int     $policyId,
    ?int    $versionId,
    string  $action,
    ?array  $oldValues,
    ?array  $newValues,
    int     $adminUid
): void {
    $pdo->prepare(
        "INSERT INTO ssa_policy_audit (policy_id, version_id, action, old_values, new_values, admin_uid)
         VALUES (:pid, :vid, :action, :old, :new, :uid)"
    )->execute([
        'pid'    => $policyId,
        'vid'    => $versionId,
        'action' => $action,
        'old'    => $oldValues ? json_encode($oldValues) : null,
        'new'    => $newValues ? json_encode($newValues) : null,
        'uid'    => $adminUid,
    ]);
}
