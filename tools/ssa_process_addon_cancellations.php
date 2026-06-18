<?php

declare(strict_types=1);

// FILE: tools/ssa_process_addon_cancellations.php

/**
 * CLI scheduled process — run daily via cron.
 *
 * Finds all approved addon_cancellation requests whose requestedEffectiveDate
 * has arrived and processes them: cancels the add-on assignment and marks the
 * request as completed.
 *
 * Usage:
 *   php tools/ssa_process_addon_cancellations.php [--dry-run]
 */

if (!function_exists('ssaApiJsonResponse')) {
    function ssaApiJsonResponse(int $statusCode, array $payload): void
    {
        throw new RuntimeException(
            'API JSON response called during CLI process: HTTP ' .
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
require_once __DIR__ . '/../public/api/ssa/v1/_user_notifications.php';

$dryRun = in_array('--dry-run', $argv, true);

if ($dryRun) {
    echo "[DRY RUN] No changes will be written.\n\n";
}

// ── Helpers ────────────────────────────────────────────────────────────────

function pacColumnExists(PDO $pdo, string $table, string $column): bool
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

function pacTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = :table'
    );
    $stmt->execute(['table' => $table]);
    return (int)$stmt->fetchColumn() > 0;
}

// ── Main ───────────────────────────────────────────────────────────────────

try {
    $pdo = ssaApiCreatePdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Today in Europe/London
    $tz    = new DateTimeZone('Europe/London');
    $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');

    echo "Processing addon cancellations for date: $today\n\n";

    // Find all approved addon_cancellation requests
    $dueStmt = $pdo->prepare(
        "SELECT r.id AS request_id, r.uid, r.payload_json, r.admin_comment,
                u.alias AS member_name
         FROM ssa_admin_requests r
         LEFT JOIN bs_users u ON u.uid = r.uid
         WHERE r.request_type = 'addon_cancellation'
           AND r.status = 'approved'"
    );
    $dueStmt->execute();
    $approvedRequests = $dueStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($approvedRequests)) {
        echo "No approved addon_cancellation requests found.\n";
        exit(0);
    }

    echo 'Found ' . count($approvedRequests) . " approved request(s). Checking effective dates...\n\n";

    $processed = 0;
    $skipped   = 0;
    $errors    = 0;

    $hasCancelledAt = pacColumnExists($pdo, 'ssa_user_membership_addons', 'cancelled_at');
    $hasAuditTable  = pacTableExists($pdo, 'ssa_member_request_audit_log');

    foreach ($approvedRequests as $reqRow) {
        $requestId = (int)$reqRow['request_id'];
        $memberUid = (int)$reqRow['uid'];
        $memberName = (string)($reqRow['member_name'] ?? '');

        $payload = [];
        if (isset($reqRow['payload_json']) && $reqRow['payload_json']) {
            $decoded = json_decode((string)$reqRow['payload_json'], true);
            if (is_array($decoded)) $payload = $decoded;
        }

        $effectiveDate = (string)($payload['requestedEffectiveDate'] ?? '');
        $userAddonId   = isset($payload['userAddonId']) ? (int)$payload['userAddonId'] : 0;
        $addonName     = (string)($payload['addonName'] ?? 'Add-on');
        $addonKey      = (string)($payload['addonKey'] ?? '');

        if ($effectiveDate === '' || $userAddonId <= 0) {
            echo "  SKIP request #$requestId: missing effectiveDate or userAddonId in payload\n";
            $skipped++;
            continue;
        }

        // Check if effective date has arrived
        if ($effectiveDate > $today) {
            echo "  SKIP request #$requestId: effectiveDate=$effectiveDate is in the future (today=$today)\n";
            $skipped++;
            continue;
        }

        echo "  PROCESS request #$requestId: uid=$memberUid, addon=$addonName, effectiveDate=$effectiveDate\n";

        if ($dryRun) {
            echo "    DRY RUN: would cancel addon assignment id=$userAddonId and complete request #$requestId\n";
            $processed++;
            continue;
        }

        $pdo->beginTransaction();
        try {
            // Lock addon assignment row FOR UPDATE
            $lockStmt = $pdo->prepare(
                'SELECT id, uid, status FROM ssa_user_membership_addons WHERE id = :id FOR UPDATE'
            );
            $lockStmt->execute(['id' => $userAddonId]);
            $addonRow = $lockStmt->fetch(PDO::FETCH_ASSOC);

            if (!$addonRow) {
                $pdo->rollBack();
                echo "    ERROR: addon assignment id=$userAddonId not found\n";
                $errors++;
                continue;
            }

            if ((int)$addonRow['uid'] !== $memberUid) {
                $pdo->rollBack();
                echo "    ERROR: addon assignment uid mismatch (expected $memberUid, got {$addonRow['uid']})\n";
                $errors++;
                continue;
            }

            if ((string)$addonRow['status'] !== 'approved') {
                $pdo->rollBack();
                echo "    SKIP: addon assignment is not 'approved' (status={$addonRow['status']})\n";
                $skipped++;
                continue;
            }

            // Re-verify request still approved (status check inside transaction)
            $reqCheckStmt = $pdo->prepare(
                "SELECT id, status FROM ssa_admin_requests WHERE id = :id AND status = 'approved' LIMIT 1"
            );
            $reqCheckStmt->execute(['id' => $requestId]);
            if (!$reqCheckStmt->fetch()) {
                $pdo->rollBack();
                echo "    SKIP: request #$requestId is no longer 'approved' (race condition or already processed)\n";
                $skipped++;
                continue;
            }

            // Cancel the addon assignment
            if ($hasCancelledAt) {
                $pdo->prepare(
                    "UPDATE ssa_user_membership_addons
                     SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP()
                     WHERE id = :id"
                )->execute(['id' => $userAddonId]);
            } else {
                $pdo->prepare(
                    "UPDATE ssa_user_membership_addons
                     SET status = 'cancelled'
                     WHERE id = :id"
                )->execute(['id' => $userAddonId]);
            }

            // Mark request as completed
            $pdo->prepare(
                "UPDATE ssa_admin_requests
                 SET status = 'completed', actioned_at = UTC_TIMESTAMP()
                 WHERE id = :id AND status = 'approved'"
            )->execute(['id' => $requestId]);

            $pdo->commit();

            echo "    OK: addon $addonName (id=$userAddonId) cancelled, request #$requestId completed\n";

            // Post-transaction: audit log (non-fatal)
            if ($hasAuditTable) {
                try {
                    $pdo->prepare(
                        'INSERT INTO ssa_member_request_audit_log
                            (request_id, request_type, member_uid, member_name_snapshot,
                             previous_status, new_status, change_summary,
                             admin_uid, admin_name_snapshot, admin_comment, actioned_at, effective_date)
                         VALUES
                            (:requestId, :requestType, :memberUid, :memberName,
                             :prevStatus, :newStatus, :summary,
                             :adminUid, :adminName, :comment, UTC_TIMESTAMP(), :effectiveDate)'
                    )->execute([
                        'requestId'   => $requestId,
                        'requestType' => 'addon_cancellation',
                        'memberUid'   => $memberUid,
                        'memberName'  => $memberName !== '' ? $memberName : null,
                        'prevStatus'  => 'approved',
                        'newStatus'   => 'completed',
                        'summary'     => 'Addon cancellation effective: ' . $addonName,
                        'adminUid'    => 0,
                        'adminName'   => 'system',
                        'comment'     => null,
                        'effectiveDate' => $effectiveDate,
                    ]);
                } catch (Throwable $auditEx) {
                    error_log('ssa_process_addon_cancellations: audit log failed: ' . $auditEx->getMessage());
                }
            }

            // Post-transaction: notification (non-fatal)
            try {
                ssaUserNotificationsEnsureTable($pdo);
                ssaUserNotificationsCreate(
                    $pdo,
                    $memberUid,
                    'addon_cancellation_effective',
                    'Add-on Cancelled',
                    'Your ' . $addonName . ' add-on has now been cancelled as of ' . $effectiveDate . '.',
                    'membership',
                    (string)$requestId,
                    ['requestId' => $requestId, 'addonKey' => $addonKey, 'addonName' => $addonName, 'effectiveDate' => $effectiveDate]
                );
            } catch (Throwable $notifEx) {
                error_log('ssa_process_addon_cancellations: notification failed: ' . $notifEx->getMessage());
            }

            $processed++;

        } catch (Throwable $txEx) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo "    ERROR: " . $txEx->getMessage() . "\n";
            $errors++;
        }
    }

    echo "\n──────────────────────────────────────────────\n";
    echo "Summary:\n";
    echo "  Processed: $processed\n";
    echo "  Skipped:   $skipped\n";
    echo "  Errors:    $errors\n";
    if ($dryRun) {
        echo "  (DRY RUN — no changes written)\n";
    }
    echo "──────────────────────────────────────────────\n";

    exit($errors > 0 ? 1 : 0);

} catch (Throwable $exception) {
    fwrite(STDERR, "FATAL: " . $exception->getMessage() . "\n");
    fwrite(STDERR, $exception->getTraceAsString() . "\n");
    exit(1);
}
