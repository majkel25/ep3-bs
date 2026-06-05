<?php

declare(strict_types=1);

/**
 * POST /api/ssa/v1/booking-running-late.php
 *
 * Lets a member inform the club they are running late for a table booking.
 * Sends APNs push notifications (and writes in-app notifications) to any
 * other members who have an adjacent booking on the same table today.
 *
 * Auth: Auth0 bearer token.
 *
 * Request body (JSON):
 *   bookingId    int  – bs_reservations.bid (preferred)
 *   reservationId int – bs_reservations.rid (fallback)
 *   At least one of bookingId / reservationId is required.
 */

require_once __DIR__ . '/_auth0.php';
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_push_apns.php';
require_once __DIR__ . '/_user_notifications.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ssaApiJsonResponse(405, [
        'error'   => 'method_not_allowed',
        'message' => 'Only POST is allowed for this endpoint.',
    ]);
}

$claims    = ssaApiRequireAuth0Claims();
$auth0Sub  = isset($claims['sub']) ? trim((string)$claims['sub']) : '';

if ($auth0Sub === '') {
    ssaApiJsonResponse(401, [
        'error'   => 'missing_auth0_subject',
        'message' => 'Auth0 token does not contain a subject.',
    ]);
}

// ── Parse body ───────────────────────────────────────────────────────────────

$rawBody = (string)file_get_contents('php://input');
$body    = [];

if ($rawBody !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
}

$bookingId     = isset($body['bookingId'])     && is_numeric($body['bookingId'])     ? (int)$body['bookingId']     : null;
$reservationId = isset($body['reservationId']) && is_numeric($body['reservationId']) ? (int)$body['reservationId'] : null;

if ($bookingId === null && $reservationId === null) {
    ssaApiJsonResponse(400, [
        'error'   => 'missing_booking_id',
        'message' => 'Provide at least one of bookingId or reservationId.',
    ]);
}

// ── Main logic ───────────────────────────────────────────────────────────────

try {
    $pdo = ssaApiCreatePdo();

    // Resolve caller uid.
    $linkStmt = $pdo->prepare(
        'SELECT uid
         FROM ssa_auth0_user_links
         WHERE auth0_sub = :auth0Sub AND revoked_at IS NULL
         LIMIT 1'
    );
    $linkStmt->execute(['auth0Sub' => $auth0Sub]);
    $link = $linkStmt->fetch();

    if (!is_array($link) || (int)($link['uid'] ?? 0) <= 0) {
        ssaApiJsonResponse(403, [
            'error'   => 'booking_account_not_linked',
            'message' => 'No linked booking account was found for this app login.',
        ]);
    }

    $callerUid = (int)$link['uid'];

    // ── Verify the booking belongs to the caller ─────────────────────────────

    $conditions = [];
    $params     = ['callerUid' => $callerUid];

    if ($bookingId !== null) {
        $conditions[] = 'r.bid = :bookingId';
        $params['bookingId'] = $bookingId;
    }

    if ($reservationId !== null) {
        $conditions[] = 'r.rid = :reservationId';
        $params['reservationId'] = $reservationId;
    }

    $whereClause = '(' . implode(' OR ', $conditions) . ')';

    $bookingStmt = $pdo->prepare(
        "SELECT r.rid, r.bid, r.date, r.time_start, r.time_end, b.uid, b.sid, s.name AS table_name
         FROM bs_reservations r
         INNER JOIN bs_bookings b ON b.bid = r.bid
         LEFT JOIN bs_squares   s ON s.sid = b.sid
         WHERE b.uid = :callerUid
           AND b.status <> 'cancelled'
           AND {$whereClause}
         LIMIT 1"
    );
    $bookingStmt->execute($params);
    $callerBooking = $bookingStmt->fetch();

    if (!is_array($callerBooking)) {
        ssaApiJsonResponse(404, [
            'error'   => 'booking_not_found',
            'message' => 'No active booking found for the given id(s) on your account.',
        ]);
    }

    $tableId   = isset($callerBooking['sid'])  ? (int)$callerBooking['sid']  : null;
    $tableName = isset($callerBooking['table_name']) ? (string)$callerBooking['table_name'] : null;
    $date      = (string)($callerBooking['date'] ?? '');
    $timeEnd   = ssaApiNormaliseTimeValue($callerBooking['time_end'] ?? null);

    // ── Only allow for today's bookings ───────────────────────────────────────

    $timezone = new DateTimeZone(SSA_API_TIMEZONE);
    $today    = (new DateTimeImmutable('today', $timezone))->format('Y-m-d');

    if ($date !== $today) {
        ssaApiJsonResponse(400, [
            'error'   => 'not_today',
            'message' => 'Running Late notifications can only be sent for bookings on the current day.',
        ]);
    }

    // ── Find adjacent bookings on the same table ──────────────────────────────
    // Notify members whose booking starts within ±60 minutes of the end of the
    // caller's slot — most commonly this is the member in the next time block.

    $adjacentUids = [];

    if ($tableId !== null && $timeEnd !== null) {
        $adjStmt = $pdo->prepare(
            "SELECT DISTINCT b.uid, r.time_start
             FROM bs_reservations r
             INNER JOIN bs_bookings b ON b.bid = r.bid
             WHERE b.sid    = :tableId
               AND r.date   = :date
               AND b.uid   <> :callerUid
               AND b.status <> 'cancelled'
             ORDER BY r.time_start ASC"
        );
        $adjStmt->execute([
            'tableId'   => $tableId,
            'date'      => $date,
            'callerUid' => $callerUid,
        ]);
        $adjRows   = $adjStmt->fetchAll();
        $endSecs   = ssaApiTimeToSeconds($timeEnd);

        foreach ($adjRows as $row) {
            $startSecs = ssaApiTimeToSeconds(
                ssaApiNormaliseTimeValue($row['time_start'] ?? null)
            );
            if ($startSecs === null || $endSecs === null) {
                continue;
            }
            $diff = $startSecs - $endSecs;
            // Within ±60 min of the caller's slot end.
            if ($diff >= -3600 && $diff <= 3600) {
                $adjacentUids[] = (int)$row['uid'];
            }
        }

        $adjacentUids = array_unique($adjacentUids);
    }

    // ── Send notifications ────────────────────────────────────────────────────

    $tableLabel  = $tableName ? 'Table ' . $tableName : 'a table';
    $notifyTitle = 'Surrey Snooker Academy';
    $notifyBody  = 'A member is running late for their slot on ' . $tableLabel . '. They should be with you shortly.';
    $notifyType  = 'running_late';
    $notifyScreen = 'bookings';

    $notifiedCount = 0;
    ssaUserNotificationsEnsureTable($pdo);

    foreach ($adjacentUids as $targetUid) {
        // Write in-app notification.
        ssaUserNotificationsCreate(
            $pdo,
            $targetUid,
            $notifyType,
            $notifyTitle,
            $notifyBody,
            $notifyScreen,
            null,
            ['type' => $notifyType, 'tableId' => $tableId]
        );

        // Send APNs push.
        $tokenStmt = $pdo->prepare(
            "SELECT device_token, environment
             FROM ssa_push_tokens
             WHERE uid = :uid AND platform = 'ios' AND enabled = 1
             ORDER BY last_seen_at DESC, id DESC"
        );
        $tokenStmt->execute(['uid' => $targetUid]);
        $tokens = $tokenStmt->fetchAll();

        if (count($tokens) > 0) {
            $pushResult = ssaPushSendToTokenRows(
                $tokens,
                $notifyTitle,
                $notifyBody,
                ['screen' => $notifyScreen, 'type' => $notifyType]
            );
            if ($pushResult['successCount'] > 0) {
                $notifiedCount++;
            }
        }
    }

    $adjacentCount = count($adjacentUids);

    ssaApiJsonResponse(200, [
        'status'               => 'ok',
        'sent'                 => $notifiedCount > 0,
        'notifiedCount'        => $notifiedCount,
        'adjacentBookingCount' => $adjacentCount,
        'message'              => $notifiedCount > 0
            ? 'Nearby members have been notified.'
            : ($adjacentCount === 0
                ? 'No members with adjacent bookings were found.'
                : 'Adjacent members were found but notifications could not be delivered.'),
    ]);
} catch (Throwable $exception) {
    error_log('SSA running-late endpoint failed: ' . $exception->getMessage());

    ssaApiJsonResponse(500, [
        'error'   => 'running_late_failed',
        'message' => 'Unable to send the running late notification.',
    ]);
}
