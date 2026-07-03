<?php

declare(strict_types=1);

/**
 * POST /api/ssa/v1/booking-amend.php
 *
 * Amends the end time of an active booking owned by the authenticated user.
 *
 * Request body (JSON):
 *   { "bookingId": 1234, "newTimeEnd": "17:00" }
 *
 * The backend derives user, date, original start/end, table, and block size
 * from the database — none of those values are accepted from the client.
 *
 * On success:
 *   HTTP 200 { "status": "ok", "bookingId": ..., "oldTimeEnd": "...", "newTimeEnd": "...", ... }
 *
 * On conflict (extension blocked):
 *   HTTP 409 { "error": "booking_amendment_conflict", "message": "..." }
 */

require_once __DIR__ . '/_auth0.php';
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_booking_write.php';
require_once __DIR__ . '/_push_apns.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ssaApiJsonResponse(405, ['error' => 'method_not_allowed', 'message' => 'Only POST is allowed.']);
}

$claims = ssaApiRequireAuth0Claims();

try {
    $body      = ssaApiReadJsonBodyArray();
    $bookingId = ssaApiRequirePositiveIntValue($body['bookingId'] ?? null, 'bookingId');
    $newEndRaw = trim((string)($body['newTimeEnd'] ?? ''));
    $newEnd    = ssaApiRequireTimeValue($newEndRaw, 'newTimeEnd');

    $pdo  = ssaApiCreatePdo();
    $user = ssaApiRequireLinkedBookingUser($pdo, $claims);
    $tz   = new DateTimeZone(SSA_API_TIMEZONE);
    $now  = new DateTimeImmutable('now', $tz);

    // ── Load booking ───────────────────────────────────────────────────────

    $stmt = $pdo->prepare(
        'SELECT
            b.bid, b.uid, b.sid, b.status,
            r.rid, r.date, r.time_start, r.time_end,
            s.time_block  AS sq_time_block,
            s.time_start  AS sq_time_start,
            s.time_end    AS sq_time_end,
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

    // ── Authorisation ──────────────────────────────────────────────────────

    if ((int)$row['uid'] !== $user['uid']) {
        ssaApiJsonResponse(403, ['error' => 'booking_not_owned', 'message' => 'You can only amend your own bookings.']);
    }

    $bookingStatus = $row['status'] ?? '';

    if ($bookingStatus === 'cancelled') {
        ssaApiJsonResponse(409, ['error' => 'booking_cancelled', 'message' => 'This booking has been cancelled.']);
    }

    // ── Derive times ───────────────────────────────────────────────────────

    $date      = (string)$row['date'];
    $rid       = (int)$row['rid'];
    $tableId   = (int)$row['sid'];
    $timeStart = ssaApiNormaliseTimeValue($row['time_start']);
    $oldEnd    = ssaApiNormaliseTimeValue($row['time_end']);

    if ($timeStart === null || $oldEnd === null) {
        ssaApiJsonResponse(409, ['error' => 'invalid_booking', 'message' => 'Booking has invalid time data.']);
    }

    $bookingStart = ssaApiBuildDateTime($date, $timeStart);
    $bookingEnd   = ssaApiBuildDateTime($date, $oldEnd);

    // ── Booking must not have ended ─────────────────────────────────────────
    // Upcoming bookings are allowed: started-slot protection below enforces the
    // minimum duration (start + one block) for both upcoming and active bookings.

    if ($now >= $bookingEnd) {
        ssaApiJsonResponse(409, ['error' => 'booking_ended', 'message' => 'This booking has already ended and cannot be amended.']);
    }

    // ── No-op check ────────────────────────────────────────────────────────

    if ($newEnd === $oldEnd) {
        ssaApiJsonResponse(400, ['error' => 'no_change', 'message' => 'The requested end time is the same as the current end time.']);
    }

    // ── Table block alignment and opening hours ───────────────────────────

    $blockSec   = max(1, isset($row['sq_time_block']) && is_numeric($row['sq_time_block'])
        ? (int)$row['sq_time_block']
        : 1800);
    $sqStartSec = ssaApiTimeToSeconds($row['sq_time_start'] ?? null);
    $sqEndSec   = ssaApiTimeToSeconds($row['sq_time_end']   ?? null);
    $startSec   = ssaApiTimeToSeconds($timeStart);
    $oldEndSec  = ssaApiTimeToSeconds($oldEnd);
    $newEndSec  = ssaApiTimeToSeconds($newEnd);

    if ($sqStartSec === null || $sqEndSec === null || $startSec === null || $newEndSec === null) {
        ssaApiJsonResponse(409, ['error' => 'invalid_config', 'message' => 'Table configuration is invalid.']);
    }

    // New end must be within table opening hours.
    if ($newEndSec > $sqEndSec || $newEndSec <= $startSec) {
        ssaApiJsonResponse(400, ['error' => 'outside_table_hours', 'message' => 'The requested end time is outside table opening hours.']);
    }

    // New end must align to the table block.
    if ((($newEndSec - $sqStartSec) % $blockSec) !== 0) {
        ssaApiJsonResponse(400, ['error' => 'time_not_aligned', 'message' => 'The requested end time does not align to the table block size.']);
    }

    // ── Started-slot protection ────────────────────────────────────────────
    // earliestNewEnd = end of the block currently in progress.
    // Use full seconds (not H:i) so rounding does not shift an exact boundary.

    $nowSec         = (int)$now->format('H') * 3600 + (int)$now->format('i') * 60 + (int)$now->format('s');
    $earliestEndSec = ssaApiEarliestAmendEndSec($startSec, $nowSec, $blockSec);

    if ($newEndSec < $earliestEndSec) {
        ssaApiJsonResponse(400, [
            'error'   => 'cannot_shorten_started_block',
            'message' => 'The earliest permitted new end time is ' . ssaApiSecondsToTime($earliestEndSec) . ' (the current block has already started).',
        ]);
    }

    // ── Determine change type ──────────────────────────────────────────────

    $changeType = $newEndSec < $oldEndSec ? 'shortened' : 'extended';

    // ── Pre-transaction conflict checks (fast path) ───────────────────────

    if ($changeType === 'extended') {
        // Check blocking events on the extended range (oldEnd → newEnd).
        if (ssaApiHasBlockingEvent($pdo, $tableId, ssaApiBuildDateTime($date, $oldEnd), ssaApiBuildDateTime($date, $newEnd))) {
            ssaApiJsonResponse(409, [
                'error'   => 'booking_amendment_conflict',
                'message' => 'The requested end time is no longer available.',
            ]);
        }

        // Check reservation overlap for the extended range only (exclude self).
        $overlapStmt = $pdo->prepare(
            'SELECT r.rid
             FROM bs_reservations r
             INNER JOIN bs_bookings b ON b.bid = r.bid
             WHERE r.date = :date
               AND b.sid = :sid
               AND b.status <> :cancelled
               AND b.bid <> :amendBid
               AND r.time_start < :newEnd
               AND r.time_end > :oldEnd
             LIMIT 1'
        );
        $overlapStmt->execute([
            'date'      => $date,
            'sid'       => $tableId,
            'cancelled' => 'cancelled',
            'amendBid'  => $bookingId,
            'newEnd'    => $newEnd,
            'oldEnd'    => $oldEnd,
        ]);

        if ($overlapStmt->fetch()) {
            ssaApiJsonResponse(409, [
                'error'   => 'booking_amendment_conflict',
                'message' => 'The requested end time is no longer available.',
            ]);
        }
    }

    // ── Idempotency key ───────────────────────────────────────────────────
    // Prevents duplicate processing if the request is retried.

    $idempotencyKey = hash('sha256', implode(':', [
        (string)$bookingId,
        $date,
        $timeStart,
        $oldEnd,
        $newEnd,
        (string)$user['uid'],
    ]));

    // ── Ensure audit table exists (DDL must run outside any transaction) ───
    // MySQL DDL triggers an implicit commit, which would corrupt the
    // surrounding transaction.  Create the table here, before beginTransaction.

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ssa_booking_amendments (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id      INT NOT NULL,
            uid             INT NOT NULL,
            table_id        INT NOT NULL,
            booking_date    DATE NOT NULL,
            old_time_start  VARCHAR(8) NOT NULL,
            old_time_end    VARCHAR(8) NOT NULL,
            new_time_end    VARCHAR(8) NOT NULL,
            change_type     VARCHAR(16) NOT NULL,
            source          VARCHAR(64) NOT NULL DEFAULT 'ios_app',
            auth0_sub       VARCHAR(255) NULL,
            idempotency_key VARCHAR(128) NULL,
            amended_at      DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_idempotency (idempotency_key),
            KEY idx_booking_id (booking_id),
            KEY idx_uid (uid)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // ── Transaction ────────────────────────────────────────────────────────
    // Lock the reservation row first (FOR UPDATE), then re-verify all
    // preconditions so concurrent amendments or cancellations are rejected.

    $pdo->beginTransaction();

    try {
        // Lock and reload the reservation + booking inside the transaction.
        $lockStmt = $pdo->prepare(
            'SELECT r.rid, r.time_end, b.status
             FROM bs_reservations r
             INNER JOIN bs_bookings b ON b.bid = r.bid
             WHERE r.rid = :rid
               AND b.bid = :bid
             FOR UPDATE'
        );
        $lockStmt->execute(['rid' => $rid, 'bid' => $bookingId]);
        $lockedRow = $lockStmt->fetch();

        if (!$lockedRow) {
            $pdo->rollBack();
            ssaApiJsonResponse(409, ['error' => 'booking_not_found', 'message' => 'Booking no longer exists.']);
        }

        // Verify no concurrent amendment has already changed the end time.
        $lockedEnd = ssaApiNormaliseTimeValue($lockedRow['time_end']);
        if ($lockedEnd !== $oldEnd) {
            $pdo->rollBack();
            ssaApiJsonResponse(409, [
                'error'   => 'booking_amendment_conflict',
                'message' => 'The booking was concurrently amended. Please refresh and try again.',
            ]);
        }

        // Verify booking is still not cancelled.
        if (($lockedRow['status'] ?? '') === 'cancelled') {
            $pdo->rollBack();
            ssaApiJsonResponse(409, ['error' => 'booking_cancelled', 'message' => 'This booking has been cancelled.']);
        }

        // Re-verify booking is still active using current server time.
        $txNow       = new DateTimeImmutable('now', $tz);
        $txBookingEnd = ssaApiBuildDateTime($date, $oldEnd);

        if ($txNow >= $txBookingEnd) {
            $pdo->rollBack();
            ssaApiJsonResponse(409, ['error' => 'booking_ended', 'message' => 'This booking has ended.']);
        }

        // Recalculate started-slot protection with current server time.
        $txNowSec        = (int)$txNow->format('H') * 3600 + (int)$txNow->format('i') * 60 + (int)$txNow->format('s');
        $txEarliestEndSec = ssaApiEarliestAmendEndSec($startSec, $txNowSec, $blockSec);

        if ($newEndSec < $txEarliestEndSec) {
            $pdo->rollBack();
            ssaApiJsonResponse(400, [
                'error'   => 'cannot_shorten_started_block',
                'message' => 'The earliest permitted new end time is ' . ssaApiSecondsToTime($txEarliestEndSec) . ' (the current block has already started).',
            ]);
        }

        // Re-check blocking events inside the transaction (catches events created
        // after option loading but before amendment submission).
        if ($changeType === 'extended') {
            if (ssaApiHasBlockingEvent($pdo, $tableId, ssaApiBuildDateTime($date, $oldEnd), ssaApiBuildDateTime($date, $newEnd))) {
                $pdo->rollBack();
                ssaApiJsonResponse(409, [
                    'error'   => 'booking_amendment_conflict',
                    'message' => 'The requested end time is no longer available.',
                ]);
            }

            // Re-check reservation conflicts (FOR UPDATE to close the race window).
            $raceStmt = $pdo->prepare(
                'SELECT r.rid
                 FROM bs_reservations r
                 INNER JOIN bs_bookings b ON b.bid = r.bid
                 WHERE r.date = :date
                   AND b.sid = :sid
                   AND b.status <> :cancelled
                   AND b.bid <> :amendBid
                   AND r.time_start < :newEnd
                   AND r.time_end > :oldEnd
                 LIMIT 1
                 FOR UPDATE'
            );
            $raceStmt->execute([
                'date'      => $date,
                'sid'       => $tableId,
                'cancelled' => 'cancelled',
                'amendBid'  => $bookingId,
                'newEnd'    => $newEnd,
                'oldEnd'    => $oldEnd,
            ]);

            if ($raceStmt->fetch()) {
                $pdo->rollBack();
                ssaApiJsonResponse(409, [
                    'error'   => 'booking_amendment_conflict',
                    'message' => 'The requested end time is no longer available.',
                ]);
            }
        }

        // Update the reservation end time.
        $updateStmt = $pdo->prepare(
            'UPDATE bs_reservations
             SET time_end = :newEnd
             WHERE rid = :rid AND bid = :bid'
        );
        $updateStmt->execute([
            'newEnd' => $newEnd,
            'rid'    => $rid,
            'bid'    => $bookingId,
        ]);

        if ($updateStmt->rowCount() < 1) {
            $pdo->rollBack();
            ssaApiJsonResponse(409, ['error' => 'update_failed', 'message' => 'Amendment could not be applied.']);
        }

        // Audit record.
        $insertAudit = $pdo->prepare(
            'INSERT IGNORE INTO ssa_booking_amendments
                (booking_id, uid, table_id, booking_date, old_time_start, old_time_end, new_time_end,
                 change_type, source, auth0_sub, idempotency_key, amended_at)
             VALUES
                (:bookingId, :uid, :tableId, :date, :timeStart, :oldEnd, :newEnd,
                 :changeType, :source, :auth0Sub, :idempotencyKey, :amendedAt)'
        );
        $insertAudit->execute([
            'bookingId'      => $bookingId,
            'uid'            => $user['uid'],
            'tableId'        => $tableId,
            'date'           => $date,
            'timeStart'      => $timeStart,
            'oldEnd'         => $oldEnd,
            'newEnd'         => $newEnd,
            'changeType'     => $changeType,
            'source'         => 'ios_app',
            'auth0Sub'       => $user['auth0Sub'] ?? null,
            'idempotencyKey' => $idempotencyKey,
            'amendedAt'      => $now->format('Y-m-d H:i:s'),
        ]);

        $amendmentId = (int)$pdo->lastInsertId();

        // If idempotency_key was already present, INSERT IGNORE had no effect and
        // lastInsertId() returns 0 — look up the existing audit row's ID.
        if ($amendmentId === 0) {
            $idStmt = $pdo->prepare(
                'SELECT id FROM ssa_booking_amendments WHERE idempotency_key = :key LIMIT 1'
            );
            $idStmt->execute(['key' => $idempotencyKey]);
            $idRow = $idStmt->fetch();
            $amendmentId = $idRow ? (int)$idRow['id'] : 0;
        }

        $pdo->commit();

    } catch (Throwable $inner) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $inner;
    }

    // ── Push notifications ─────────────────────────────────────────────────
    // Sent AFTER the transaction commits.  Do not let push failures fail the request.

    try {
        ssaApiSendAmendmentNotifications($pdo, $bookingId, $tableId, (string)$row['sq_name'], $date, $oldEnd, $newEnd, $changeType, $tz);
    } catch (Throwable $pushEx) {
        error_log('SSA booking-amend.php: push notification failed: ' . $pushEx->getMessage());
    }

    ssaApiJsonResponse(200, [
        'status'      => 'ok',
        'bookingId'   => $bookingId,
        'tableId'     => $tableId,
        'date'        => $date,
        'timeStart'   => $timeStart,
        'oldTimeEnd'  => $oldEnd,
        'newTimeEnd'  => $newEnd,
        'startLocal'  => ssaApiDateTimeLocalIso($date, $timeStart),
        'newEndLocal' => ssaApiDateTimeLocalIso($date, $newEnd),
        'changeType'  => $changeType,
        'amendmentId' => $amendmentId,
    ]);

} catch (Throwable $ex) {
    error_log('SSA booking-amend.php failed: ' . $ex->getMessage());
    ssaApiJsonResponse(500, ['error' => 'amendment_failed', 'message' => 'Unable to amend the booking.']);
}

// ── Notification helper ────────────────────────────────────────────────────

function ssaApiSendAmendmentNotifications(
    PDO    $pdo,
    int    $bookingId,
    int    $tableId,
    string $tableName,
    string $date,
    string $oldEnd,
    string $newEnd,
    string $changeType,
    DateTimeZone $tz
): void {
    // Active-week boundaries: Monday 00:00 through Sunday 23:59 Europe/London.
    $now       = new DateTimeImmutable('now', $tz);
    $weekStart = new DateTimeImmutable('monday this week', $tz);
    $weekEnd   = $weekStart->modify('+7 days')->modify('-1 second');

    $bookingDate = new DateTimeImmutable($date . ' 00:00:00', $tz);
    if ($bookingDate < $weekStart || $bookingDate > $weekEnd) {
        return; // Outside active week — no notification.
    }

    // Is the booking today?
    $today = $now->format('Y-m-d');
    $isToday = ($date === $today);

    if ($isToday) {
        $dayLabel = 'today';
    } else {
        $df = new DateTimeImmutable($date . ' 00:00:00', $tz);
        $dayLabel = 'on ' . $df->format('l');   // e.g. "on Thursday"
    }

    if ($changeType === 'extended') {
        $notifBody = "A {$tableName} booking {$dayLabel} has been extended from {$oldEnd} to {$newEnd}.";
    } else {
        $notifBody = "A {$tableName} booking {$dayLabel} has been shortened from {$oldEnd} to {$newEnd}.";
    }

    $notifTitle = 'Booking amended';

    $customPayload = [
        'type'      => 'booking_amended_active_week',
        'bookingId' => $bookingId,
        'tableId'   => $tableId,
        'date'      => $date,
    ];

    // Collect eligible push token rows.
    // Members with booking_amended_active_week enabled in ssa.notification.preferences,
    // active APNs tokens, and enabled (non-deleted) accounts.
    $tokenStmt = $pdo->prepare(
        "SELECT
            pt.uid,
            pt.device_token,
            pt.environment,
            COALESCE(um.value, 'null') AS pref_json
         FROM ssa_push_tokens pt
         INNER JOIN bs_users u ON u.uid = pt.uid AND u.status = 'enabled'
         LEFT JOIN bs_users_meta um
            ON um.uid = pt.uid AND um.key = 'ssa.notification.preferences'
         WHERE pt.device_token IS NOT NULL
           AND pt.device_token <> ''
         ORDER BY pt.uid ASC, pt.id ASC"
    );
    $tokenStmt->execute();
    $allTokenRows = $tokenStmt->fetchAll();

    $eligibleRows = [];
    foreach ($allTokenRows as $tokenRow) {
        $prefJson = $tokenRow['pref_json'] ?? 'null';
        $prefs    = json_decode((string)$prefJson, true);

        if (!is_array($prefs)) {
            // No preferences stored → default is disabled for new category.
            continue;
        }

        $enabled = isset($prefs['booking_amended_active_week'])
            ? (bool)$prefs['booking_amended_active_week']
            : false;

        if ($enabled) {
            $eligibleRows[] = $tokenRow;
        }
    }

    if (!empty($eligibleRows)) {
        ssaPushSendToTokenRows($eligibleRows, $notifTitle, $notifBody, $customPayload);
    }
}
