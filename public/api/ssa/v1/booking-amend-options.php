<?php

declare(strict_types=1);

/**
 * GET /api/ssa/v1/booking-amend-options.php?bookingId=<id>
 *
 * Returns the valid end-time options for amending an active booking owned by
 * the authenticated user.
 *
 * The endpoint derives the booking start/end, current time, and table block
 * size from the database.  It does NOT trust client-supplied times.
 *
 * Response:
 *   {
 *     "status": "ok",
 *     "bookingId": 1234,
 *     "tableId": 1,
 *     "date": "2026-07-02",
 *     "timeStart": "13:00",
 *     "currentTimeEnd": "16:30",
 *     "earliestNewEnd": "14:30",      // end of the currently started block
 *     "blockSeconds": 1800,
 *     "options": [
 *       { "timeEnd": "14:30", "changeType": "shorten", "available": true },
 *       { "timeEnd": "16:30", "changeType": "current", "available": true },
 *       { "timeEnd": "17:00", "changeType": "extend",  "available": true },
 *       ...
 *     ]
 *   }
 */

require_once __DIR__ . '/_auth0.php';
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_booking_write.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ssaApiJsonResponse(405, ['error' => 'method_not_allowed', 'message' => 'Only GET is allowed.']);
}

$claims = ssaApiRequireAuth0Claims();

try {
    $bookingId = ssaApiRequiredGetIntParam('bookingId');

    $pdo  = ssaApiCreatePdo();
    $user = ssaApiRequireLinkedBookingUser($pdo, $claims);
    $tz   = new DateTimeZone(SSA_API_TIMEZONE);
    $now  = new DateTimeImmutable('now', $tz);

    // ── Load booking + reservation (earliest reservation for this booking) ──

    $stmt = $pdo->prepare(
        'SELECT
            b.bid, b.uid, b.sid, b.status,
            r.rid, r.date, r.time_start, r.time_end,
            s.time_start  AS sq_time_start,
            s.time_end    AS sq_time_end,
            s.time_block  AS sq_time_block,
            s.name        AS sq_name
         FROM bs_bookings b
         INNER JOIN bs_reservations r ON r.bid = b.bid
         INNER JOIN bs_squares s ON s.sid = b.sid
         WHERE b.bid = :bid
         ORDER BY r.date ASC, r.time_start ASC
         LIMIT 1'
    );
    $stmt->execute(['bid' => $bookingId]);
    $row = $stmt->fetch();

    if (!$row) {
        ssaApiJsonResponse(404, ['error' => 'booking_not_found', 'message' => 'Booking not found.']);
    }

    if ((int)$row['uid'] !== $user['uid']) {
        ssaApiJsonResponse(403, ['error' => 'booking_not_owned', 'message' => 'You can only amend your own bookings.']);
    }

    if (($row['status'] ?? '') === 'cancelled') {
        ssaApiJsonResponse(409, ['error' => 'booking_cancelled', 'message' => 'This booking has been cancelled.']);
    }

    $date      = (string)$row['date'];
    $timeStart = ssaApiNormaliseTimeValue($row['time_start']);
    $timeEnd   = ssaApiNormaliseTimeValue($row['time_end']);
    $tableId   = (int)$row['sid'];

    if ($timeStart === null || $timeEnd === null) {
        ssaApiJsonResponse(409, ['error' => 'invalid_booking', 'message' => 'Booking has invalid time data.']);
    }

    $bookingStart = ssaApiBuildDateTime($date, $timeStart);
    $bookingEnd   = ssaApiBuildDateTime($date, $timeEnd);

    // ── Booking must be active now ──────────────────────────────────────────

    if ($now >= $bookingEnd) {
        ssaApiJsonResponse(409, ['error' => 'booking_ended', 'message' => 'This booking has already ended.']);
    }

    if ($now < $bookingStart) {
        ssaApiJsonResponse(409, ['error' => 'booking_not_started', 'message' => 'This booking has not started yet. Use cancellation instead.']);
    }

    // ── Block size ─────────────────────────────────────────────────────────

    $blockSec = max(1, isset($row['sq_time_block']) && is_numeric($row['sq_time_block'])
        ? (int)$row['sq_time_block']
        : 1800);

    $sqStartSec = ssaApiTimeToSeconds($row['sq_time_start'] ?? null);
    $sqEndSec   = ssaApiTimeToSeconds($row['sq_time_end']   ?? null);

    if ($sqStartSec === null || $sqEndSec === null) {
        ssaApiJsonResponse(409, ['error' => 'invalid_table_config', 'message' => 'Table has invalid time configuration.']);
    }

    // ── Earliest permitted new end ─────────────────────────────────────────
    // End of the booking block that contains the current time.
    // At exactly a slot boundary, that newly-commencing block is started.
    // Use full seconds (not H:i) so rounding does not shift an exact boundary.

    $nowSec   = (int)$now->format('H') * 3600 + (int)$now->format('i') * 60 + (int)$now->format('s');
    $startSec = ssaApiTimeToSeconds($timeStart);
    $endSec   = ssaApiTimeToSeconds($timeEnd);

    $earliestEndSec = ssaApiEarliestAmendEndSec($startSec, $nowSec, $blockSec);
    $earliestNewEnd = ssaApiSecondsToTime($earliestEndSec);

    // ── Load blocking events for today ─────────────────────────────────────

    $eventRangeStart = $date . ' 00:00:00';
    $eventRangeEnd   = (new DateTimeImmutable($date . ' 00:00:00', $tz))->modify('+1 day')->format('Y-m-d H:i:s');

    $evtStmt = $pdo->prepare(
        'SELECT eid, sid, datetime_start, datetime_end
         FROM bs_events
         WHERE status = :status
           AND datetime_end > :rangeStart
           AND datetime_start < :rangeEnd
           AND (sid IS NULL OR sid = 0 OR sid = :tableId)'
    );
    $evtStmt->execute([
        'status'     => 'enabled',
        'rangeStart' => $eventRangeStart,
        'rangeEnd'   => $eventRangeEnd,
        'tableId'    => $tableId,
    ]);
    $blockingEvents = $evtStmt->fetchAll();

    // ── Load existing reservations for today on this table ─────────────────
    // Exclude the booking being amended so it does not block its own extension.

    $resStmt = $pdo->prepare(
        'SELECT r.rid, r.bid, r.time_start, r.time_end, b.sid
         FROM bs_reservations r
         INNER JOIN bs_bookings b ON b.bid = r.bid
         WHERE r.date = :date
           AND b.sid = :sid
           AND b.status <> :cancelled
           AND b.bid <> :amendBid'
    );
    $resStmt->execute([
        'date'      => $date,
        'sid'       => $tableId,
        'cancelled' => 'cancelled',
        'amendBid'  => $bookingId,
    ]);
    $otherReservations = $resStmt->fetchAll();

    // ── Build option list ──────────────────────────────────────────────────
    // Walk every block boundary from earliest end to table close (or current
    // end + reasonable look-ahead).

    $maxExtendLookSec = $endSec + (4 * 3600); // look 4 hours beyond current end
    $maxSec           = min($sqEndSec, $maxExtendLookSec);

    $options = [];

    for ($candidateSec = $earliestEndSec; $candidateSec <= $maxSec; $candidateSec += $blockSec) {
        if ($candidateSec > $sqEndSec) {
            break;
        }

        $candidateTime = ssaApiSecondsToTime($candidateSec);

        if ($candidateSec == $endSec) {
            $options[] = ['timeEnd' => $candidateTime, 'changeType' => 'current', 'available' => true];
            continue;
        }

        $changeType = $candidateSec < $endSec ? 'shorten' : 'extend';

        // For extension options, check that every new block between current
        // end and candidate is free of events and reservations.
        if ($changeType === 'extend') {
            $available = ssaApiAmendExtensionAvailable(
                $pdo, $tableId, $date, $timeEnd, $candidateTime,
                $blockingEvents, $otherReservations
            );
        } else {
            // Shortening: always available (we only remove future blocks).
            $available = true;
        }

        $options[] = ['timeEnd' => $candidateTime, 'changeType' => $changeType, 'available' => $available];
    }

    ssaApiJsonResponse(200, [
        'status'         => 'ok',
        'bookingId'      => $bookingId,
        'tableId'        => $tableId,
        'date'           => $date,
        'timeStart'      => $timeStart,
        'currentTimeEnd' => $timeEnd,
        'earliestNewEnd' => $earliestNewEnd,
        'blockSeconds'   => $blockSec,
        'options'        => $options,
    ]);

} catch (Throwable $ex) {
    error_log('SSA booking-amend-options failed: ' . $ex->getMessage());
    ssaApiJsonResponse(500, ['error' => 'amend_options_failed', 'message' => 'Unable to load amendment options.']);
}

// ── Helpers ────────────────────────────────────────────────────────────────

/**
 * Returns true if the extension from currentEnd to newEnd is free of events
 * and other member reservations.
 *
 * We walk block by block from currentEnd to newEnd; if any block is blocked,
 * the function returns false (and all further extension blocks are also
 * considered unavailable — do not call this for blocks beyond the first
 * unavailable one if you want to stop offering extensions after a gap).
 */
function ssaApiAmendExtensionAvailable(
    PDO    $pdo,
    int    $tableId,
    string $date,
    string $fromTime,
    string $toTime,
    array  $blockingEvents,
    array  $otherReservations
): bool {
    $fromSec = ssaApiTimeToSeconds($fromTime);
    $toSec   = ssaApiTimeToSeconds($toTime);

    if ($fromSec === null || $toSec === null || $toSec <= $fromSec) {
        return false;
    }

    $startDatetime = $date . ' ' . $fromTime . ':00';
    $endDatetime   = $date . ' ' . $toTime   . ':00';

    // Check blocking events.
    foreach ($blockingEvents as $event) {
        $evStart = (string)$event['datetime_start'];
        $evEnd   = (string)$event['datetime_end'];

        // Half-open overlap: candidate_start < event_end AND candidate_end > event_start
        if ($startDatetime < $evEnd && $endDatetime > $evStart) {
            return false;
        }
    }

    // Check other reservations (half-open overlap on time_start / time_end).
    $candidateStartSec = ssaApiTimeToSeconds($fromTime);
    $candidateEndSec   = ssaApiTimeToSeconds($toTime);

    foreach ($otherReservations as $res) {
        $rStart = ssaApiTimeToSeconds($res['time_start'] ?? null);
        $rEnd   = ssaApiTimeToSeconds($res['time_end']   ?? null);

        if ($rStart === null || $rEnd === null) {
            continue;
        }

        if ($candidateStartSec < $rEnd && $candidateEndSec > $rStart) {
            return false;
        }
    }

    return true;
}
