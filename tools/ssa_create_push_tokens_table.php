<?php
declare(strict_types=1);

/**
 * CLI-only migration for SSA push token storage.
 * Defines a fallback ssaApiJsonResponse() because _db.php may reference it.
 */
if (!function_exists('ssaApiJsonResponse')) {
    function ssaApiJsonResponse(int $statusCode, array $payload): void
    {
        throw new RuntimeException(
            'API JSON response called during CLI migration: HTTP ' .
            $statusCode .
            ' ' .
            json_encode($payload)
        );
    }
}

require_once __DIR__ . '/../public/api/ssa/v1/_db.php';

try {
    $pdo = ssaApiCreatePdo();

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS ssa_push_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    auth0_sub VARCHAR(255) NOT NULL,
    uid INT UNSIGNED NOT NULL,
    device_token VARCHAR(512) NOT NULL,
    device_token_hash CHAR(64) NOT NULL,
    platform VARCHAR(20) NOT NULL DEFAULT 'ios',
    environment VARCHAR(32) NOT NULL DEFAULT 'development',
    app_version VARCHAR(64) NULL,
    device_name VARCHAR(255) NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_seen_at DATETIME NULL,
    disabled_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ssa_push_tokens_device_token_hash (device_token_hash),
    KEY idx_ssa_push_tokens_auth0_sub (auth0_sub),
    KEY idx_ssa_push_tokens_uid (uid),
    KEY idx_ssa_push_tokens_enabled (enabled),
    KEY idx_ssa_push_tokens_environment (environment)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    $pdo->exec($sql);

    echo "OK: ssa_push_tokens table exists.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "FAILED: " . $exception->getMessage() . "\n");
    exit(1);
}