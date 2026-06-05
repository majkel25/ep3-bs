<?php

declare(strict_types=1);

/**
 * POST /api/ssa/v1/booking-running-late.php
 *
 * Lets a member inform the club they are running late for a table booking.
 * Sends APNs push notifications (and in-app notifications) to every other
 * member who has any active booking on the same date, on any table.
 *
 * Idempotent: one notification per sender per booking (uid + rule_key +
 * notification_date + booking_signature_hash). Returns already_sent if the
 * caller has already sent a Running Late for this booking today.
 *
 * Auth: Auth0 bearer token.
 *
 * Request body (JSON):
 *   bookingId    int  – bs_reservations.bid (preferred)
 *   reservationId int – bs_reservations.rid (fallback)
 *   At least one of bookingId / reservationId is required.
 *
 * Response fields:
 *   status, bookingDate, senderUid, recipientUserCount,
 *   tokenCount, successCount, failureCount, skippedReason (if any)
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

$claims   = ssaApiRequireAuth0Claims();
$auth0Sub = isset($claims['sub']) ? trim((string)$claims['sub']) : '';

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

// ── Helpers ───────────────────────────────────────────────────────────────────

function ssaRunningLateEnsureLogTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ssa_push_notification_log (
            id                    BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            uid                   INT UNSIGNED     NOT NULL,
            rule_key              VARCHAR(96)      NOT NULL,
            notification_date     DATE             NOT NULL,
            booking_signature_hash CHAR(64)        NULL,
            event_type            VARCHAR(96)      NOT NULL,
            payload_json          TEXT             NULL,
            status                VARCHAR(32)      NOT NULL DEFAULT 'sending',
            token_count           INT UNSIGNED     NOT NULL DEFAULT 0,
            success_count         INT UNSIGNED     NOT NULL DEFAULT 0,
            failure_count         INT UNSIGNED     NOT NULL DEFAULT 0,
            error_message         VARCHAR(512)     NULL,
            sent_at               DATETIME         NULL,
            created_at            DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at            DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_booking_notification (uid, rule_key, notification_date, booking_signature_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

// ── Main logic ────────────────────────────────────────────────────────────────

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

    // ── Caller first name ─────────────────────────────────────────────────────

    $userStmt = $pdo->prepare(
        'SELECT firstname, alias FROM bs_users WHERE uid = :uid LIMIT 1'
    );
    $userStmt->execute(['uid' => $callerUid]);
    $userRow   = $userStmt->fetch();
    $firstName = '';

    if (is_array($userRow)) {
        $firstName = trim((string)($userRow['firstname'] ?? ''));
        if ($firstName === '') {
            $firstName = trim((string)($userRow['alias'] ?? ''));
        }
    }

    if ($firstName === '') {
        $firstName = 'A member';
    }

    // ── Verify the booking belongs to the caller ──────────────────────────────

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

    $tableId   = isset($callerBooking['sid'])        ? (int)$callerBooking['sid']        : null;
    $tableName = isset($callerBooking['table_name']) ? (string)$callerBooking['table_name'] : null;
    $date      = (string)($callerBooking['date'] ?? '');
    $timeStart = ssaApiNormaliseTimeValue($callerBooking['time_start'] ?? null);
    $timeEnd   = ssaApiNormaliseTimeValue($callerBooking['time_end']   ?? null);

    // ── Today-only guard ──────────────────────────────────────────────────────

    $timezone = new DateTimeZone(SSA_API_TIMEZONE);
    $today    = (new DateTimeImmutable('today', $timezone))->format('Y-m-d');

    if ($date !== $today) {
        ssaApiJsonResponse(400, [
            'error'   => 'not_today',
            'message' => 'Running Late notifications can only be sent for bookings on the current day.',
        ]);
    }

    // ── Idempotency check ─────────────────────────────────────────────────────
    // One Running Late notification per sender per booking per day.

    ssaRunningLateEnsureLogTable($pdo);

    $bookingKey = hash(
        'sha256',
        'bid:' . ($bookingId ?? '') . '|rid:' . ($reservationId ?? '')
    );

    $idempStmt = $pdo->prepare(
        "SELECT id FROM ssa_push_notification_log
         WHERE uid                    = :uid
           AND rule_key               = 'running_late'
           AND notification_date      = :date
           AND booking_signature_hash = :hash
         LIMIT 1"
    );
    $idempStmt->execute([
        'uid'  => $callerUid,
        'date' => $today,
        'hash' => $bookingKey,
    ]);

    if ($idempStmt->fetch()) {
        ssaApiJsonResponse(200, [
            'status'        => 'ok',
            'skippedReason' => 'already_sent',
            'bookingDate'   => $date,
            'senderUid'     => $callerUid,
            'message'       => 'A Running Late notification has already been sent for this booking.',
        ]);
    }

    // ── Find all members with any booking today (any table) ───────────────────

    $allDayStmt = $pdo->prepare(
        "SELECT DISTINCT b.uid
         FROM bs_reservations r
         INNER JOIN bs_bookings b ON b.bid = r.bid
         WHERE r.date   = :date
           AND b.uid   <> :callerUid
           AND b.status <> 'cancelled'"
    );
    $allDayStmt->execute([
        'date'      => $date,
        'callerUid' => $callerUid,
    ]);
    $allDayUids = array_column($allDayStmt->fetchAll(), 'uid');
    $allDayUids = array_unique(array_map('intval', $allDayUids));

    // ── Build notification content ────────────────────────────────────────────

    $tableLabel = $tableName !== null && $tableName !== '' ? 'Table ' . $tableName : 'the table';
    $timeSlot   = ($timeStart !== null && $timeEnd !== null)
        ? $timeStart . '–' . $timeEnd
        : null;

    $notifyTitle = 'Surrey Snooker Academy';
    $notifyBody  = $timeSlot !== null
        ? "{$firstName} is running late for their booking on {$tableLabel}, {$timeSlot}."
        : "{$firstName} is running late for their booking on {$tableLabel}.";
    $notifyType   = 'running_late';
    $notifyScreen = 'bookings';

    // ── Send notifications ────────────────────────────────────────────────────

    $recipientUserCount = count($allDayUids);
    $totalTokenCount    = 0;
    $totalSuccessCount  = 0;
    $totalFailureCount  = 0;

    ssaUserNotificationsEnsureTable($pdo);

    foreach ($allDayUids as $targetUid) {
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
            $pushResult         = ssaPushSendToTokenRows(
                $tokens,
                $notifyTitle,
                $notifyBody,
                ['screen' => $notifyScreen, 'type' => $notifyType]
            );
            $totalTokenCount   += $pushResult['tokenCount'];
            $totalSuccessCount += $pushResult['successCount'];
            $totalFailureCount += $pushResult['failureCount'];
        }
    }

    // ── Record idempotency row ────────────────────────────────────────────────

    $logStmt = $pdo->prepare(
        "INSERT INTO ssa_push_notification_log
            (uid, rule_key, notification_date, booking_signature_hash, event_type,
             token_count, success_count, failure_count, status, sent_at)
         VALUES
            (:uid, 'running_late', :date, :hash, 'running_late',
             :tokenCount, :successCount, :failureCount, 'sent', UTC_TIMESTAMP())"
    );
    $logStmt->execute([
        'uid'          => $callerUid,
        'date'         => $today,
        'hash'         => $bookingKey,
        'tokenCount'   => $totalTokenCount,
        'successCount' => $totalSuccessCount,
        'failureCount' => $totalFailureCount,
    ]);

    ssaApiJsonResponse(200, [
        'status'             => 'ok',
        'bookingDate'        => $date,
        'senderUid'          => $callerUid,
        'recipientUserCount' => $recipientUserCount,
        'tokenCount'         => $totalTokenCount,
        'successCount'       => $totalSuccessCount,
        'failureCount'       => $totalFailureCount,
        'message'            => $totalSuccessCount > 0
            ? 'Members at the club today have been notified.'
            : ($recipientUserCount === 0
                ? 'No other members have bookings today.'
                : 'Members were found but push notifications could not be delivered.'),
    ]);
} catch (Throwable $exception) {
    error_log('SSA running-late endpoint failed: ' . $exception->getMessage());

    ssaApiJsonResponse(500, [
        'error'   => 'running_late_failed',
        'message' => 'Unable to send the running late notification.',
    ]);
}
