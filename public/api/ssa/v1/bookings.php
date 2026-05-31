<?php
require_once __DIR__ . '/_auth0.php';
require_once __DIR__ . '/_db.php';

ssaApiRequireAuth0Claims();

try {
    $today = gmdate('Y-m-d');
    $defaultTo = gmdate('Y-m-d', strtotime('+7 days'));

    $from = ssaApiDateParam('from', $today);
    $to = ssaApiDateParam('to', $defaultTo);
    $tableId = ssaApiGetOptionalIntParam('tableId');

    ssaApiValidateDateRange($from, $to, 62);

    $pdo = ssaApiCreatePdo();

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
            s.status AS table_status
        FROM bs_reservations r
        INNER JOIN bs_bookings b ON b.bid = r.bid
        LEFT JOIN bs_squares s ON s.sid = b.sid
        WHERE r.date >= :from
          AND r.date <= :to
          AND b.status <> :cancelledStatus';

    $params = [
        'from' => $from,
        'to' => $to,
        'cancelledStatus' => 'cancelled',
    ];

    if ($tableId !== null) {
        $sql .= ' AND b.sid = :tableId';
        $params['tableId'] = $tableId;
    }

    $sql .= ' ORDER BY r.date ASC, r.time_start ASC, b.sid ASC, r.rid ASC';

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    $rows = $statement->fetchAll();
    $reservations = [];

    foreach ($rows as $row) {
        $date = (string)$row['date'];

        $reservations[] = [
            'reservationId' => isset($row['rid']) ? (int)$row['rid'] : null,
            'bookingId' => isset($row['bid']) ? (int)$row['bid'] : null,
            'userId' => isset($row['uid']) ? (int)$row['uid'] : null,
            'tableId' => isset($row['sid']) ? (int)$row['sid'] : null,
            'tableName' => $row['table_name'] ?? null,
            'tableStatus' => $row['table_status'] ?? null,
            'date' => $date,
            'timeStart' => ssaApiNormaliseTimeValue($row['time_start'] ?? null),
            'timeEnd' => ssaApiNormaliseTimeValue($row['time_end'] ?? null),
            'startLocal' => ssaApiDateTimeLocalIso($date, $row['time_start'] ?? null),
            'endLocal' => ssaApiDateTimeLocalIso($date, $row['time_end'] ?? null),
            'status' => 'occupied',
            'bookingStatus' => $row['booking_status'] ?? null,
            'billingStatus' => $row['status_billing'] ?? null,
            'visibility' => $row['visibility'] ?? null,
            'quantity' => isset($row['quantity']) ? (int)$row['quantity'] : null,
            'created' => $row['created'] ?? null,
        ];
    }

    ssaApiJsonResponse(200, [
        'status' => 'ok',
        'source' => 'live_database',
        'mode' => 'occupied_reservations_only',
        'timezone' => SSA_API_TIMEZONE,
        'from' => $from,
        'to' => $to,
        'filter' => [
            'tableId' => $tableId,
        ],
        'count' => count($reservations),
        'reservations' => $reservations,
    ]);
} catch (Throwable $exception) {
    error_log('SSA API bookings endpoint failed: ' . $exception->getMessage());

    ssaApiJsonResponse(500, [
        'error' => 'bookings_query_failed',
        'message' => 'Unable to read bookings from the booking database.',
    ]);
}