<?php

declare(strict_types=1);

/**
 * CLI-only idempotent migration for SSA membership tables.
 *
 * Creates ssa_membership_plans, ssa_membership_plan_benefits,
 * ssa_user_memberships, ssa_membership_addons, ssa_user_membership_addons,
 * seeds initial plan/benefit/addon rows, and assigns linked users who do not
 * yet have a current membership to the Standard plan.
 *
 * Safe to re-run: all CREATE TABLE statements use IF NOT EXISTS; seed rows use
 * INSERT IGNORE; user assignment only touches users with no active membership.
 *
 * Usage:
 *   php tools/ssa_migrate_memberships.php [--dry-run]
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

try {
    $pdo = ssaApiCreatePdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // -------------------------------------------------------------------------
    // 1. ssa_membership_plans
    // -------------------------------------------------------------------------
    $step = 'CREATE TABLE ssa_membership_plans';
    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS ssa_membership_plans (
    id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(64) NOT NULL,
    name VARCHAR(128) NOT NULL,
    description TEXT NULL,
    price_pence INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Monthly price in GBP pence; 0 = not yet set',
    priority TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Display order, ascending',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = available for new sign-ups',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ssa_membership_plans_slug (slug),
    KEY idx_ssa_membership_plans_priority (priority),
    KEY idx_ssa_membership_plans_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    if (!$dryRun) {
        $pdo->exec($sql);
    }
    echo "OK: $step\n";

    // -------------------------------------------------------------------------
    // 2. ssa_membership_plan_benefits
    // -------------------------------------------------------------------------
    $step = 'CREATE TABLE ssa_membership_plan_benefits';
    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS ssa_membership_plan_benefits (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    plan_id SMALLINT UNSIGNED NOT NULL,
    benefit_key VARCHAR(64) NOT NULL COMMENT 'Machine key, e.g. table_booking, match_recordings',
    label VARCHAR(256) NOT NULL COMMENT 'Display text shown in the app',
    priority TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Display order within plan, ascending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ssa_membership_plan_benefits_plan_id (plan_id),
    KEY idx_ssa_membership_plan_benefits_benefit_key (benefit_key),
    CONSTRAINT fk_ssa_plan_benefits_plan FOREIGN KEY (plan_id)
        REFERENCES ssa_membership_plans (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    if (!$dryRun) {
        $pdo->exec($sql);
    }
    echo "OK: $step\n";

    // -------------------------------------------------------------------------
    // 3. ssa_user_memberships
    // uid is not FK'd to bs_users because bs_users uses CHARSET=utf8 (legacy),
    // which causes collation conflicts with utf8mb4_unicode_ci tables.
    // -------------------------------------------------------------------------
    $step = 'CREATE TABLE ssa_user_memberships';
    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS ssa_user_memberships (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid INT UNSIGNED NOT NULL COMMENT 'Matches bs_users.uid; no FK to avoid legacy charset conflict',
    plan_id SMALLINT UNSIGNED NOT NULL,
    started_at DATE NOT NULL COMMENT 'Membership period start date (calendar date)',
    ended_at DATE NULL COMMENT 'NULL = current active membership; set when plan changes or membership ends',
    notes VARCHAR(512) NULL COMMENT 'Admin notes, e.g. reason for plan change',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ssa_user_memberships_uid (uid),
    KEY idx_ssa_user_memberships_uid_ended (uid, ended_at),
    KEY idx_ssa_user_memberships_plan_id (plan_id),
    KEY idx_ssa_user_memberships_started_at (started_at),
    CONSTRAINT fk_ssa_user_memberships_plan FOREIGN KEY (plan_id)
        REFERENCES ssa_membership_plans (id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    if (!$dryRun) {
        $pdo->exec($sql);
    }
    echo "OK: $step\n";

    // -------------------------------------------------------------------------
    // 4. ssa_membership_addons
    // -------------------------------------------------------------------------
    $step = 'CREATE TABLE ssa_membership_addons';
    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS ssa_membership_addons (
    id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(64) NOT NULL,
    name VARCHAR(128) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ssa_membership_addons_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    if (!$dryRun) {
        $pdo->exec($sql);
    }
    echo "OK: $step\n";

    // -------------------------------------------------------------------------
    // 5. ssa_user_membership_addons
    // -------------------------------------------------------------------------
    $step = 'CREATE TABLE ssa_user_membership_addons';
    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS ssa_user_membership_addons (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid INT UNSIGNED NOT NULL,
    addon_id SMALLINT UNSIGNED NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'requested'
        COMMENT 'requested | approved | declined | cancelled',
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    notes VARCHAR(512) NULL COMMENT 'Admin notes on approval or decline',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ssa_user_membership_addons_uid (uid),
    KEY idx_ssa_user_membership_addons_addon (addon_id),
    KEY idx_ssa_user_membership_addons_status (status),
    KEY idx_ssa_user_membership_addons_uid_addon (uid, addon_id),
    CONSTRAINT fk_ssa_user_addons_addon FOREIGN KEY (addon_id)
        REFERENCES ssa_membership_addons (id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    if (!$dryRun) {
        $pdo->exec($sql);
    }
    echo "OK: $step\n";

    echo "\n";

    // -------------------------------------------------------------------------
    // 6. Seed membership plans (INSERT IGNORE — safe to re-run)
    // price_pence is 0 until an admin sets real values
    // -------------------------------------------------------------------------
    $plans = [
        ['standard', 'Standard Membership',
            'Full access to table booking and club facilities.', 0, 10],
        ['plus',     'Plus Membership',
            'Standard benefits plus priority booking window and guest passes.', 0, 20],
        ['premium',  'Premium Membership',
            'All Plus benefits plus match recordings access and coaching discounts.', 0, 30],
    ];

    $planSlugToId = [];

    foreach ($plans as [$slug, $name, $desc, $price, $priority]) {
        if (!$dryRun) {
            $pdo->prepare(
                'INSERT IGNORE INTO ssa_membership_plans
                    (slug, name, description, price_pence, priority, is_active)
                 VALUES (:slug, :name, :desc, :price, :priority, 1)'
            )->execute(['slug' => $slug, 'name' => $name, 'desc' => $desc,
                        'price' => $price, 'priority' => $priority]);

            $row = $pdo->prepare('SELECT id FROM ssa_membership_plans WHERE slug = :slug');
            $row->execute(['slug' => $slug]);
            $planSlugToId[$slug] = (int)$row->fetchColumn();
        } else {
            $planSlugToId[$slug] = 0;
        }
        echo "OK: plan seed [$slug]\n";
    }

    // -------------------------------------------------------------------------
    // 7. Seed plan benefits (INSERT IGNORE by plan_id + benefit_key)
    //    Add UNIQUE KEY if missing, otherwise use INSERT IGNORE safely.
    // -------------------------------------------------------------------------
    if (!$dryRun) {
        $keyExists = (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'ssa_membership_plan_benefits'
               AND INDEX_NAME = 'uq_ssa_plan_benefit_plan_key'"
        )->fetchColumn();
        if ($keyExists === 0) {
            $pdo->exec(
                'ALTER TABLE ssa_membership_plan_benefits
                 ADD UNIQUE KEY uq_ssa_plan_benefit_plan_key (plan_id, benefit_key)'
            );
        }
    }

    $benefits = [
        'standard' => [
            ['table_booking',        'Table booking access',               10],
            ['online_booking',       'Online & app booking',               20],
            ['locker_access',        'Locker room access',                 30],
        ],
        'plus' => [
            ['table_booking',        'Table booking access',               10],
            ['online_booking',       'Online & app booking',               20],
            ['locker_access',        'Locker room access',                 30],
            ['priority_booking',     'Priority 7-day advance booking',     40],
            ['guest_passes',         '2 guest passes per month',           50],
        ],
        'premium' => [
            ['table_booking',        'Table booking access',               10],
            ['online_booking',       'Online & app booking',               20],
            ['locker_access',        'Locker room access',                 30],
            ['priority_booking',     'Priority 7-day advance booking',     40],
            ['guest_passes',         '4 guest passes per month',           50],
            ['match_recordings',     'Match recordings access',            60],
            ['coaching_discount',    '10% coaching session discount',      70],
        ],
    ];

    foreach ($benefits as $planSlug => $planBenefits) {
        $planId = $planSlugToId[$planSlug] ?? 0;
        foreach ($planBenefits as [$key, $label, $priority]) {
            if (!$dryRun && $planId > 0) {
                $pdo->prepare(
                    'INSERT IGNORE INTO ssa_membership_plan_benefits
                        (plan_id, benefit_key, label, priority)
                     VALUES (:planId, :key, :label, :priority)'
                )->execute(['planId' => $planId, 'key' => $key,
                            'label' => $label, 'priority' => $priority]);
            }
            echo "OK: benefit seed [$planSlug / $key]\n";
        }
    }

    // -------------------------------------------------------------------------
    // 8. Seed addon: match_recordings
    // -------------------------------------------------------------------------
    if (!$dryRun) {
        $pdo->prepare(
            'INSERT IGNORE INTO ssa_membership_addons (slug, name, description, is_active)
             VALUES (:slug, :name, :desc, 1)'
        )->execute([
            'slug' => 'match_recordings',
            'name' => 'Match Recordings',
            'desc' => 'Access to recorded match footage from SSA tables. Requires admin approval.',
        ]);
    }
    echo "OK: addon seed [match_recordings]\n";

    echo "\n";

    // -------------------------------------------------------------------------
    // 9. Initial user assignment
    //    For every linked uid with no current membership, assign Standard plan
    //    with started_at = bs_users.created (fallback: today in Europe/London).
    // -------------------------------------------------------------------------
    $step = 'Initial user assignment';

    $today = (new DateTimeImmutable('now', new DateTimeZone(SSA_API_TIMEZONE)))->format('Y-m-d');
    $standardPlanId = $planSlugToId['standard'];

    // Find linked uids that have no current (ended_at IS NULL) membership row.
    $unassignedRows = $pdo->query(
        'SELECT DISTINCT l.uid
         FROM ssa_auth0_user_links l
         LEFT JOIN ssa_user_memberships m
             ON m.uid = l.uid AND m.ended_at IS NULL
         WHERE l.revoked_at IS NULL
           AND m.id IS NULL'
    )->fetchAll(PDO::FETCH_COLUMN);

    $assigned = 0;
    $skipped = 0;

    foreach ($unassignedRows as $uid) {
        $uid = (int)$uid;

        // Look up member creation date from bs_users.
        $createdRow = $pdo->prepare(
            'SELECT created FROM bs_users WHERE uid = :uid LIMIT 1'
        );
        $createdRow->execute(['uid' => $uid]);
        $rawCreated = $createdRow->fetchColumn();

        if ($rawCreated && preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$rawCreated)) {
            $startedAt = substr((string)$rawCreated, 0, 10);
        } else {
            $startedAt = $today;
        }

        if ($dryRun) {
            echo "DRY RUN: would assign uid=$uid plan=standard started_at=$startedAt\n";
            $assigned++;
            continue;
        }

        if ($standardPlanId <= 0) {
            echo "SKIP: uid=$uid — standard plan id not resolved (seed may have failed)\n";
            $skipped++;
            continue;
        }

        $pdo->prepare(
            'INSERT IGNORE INTO ssa_user_memberships
                (uid, plan_id, started_at, ended_at, notes)
             VALUES (:uid, :planId, :startedAt, NULL, :notes)'
        )->execute([
            'uid' => $uid,
            'planId' => $standardPlanId,
            'startedAt' => $startedAt,
            'notes' => 'Auto-assigned by initial membership migration',
        ]);

        echo "OK: assigned uid=$uid plan=standard started_at=$startedAt\n";
        $assigned++;
    }

    echo "\n";
    echo "Summary: assigned=$assigned skipped=$skipped\n";
    echo "Migration complete.\n";
    exit(0);

} catch (Throwable $exception) {
    fwrite(STDERR, "FAILED: " . $exception->getMessage() . "\n");
    exit(1);
}
