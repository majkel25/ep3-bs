<?php
declare(strict_types=1);
// TEMPORARY — remove before production merge
define('SEC', 'e2c8f4a1d9b306745a1e3c7f2b8d05e1');
if (($_GET['t'] ?? '') !== SEC) { http_response_code(404); exit; }
require_once __DIR__ . '/../_db.php';
header('Content-Type: application/json; charset=utf-8');

$log = [];
try {
    $pdo = ssaApiCreatePdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create ssa_membership_import_batches
    $pdo->exec("CREATE TABLE IF NOT EXISTS ssa_membership_import_batches (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log[] = 'OK: ssa_membership_import_batches created (or already existed)';

    // Create ssa_membership_import_rows
    $pdo->exec("CREATE TABLE IF NOT EXISTS ssa_membership_import_rows (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log[] = 'OK: ssa_membership_import_rows created (or already existed)';

    // Set parent_plan_id for variant plans
    $planKeyToId = [];
    foreach ($pdo->query('SELECT id,plan_key FROM ssa_membership_plans')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $planKeyToId[$r['plan_key']] = (int)$r['id'];
    }
    $parentMap = [
        'RED_STANDARD_UPFRONT' => 'red', 'RED_JUNIOR' => 'red', 'RED_NHS' => 'red',
        'RED_POLICE' => 'red', 'RED_SENIOR' => 'red', 'RED_STUDENT' => 'red', 'RED_NHS_UPFRONT' => 'red',
        'PINK_STANDARD_UPFRONT' => 'pink', 'PINK_JUNIOR' => 'pink', 'PINK_SENIOR' => 'pink',
        'BLACK_SENIOR' => 'black', 'BLACK_DISCOUNTED' => 'black',
    ];
    $upd = $pdo->prepare('UPDATE ssa_membership_plans SET parent_plan_id=:pid WHERE plan_key=:pk');
    foreach ($parentMap as $childKey => $parentKey) {
        $parentId = $planKeyToId[$parentKey] ?? null;
        if ($parentId === null) { $log[] = "SKIP parent: $parentKey not found for $childKey"; continue; }
        $upd->execute(['pid' => $parentId, 'pk' => $childKey]);
        $log[] = ($upd->rowCount() > 0 ? 'OK' : 'SKIP') . ": $childKey -> parent=$parentKey";
    }

    echo json_encode(['status' => 'ok', 'log' => $log], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'error' => $e->getMessage(), 'log' => $log], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
