<?php

declare(strict_types=1);

/**
 * TEMPORARY SCHEMA PROBE — REMOVE BEFORE PRODUCTION MERGE
 * GET /api/ssa/v1/admin/schema-probe-temp.php?t=<secret>
 */

define('PROBE_SECRET', 'f9e2a7c4b1d83e56f0a2c5b9d7e14380');

if (($_GET['t'] ?? '') !== PROBE_SECRET) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../_db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = ssaApiCreatePdo();

    $colStmt = $pdo->query(
        "SELECT
            COLUMN_NAME,
            ORDINAL_POSITION,
            COLUMN_DEFAULT,
            IS_NULLABLE,
            DATA_TYPE,
            CHARACTER_MAXIMUM_LENGTH,
            COLUMN_TYPE,
            COLUMN_KEY,
            EXTRA,
            COLUMN_COMMENT
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'ssa_user_memberships'
         ORDER BY ORDINAL_POSITION"
    );
    $columns = $colStmt->fetchAll(PDO::FETCH_ASSOC);

    $idxStmt = $pdo->query(
        "SHOW INDEX FROM ssa_user_memberships"
    );
    $indexes = $idxStmt->fetchAll(PDO::FETCH_ASSOC);

    $activeStmt = $pdo->query(
        "SELECT
            id, uid, plan_id, status, started_at,
            current_period_starts_at, current_period_ends_at,
            cancellation_notice_deadline_at, source,
            price_snapshot_pence, currency_snapshot, plan_name_snapshot,
            active_uid, created_at, updated_at
         FROM ssa_user_memberships
         WHERE status = 'active'
         ORDER BY id DESC
         LIMIT 5"
    );
    $activeRows = $activeStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'columns' => $columns,
        'indexes' => $indexes,
        'activeRows' => $activeRows,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
