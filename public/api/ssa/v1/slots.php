<?php
require_once __DIR__ . '/_auth0.php';
require_once __DIR__ . '/_db.php';

ssaApiRequireAuth0Claims();

function ssaApiFetchReservationsForSlots(PDO $pdo, string $from, string $to, array $tableIds): array
{
    if (count($tableIds) === 0) {
        return [];
    }

    $placeholders = [];
    $params = [
        'from' => $from,
        'to' => $to,
        'cancelledStatus' => 'cancelled',
    ];

    foreach ($tableIds as $index => $tableId) {
        $key = 'tableId' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $tableId;
    }

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
            u.firstname,
            u.lastname,
            u.name,
            u.alias
        FROM bs_reservations r
        INNER JOIN bs_bookings b ON b.bid = r.bid
        LEFT JOIN bs_users u ON u.uid = b.uid
        WHERE r.date >= :from
          AND r.date <= :to
          AND b.status <> :cancelledStatus
          AND b.sid IN (' . implode(',', $placeholders) . ')
        ORDER BY r.date ASC, r.time_start ASC, b.sid ASC, r.rid ASC';

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

function ssaApiIndexReservations(array $reservations): array
{
    $index = [];

    foreach ($reservations as $reservation) {
        $tableId = isset($reservation['sid']) ? (int)$reservation['sid'] : 0;
        $date = (string)$reservation['date'];

        if ($tableId <= 0 || $date === '') {
            continue;
        }

        $reservationStart = ssaApiTimeToSeconds($reservation['time_start'] ?? null);
        $reservationEnd = ssaApiTimeToSeconds($reservation['time_end'] ?? null);

        if ($reservationStart === null || $reservationEnd === null) {
            continue;
        }

        $reservation['_startSeconds'] = $reservationStart;
        $reservation['_endSeconds'] = $reservationEnd;

        if (!isset($index[$tableId])) {
            $index[$tableId] = [];
        }

        if (!isset($index[$tableId][$date])) {
            $index[$tableId][$date] = [];
        }

        $index[$tableId][$date][] = $reservation;
    }

    return $index;
}

function ssaApiFindOverlappingReservation(array $reservations, int $slotStart, int $slotEnd): ?array
{
    foreach ($reservations as $reservation) {
        $reservationStart = $reservation['_startSeconds'];
        $reservationEnd = $reservation['_endSeconds'];

        if ($slotStart < $reservationEnd && $slotEnd > $reservationStart) {
            return $reservation;
        }
    }

    return null;
}

function ssaApiDateList(string $from, string $to): array
{
    $timezone = new DateTimeZone(SSA_API_TIMEZONE);
    $dates = [];

    $current = new DateTimeImmutable($from, $timezone);
    $end = new DateTimeImmutable($to, $timezone);

    while ($current <= $end) {
        $dates[] = $current->format('Y-m-d');
        $current = $current->modify('+1 day');
    }

    return $dates;
}

try {
    $today = gmdate('Y-m-d');
    $defaultTo = gmdate('Y-m-d', strtotime('+7 days'));

    $from = ssaApiDateParam('from', $today);
    $to = ssaApiDateParam('to', $defaultTo);
    $tableIdFilter = ssaApiGetOptionalIntParam('tableId');
    $statusFilter = ssaApiGetStatusFilter('enabled');

    ssaApiValidateDateRange($from, $to, 31);

    $pdo = ssaApiCreatePdo();

    $squareRows = ssaApiFetchSquares($pdo, $statusFilter);

    if ($tableIdFilter !== null) {
        $squareRows = array_values(array_filter($squareRows, function (array $row) use ($tableIdFilter): bool {
            return isset($row['sid']) && (int)$row['sid'] === $tableIdFilter;
        }));
    }

    $tableIds = [];

    foreach ($squareRows as $row) {
        if (isset($row['sid'])) {
            $tableIds[] = (int)$row['sid'];
        }
    }

    $reservations = ssaApiFetchReservationsForSlots($pdo, $from, $to, $tableIds);
    $reservationIndex = ssaApiIndexReservations($reservations);
    $dates = ssaApiDateList($from, $to);

    $tables = [];

    foreach ($squareRows as $row) {
        $table = ssaApiFormatSquareRow($row);
        $tableId = (int)$table['id'];

        $dayStartSeconds = ssaApiTimeToSeconds($row['time_start'] ?? null);
        $dayEndSeconds = ssaApiTimeToSeconds($row['time_end'] ?? null);
        $blockSeconds = isset($row['time_block']) && is_numeric($row['time_block']) ? (int)$row['time_block'] : 1800;

        if ($dayStartSeconds === null || $dayEndSeconds === null || $blockSeconds <= 0) {
            $table['slots'] = [];
            $tables[] = $table;
            continue;
        }

        $slots = [];

        foreach ($dates as $date) {
            for ($slotStart = $dayStartSeconds; $slotStart < $dayEndSeconds; $slotStart += $blockSeconds) {
                $slotEnd = min($slotStart + $blockSeconds, $dayEndSeconds);

                if ($slotEnd <= $slotStart) {
                    continue;
                }

                $dayReservations = $reservationIndex[$tableId][$date] ?? [];
                $overlap = ssaApiFindOverlappingReservation($dayReservations, $slotStart, $slotEnd);

                $timeStart = ssaApiSecondsToTime($slotStart);
                $timeEnd = ssaApiSecondsToTime($slotEnd);

                $slot = [
                    'date' => $date,
                    'timeStart' => $timeStart,
                    'timeEnd' => $timeEnd,
                    'startLocal' => ssaApiDateTimeLocalIso($date, $timeStart),
                    'endLocal' => ssaApiDateTimeLocalIso($date, $timeEnd),
                    'status' => 'free',
                    'isBookable' => true,
                ];

                if ($overlap !== null) {
                    $bookingStatus = $overlap['booking_status'] ?? null;

                    $slot['status'] = $bookingStatus === 'subscription' ? 'subscription' : 'occupied';
                    $slot['isBookable'] = false;
                    $slot['reservationId'] = isset($overlap['rid']) ? (int)$overlap['rid'] : null;
                    $slot['bookingId'] = isset($overlap['bid']) ? (int)$overlap['bid'] : null;
                    $slot['userId'] = isset($overlap['uid']) ? (int)$overlap['uid'] : null;
                    $slot['bookedBy'] = ssaApiPublicBookedBy($overlap);
                    $slot['bookingStatus'] = $bookingStatus;
                    $slot['billingStatus'] = $overlap['status_billing'] ?? null;
                    $slot['visibility'] = $overlap['visibility'] ?? null;
                    $slot['quantity'] = isset($overlap['quantity']) ? (int)$overlap['quantity'] : null;
                }

                $slots[] = $slot;
            }
        }

        $table['slots'] = $slots;
        $tables[] = $table;
    }

    ssaApiJsonResponse(200, [
        'status' => 'ok',
        'source' => 'live_database',
        'mode' => 'full_slot_grid',
        'timezone' => SSA_API_TIMEZONE,
        'from' => $from,
        'to' => $to,
        'filter' => [
            'status' => $statusFilter ?? 'all',
            'tableId' => $tableIdFilter,
        ],
        'tableCount' => count($tables),
        'tables' => $tables,
    ]);
} catch (Throwable $exception) {
    error_log('SSA API slots endpoint failed: ' . $exception->getMessage());

    ssaApiJsonResponse(500, [
        'error' => 'slots_query_failed',
        'message' => 'Unable to generate slot grid from the booking database.',
    ]);
}