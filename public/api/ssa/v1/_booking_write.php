<?php

function ssaApiReadJsonBodyArray(): array
{
    $rawBody = file_get_contents('php://input');

    if ($rawBody === false || trim($rawBody) === '') {
        ssaApiJsonResponse(400, [
            'error' => 'empty_body',
            'message' => 'Request body must contain JSON.',
        ]);
    }

    $body = json_decode($rawBody, true);

    if (!is_array($body)) {
        ssaApiJsonResponse(400, [
            'error' => 'invalid_json',
            'message' => 'Request body must be valid JSON.',
        ]);
    }

    return $body;
}

function ssaApiRequireLinkedBookingUser(PDO $pdo, array $claims): array
{
    $auth0Sub = isset($claims['sub']) ? trim((string)$claims['sub']) : '';

    if ($auth0Sub === '') {
        ssaApiJsonResponse(401, [
            'error' => 'missing_auth0_subject',
            'message' => 'Auth0 token does not contain a subject.',
        ]);
    }

    $statement = $pdo->prepare(
        'SELECT
            l.id,
            l.uid,
            l.linked_email,
            l.linked_alias,
            u.status AS user_status,
            u.alias AS user_alias,
            u.email AS user_email
         FROM ssa_auth0_user_links l
         INNER JOIN bs_users u ON u.uid = l.uid
         WHERE l.auth0_sub = :auth0Sub
           AND l.revoked_at IS NULL
         LIMIT 1'
    );

    $statement->execute([
        'auth0Sub' => $auth0Sub,
    ]);

    $row = $statement->fetch();

    if (!$row) {
        ssaApiJsonResponse(403, [
            'error' => 'booking_account_not_linked',
            'message' => 'No linked booking account was found for this app login.',
        ]);
    }

    if (($row['user_status'] ?? '') !== 'enabled') {
        ssaApiJsonResponse(403, [
            'error' => 'booking_account_disabled',
            'message' => 'The linked booking account is not enabled.',
        ]);
    }

    return [
        'uid' => (int)$row['uid'],
        'alias' => $row['linked_alias'] ?: ($row['user_alias'] ?? null),
        'email' => $row['linked_email'] ?: ($row['user_email'] ?? null),
        'auth0Sub' => $auth0Sub,
    ];
}

function ssaApiRequireDateValue($value, string $name): string
{
    $value = trim((string)$value);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        ssaApiJsonResponse(400, [
            'error' => 'invalid_' . $name,
            'message' => 'Parameter "' . $name . '" must be in YYYY-MM-DD format.',
        ]);
    }

    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(SSA_API_TIMEZONE));
    $errors = DateTimeImmutable::getLastErrors();

    if (!$dt || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        ssaApiJsonResponse(400, [
            'error' => 'invalid_' . $name,
            'message' => 'Parameter "' . $name . '" is not a valid date.',
        ]);
    }

    return $dt->format('Y-m-d');
}

function ssaApiRequireTimeValue($value, string $name): string
{
    $value = trim((string)$value);

    if (!preg_match('/^(00|0?[1-9]|1[0-9]|2[0-3]):([0-5][0-9])(?::([0-5][0-9]))?$/', $value)) {
        ssaApiJsonResponse(400, [
            'error' => 'invalid_' . $name,
            'message' => 'Parameter "' . $name . '" must be in HH:MM format.',
        ]);
    }

    $parts = explode(':', $value);
    return sprintf('%02d:%02d', (int)$parts[0], (int)$parts[1]);
}

function ssaApiRequirePositiveIntValue($value, string $name): int
{
    if (!is_numeric($value) || (int)$value <= 0 || (string)(int)$value !== (string)$value && !is_int($value)) {
        ssaApiJsonResponse(400, [
            'error' => 'invalid_' . $name,
            'message' => 'Parameter "' . $name . '" must be a positive integer.',
        ]);
    }

    return (int)$value;
}

function ssaApiRequiredGetIntParam(string $name): int
{
    if (!isset($_GET[$name]) || trim((string)$_GET[$name]) === '') {
        ssaApiJsonResponse(400, [
            'error' => 'missing_' . $name,
            'message' => 'Parameter "' . $name . '" is required.',
        ]);
    }

    return ssaApiRequirePositiveIntValue($_GET[$name], $name);
}

function ssaApiRequiredGetDateParam(string $name): string
{
    if (!isset($_GET[$name]) || trim((string)$_GET[$name]) === '') {
        ssaApiJsonResponse(400, [
            'error' => 'missing_' . $name,
            'message' => 'Parameter "' . $name . '" is required.',
        ]);
    }

    return ssaApiRequireDateValue($_GET[$name], $name);
}

function ssaApiRequiredGetTimeParam(string $name): string
{
    if (!isset($_GET[$name]) || trim((string)$_GET[$name]) === '') {
        ssaApiJsonResponse(400, [
            'error' => 'missing_' . $name,
            'message' => 'Parameter "' . $name . '" is required.',
        ]);
    }

    return ssaApiRequireTimeValue($_GET[$name], $name);
}

function ssaApiFetchEnabledSquare(PDO $pdo, int $tableId): array
{
    $statement = $pdo->prepare(
        'SELECT
            sid,
            name,
            status,
            priority,
            capacity,
            capacity_heterogenic,
            time_start,
            time_end,
            time_block,
            time_block_bookable,
            time_block_bookable_max,
            min_range_book,
            range_book,
            max_active_bookings,
            range_cancel
         FROM bs_squares
         WHERE sid = :sid
         LIMIT 1'
    );

    $statement->execute([
        'sid' => $tableId,
    ]);

    $row = $statement->fetch();

    if (!$row) {
        ssaApiJsonResponse(404, [
            'error' => 'table_not_found',
            'message' => 'The requested table was not found.',
        ]);
    }

    if (($row['status'] ?? '') !== 'enabled') {
        ssaApiJsonResponse(409, [
            'error' => 'table_not_bookable',
            'message' => 'The requested table is not currently bookable.',
        ]);
    }

    return $row;
}

function ssaApiBuildDateTime(string $date, string $time): DateTimeImmutable
{
    return new DateTimeImmutable($date . ' ' . $time, new DateTimeZone(SSA_API_TIMEZONE));
}

function ssaApiDurationLabel(int $seconds): string
{
    $minutes = (int)round($seconds / 60);

    if ($minutes < 60) {
        return $minutes . ' minutes';
    }

    $hours = intdiv($minutes, 60);
    $remainingMinutes = $minutes % 60;

    if ($remainingMinutes === 0) {
        return $hours === 1 ? '1 hour' : $hours . ' hours';
    }

    return ($hours === 1 ? '1 hour' : $hours . ' hours') . ' ' . $remainingMinutes . ' minutes';
}

function ssaApiSecondsDiff(DateTimeImmutable $start, DateTimeImmutable $end): int
{
    return $end->getTimestamp() - $start->getTimestamp();
}

function ssaApiBookingRangeChecks(array $square, string $date, string $timeStart, string $timeEnd): array
{
    $start = ssaApiBuildDateTime($date, $timeStart);
    $end = ssaApiBuildDateTime($date, $timeEnd);
    $now = new DateTimeImmutable('now', new DateTimeZone(SSA_API_TIMEZONE));

    if ($end <= $start) {
        return [
            'ok' => false,
            'error' => 'invalid_time_range',
            'message' => 'The booking end time must be after the start time.',
        ];
    }

    $squareStartSeconds = ssaApiTimeToSeconds($square['time_start'] ?? null);
    $squareEndSeconds = ssaApiTimeToSeconds($square['time_end'] ?? null);
    $startSeconds = ssaApiTimeToSeconds($timeStart);
    $endSeconds = ssaApiTimeToSeconds($timeEnd);

    if ($squareStartSeconds === null || $squareEndSeconds === null || $startSeconds === null || $endSeconds === null) {
        return [
            'ok' => false,
            'error' => 'invalid_table_time_configuration',
            'message' => 'The table booking time configuration is invalid.',
        ];
    }

    if ($startSeconds < $squareStartSeconds || $endSeconds > $squareEndSeconds) {
        return [
            'ok' => false,
            'error' => 'outside_table_hours',
            'message' => 'The requested booking is outside the table opening hours.',
        ];
    }

    $blockSeconds = isset($square['time_block']) && is_numeric($square['time_block']) ? (int)$square['time_block'] : 1800;
    $bookableBlockSeconds = isset($square['time_block_bookable']) && is_numeric($square['time_block_bookable']) ? (int)$square['time_block_bookable'] : $blockSeconds;

    if ($blockSeconds <= 0) {
        $blockSeconds = 1800;
    }

    if ($bookableBlockSeconds <= 0) {
        $bookableBlockSeconds = $blockSeconds;
    }

    if ((($startSeconds - $squareStartSeconds) % $blockSeconds) !== 0 || (($endSeconds - $squareStartSeconds) % $blockSeconds) !== 0) {
        return [
            'ok' => false,
            'error' => 'time_not_aligned',
            'message' => 'The requested booking time does not align with the table booking blocks.',
        ];
    }

    $durationSeconds = ssaApiSecondsDiff($start, $end);

    if ($durationSeconds < $bookableBlockSeconds) {
        return [
            'ok' => false,
            'error' => 'duration_too_short',
            'message' => 'The requested booking duration is too short.',
        ];
    }

    $maxBookableSeconds = isset($square['time_block_bookable_max']) && is_numeric($square['time_block_bookable_max']) ? (int)$square['time_block_bookable_max'] : null;

    if ($maxBookableSeconds !== null && $maxBookableSeconds > 0 && $durationSeconds > $maxBookableSeconds) {
        return [
            'ok' => false,
            'error' => 'duration_too_long',
            'message' => 'The requested booking duration is longer than allowed for this table.',
        ];
    }

    $minRangeBook = isset($square['min_range_book']) && is_numeric($square['min_range_book']) ? (int)$square['min_range_book'] : 0;

    if ($minRangeBook > 0) {
        if ($start->getTimestamp() < ($now->getTimestamp() + $minRangeBook)) {
            return [
                'ok' => false,
                'error' => 'booking_too_short_term',
                'message' => 'This booking is too short-term.',
            ];
        }
    } else {
        $graceSeconds = (int)floor($bookableBlockSeconds / 2);
        if ($start->getTimestamp() < ($now->getTimestamp() - $graceSeconds)) {
            return [
                'ok' => false,
                'error' => 'booking_in_past',
                'message' => 'This booking time has already passed.',
            ];
        }
    }

    $rangeBook = isset($square['range_book']) && is_numeric($square['range_book']) ? (int)$square['range_book'] : null;

    if ($rangeBook !== null && $rangeBook > 0 && $start->getTimestamp() > ($now->getTimestamp() + $rangeBook)) {
        return [
            'ok' => false,
            'error' => 'booking_too_far_ahead',
            'message' => 'This booking is too far in the future.',
        ];
    }

    return [
        'ok' => true,
        'start' => $start,
        'end' => $end,
        'durationSeconds' => $durationSeconds,
        'blockSeconds' => $blockSeconds,
    ];
}

function ssaApiFetchOverlappingBookings(PDO $pdo, int $tableId, string $date, string $timeStart, string $timeEnd): array
{
    $statement = $pdo->prepare(
        'SELECT
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
            b.quantity
         FROM bs_reservations r
         INNER JOIN bs_bookings b ON b.bid = r.bid
         WHERE r.date = :date
           AND b.sid = :sid
           AND b.status <> :cancelledStatus
           AND r.time_start < :timeEnd
           AND r.time_end > :timeStart
         ORDER BY r.time_start ASC, r.rid ASC'
    );

    $statement->execute([
        'date' => $date,
        'sid' => $tableId,
        'cancelledStatus' => 'cancelled',
        'timeStart' => $timeStart,
        'timeEnd' => $timeEnd,
    ]);

    return $statement->fetchAll();
}

function ssaApiHasBlockingEvent(PDO $pdo, int $tableId, DateTimeImmutable $start, DateTimeImmutable $end): bool
{
    $statement = $pdo->prepare(
        'SELECT eid
         FROM bs_events
         WHERE status = :enabledStatus
           AND (sid IS NULL OR sid = :sid)
           AND datetime_start < :endDateTime
           AND datetime_end > :startDateTime
         LIMIT 1'
    );

    $statement->execute([
        'enabledStatus' => 'enabled',
        'sid' => $tableId,
        'startDateTime' => $start->format('Y-m-d H:i:s'),
        'endDateTime' => $end->format('Y-m-d H:i:s'),
    ]);

    return (bool)$statement->fetch();
}

function ssaApiValidateCapacityAndOverlap(PDO $pdo, array $square, int $tableId, string $date, string $timeStart, string $timeEnd, int $quantity = 1): array
{
    $overlaps = ssaApiFetchOverlappingBookings($pdo, $tableId, $date, $timeStart, $timeEnd);

    $existingQuantity = 0;

    foreach ($overlaps as $overlap) {
        $existingQuantity += isset($overlap['quantity']) ? (int)$overlap['quantity'] : 1;
    }

    $capacity = isset($square['capacity']) ? (int)$square['capacity'] : 1;
    $capacityHeterogenic = !empty($square['capacity_heterogenic']);

    if ($existingQuantity > 0 && !$capacityHeterogenic) {
        return [
            'ok' => false,
            'error' => 'slot_not_available',
            'message' => 'This time slot is no longer available.',
        ];
    }

    if (($capacity - $existingQuantity) < $quantity) {
        return [
            'ok' => false,
            'error' => 'slot_not_available',
            'message' => 'This time slot is no longer available.',
        ];
    }

    return [
        'ok' => true,
        'overlaps' => $overlaps,
    ];
}

function ssaApiValidateActiveBookingLimit(PDO $pdo, array $square, int $uid): array
{
    $maxActiveBookings = isset($square['max_active_bookings']) && is_numeric($square['max_active_bookings']) ? (int)$square['max_active_bookings'] : 0;

    if ($maxActiveBookings <= 0) {
        return [
            'ok' => true,
        ];
    }

    $now = new DateTimeImmutable('now', new DateTimeZone(SSA_API_TIMEZONE));

    $statement = $pdo->prepare(
        'SELECT COUNT(DISTINCT b.bid) AS active_count
         FROM bs_bookings b
         INNER JOIN bs_reservations r ON r.bid = b.bid
         WHERE b.uid = :uid
           AND b.status <> :cancelledStatus
           AND CONCAT(r.date, " ", r.time_start) > :nowDateTime'
    );

    $statement->execute([
        'uid' => $uid,
        'cancelledStatus' => 'cancelled',
        'nowDateTime' => $now->format('Y-m-d H:i:s'),
    ]);

    $count = (int)$statement->fetchColumn();

    if ($count >= $maxActiveBookings) {
        return [
            'ok' => false,
            'error' => 'too_many_active_bookings',
            'message' => 'You have reached the maximum number of active bookings.',
        ];
    }

    return [
        'ok' => true,
    ];
}

/**
 * Returns the earliest permitted amendment end time in seconds from midnight.
 *
 * The block that contains the current moment — including one that begins
 * exactly now — is considered started and may not be shortened away.
 *
 * Uses intdiv+1 so that exact slot boundaries are correctly treated as the
 * start of the next block (ceil() fails at exact boundaries).
 *
 * @param int $startSec  Booking start, seconds from midnight
 * @param int $nowSec    Current time, seconds from midnight (include sub-minute seconds)
 * @param int $blockSec  Block duration in seconds (e.g. 1800)
 */
function ssaApiEarliestAmendEndSec(int $startSec, int $nowSec, int $blockSec): int
{
    $elapsedSec    = max(0, $nowSec - $startSec);
    $startedBlocks = intdiv($elapsedSec, $blockSec) + 1;
    return $startSec + ($startedBlocks * $blockSec);
}

function ssaApiBookingPayload(array $bookingRow, array $reservationRow, array $squareRow): array
{
    $date = (string)$reservationRow['date'];
    $timeStart = ssaApiNormaliseTimeValue($reservationRow['time_start'] ?? null);
    $timeEnd = ssaApiNormaliseTimeValue($reservationRow['time_end'] ?? null);

    return [
        'reservationId' => isset($reservationRow['rid']) ? (int)$reservationRow['rid'] : null,
        'bookingId' => isset($bookingRow['bid']) ? (int)$bookingRow['bid'] : null,
        'tableId' => isset($bookingRow['sid']) ? (int)$bookingRow['sid'] : null,
        'tableName' => $squareRow['name'] ?? null,
        'date' => $date,
        'timeStart' => $timeStart,
        'timeEnd' => $timeEnd,
        'startLocal' => ssaApiDateTimeLocalIso($date, $timeStart),
        'endLocal' => ssaApiDateTimeLocalIso($date, $timeEnd),
        'bookingStatus' => $bookingRow['status'] ?? null,
        'billingStatus' => $bookingRow['status_billing'] ?? null,
        'visibility' => $bookingRow['visibility'] ?? null,
        'quantity' => isset($bookingRow['quantity']) ? (int)$bookingRow['quantity'] : null,
    ];
}