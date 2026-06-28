<?php
/**
 * Temporary diagnostic endpoint — read-only event query for verification.
 * Protected by a one-time key header; expires 2026-06-29 23:59 UTC.
 * MUST be deleted immediately after verification.
 */

// Strict expiry — this file must be removed after today
define('DIAG_EXPIRES_UTC', '2026-06-29 23:59:59');
define('DIAG_KEY', '76d15b3c28186d92979793a8ca80aa122bb69c33');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$now = gmdate('Y-m-d H:i:s');
if ($now > DIAG_EXPIRES_UTC) {
    http_response_code(410);
    echo json_encode(['error' => 'expired']);
    exit;
}

$key = $_SERVER['HTTP_X_DIAG_KEY'] ?? '';
if (!hash_equals(DIAG_KEY, $key)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

require_once __DIR__ . '/_db.php';

try {
    $config = ssaApiLoadLocalConfig()['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $config['hostname'],
        $config['port'] ?? 3306,
        $config['database']
    );
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Query events overlapping 2026-06-29 (full day, Europe/London = UTC+1 BST)
    $stmt = $pdo->prepare(
        "SELECT e.eid, e.sid, e.status, e.datetime_start, e.datetime_end, e.capacity
         FROM bs_events e
         WHERE e.datetime_end > '2026-06-29 00:00:00'
           AND e.datetime_start < '2026-06-30 00:00:00'
         ORDER BY e.datetime_start"
    );
    $stmt->execute();
    $events = $stmt->fetchAll();

    // Fetch metadata for each event
    $meta = [];
    foreach ($events as $event) {
        $mStmt = $pdo->prepare(
            "SELECT em.eid, em.key, em.value
             FROM bs_events_meta em
             WHERE em.eid = ?
             ORDER BY em.key"
        );
        $mStmt->execute([$event['eid']]);
        $meta[$event['eid']] = $mStmt->fetchAll();
    }

    // Test the slot generation logic for table 1 (or first available table)
    $tz = new DateTimeZone('Europe/London');
    $rangeStart = (new DateTimeImmutable('2026-06-29 00:00:00', $tz))->format('Y-m-d H:i:s');
    $rangeEnd   = (new DateTimeImmutable('2026-06-30 00:00:00', $tz))->format('Y-m-d H:i:s');

    echo json_encode([
        'ok' => true,
        'diagKey' => 'valid',
        'utcNow' => $now,
        'rangeStart' => $rangeStart,
        'rangeEnd' => $rangeEnd,
        'eventCount' => count($events),
        'events' => array_map(function (array $e) use ($meta): array {
            return [
                'eid' => (int)$e['eid'],
                'sid' => $e['sid'],
                'sidType' => $e['sid'] === null ? 'NULL' : (((int)$e['sid'] === 0) ? 'zero' : 'tableId:' . $e['sid']),
                'status' => $e['status'],
                'datetime_start' => $e['datetime_start'],
                'datetime_end' => $e['datetime_end'],
                'capacity' => $e['capacity'],
                'meta' => $meta[$e['eid']] ?? [],
            ];
        }, $events),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Throwable $ex) {
    http_response_code(500);
    echo json_encode(['error' => $ex->getMessage()]);
}
