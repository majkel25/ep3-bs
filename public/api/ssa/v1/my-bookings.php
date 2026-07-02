<?php
require_once __DIR__ . '/_auth0.php';
require_once __DIR__ . '/_db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ssaApiJsonResponse(405, [
        'error' => 'method_not_allowed',
        'message' => 'Only GET is allowed for this endpoint.',
    ]);
}

$claims = ssaApiRequireAuth0Claims();

$auth0Sub = isset($claims['sub']) ? trim((string)$claims['sub']) : '';

if ($auth0Sub === '') {
    ssaApiJsonResponse(401, [
        'error' => 'missing_auth0_subject',
        'message' => 'Auth0 token does not contain a subject.',
    ]);
}

function ssaApiMyBookingsScope(): string
{
    $scope = isset($_GET['scope']) ? strtolower(trim((string)$_GET['scope'])) : 'upcoming';

    if ($scope === '') {
        $scope = 'upcoming';
    }

    if (!in_array($scope, ['upcoming', 'past', 'all'], true)) {
        ssaApiJsonResponse(400, [
            'error' => 'invalid_scope',
            'message' => 'Parameter "scope" must be one of: upcoming, past, all.',
        ]);
    }

    return $scope;
}

function ssaApiDefaultDateRangeForScope(string $scope): array
{
    $timezone = new DateTimeZone(SSA_API_TIMEZONE);
    $today = new DateTimeImmutable('today', $timezone);

    if ($scope === 'past') {
        return [
            $today->modify('-180 days')->format('Y-m-d'),
            $today->format('Y-m-d'),
        ];
    }

    if ($scope === 'all') {
        return [
            $today->modify('-180 days')->format('Y-m-d'),
            $today->modify('+90 days')->format('Y-m-d'),
        ];
    }

    return [
        $today->format('Y-m-d'),
        $today->modify('+90 days')->format('Y-m-d'),
    ];
}

function ssaApiBookingIsPast(string $date, ?string $timeEnd): bool
{
    $timezone = new DateTimeZone(SSA_API_TIMEZONE);
    $now = new DateTimeImmutable('now', $timezone);

    $normalisedTimeEnd = ssaApiNormaliseTimeValue($timeEnd);

    if ($normalisedTimeEnd === null) {
        $bookingDate = new DateTimeImmutable($date . ' 23:59:59', $timezone);
        return $bookingDate < $now;
    }

    $bookingEnd = new DateTimeImmutable($date . ' ' . $normalisedTimeEnd, $timezone);
    return $bookingEnd < $now;
}

try {
    $scope = ssaApiMyBookingsScope();
    [$defaultFrom, $defaultTo] = ssaApiDefaultDateRangeForScope($scope);

    $from = ssaApiDateParam('from', $defaultFrom);
    $to = ssaApiDateParam('to', $defaultTo);

    ssaApiValidateDateRange($from, $to, 370);

    $pdo = ssaApiCreatePdo();

    $linkStatement = $pdo->prepare(
        'SELECT
            id,
            uid,
            linked_email,
            linked_alias,
            revoked_at
         FROM ssa_auth0_user_links
         WHERE auth0_sub = :auth0Sub
           AND revoked_at IS NULL
         LIMIT 1'
    );

    $linkStatement->execute([
        'auth0Sub' => $auth0Sub,
    ]);

    $link = $linkStatement->fetch();

    if (!$link) {
        ssaApiJsonResponse(403, [
            'error' => 'booking_account_not_linked',
            'message' => 'No linked booking account was found for this app login.',
        ]);
    }

    $uid = isset($link['uid']) ? (int)$link['uid'] : 0;

    if ($uid <= 0) {
        ssaApiJsonResponse(403, [
            'error' => 'booking_account_not_linked',
            'message' => 'No linked booking account was found for this app login.',
        ]);
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
            s.name AS table_name,
            s.status AS table_status,
            n.note AS private_note
        FROM bs_reservations r
        INNER JOIN bs_bookings b ON b.bid = r.bid
        LEFT JOIN bs_squares s ON s.sid = b.sid
        LEFT JOIN ssa_booking_private_notes n ON n.booking_id = b.bid AND n.uid = :uid
        WHERE r.date >= :from
          AND r.date <= :to
          AND b.uid = :uid
          AND b.status <> :cancelledStatus
        ORDER BY r.date ASC, r.time_start ASC, b.sid ASC, r.rid ASC';

    $statement = $pdo->prepare($sql);

    $statement->execute([
        'from' => $from,
        'to' => $to,
        'uid' => $uid,
        'cancelledStatus' => 'cancelled',
    ]);

    $rows = $statement->fetchAll();
    $bookings = [];

    foreach ($rows as $row) {
        $date = (string)$row['date'];
        $timeStart = ssaApiNormaliseTimeValue($row['time_start'] ?? null);
        $timeEnd = ssaApiNormaliseTimeValue($row['time_end'] ?? null);

        $bookings[] = [
            'reservationId' => isset($row['rid']) ? (int)$row['rid'] : null,
            'bookingId' => isset($row['bid']) ? (int)$row['bid'] : null,
            'tableId' => isset($row['sid']) ? (int)$row['sid'] : null,
            'tableName' => $row['table_name'] ?? null,
            'tableStatus' => $row['table_status'] ?? null,
            'date' => $date,
            'timeStart' => $timeStart,
            'timeEnd' => $timeEnd,
            'startLocal' => ssaApiDateTimeLocalIso($date, $timeStart),
            'endLocal' => ssaApiDateTimeLocalIso($date, $timeEnd),
            'bookingStatus' => $row['booking_status'] ?? null,
            'billingStatus' => $row['status_billing'] ?? null,
            'visibility' => $row['visibility'] ?? null,
            'quantity' => isset($row['quantity']) ? (int)$row['quantity'] : null,
            'created' => $row['created'] ?? null,
            'isPast'      => ssaApiBookingIsPast($date, $timeEnd),
            'privateNote' => isset($row['private_note']) && $row['private_note'] !== '' ? (string)$row['private_note'] : null,
        ];
    }

    ssaApiJsonResponse(200, [
        'status' => 'ok',
        'source' => 'live_database',
        'timezone' => SSA_API_TIMEZONE,
        'scope' => $scope,
        'from' => $from,
        'to' => $to,
        'count' => count($bookings),
        'user' => [
            'uid' => $uid,
            'alias' => $link['linked_alias'] ?? null,
            'email' => $link['linked_email'] ?? null,
        ],
        'bookings' => $bookings,
    ]);
} catch (Throwable $exception) {
    error_log('SSA API my bookings endpoint failed: ' . $exception->getMessage());

    ssaApiJsonResponse(500, [
        'error' => 'my_bookings_query_failed',
        'message' => 'Unable to read your bookings from the booking database.',
    ]);
}