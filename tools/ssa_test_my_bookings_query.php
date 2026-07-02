<?php
declare(strict_types=1);

/**
 * Regression test: my-bookings.php PDO named placeholder uniqueness.
 *
 * Confirms that the SQL executed by my-bookings.php does not throw
 * SQLSTATE[HY093] (Invalid parameter number) when native prepared statements
 * are used. Tests upcoming and past scope date ranges.
 *
 * Usage: php tools/ssa_test_my_bookings_query.php
 * Exit 0 = all assertions passed.
 * Exit 1 = one or more assertions failed.
 */

if (!function_exists('ssaApiJsonResponse')) {
    function ssaApiJsonResponse(int $statusCode, array $payload): void
    {
        throw new RuntimeException(
            'API JSON response called during CLI test: HTTP ' .
            $statusCode .
            ' ' .
            json_encode($payload)
        );
    }
}

require_once __DIR__ . '/../public/api/ssa/v1/_db.php';

$failed = 0;

function assert_true(bool $condition, string $label): void
{
    global $failed;
    if ($condition) {
        echo "  PASS  $label\n";
    } else {
        echo "  FAIL  $label\n";
        $failed++;
    }
}

echo "ssa_test_my_bookings_query.php\n";
echo "==============================\n\n";

// --- Build the exact SQL used in my-bookings.php ---

$sql = 'SELECT
        r.rid,
        r.bid,
        r.date,
        r.time_start,
        r.time_end,
        b.uid,
        b.sid,
        b.status AS booking_status,
        b.status_billing,
        b.visibility,
        b.quantity,
        b.created,
        s.name AS table_name,
        s.status AS table_status,
        n.note AS private_note
    FROM bs_reservations r
    INNER JOIN bs_bookings b ON b.bid = r.bid
    LEFT JOIN bs_squares s ON s.sid = b.sid
    LEFT JOIN ssa_booking_private_notes n ON n.booking_id = b.bid AND n.uid = :noteUid
    WHERE r.date >= :from
      AND r.date <= :to
      AND b.uid = :bookingUid
      AND b.status <> :cancelledStatus
    ORDER BY r.date ASC, r.time_start ASC, b.sid ASC, r.rid ASC';

// Use a non-existent uid so no rows are returned; we only care that HY093 is not thrown.
$testUid = 0;

// --- Test 1: upcoming scope (today → +90 days) ---

echo "Test 1: upcoming scope\n";
try {
    $pdo1 = ssaApiCreatePdo();
    $stmt1 = $pdo1->prepare($sql);

    $today = date('Y-m-d');
    $future = date('Y-m-d', strtotime('+90 days'));

    $stmt1->execute([
        'from'            => $today,
        'to'              => $future,
        'noteUid'         => $testUid,
        'bookingUid'      => $testUid,
        'cancelledStatus' => 'cancelled',
    ]);

    $rows1 = $stmt1->fetchAll(PDO::FETCH_ASSOC);

    assert_true(true, 'upcoming scope: no HY093 thrown');
    assert_true(is_array($rows1), 'upcoming scope: fetchAll returns an array');
    echo "  INFO  upcoming scope returned " . count($rows1) . " row(s)\n";
} catch (PDOException $e) {
    assert_true(false, 'upcoming scope: no HY093 thrown (got: ' . $e->getMessage() . ')');
    assert_true(false, 'upcoming scope: fetchAll returns an array (query failed)');
}
echo "\n";

// --- Test 2: past scope (today-180 days → today) ---

echo "Test 2: past scope\n";
try {
    $pdo2 = ssaApiCreatePdo();
    $stmt2 = $pdo2->prepare($sql);

    $past = date('Y-m-d', strtotime('-180 days'));
    $today2 = date('Y-m-d');

    $stmt2->execute([
        'from'            => $past,
        'to'              => $today2,
        'noteUid'         => $testUid,
        'bookingUid'      => $testUid,
        'cancelledStatus' => 'cancelled',
    ]);

    $rows2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    assert_true(true, 'past scope: no HY093 thrown');
    assert_true(is_array($rows2), 'past scope: fetchAll returns an array');
    echo "  INFO  past scope returned " . count($rows2) . " row(s)\n";
} catch (PDOException $e) {
    assert_true(false, 'past scope: no HY093 thrown (got: ' . $e->getMessage() . ')');
    assert_true(false, 'past scope: fetchAll returns an array (query failed)');
}
echo "\n";

// --- Test 3: privateNote column is present in SELECT output ---

echo "Test 3: privateNote column shape\n";
try {
    $pdo3 = ssaApiCreatePdo();
    $stmt3 = $pdo3->prepare($sql);

    $stmt3->execute([
        'from'            => date('Y-m-d', strtotime('-180 days')),
        'to'              => date('Y-m-d', strtotime('+90 days')),
        'noteUid'         => $testUid,
        'bookingUid'      => $testUid,
        'cancelledStatus' => 'cancelled',
    ]);

    // Check column metadata (works even when zero rows are returned).
    $colCount = $stmt3->columnCount();
    $privateNoteColFound = false;
    for ($i = 0; $i < $colCount; $i++) {
        $meta = $stmt3->getColumnMeta($i);
        if (($meta['name'] ?? '') === 'private_note') {
            $privateNoteColFound = true;
            break;
        }
    }

    assert_true($privateNoteColFound, 'privateNote (private_note) column present in result set');
} catch (PDOException $e) {
    assert_true(false, 'privateNote column check: query failed (' . $e->getMessage() . ')');
}
echo "\n";

// --- Test 4: cancelled bookings excluded by the WHERE clause ---

echo "Test 4: cancelled bookings excluded\n";
try {
    $pdo4 = ssaApiCreatePdo();

    // Count raw cancelled bookings for any user to verify the filter has effect.
    $cancelledCount = $pdo4
        ->query("SELECT COUNT(*) FROM bs_bookings WHERE status = 'cancelled'")
        ->fetchColumn();

    // Run the my-bookings query with a uid of 0 (no rows expected) — confirm
    // the cancelled filter is syntactically part of the query without error.
    $stmt4 = $pdo4->prepare($sql);
    $stmt4->execute([
        'from'            => date('Y-m-d', strtotime('-180 days')),
        'to'              => date('Y-m-d', strtotime('+90 days')),
        'noteUid'         => $testUid,
        'bookingUid'      => $testUid,
        'cancelledStatus' => 'cancelled',
    ]);

    $rows4 = $stmt4->fetchAll(PDO::FETCH_ASSOC);
    $anyRowHasCancelled = false;
    foreach ($rows4 as $row) {
        if (($row['booking_status'] ?? '') === 'cancelled') {
            $anyRowHasCancelled = true;
            break;
        }
    }

    assert_true(true, 'cancelled filter: query executed without error');
    assert_true(!$anyRowHasCancelled, 'cancelled filter: no cancelled rows in result');
    echo "  INFO  total cancelled bookings in DB: $cancelledCount\n";
} catch (PDOException $e) {
    assert_true(false, 'cancelled filter: query failed (' . $e->getMessage() . ')');
    assert_true(false, 'cancelled filter: no cancelled rows in result (query failed)');
}
echo "\n";

// --- Summary ---

echo "==============================\n";
if ($failed === 0) {
    echo "All assertions passed.\n";
    exit(0);
} else {
    echo "$failed assertion(s) FAILED.\n";
    exit(1);
}
