<?php
declare(strict_types=1);
// TEMPORARY — remove before production merge
define('PROBE_SECRET', 'b2d9f3a1c7e84052b6d1a3f9c2e07481');
if (($_GET['t'] ?? '') !== PROBE_SECRET) { http_response_code(404); exit; }
require_once __DIR__ . '/../_db.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $pdo = ssaApiCreatePdo();
    $tables = $pdo->query("SHOW TABLES LIKE 'ssa\_%'")->fetchAll(PDO::FETCH_COLUMN);
    $out = ['tables' => $tables, 'schemas' => [], 'plans' => [], 'bs_users_sample' => []];
    foreach ($tables as $t) {
        $out['schemas'][$t] = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_ASSOC);
    }
    try { $out['plans'] = $pdo->query("SELECT * FROM ssa_membership_plans ORDER BY id")->fetchAll(PDO::FETCH_ASSOC); } catch(Throwable $e) { $out['plans_error'] = $e->getMessage(); }
    try { $out['bs_users_count'] = $pdo->query("SELECT COUNT(*) FROM bs_users")->fetchColumn(); } catch(Throwable $e) {}
    try { $out['auth0_links_count'] = $pdo->query("SELECT COUNT(*) FROM ssa_auth0_user_links WHERE revoked_at IS NULL")->fetchColumn(); } catch(Throwable $e) {}
    try {
        $out['bs_users_columns'] = $pdo->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bs_users' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
    } catch(Throwable $e) {}
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) { echo json_encode(['error' => $e->getMessage()]); }
