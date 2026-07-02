<?php
declare(strict_types=1);

/**
 * CLI-only migration for SSA private booking notes.
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
CREATE TABLE IF NOT EXISTS ssa_booking_private_notes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id INT UNSIGNED NOT NULL,
    uid VARCHAR(255) NOT NULL,
    note VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_booking_private_notes_bid_uid (booking_id, uid),
    KEY idx_booking_private_notes_uid (uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    $pdo->exec($sql);

    echo "OK: ssa_booking_private_notes table exists.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "FAILED: " . $exception->getMessage() . "\n");
    exit(1);
}
