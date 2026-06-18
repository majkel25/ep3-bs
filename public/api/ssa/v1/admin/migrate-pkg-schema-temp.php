<?php
declare(strict_types=1);
// TEMPORARY — remove before production merge
define('MPK_SECRET', 'b4e9f2c1a8d3056794f2e1c8b3a7d094');
if (($_GET['t'] ?? '') !== MPK_SECRET) { http_response_code(404); exit; }
require_once __DIR__ . '/../_db.php';
header('Content-Type: application/json; charset=utf-8');

$log = [];
try {
    $pdo = ssaApiCreatePdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $cols = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ssa_membership_plans'")->fetchAll(PDO::FETCH_COLUMN);
    $cols = array_map('strtolower', $cols);

    // description TEXT NULL
    if (!in_array('description', $cols, true)) {
        $pdo->exec("ALTER TABLE ssa_membership_plans ADD COLUMN description TEXT NULL AFTER display_name");
        $log[] = "OK: added description";
    } else { $log[] = "SKIP: description exists"; }

    // billing_type VARCHAR(20) DEFAULT 'monthly'
    if (!in_array('billing_type', $cols, true)) {
        $pdo->exec("ALTER TABLE ssa_membership_plans ADD COLUMN billing_type VARCHAR(20) NOT NULL DEFAULT 'monthly' AFTER currency");
        $log[] = "OK: added billing_type";
    } else { $log[] = "SKIP: billing_type exists"; }

    // available_from DATE NULL
    if (!in_array('available_from', $cols, true)) {
        $pdo->exec("ALTER TABLE ssa_membership_plans ADD COLUMN available_from DATE NULL DEFAULT NULL");
        $log[] = "OK: added available_from";
    } else { $log[] = "SKIP: available_from exists"; }

    // available_until DATE NULL
    if (!in_array('available_until', $cols, true)) {
        $pdo->exec("ALTER TABLE ssa_membership_plans ADD COLUMN available_until DATE NULL DEFAULT NULL");
        $log[] = "OK: added available_until";
    } else { $log[] = "SKIP: available_until exists"; }

    // updated_at DATETIME auto-update
    if (!in_array('updated_at', $cols, true)) {
        $pdo->exec("ALTER TABLE ssa_membership_plans ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        $log[] = "OK: added updated_at";
    } else { $log[] = "SKIP: updated_at exists"; }

    // ssa_membership_package_audit table
    $pdo->exec("CREATE TABLE IF NOT EXISTS ssa_membership_package_audit (
        id                   BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
        package_id           BIGINT UNSIGNED  NOT NULL,
        admin_uid            INT UNSIGNED     NOT NULL,
        admin_name_snapshot  VARCHAR(255)     NULL,
        action               VARCHAR(32)      NOT NULL DEFAULT 'update',
        changed_fields_json  TEXT             NULL,
        old_values_json      TEXT             NULL,
        new_values_json      TEXT             NULL,
        ip_address           VARCHAR(45)      NULL,
        created_at           DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_ssa_pkg_audit_package (package_id),
        KEY idx_ssa_pkg_audit_admin (admin_uid),
        CONSTRAINT fk_ssa_pkg_audit_package FOREIGN KEY (package_id) REFERENCES ssa_membership_plans(id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log[] = "OK: ssa_membership_package_audit created (or already existed)";

    echo json_encode(['status' => 'ok', 'log' => $log], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'error' => $e->getMessage(), 'log' => $log], JSON_PRETTY_PRINT);
}
