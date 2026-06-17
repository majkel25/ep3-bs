<?php

declare(strict_types=1);

// FILE: tools/ssa_migrate_membership_packages.php

/**
 * CLI-only idempotent migration for SSA membership package expansion.
 *
 * Steps performed:
 *   1. Add parent_plan_id column to ssa_membership_plans (FK to self)
 *   2. ALTER ssa_user_memberships:
 *        a. Add 'legacy_excel_import' to source enum
 *        b. Make current_period_starts_at DATETIME NULL
 *        c. Make current_period_ends_at DATETIME NULL
 *        d. Make cancellation_notice_deadline_at DATETIME NULL
 *        e. Make price_snapshot_pence INT UNSIGNED NULL
 *   3. Seed new ssa_membership_plans rows (INSERT IGNORE)
 *   4. Set parent_plan_id FK references
 *   5. Create ssa_membership_import_batches table (IF NOT EXISTS)
 *   6. Create ssa_membership_import_rows table (IF NOT EXISTS)
 *
 * Safe to re-run: all structural changes are guarded by INFORMATION_SCHEMA checks;
 * seed rows use INSERT IGNORE.
 *
 * Usage:
 *   php tools/ssa_migrate_membership_packages.php [--dry-run]
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

/**
 * Check whether a column exists in a table via INFORMATION_SCHEMA.
 */
function columnExists(PDO $pdo, string $table, string $column): bool
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

/**
 * Return the current COLUMN_TYPE string for a column (e.g. "enum('admin','migration')").
 * Returns empty string if the column does not exist.
 */
function columnType(PDO $pdo, string $table, string $column): string
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = :table
           AND COLUMN_NAME  = :column
         LIMIT 1'
    );
    $stmt->execute(['table' => $table, 'column' => $column]);
    $row = $stmt->fetchColumn();
    return $row !== false ? (string)$row : '';
}

/**
 * Check whether a column IS_NULLABLE = 'YES'.
 */
function columnIsNullable(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = :table
           AND COLUMN_NAME  = :column
         LIMIT 1'
    );
    $stmt->execute(['table' => $table, 'column' => $column]);
    $row = $stmt->fetchColumn();
    return $row !== false && strtoupper((string)$row) === 'YES';
}

/**
 * Check whether a table exists.
 */
function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = :table'
    );
    $stmt->execute(['table' => $table]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Check whether a named FK constraint exists on a table.
 */
function fkExists(PDO $pdo, string $table, string $constraintName): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA      = DATABASE()
           AND TABLE_NAME        = :table
           AND CONSTRAINT_NAME   = :name
           AND CONSTRAINT_TYPE   = \'FOREIGN KEY\''
    );
    $stmt->execute(['table' => $table, 'name' => $constraintName]);
    return (int)$stmt->fetchColumn() > 0;
}

// ─────────────────────────────────────────────────────────────────────────────

try {
    $pdo = ssaApiCreatePdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // =========================================================================
    // STEP 1 — Add parent_plan_id to ssa_membership_plans
    // =========================================================================
    echo "STEP 1: Add parent_plan_id to ssa_membership_plans\n";

    if (columnExists($pdo, 'ssa_membership_plans', 'parent_plan_id')) {
        echo "  SKIP: column already exists\n";
    } else {
        if (!$dryRun) {
            $pdo->exec(
                'ALTER TABLE ssa_membership_plans
                 ADD COLUMN parent_plan_id BIGINT UNSIGNED NULL DEFAULT NULL
                     COMMENT \'FK to ssa_membership_plans.id; NULL = top-level plan\',
                 ADD KEY idx_ssa_membership_plans_parent (parent_plan_id)'
            );
        }
        echo "  OK: column added\n";
    }

    // The FK itself is added in Step 4 after all rows are seeded.

    // =========================================================================
    // STEP 2a — Add 'legacy_excel_import' to ssa_user_memberships.source enum
    // =========================================================================
    echo "\nSTEP 2a: Add 'legacy_excel_import' to ssa_user_memberships.source enum\n";

    $currentSourceType = columnType($pdo, 'ssa_user_memberships', 'source');
    if (str_contains($currentSourceType, 'legacy_excel_import')) {
        echo "  SKIP: enum value already present (type: $currentSourceType)\n";
    } else {
        // Parse existing enum values dynamically so we never drop production values.
        preg_match_all("/'([^']+)'/", $currentSourceType, $enumMatches);
        $existingEnumValues = $enumMatches[1] ?? [];
        if (empty($existingEnumValues)) {
            // Fallback if parsing fails (shouldn't happen).
            $existingEnumValues = ['admin', 'migration', 'user_request', 'system'];
        }
        if (!in_array('legacy_excel_import', $existingEnumValues, true)) {
            $existingEnumValues[] = 'legacy_excel_import';
        }
        $enumDef = implode(',', array_map(static fn(string $v) => "'" . addslashes($v) . "'", $existingEnumValues));
        if (!$dryRun) {
            $pdo->exec(
                "ALTER TABLE ssa_user_memberships
                 MODIFY COLUMN source ENUM($enumDef) NOT NULL DEFAULT 'admin'"
            );
        }
        echo "  OK: enum extended to include 'legacy_excel_import' (preserved existing: " . implode(', ', array_slice($existingEnumValues, 0, -1)) . ")\n";
    }

    // =========================================================================
    // STEP 2b — Make current_period_starts_at DATETIME NULL
    // =========================================================================
    echo "\nSTEP 2b: Make current_period_starts_at DATETIME NULL\n";

    if (columnIsNullable($pdo, 'ssa_user_memberships', 'current_period_starts_at')) {
        echo "  SKIP: already nullable\n";
    } else {
        if (!$dryRun) {
            $pdo->exec(
                'ALTER TABLE ssa_user_memberships
                 MODIFY COLUMN current_period_starts_at DATETIME NULL DEFAULT NULL'
            );
        }
        echo "  OK: column is now DATETIME NULL\n";
    }

    // =========================================================================
    // STEP 2c — Make current_period_ends_at DATETIME NULL
    // =========================================================================
    echo "\nSTEP 2c: Make current_period_ends_at DATETIME NULL\n";

    if (columnIsNullable($pdo, 'ssa_user_memberships', 'current_period_ends_at')) {
        echo "  SKIP: already nullable\n";
    } else {
        if (!$dryRun) {
            $pdo->exec(
                'ALTER TABLE ssa_user_memberships
                 MODIFY COLUMN current_period_ends_at DATETIME NULL DEFAULT NULL'
            );
        }
        echo "  OK: column is now DATETIME NULL\n";
    }

    // =========================================================================
    // STEP 2d — Make cancellation_notice_deadline_at DATETIME NULL
    // =========================================================================
    echo "\nSTEP 2d: Make cancellation_notice_deadline_at DATETIME NULL\n";

    if (columnIsNullable($pdo, 'ssa_user_memberships', 'cancellation_notice_deadline_at')) {
        echo "  SKIP: already nullable\n";
    } else {
        if (!$dryRun) {
            $pdo->exec(
                'ALTER TABLE ssa_user_memberships
                 MODIFY COLUMN cancellation_notice_deadline_at DATETIME NULL DEFAULT NULL'
            );
        }
        echo "  OK: column is now DATETIME NULL\n";
    }

    // =========================================================================
    // STEP 2e — Make price_snapshot_pence INT UNSIGNED NULL
    // =========================================================================
    echo "\nSTEP 2e: Make price_snapshot_pence INT UNSIGNED NULL\n";

    if (columnIsNullable($pdo, 'ssa_user_memberships', 'price_snapshot_pence')) {
        echo "  SKIP: already nullable\n";
    } else {
        if (!$dryRun) {
            $pdo->exec(
                'ALTER TABLE ssa_user_memberships
                 MODIFY COLUMN price_snapshot_pence INT UNSIGNED NULL DEFAULT NULL'
            );
        }
        echo "  OK: column is now INT UNSIGNED NULL\n";
    }

    // =========================================================================
    // STEP 3 — Seed new ssa_membership_plans rows (INSERT IGNORE)
    // =========================================================================
    echo "\nSTEP 3: Seed new ssa_membership_plans rows\n";

    // New plan rows keyed by plan_key.
    // parent_plan_key is resolved to parent_plan_id in Step 4.
    // Rows with is_public=0 are private sub-plans not shown in the general catalogue.
    $newPlans = [
        [
            'plan_key'        => 'SUMMER_STANDARD',
            'name'            => 'Summer Package',
            'display_name'    => 'Summer Package',
            'description'     => null,
            'monthly_price_pence' => 0,
            'currency'        => 'GBP',
            'table_access_summary' => null,
            'coaching_summary'=> null,
            'sort_order'      => 50,
            'is_active'       => 1,
            'is_public'       => 1,
            'parent_plan_key' => null,
        ],
        [
            'plan_key'        => 'RED_STANDARD_UPFRONT',
            'name'            => 'Pay As You Play (Upfront)',
            'display_name'    => 'Pay As You Play (Upfront)',
            'description'     => null,
            'monthly_price_pence' => 0,
            'currency'        => 'GBP',
            'table_access_summary' => null,
            'coaching_summary'=> null,
            'sort_order'      => 11,
            'is_active'       => 1,
            'is_public'       => 0,
            'parent_plan_key' => 'red',
        ],
        [
            'plan_key'        => 'RED_JUNIOR',
            'name'            => 'Pay As You Play (Junior)',
            'display_name'    => 'Pay As You Play (Junior)',
            'description'     => null,
            'monthly_price_pence' => 0,
            'currency'        => 'GBP',
            'table_access_summary' => null,
            'coaching_summary'=> null,
            'sort_order'      => 12,
            'is_active'       => 1,
            'is_public'       => 0,
            'parent_plan_key' => 'red',
        ],
        [
            'plan_key'        => 'RED_NHS',
            'name'            => 'Pay As You Play (NHS Discounted)',
            'display_name'    => 'Pay As You Play (NHS Discounted)',
            'description'     => null,
            'monthly_price_pence' => 0,
            'currency'        => 'GBP',
            'table_access_summary' => null,
            'coaching_summary'=> null,
            'sort_order'      => 13,
            'is_active'       => 1,
            'is_public'       => 0,
            'parent_plan_key' => 'red',
        ],
        [
            'plan_key'        => 'RED_POLICE',
            'name'            => 'Pay As You Play (Police Discounted)',
            'display_name'    => 'Pay As You Play (Police Discounted)',
            'description'     => null,
            'monthly_price_pence' => 0,
            'currency'        => 'GBP',
            'table_access_summary' => null,
            'coaching_summary'=> null,
            'sort_order'      => 14,
            'is_active'       => 1,
            'is_public'       => 0,
            'parent_plan_key' => 'red',
        ],
        [
            'plan_key'        => 'RED_SENIOR',
            'name'            => 'Pay As You Play (Senior Discounted)',
            'display_name'    => 'Pay As You Play (Senior Discounted)',
            'description'     => null,
            'monthly_price_pence' => 0,
            'currency'        => 'GBP',
            'table_access_summary' => null,
            'coaching_summary'=> null,
            'sort_order'      => 15,
            'is_active'       => 1,
            'is_public'       => 0,
            'parent_plan_key' => 'red',
        ],
        [
            'plan_key'        => 'RED_STUDENT',
            'name'            => 'Pay As You Play (Student Discounted)',
            'display_name'    => 'Pay As You Play (Student Discounted)',
            'description'     => null,
            'monthly_price_pence' => 0,
            'currency'        => 'GBP',
            'table_access_summary' => null,
            'coaching_summary'=> null,
            'sort_order'      => 16,
            'is_active'       => 1,
            'is_public'       => 0,
            'parent_plan_key' => 'red',
        ],
        [
            'plan_key'        => 'RED_NHS_UPFRONT',
            'name'            => 'Pay As You Play (Upfront NHS)',
            'display_name'    => 'Pay As You Play (Upfront NHS)',
            'description'     => null,
            'monthly_price_pence' => 0,
            'currency'        => 'GBP',
            'table_access_summary' => null,
            'coaching_summary'=> null,
            'sort_order'      => 17,
            'is_active'       => 1,
            'is_public'       => 0,
            'parent_plan_key' => 'red',
        ],
        [
            'plan_key'        => 'PINK_STANDARD_UPFRONT',
            'name'            => 'Daytime Pass (Upfront)',
            'display_name'    => 'Daytime Pass (Upfront)',
            'description'     => null,
            'monthly_price_pence' => 0,
            'currency'        => 'GBP',
            'table_access_summary' => null,
            'coaching_summary'=> null,
            'sort_order'      => 21,
            'is_active'       => 1,
            'is_public'       => 0,
            'parent_plan_key' => 'pink',
        ],
        [
            'plan_key'        => 'PINK_JUNIOR',
            'name'            => 'Daytime Pass (Junior)',
            'display_name'    => 'Daytime Pass (Junior)',
            'description'     => null,
            'monthly_price_pence' => 0,
            'currency'        => 'GBP',
            'table_access_summary' => null,
            'coaching_summary'=> null,
            'sort_order'      => 22,
            'is_active'       => 1,
            'is_public'       => 0,
            'parent_plan_key' => 'pink',
        ],
        [
            'plan_key'        => 'PINK_SENIOR',
            'name'            => 'Daytime Pass (Senior Discounted)',
            'display_name'    => 'Daytime Pass (Senior Discounted)',
            'description'     => null,
            'monthly_price_pence' => 0,
            'currency'        => 'GBP',
            'table_access_summary' => null,
            'coaching_summary'=> null,
            'sort_order'      => 23,
            'is_active'       => 1,
            'is_public'       => 0,
            'parent_plan_key' => 'pink',
        ],
        [
            'plan_key'        => 'BLACK_SENIOR',
            'name'            => 'All Day Pass (Senior Discounted)',
            'display_name'    => 'All Day Pass (Senior Discounted)',
            'description'     => null,
            'monthly_price_pence' => 0,
            'currency'        => 'GBP',
            'table_access_summary' => null,
            'coaching_summary'=> null,
            'sort_order'      => 31,
            'is_active'       => 1,
            'is_public'       => 0,
            'parent_plan_key' => 'black',
        ],
        [
            'plan_key'        => 'BLACK_DISCOUNTED',
            'name'            => 'All Day Pass (Discounted)',
            'display_name'    => 'All Day Pass (Discounted)',
            'description'     => null,
            'monthly_price_pence' => 0,
            'currency'        => 'GBP',
            'table_access_summary' => null,
            'coaching_summary'=> null,
            'sort_order'      => 32,
            'is_active'       => 1,
            'is_public'       => 0,
            'parent_plan_key' => 'black',
        ],
        [
            'plan_key'        => 'CONCESSION',
            'name'            => 'Concession',
            'display_name'    => 'Concession',
            'description'     => null,
            'monthly_price_pence' => 0,
            'currency'        => 'GBP',
            'table_access_summary' => null,
            'coaching_summary'=> null,
            'sort_order'      => 60,
            'is_active'       => 1,
            'is_public'       => 0,
            'parent_plan_key' => null,
        ],
        [
            'plan_key'        => 'COACH',
            'name'            => 'Coach',
            'display_name'    => 'Coach',
            'description'     => null,
            'monthly_price_pence' => 0,
            'currency'        => 'GBP',
            'table_access_summary' => null,
            'coaching_summary'=> null,
            'sort_order'      => 70,
            'is_active'       => 1,
            'is_public'       => 0,
            'parent_plan_key' => null,
        ],
        [
            'plan_key'        => 'SPECIAL_ARRANGEMENT',
            'name'            => 'Special Arrangement',
            'display_name'    => 'Special Arrangement',
            'description'     => null,
            'monthly_price_pence' => 0,
            'currency'        => 'GBP',
            'table_access_summary' => null,
            'coaching_summary'=> null,
            'sort_order'      => 80,
            'is_active'       => 1,
            'is_public'       => 0,
            'parent_plan_key' => null,
        ],
    ];

    $insertPlanStmt = $pdo->prepare(
        'INSERT IGNORE INTO ssa_membership_plans
            (plan_key, name, display_name, description,
             monthly_price_pence, currency,
             table_access_summary, coaching_summary,
             sort_order, is_active, is_public)
         VALUES
            (:plan_key, :name, :display_name, :description,
             :monthly_price_pence, :currency,
             :table_access_summary, :coaching_summary,
             :sort_order, :is_active, :is_public)'
    );

    foreach ($newPlans as $plan) {
        if ($dryRun) {
            echo "  DRY RUN: would INSERT IGNORE plan_key={$plan['plan_key']}\n";
            continue;
        }
        $insertPlanStmt->execute([
            'plan_key'             => $plan['plan_key'],
            'name'                 => $plan['name'],
            'display_name'         => $plan['display_name'],
            'description'          => $plan['description'],
            'monthly_price_pence'  => $plan['monthly_price_pence'],
            'currency'             => $plan['currency'],
            'table_access_summary' => $plan['table_access_summary'],
            'coaching_summary'     => $plan['coaching_summary'],
            'sort_order'           => $plan['sort_order'],
            'is_active'            => $plan['is_active'],
            'is_public'            => $plan['is_public'],
        ]);
        $affected = $insertPlanStmt->rowCount();
        echo '  ' . ($affected > 0 ? 'OK' : 'SKIP (already exists)') . ": plan_key={$plan['plan_key']}\n";
    }

    // =========================================================================
    // STEP 4 — Set parent_plan_id FK references
    // =========================================================================
    echo "\nSTEP 4: Set parent_plan_id references\n";

    // Build plan_key → id map for all plans we care about.
    $planKeyToId = [];
    if (!$dryRun) {
        $allPlansStmt = $pdo->query(
            'SELECT id, plan_key FROM ssa_membership_plans'
        );
        foreach ($allPlansStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $planKeyToId[$row['plan_key']] = (int)$row['id'];
        }
    }

    // Add the FK constraint on parent_plan_id if not already present.
    $fkName = 'fk_ssa_membership_plans_parent';
    if (!$dryRun && !fkExists($pdo, 'ssa_membership_plans', $fkName)) {
        $pdo->exec(
            "ALTER TABLE ssa_membership_plans
             ADD CONSTRAINT $fkName
             FOREIGN KEY (parent_plan_id) REFERENCES ssa_membership_plans(id)
             ON DELETE SET NULL ON UPDATE CASCADE"
        );
        echo "  OK: FK constraint $fkName added\n";
    } elseif ($dryRun) {
        echo "  DRY RUN: would add FK constraint $fkName if missing\n";
    } else {
        echo "  SKIP: FK constraint $fkName already exists\n";
    }

    // Update parent_plan_id for each child plan.
    $updateParentStmt = $dryRun ? null : $pdo->prepare(
        'UPDATE ssa_membership_plans
         SET parent_plan_id = :parentId
         WHERE plan_key = :planKey
           AND (parent_plan_id IS NULL OR parent_plan_id != :parentId)'
    );

    foreach ($newPlans as $plan) {
        if ($plan['parent_plan_key'] === null) {
            continue;
        }
        $parentKey = $plan['parent_plan_key'];
        if ($dryRun) {
            echo "  DRY RUN: would set parent_plan_id for {$plan['plan_key']} → parent=$parentKey\n";
            continue;
        }
        $parentId = $planKeyToId[$parentKey] ?? null;
        if ($parentId === null) {
            echo "  ERROR: parent plan_key=$parentKey not found — skipping {$plan['plan_key']}\n";
            continue;
        }
        $updateParentStmt->execute([
            'parentId' => $parentId,
            'planKey'  => $plan['plan_key'],
        ]);
        $affected = $updateParentStmt->rowCount();
        echo '  ' . ($affected > 0 ? 'OK' : 'SKIP (already set)') . ": {$plan['plan_key']} → parent={$parentKey} (id=$parentId)\n";
    }

    // =========================================================================
    // STEP 5 — Create ssa_membership_import_batches
    // =========================================================================
    echo "\nSTEP 5: Create ssa_membership_import_batches\n";

    if (!$dryRun && tableExists($pdo, 'ssa_membership_import_batches')) {
        echo "  SKIP: table already exists\n";
    } else {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS ssa_membership_import_batches (
    id              BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    source_filename VARCHAR(255)      NOT NULL,
    source_sha256   CHAR(64)          NOT NULL,
    status          ENUM('pending','running','completed','failed','rolled_back')
                                      NOT NULL DEFAULT 'pending',
    total_rows      INT UNSIGNED      NOT NULL DEFAULT 0,
    matched_rows    INT UNSIGNED      NOT NULL DEFAULT 0,
    unresolved_rows INT UNSIGNED      NOT NULL DEFAULT 0,
    conflict_rows   INT UNSIGNED      NOT NULL DEFAULT 0,
    imported_rows   INT UNSIGNED      NOT NULL DEFAULT 0,
    skipped_rows    INT UNSIGNED      NOT NULL DEFAULT 0,
    started_at      DATETIME          NULL,
    completed_at    DATETIME          NULL,
    created_by      VARCHAR(255)      NULL,
    notes           TEXT              NULL,
    created_at      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_source_sha256 (source_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
        if (!$dryRun) {
            $pdo->exec($sql);
        }
        echo "  " . ($dryRun ? "DRY RUN: would create" : "OK: created") . " ssa_membership_import_batches\n";
    }

    // =========================================================================
    // STEP 6 — Create ssa_membership_import_rows
    // =========================================================================
    echo "\nSTEP 6: Create ssa_membership_import_rows\n";

    if (!$dryRun && tableExists($pdo, 'ssa_membership_import_rows')) {
        echo "  SKIP: table already exists\n";
    } else {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS ssa_membership_import_rows (
    id                           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    batch_id                     BIGINT UNSIGNED  NOT NULL,
    source_row_number            INT UNSIGNED     NOT NULL,
    source_member_name           VARCHAR(255)     NULL,
    source_account_number        VARCHAR(32)      NULL,
    source_email                 VARCHAR(255)     NULL,
    source_mobile                VARCHAR(64)      NULL,
    raw_package_description      VARCHAR(255)     NULL,
    normalised_package_description VARCHAR(255)   NULL,
    resolved_plan_id             BIGINT UNSIGNED  NULL,
    matched_uid                  INT UNSIGNED     NULL,
    match_method                 VARCHAR(64)      NULL
                                     COMMENT 'email|account_no|mobile|name_exact|name_fuzzy_suggestion|unresolved',
    status                       ENUM('imported','skipped','conflict','unresolved','error')
                                     NOT NULL DEFAULT 'unresolved',
    reason                       TEXT             NULL,
    created_at                   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_batch_row (batch_id, source_row_number),
    KEY idx_ssa_import_rows_batch (batch_id),
    KEY idx_ssa_import_rows_status (status),
    KEY idx_ssa_import_rows_matched_uid (matched_uid),
    CONSTRAINT fk_ssa_import_rows_batch
        FOREIGN KEY (batch_id) REFERENCES ssa_membership_import_batches(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_ssa_import_rows_plan
        FOREIGN KEY (resolved_plan_id) REFERENCES ssa_membership_plans(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
        if (!$dryRun) {
            $pdo->exec($sql);
        }
        echo "  " . ($dryRun ? "DRY RUN: would create" : "OK: created") . " ssa_membership_import_rows\n";
    }

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
