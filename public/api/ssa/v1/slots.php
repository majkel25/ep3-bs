<?php
require_once __DIR__ . '/_auth0.php';
require_once __DIR__ . '/_db.php';

$slotsClaims = ssaApiRequireAuth0Claims();

// ---------------------------------------------------------------------------
// Reservations (bs_reservations + bs_bookings)
// ---------------------------------------------------------------------------

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

// ---------------------------------------------------------------------------
// Blocking events (bs_events + bs_events_meta)
// ---------------------------------------------------------------------------

/**
 * Fetches enabled events that overlap the given datetime range, for the given
 * table IDs (including any all-table events where sid IS NULL).
 *
 * rangeStart / rangeEnd must be full Europe/London datetime strings
 * (Y-m-d H:i:s) matching what is stored in bs_events.
 */
function ssaApiFetchBlockingEventsForSlots(PDO $pdo, string $rangeStart, string $rangeEnd, array $tableIds): array
{
    $params = [
        'metaKeyName'  => 'name',
        'enabledStatus' => 'enabled',
        'rangeStart'   => $rangeStart,
        'rangeEnd'     => $rangeEnd,
    ];

    if (empty($tableIds)) {
        // No tables requested — only all-table events are relevant.
        // sid IS NULL  → all-table (standard)
        // sid = 0      → all-table (legacy fallback)
        $tableSql = '(e.sid IS NULL OR e.sid = 0)';
    } else {
        $placeholders = [];

        foreach ($tableIds as $index => $tableId) {
            $key = 'tableId' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $tableId;
        }

        // sid IS NULL  → all-table (standard)
        // sid = 0      → all-table (legacy fallback)
        // sid IN (...)  → table-specific
        $tableSql = '(e.sid IS NULL OR e.sid = 0 OR e.sid IN (' . implode(',', $placeholders) . '))';
    }

    $sql = 'SELECT
                e.eid,
                e.sid,
                e.datetime_start,
                e.datetime_end,
                MIN(em.value) AS event_name
            FROM bs_events e
            LEFT JOIN bs_events_meta em ON em.eid = e.eid AND em.key = :metaKeyName
            WHERE e.status = :enabledStatus
              AND e.datetime_end > :rangeStart
              AND e.datetime_start < :rangeEnd
              AND ' . $tableSql . '
            GROUP BY e.eid, e.sid, e.datetime_start, e.datetime_end
            ORDER BY e.datetime_start ASC';

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

/**
 * Indexes events for O(1) per-slot lookup.
 * Returns:
 *   'allTable' => [...events with sid=NULL, apply to every table...]
 *   'byTable'  => [tableId => [...table-specific events...], ...]
 */
function ssaApiIndexBlockingEvents(array $events): array
{
    $index = ['allTable' => [], 'byTable' => []];

    foreach ($events as $event) {
        $rawName = isset($event['event_name']) ? trim((string)$event['event_name']) : '';
        $eventName = ($rawName !== '' && $rawName !== '?') ? $rawName : 'Club event';

        $entry = [
            'eid'            => (int)$event['eid'],
            'sid'            => $event['sid'],
            'datetime_start' => (string)$event['datetime_start'],
            'datetime_end'   => (string)$event['datetime_end'],
            'event_name'     => $eventName,
        ];

        // sid IS NULL or sid = 0 → all-table event (NULL is standard; 0 is legacy fallback)
        $isAllTable = $event['sid'] === null || (int)$event['sid'] === 0;

        if ($isAllTable) {
            $index['allTable'][] = $entry;
        } else {
            $tableId = (int)$event['sid'];

            if (!isset($index['byTable'][$tableId])) {
                $index['byTable'][$tableId] = [];
            }

            $index['byTable'][$tableId][] = $entry;
        }
    }

    return $index;
}

/**
 * Returns the first event (if any) that overlaps the given slot datetime range.
 *
 * Overlap uses the half-open interval convention:
 *   slotStart < eventEnd  AND  slotEnd > eventStart
 *
 * All-table events (sid IS NULL) are checked before table-specific events,
 * matching the order used by ssaApiHasBlockingEvent() in _booking_write.php.
 *
 * @param string $slotStartDatetime  Y-m-d H:i:s (Europe/London)
 * @param string $slotEndDatetime    Y-m-d H:i:s (Europe/London)
 */
function ssaApiFindOverlappingEvent(array $eventIndex, int $tableId, string $slotStartDatetime, string $slotEndDatetime): ?array
{
    foreach ($eventIndex['allTable'] as $event) {
        if ($slotStartDatetime < $event['datetime_end'] && $slotEndDatetime > $event['datetime_start']) {
            return $event;
        }
    }

    if (isset($eventIndex['byTable'][$tableId])) {
        foreach ($eventIndex['byTable'][$tableId] as $event) {
            if ($slotStartDatetime < $event['datetime_end'] && $slotEndDatetime > $event['datetime_start']) {
                return $event;
            }
        }
    }

    return null;
}

// ---------------------------------------------------------------------------
// Date list helper
// ---------------------------------------------------------------------------

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

// ---------------------------------------------------------------------------
// Main handler
// ---------------------------------------------------------------------------

try {
    // Use Europe/London for the default date so BST transitions do not shift
    // the displayed week by one day.
    $tz = new DateTimeZone(SSA_API_TIMEZONE);
    $now = new DateTimeImmutable('now', $tz);
    $today = $now->format('Y-m-d');
    $defaultTo = $now->modify('+7 days')->format('Y-m-d');

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

    // Resolve the authenticated member's UID so we can attach their private notes
    // to their own occupied slots. Non-fatal: if the user is not linked or the
    // lookup fails we simply proceed without note data.
    $slotsAuthenticatedUid = null;
    $slotsAuthSub = isset($slotsClaims['sub']) ? trim((string)$slotsClaims['sub']) : '';
    if ($slotsAuthSub !== '') {
        try {
            $slotsLinkStmt = $pdo->prepare(
                'SELECT uid FROM ssa_auth0_user_links
                 WHERE auth0_sub = :sub AND revoked_at IS NULL
                 LIMIT 1'
            );
            $slotsLinkStmt->execute(['sub' => $slotsAuthSub]);
            $slotsLink = $slotsLinkStmt->fetch();
            if ($slotsLink) {
                $slotsAuthenticatedUid = (int)$slotsLink['uid'];
            }
        } catch (Throwable $e) {
            // Non-fatal: proceed without private notes.
        }
    }

    // Load reservations (bs_reservations + bs_bookings, excluding cancelled).
    $reservations = ssaApiFetchReservationsForSlots($pdo, $from, $to, $tableIds);
    $reservationIndex = ssaApiIndexReservations($reservations);

    // Build a map of booking_id → private_note for the authenticated user.
    // Wrapped in try/catch so a missing table on first deploy is non-fatal.
    $slotsPrivateNoteIndex = [];
    if ($slotsAuthenticatedUid !== null && count($reservations) > 0) {
        try {
            $slotsBidList = array_values(array_unique(array_filter(
                array_map(fn($r) => isset($r['bid']) ? (int)$r['bid'] : null, $reservations),
                fn($bid) => $bid !== null && $bid > 0
            )));
            if (count($slotsBidList) > 0) {
                $slotsNotePlaceholders = [];
                $slotsNoteParams = ['uid' => $slotsAuthenticatedUid];
                foreach ($slotsBidList as $i => $bid) {
                    $pk = 'nbid' . $i;
                    $slotsNotePlaceholders[] = ':' . $pk;
                    $slotsNoteParams[$pk] = $bid;
                }
                $slotsNoteStmt = $pdo->prepare(
                    'SELECT booking_id, note
                     FROM ssa_booking_private_notes
                     WHERE uid = :uid
                       AND booking_id IN (' . implode(',', $slotsNotePlaceholders) . ')'
                );
                $slotsNoteStmt->execute($slotsNoteParams);
                foreach ($slotsNoteStmt->fetchAll() as $nr) {
                    $slotsPrivateNoteIndex[(int)$nr['booking_id']] = (string)$nr['note'];
                }
            }
        } catch (Throwable $e) {
            // Table may not exist yet on a fresh deployment.
        }
    }

    // Load blocking events (bs_events + bs_events_meta).
    // Range is [from 00:00, (to+1) 00:00) in Europe/London — covers the full
    // date range and handles events that cross midnight or span several days.
    $eventRangeStart = (new DateTimeImmutable($from . ' 00:00:00', $tz))->format('Y-m-d H:i:s');
    $eventRangeEnd   = (new DateTimeImmutable($to . ' 00:00:00', $tz))->modify('+1 day')->format('Y-m-d H:i:s');
    $rawEvents = ssaApiFetchBlockingEventsForSlots($pdo, $eventRangeStart, $eventRangeEnd, $tableIds);
    $eventIndex = ssaApiIndexBlockingEvents($rawEvents);

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

                $timeStart = ssaApiSecondsToTime($slotStart);
                $timeEnd = ssaApiSecondsToTime($slotEnd);

                // Full datetime strings for event overlap comparison.
                // These are Europe/London local times, matching how create-booking.php
                // calls ssaApiHasBlockingEvent() via ssaApiBuildDateTime().
                $slotStartDatetime = $date . ' ' . $timeStart . ':00';
                $slotEndDatetime   = $date . ' ' . $timeEnd . ':00';

                $slot = [
                    'date'       => $date,
                    'timeStart'  => $timeStart,
                    'timeEnd'    => $timeEnd,
                    'startLocal' => ssaApiDateTimeLocalIso($date, $timeStart),
                    'endLocal'   => ssaApiDateTimeLocalIso($date, $timeEnd),
                    'status'     => 'free',
                    'isBookable' => true,
                ];

                // Check for blocking events FIRST — exactly as create-booking.php
                // calls ssaApiHasBlockingEvent() before ssaApiValidateCapacityAndOverlap().
                $blockingEvent = ssaApiFindOverlappingEvent(
                    $eventIndex,
                    $tableId,
                    $slotStartDatetime,
                    $slotEndDatetime
                );

                if ($blockingEvent !== null) {
                    $slot['status']         = 'event';
                    $slot['isBookable']     = false;
                    $slot['eventId']        = $blockingEvent['eid'];
                    $slot['eventName']      = $blockingEvent['event_name'];
                    $slot['blockingReason'] = 'club_event';
                    // Reservation fields are intentionally absent for event slots.
                    $slot['reservationId']  = null;
                    $slot['bookingId']      = null;
                    $slot['userId']         = null;
                    $slot['bookedBy']       = null;
                    $slot['bookingStatus']  = null;
                    $slot['billingStatus']  = null;
                    $slot['visibility']     = null;
                    $slot['quantity']       = null;
                    $slot['privateNote']    = null;
                } else {
                    $dayReservations = $reservationIndex[$tableId][$date] ?? [];
                    $overlap = ssaApiFindOverlappingReservation($dayReservations, $slotStart, $slotEnd);

                    if ($overlap !== null) {
                        $bookingStatus = $overlap['booking_status'] ?? null;
                        $overlapBid    = isset($overlap['bid']) ? (int)$overlap['bid'] : null;
                        $overlapUid    = isset($overlap['uid']) ? (int)$overlap['uid'] : null;

                        // Only return the private note to the booking owner.
                        $privateNote = null;
                        if ($overlapBid !== null
                            && $overlapUid !== null
                            && $slotsAuthenticatedUid !== null
                            && $overlapUid === $slotsAuthenticatedUid
                        ) {
                            $raw = $slotsPrivateNoteIndex[$overlapBid] ?? null;
                            $privateNote = ($raw !== null && $raw !== '') ? $raw : null;
                        }

                        $slot['status']        = $bookingStatus === 'subscription' ? 'subscription' : 'occupied';
                        $slot['isBookable']    = false;
                        $slot['reservationId'] = isset($overlap['rid']) ? (int)$overlap['rid'] : null;
                        $slot['bookingId']     = $overlapBid;
                        $slot['userId']        = $overlapUid;
                        $slot['bookedBy']      = ssaApiPublicBookedBy($overlap);
                        $slot['bookingStatus'] = $bookingStatus;
                        $slot['billingStatus'] = $overlap['status_billing'] ?? null;
                        $slot['visibility']    = $overlap['visibility'] ?? null;
                        $slot['quantity']      = isset($overlap['quantity']) ? (int)$overlap['quantity'] : null;
                        $slot['privateNote']   = $privateNote;
                    }
                }

                $slots[] = $slot;
            }
        }

        $table['slots'] = $slots;
        $tables[] = $table;
    }

    ssaApiJsonResponse(200, [
        'status'     => 'ok',
        'source'     => 'live_database',
        'mode'       => 'full_slot_grid',
        'timezone'   => SSA_API_TIMEZONE,
        'from'       => $from,
        'to'         => $to,
        'filter'     => [
            'status'  => $statusFilter ?? 'all',
            'tableId' => $tableIdFilter,
        ],
        'tableCount' => count($tables),
        'tables'     => $tables,
    ]);
} catch (Throwable $exception) {
    error_log('SSA API slots endpoint failed: ' . $exception->getMessage());

    ssaApiJsonResponse(500, [
        'error'   => 'slots_query_failed',
        'message' => 'Unable to generate slot grid from the booking database.',
    ]);
}
