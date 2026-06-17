<?php
declare(strict_types=1);
// TEMPORARY — remove before production merge
define('MIG_SECRET', 'a3f1c9d2e8b047560f7e2a1d4c8b3f92');
if (($_GET['t'] ?? '') !== MIG_SECRET) { http_response_code(404); exit; }
if (($_GET['mode'] ?? '') !== 'apply' && ($_GET['mode'] ?? '') !== 'dry') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'pass ?mode=dry or ?mode=apply']);
    exit;
}
$dryRun = ($_GET['mode'] === 'dry');
require_once __DIR__ . '/../_db.php';
header('Content-Type: application/json; charset=utf-8');

$log = [];
$errors = [];

function migLog(array &$log, string $step, string $msg): void
{
    $log[] = "[$step] $msg";
}

function migColumnExists(PDO $pdo, string $table, string $col): bool
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c');
    $s->execute(['t'=>$table,'c'=>$col]);
    return (int)$s->fetchColumn() > 0;
}

function migColumnType(PDO $pdo, string $table, string $col): string
{
    $s = $pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c LIMIT 1');
    $s->execute(['t'=>$table,'c'=>$col]);
    $r = $s->fetchColumn();
    return $r !== false ? (string)$r : '';
}

function migColumnIsNullable(PDO $pdo, string $table, string $col): bool
{
    $s = $pdo->prepare('SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c LIMIT 1');
    $s->execute(['t'=>$table,'c'=>$col]);
    $r = $s->fetchColumn();
    return $r !== false && strtoupper((string)$r) === 'YES';
}

function migTableExists(PDO $pdo, string $table): bool
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t');
    $s->execute(['t'=>$table]);
    return (int)$s->fetchColumn() > 0;
}

function migFkExists(PDO $pdo, string $table, string $name): bool
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND CONSTRAINT_NAME=:n AND CONSTRAINT_TYPE='FOREIGN KEY'");
    $s->execute(['t'=>$table,'n'=>$name]);
    return (int)$s->fetchColumn() > 0;
}

try {
    $pdo = ssaApiCreatePdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // STEP 1: parent_plan_id
    if (migColumnExists($pdo, 'ssa_membership_plans', 'parent_plan_id')) {
        migLog($log, 'STEP1', 'SKIP: parent_plan_id already exists');
    } else {
        if (!$dryRun) {
            $pdo->exec("ALTER TABLE ssa_membership_plans ADD COLUMN parent_plan_id BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'FK to ssa_membership_plans.id; NULL = top-level plan', ADD KEY idx_ssa_membership_plans_parent (parent_plan_id)");
        }
        migLog($log, 'STEP1', $dryRun ? 'DRY: would add parent_plan_id' : 'OK: parent_plan_id added');
    }

    // STEP 2a: source enum
    $curType = migColumnType($pdo, 'ssa_user_memberships', 'source');
    if (str_contains($curType, 'legacy_excel_import')) {
        migLog($log, 'STEP2a', 'SKIP: legacy_excel_import already in enum');
    } else {
        preg_match_all("/'([^']+)'/", $curType, $m);
        $vals = $m[1] ?? ['admin','migration','user_request','system'];
        if (!in_array('legacy_excel_import', $vals, true)) { $vals[] = 'legacy_excel_import'; }
        $enumDef = implode(',', array_map(fn($v) => "'" . addslashes($v) . "'", $vals));
        if (!$dryRun) {
            $pdo->exec("ALTER TABLE ssa_user_memberships MODIFY COLUMN source ENUM($enumDef) NOT NULL DEFAULT 'admin'");
        }
        migLog($log, 'STEP2a', $dryRun ? 'DRY: would extend source enum' : 'OK: source enum extended, vals=' . implode(',', $vals));
    }

    // STEP 2b-2e: nullable columns
    foreach ([
        'current_period_starts_at'      => 'STEP2b',
        'current_period_ends_at'        => 'STEP2c',
        'cancellation_notice_deadline_at' => 'STEP2d',
        'price_snapshot_pence'          => 'STEP2e',
    ] as $col => $step) {
        if (migColumnIsNullable($pdo, 'ssa_user_memberships', $col)) {
            migLog($log, $step, "SKIP: $col already nullable");
        } else {
            if (!$dryRun) {
                $typeMap = ['price_snapshot_pence' => 'INT UNSIGNED'];
                $type = $typeMap[$col] ?? 'DATETIME';
                $pdo->exec("ALTER TABLE ssa_user_memberships MODIFY COLUMN `$col` $type NULL DEFAULT NULL");
            }
            migLog($log, $step, $dryRun ? "DRY: would make $col nullable" : "OK: $col is now nullable");
        }
    }

    // STEP 3: seed new plans
    $newPlans = [
        ['SUMMER_STANDARD','Summer Package','Summer Package',50,1,1,null],
        ['RED_STANDARD_UPFRONT','Pay As You Play (Upfront)','Pay As You Play (Upfront)',11,1,0,'red'],
        ['RED_JUNIOR','Pay As You Play (Junior)','Pay As You Play (Junior)',12,1,0,'red'],
        ['RED_NHS','Pay As You Play (NHS Discounted)','Pay As You Play (NHS Discounted)',13,1,0,'red'],
        ['RED_POLICE','Pay As You Play (Police Discounted)','Pay As You Play (Police Discounted)',14,1,0,'red'],
        ['RED_SENIOR','Pay As You Play (Senior Discounted)','Pay As You Play (Senior Discounted)',15,1,0,'red'],
        ['RED_STUDENT','Pay As You Play (Student Discounted)','Pay As You Play (Student Discounted)',16,1,0,'red'],
        ['RED_NHS_UPFRONT','Pay As You Play (Upfront NHS)','Pay As You Play (Upfront NHS)',17,1,0,'red'],
        ['PINK_STANDARD_UPFRONT','Daytime Pass (Upfront)','Daytime Pass (Upfront)',21,1,0,'pink'],
        ['PINK_JUNIOR','Daytime Pass (Junior)','Daytime Pass (Junior)',22,1,0,'pink'],
        ['PINK_SENIOR','Daytime Pass (Senior Discounted)','Daytime Pass (Senior Discounted)',23,1,0,'pink'],
        ['BLACK_SENIOR','All Day Pass (Senior Discounted)','All Day Pass (Senior Discounted)',31,1,0,'black'],
        ['BLACK_DISCOUNTED','All Day Pass (Discounted)','All Day Pass (Discounted)',32,1,0,'black'],
        ['CONCESSION','Concession','Concession',60,1,0,null],
        ['COACH','Coach','Coach',70,1,0,null],
        ['SPECIAL_ARRANGEMENT','Special Arrangement','Special Arrangement',80,1,0,null],
    ];

    $ins = $pdo->prepare(
        'INSERT IGNORE INTO ssa_membership_plans (plan_key,name,display_name,monthly_price_pence,currency,sort_order,is_active,is_public)
         VALUES (:plan_key,:name,:display_name,0,\'GBP\',:sort_order,:is_active,:is_public)'
    );
    foreach ($newPlans as [$pk,$name,$dn,$sort,$active,$public,$parent]) {
        if ($dryRun) {
            migLog($log, 'STEP3', "DRY: would INSERT IGNORE plan_key=$pk");
        } else {
            $ins->execute(['plan_key'=>$pk,'name'=>$name,'display_name'=>$dn,'sort_order'=>$sort,'is_active'=>$active,'is_public'=>$public]);
            migLog($log, 'STEP3', ($ins->rowCount() > 0 ? 'OK' : 'SKIP') . ": plan_key=$pk");
        }
    }

    // STEP 4: parent_plan_id FK + set references
    $fkName = 'fk_ssa_membership_plans_parent';
    if (!$dryRun && !migFkExists($pdo, 'ssa_membership_plans', $fkName)) {
        $pdo->exec("ALTER TABLE ssa_membership_plans ADD CONSTRAINT $fkName FOREIGN KEY (parent_plan_id) REFERENCES ssa_membership_plans(id) ON DELETE SET NULL ON UPDATE CASCADE");
        migLog($log, 'STEP4', "OK: FK $fkName added");
    } elseif ($dryRun) {
        migLog($log, 'STEP4', "DRY: would add FK $fkName if missing");
    } else {
        migLog($log, 'STEP4', "SKIP: FK $fkName already exists");
    }

    if (!$dryRun) {
        $planKeyToId = [];
        foreach ($pdo->query('SELECT id,plan_key FROM ssa_membership_plans')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $planKeyToId[$r['plan_key']] = (int)$r['id'];
        }
        $updParent = $pdo->prepare('UPDATE ssa_membership_plans SET parent_plan_id=:pid WHERE plan_key=:pk AND (parent_plan_id IS NULL OR parent_plan_id!=:pid)');
        foreach ($newPlans as [$pk,$name,$dn,$sort,$active,$public,$parentKey]) {
            if ($parentKey === null) continue;
            $parentId = $planKeyToId[$parentKey] ?? null;
            if ($parentId === null) { migLog($log, 'STEP4', "ERROR: parent plan_key=$parentKey not found for $pk"); continue; }
            $updParent->execute(['pid'=>$parentId,'pk'=>$pk]);
            migLog($log, 'STEP4', ($updParent->rowCount() > 0 ? 'OK' : 'SKIP') . ": $pk -> parent=$parentKey (id=$parentId)");
        }
    } else {
        foreach ($newPlans as [$pk,$n,$dn,$s,$a,$p,$parentKey]) {
            if ($parentKey !== null) migLog($log, 'STEP4', "DRY: would set $pk -> parent=$parentKey");
        }
    }

    // STEP 5: ssa_membership_import_batches
    if (!$dryRun && migTableExists($pdo, 'ssa_membership_import_batches')) {
        migLog($log, 'STEP5', 'SKIP: ssa_membership_import_batches already exists');
    } else {
        $sql5 = "CREATE TABLE IF NOT EXISTS ssa_membership_import_batches (
            id              BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
            source_filename VARCHAR(255)      NOT NULL,
            source_sha256   CHAR(64)          NOT NULL,
            status          ENUM('pending','running','completed','failed','rolled_back') NOT NULL DEFAULT 'pending',
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if (!$dryRun) { $pdo->exec($sql5); }
        migLog($log, 'STEP5', $dryRun ? 'DRY: would create ssa_membership_import_batches' : 'OK: created ssa_membership_import_batches');
    }

    // STEP 6: ssa_membership_import_rows
    if (!$dryRun && migTableExists($pdo, 'ssa_membership_import_rows')) {
        migLog($log, 'STEP6', 'SKIP: ssa_membership_import_rows already exists');
    } else {
        $sql6 = "CREATE TABLE IF NOT EXISTS ssa_membership_import_rows (
            id                             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            batch_id                       BIGINT UNSIGNED  NOT NULL,
            source_row_number              INT UNSIGNED     NOT NULL,
            source_member_name             VARCHAR(255)     NULL,
            source_account_number          VARCHAR(32)      NULL,
            source_email                   VARCHAR(255)     NULL,
            source_mobile                  VARCHAR(64)      NULL,
            raw_package_description        VARCHAR(255)     NULL,
            normalised_package_description VARCHAR(255)     NULL,
            resolved_plan_id               BIGINT UNSIGNED  NULL,
            matched_uid                    INT UNSIGNED     NULL,
            match_method                   VARCHAR(64)      NULL,
            status                         ENUM('imported','skipped','conflict','unresolved','error') NOT NULL DEFAULT 'unresolved',
            reason                         TEXT             NULL,
            created_at                     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at                     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_batch_row (batch_id, source_row_number),
            KEY idx_ssa_import_rows_batch (batch_id),
            KEY idx_ssa_import_rows_status (status),
            KEY idx_ssa_import_rows_matched_uid (matched_uid),
            CONSTRAINT fk_ssa_import_rows_batch FOREIGN KEY (batch_id) REFERENCES ssa_membership_import_batches(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_ssa_import_rows_plan  FOREIGN KEY (resolved_plan_id) REFERENCES ssa_membership_plans(id) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if (!$dryRun) { $pdo->exec($sql6); }
        migLog($log, 'STEP6', $dryRun ? 'DRY: would create ssa_membership_import_rows' : 'OK: created ssa_membership_import_rows');
    }

    echo json_encode([
        'dry_run' => $dryRun,
        'status'  => 'ok',
        'log'     => $log,
        'errors'  => $errors,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    echo json_encode([
        'dry_run' => $dryRun,
        'status'  => 'error',
        'error'   => $e->getMessage(),
        'log'     => $log,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
