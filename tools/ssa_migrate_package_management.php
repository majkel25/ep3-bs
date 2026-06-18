<?php

declare(strict_types=1);

// FILE: tools/ssa_migrate_package_management.php

/**
 * CLI-only idempotent migration for SSA package management expansion.
 *
 * Steps performed:
 *   1. Add updated_at, billing_type, available_from, available_until to ssa_membership_plans
 *   2. Seed billing_type for existing plans
 *   3. Create ssa_membership_package_audit table
 *   4. Extend ssa_user_membership_addons (cancellation columns)
 *   5. Extend ssa_admin_requests (member_comment column)
 *   6. Ensure is_public = 0 for private plan variants
 *   7. Print rollback SQL (not executed)
 *
 * Usage:
 *   php tools/ssa_migrate_package_management.php [--dry-run]
 */

if (!function_exists('ssaApiJsonResponse')) {
    function ssaApiJsonResponse(int $statusCode, array $payload): void
    {
        throw new RuntimeException(
            'API JSON response called during CLI migration: HTTP ' .
            $statusCode . ' ' .
            json_encode($payload, JSON_UNESCAPED_SLASHES)
        );
    }
}

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../public/api/ssa/v1/_db.php';

$dryRun = in_array('--dry-run', $argv, true);

if ($dryRun) {
    echo "[DRY RUN] No changes will be written.\n\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// Helper functions
// ─────────────────────────────────────────────────────────────────────────────

function pmColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = :table
           AND COLUMN_NAME  = :column'
    );
    $stmt->execute(['table' => $table, 'column' => $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function pmTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = :table'
    );
    $stmt->execute(['table' => $table]);
    return (int)$stmt->fetchColumn() > 0;
}

// ─────────────────────────────────────────────────────────────────────────────

try {
    $pdo = ssaApiCreatePdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // =========================================================================
    // STEP 1 — Add columns to ssa_membership_plans
    // =========================================================================
    echo "STEP 1: Add updated_at, billing_type, available_from, available_until to ssa_membership_plans\n";

    // 1a. updated_at
    if (pmColumnExists($pdo, 'ssa_membership_plans', 'updated_at')) {
        echo "  SKIP: updated_at already exists\n";
    } else {
        if (!$dryRun) {
            $pdo->exec(
                'ALTER TABLE ssa_membership_plans
                 ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
            );
        }
        echo '  ' . ($dryRun ? 'DRY RUN: would add' : 'OK: added') . " updated_at\n";
    }

    // 1b. billing_type
    if (pmColumnExists($pdo, 'ssa_membership_plans', 'billing_type')) {
        echo "  SKIP: billing_type already exists\n";
    } else {
        if (!$dryRun) {
            $pdo->exec(
                "ALTER TABLE ssa_membership_plans
                 ADD COLUMN billing_type ENUM('monthly','upfront','fixed_term','other') NOT NULL DEFAULT 'monthly'"
            );
        }
        echo '  ' . ($dryRun ? 'DRY RUN: would add' : 'OK: added') . " billing_type\n";
    }

    // 1c. available_from
    if (pmColumnExists($pdo, 'ssa_membership_plans', 'available_from')) {
        echo "  SKIP: available_from already exists\n";
    } else {
        if (!$dryRun) {
            $pdo->exec(
                'ALTER TABLE ssa_membership_plans
                 ADD COLUMN available_from DATE NULL'
            );
        }
        echo '  ' . ($dryRun ? 'DRY RUN: would add' : 'OK: added') . " available_from\n";
    }

    // 1d. available_until
    if (pmColumnExists($pdo, 'ssa_membership_plans', 'available_until')) {
        echo "  SKIP: available_until already exists\n";
    } else {
        if (!$dryRun) {
            $pdo->exec(
                'ALTER TABLE ssa_membership_plans
                 ADD COLUMN available_until DATE NULL'
            );
        }
        echo '  ' . ($dryRun ? 'DRY RUN: would add' : 'OK: added') . " available_until\n";
    }

    // =========================================================================
    // STEP 2 — Seed billing_type values for existing plans
    // =========================================================================
    echo "\nSTEP 2: Seed billing_type for existing plans\n";

    if (!pmColumnExists($pdo, 'ssa_membership_plans', 'billing_type')) {
        echo "  SKIP: billing_type column does not exist yet (dry-run or step 1 not applied)\n";
    } else {
        // Upfront plans
        if ($dryRun) {
            echo "  DRY RUN: would UPDATE RED_STANDARD_UPFRONT, RED_NHS_UPFRONT → billing_type='upfront'\n";
            echo "  DRY RUN: would UPDATE PINK_STANDARD_UPFRONT → billing_type='fixed_term'\n";
        } else {
            $upfrontStmt = $pdo->prepare(
                "UPDATE ssa_membership_plans
                 SET billing_type = 'upfront'
                 WHERE plan_key IN ('RED_STANDARD_UPFRONT', 'RED_NHS_UPFRONT')
                   AND billing_type = 'monthly'"
            );
            $upfrontStmt->execute();
            echo '  OK: ' . $upfrontStmt->rowCount() . " row(s) set to 'upfront' (RED_STANDARD_UPFRONT, RED_NHS_UPFRONT)\n";

            $fixedTermStmt = $pdo->prepare(
                "UPDATE ssa_membership_plans
                 SET billing_type = 'fixed_term'
                 WHERE plan_key = 'PINK_STANDARD_UPFRONT'
                   AND billing_type = 'monthly'"
            );
            $fixedTermStmt->execute();
            echo '  OK: ' . $fixedTermStmt->rowCount() . " row(s) set to 'fixed_term' (PINK_STANDARD_UPFRONT)\n";
        }
    }

    // =========================================================================
    // STEP 3 — Create ssa_membership_package_audit
    // =========================================================================
    echo "\nSTEP 3: Create ssa_membership_package_audit\n";

    if (!$dryRun && pmTableExists($pdo, 'ssa_membership_package_audit')) {
        echo "  SKIP: table already exists\n";
    } else {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS ssa_membership_package_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    package_id SMALLINT UNSIGNED NOT NULL,
    admin_uid INT UNSIGNED NOT NULL,
    admin_name_snapshot VARCHAR(255) NULL,
    action VARCHAR(64) NOT NULL COMMENT 'update|create|deactivate|reactivate',
    changed_fields_json JSON NULL,
    old_values_json JSON NULL,
    new_values_json JSON NULL,
    ip_address VARCHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pkg_audit_package_id (package_id),
    KEY idx_pkg_audit_admin_uid (admin_uid),
    KEY idx_pkg_audit_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
        if (!$dryRun) {
            $pdo->exec($sql);
        }
        echo '  ' . ($dryRun ? 'DRY RUN: would create' : 'OK: created') . " ssa_membership_package_audit\n";
    }

    // =========================================================================
    // STEP 4 — Extend ssa_user_membership_addons
    // =========================================================================
    echo "\nSTEP 4: Extend ssa_user_membership_addons (cancellation columns)\n";

    $addonCols = [
        'activated_at'              => 'DATETIME NULL',
        'cancelled_at'              => 'DATETIME NULL',
        'cancellation_effective_at' => "DATETIME NULL COMMENT 'First day of month when addon is scheduled to cancel'",
        'cancellation_request_id'   => "BIGINT UNSIGNED NULL COMMENT 'FK to ssa_admin_requests.id'",
        'member_facing_notes'       => "TEXT NULL COMMENT 'Member-visible notes on cancellation'",
    ];

    foreach ($addonCols as $col => $def) {
        if (pmColumnExists($pdo, 'ssa_user_membership_addons', $col)) {
            echo "  SKIP: $col already exists\n";
        } else {
            if (!$dryRun) {
                $pdo->exec("ALTER TABLE ssa_user_membership_addons ADD COLUMN $col $def");
            }
            echo '  ' . ($dryRun ? 'DRY RUN: would add' : 'OK: added') . " $col\n";
        }
    }

    // =========================================================================
    // STEP 5 — Extend ssa_admin_requests (member_comment)
    // =========================================================================
    echo "\nSTEP 5: Extend ssa_admin_requests (member_comment)\n";

    if (pmColumnExists($pdo, 'ssa_admin_requests', 'member_comment')) {
        echo "  SKIP: member_comment already exists\n";
    } else {
        if (!$dryRun) {
            $pdo->exec(
                "ALTER TABLE ssa_admin_requests
                 ADD COLUMN member_comment TEXT NULL COMMENT 'Member-facing note separate from admin_comment'"
            );
        }
        echo '  ' . ($dryRun ? 'DRY RUN: would add' : 'OK: added') . " member_comment\n";
    }

    // =========================================================================
    // STEP 6 — Ensure is_public = 0 for private plan variants
    // =========================================================================
    echo "\nSTEP 6: Ensure is_public = 0 for private plan variants\n";

    $privatePlanKeys = [
        'RED_STANDARD_UPFRONT',
        'RED_JUNIOR',
        'RED_NHS',
        'RED_NHS_UPFRONT',
        'RED_POLICE',
        'RED_SENIOR',
        'RED_STUDENT',
        'PINK_STANDARD_UPFRONT',
        'PINK_JUNIOR',
        'PINK_SENIOR',
        'BLACK_SENIOR',
        'BLACK_DISCOUNTED',
        'CONCESSION',
        'COACH',
        'SPECIAL_ARRANGEMENT',
    ];

    if ($dryRun) {
        echo '  DRY RUN: would UPDATE ' . count($privatePlanKeys) . " private plan_keys → is_public = 0\n";
    } else {
        $placeholders = implode(',', array_fill(0, count($privatePlanKeys), '?'));
        $privStmt = $pdo->prepare(
            "UPDATE ssa_membership_plans
             SET is_public = 0
             WHERE plan_key IN ($placeholders)
               AND is_public = 1"
        );
        $privStmt->execute($privatePlanKeys);
        echo '  OK: ' . $privStmt->rowCount() . " row(s) set to is_public=0\n";
    }

    // =========================================================================
    // STEP 7 — Print rollback SQL (not executed)
    // =========================================================================
    echo "\nSTEP 7: Rollback SQL (NOT executed — for reference only)\n";
    echo "\n-- ── ROLLBACK SQL ──────────────────────────────────────────────────────────\n";
    echo "-- Run these manually to undo this migration:\n";
    echo "\n";
    echo "ALTER TABLE ssa_membership_plans\n";
    echo "  DROP COLUMN IF EXISTS updated_at,\n";
    echo "  DROP COLUMN IF EXISTS billing_type,\n";
    echo "  DROP COLUMN IF EXISTS available_from,\n";
    echo "  DROP COLUMN IF EXISTS available_until;\n\n";
    echo "ALTER TABLE ssa_user_membership_addons\n";
    echo "  DROP COLUMN IF EXISTS activated_at,\n";
    echo "  DROP COLUMN IF EXISTS cancelled_at,\n";
    echo "  DROP COLUMN IF EXISTS cancellation_effective_at,\n";
    echo "  DROP COLUMN IF EXISTS cancellation_request_id,\n";
    echo "  DROP COLUMN IF EXISTS member_facing_notes;\n\n";
    echo "ALTER TABLE ssa_admin_requests\n";
    echo "  DROP COLUMN IF EXISTS member_comment;\n\n";
    echo "DROP TABLE IF EXISTS ssa_membership_package_audit;\n";
    echo "\n-- ── END ROLLBACK SQL ───────────────────────────────────────────────────────\n";

    // =========================================================================
    // Done
    // =========================================================================
    echo "\nMigration complete" . ($dryRun ? " (DRY RUN — nothing was written)" : "") . ".\n";
    exit(0);

} catch (Throwable $exception) {
    fwrite(STDERR, "FAILED: " . $exception->getMessage() . "\n");
    fwrite(STDERR, $exception->getTraceAsString() . "\n");
    exit(1);
}
