<?php
declare(strict_types=1);
define('VER_SECRET', 'd1f8e4a9c2b7506380e5c1a4b9d2f6e3');
if (($_GET['t'] ?? '') !== VER_SECRET) { http_response_code(404); exit; }
require_once __DIR__ . '/../_db.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = ssaApiCreatePdo();
    $log = [];

    // Batch info
    $batch = $pdo->query("SELECT id,source_filename,source_sha256,status,total_rows,matched_rows,imported_rows,skipped_rows,conflict_rows,unresolved_rows,completed_at FROM ssa_membership_import_batches WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    $log[] = "Batch: " . json_encode($batch);

    // Import rows summary
    $byStatus = $pdo->query("SELECT status,COUNT(*) as cnt FROM ssa_membership_import_rows WHERE batch_id=1 GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
    $log[] = "Import rows by status: " . json_encode($byStatus);

    // New active memberships from this import
    $newMems = $pdo->query("SELECT COUNT(DISTINCT uid) as member_count, COUNT(*) as membership_count FROM ssa_user_memberships WHERE source='legacy_excel_import'")->fetch(PDO::FETCH_ASSOC);
    $log[] = "New memberships from import: " . json_encode($newMems);

    // Sample: one imported member
    $sample = $pdo->query("SELECT u.email, um.id, um.plan_id, um.status, um.started_at, p.plan_key FROM ssa_user_memberships um JOIN bs_users u ON um.uid=u.id JOIN ssa_membership_plans p ON um.plan_id=p.id WHERE um.source='legacy_excel_import' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $log[] = "Sample imported membership: " . json_encode($sample);

    // Check conflicts in import_rows
    $conflicts = $pdo->query("SELECT ir.source_row_number, ir.source_member_name, ir.matched_uid, ir.reason FROM ssa_membership_import_rows WHERE batch_id=1 AND status='conflict' LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    $log[] = "Sample conflicts: " . json_encode($conflicts);

    // Check unresolved
    $unres = $pdo->query("SELECT ir.source_row_number, ir.source_member_name, ir.raw_package_description FROM ssa_membership_import_rows WHERE batch_id=1 AND status='unresolved' LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    $log[] = "Unresolved: " . json_encode($unres);

    echo json_encode(['status' => 'ok', 'log' => $log], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
