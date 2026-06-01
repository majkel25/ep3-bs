<?php
require_once __DIR__ . '/_auth0.php';
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_booking_write.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ssaApiJsonResponse(405, [
        'error' => 'method_not_allowed',
        'message' => 'Only POST is allowed for this endpoint.',
    ]);
}

$claims = ssaApiRequireAuth0Claims();

try {
    $body = ssaApiReadJsonBodyArray();

    $bookingId = null;

    if (isset($body['bookingId']) && trim((string)$body['bookingId']) !== '') {
        $bookingId = ssaApiRequirePositiveIntValue($body['bookingId'], 'bookingId');
    } elseif (isset($body['reservationId']) && trim((string)$body['reservationId']) !== '') {
        $reservationId = ssaApiRequirePositiveIntValue($body['reservationId'], 'reservationId');
    } else {
        ssaApiJsonResponse(400, [
            'error' => 'missing_booking_identifier',
            'message' => 'Either bookingId or reservationId is required.',
        ]);
    }

    $pdo = ssaApiCreatePdo();
    $user = ssaApiRequireLinkedBookingUser($pdo, $claims);

    if ($bookingId === null) {
        $reservationLookup = $pdo->prepare(
            'SELECT bid
             FROM bs_reservations
             WHERE rid = :rid
             LIMIT 1'
        );

        $reservationLookup->execute([
            'rid' => $reservationId,
        ]);

        $bookingId = (int)$reservationLookup->fetchColumn();

        if ($bookingId <= 0) {
            ssaApiJsonResponse(404, [
                'error' => 'booking_not_found',
                'message' => 'The requested booking was not found.',
            ]);
        }
    }

    $statement = $pdo->prepare(
        'SELECT
            b.bid,
            b.uid,
            b.sid,
            b.status,
            b.status_billing,
            b.visibility,
            b.quantity,
            s.name AS table_name,
            s.range_cancel,
            r.rid,
            r.date,
            r.time_start,
            r.time_end
         FROM bs_bookings b
         LEFT JOIN bs_squares s ON s.sid = b.sid
         LEFT JOIN bs_reservations r ON r.bid = b.bid
         WHERE b.bid = :bid
         ORDER BY r.date ASC, r.time_start ASC, r.rid ASC
         LIMIT 1'
    );

    $statement->execute([
        'bid' => $bookingId,
    ]);

    $row = $statement->fetch();

    if (!$row) {
        ssaApiJsonResponse(404, [
            'error' => 'booking_not_found',
            'message' => 'The requested booking was not found.',
        ]);
    }

    if ((int)$row['uid'] !== $user['uid']) {
        ssaApiJsonResponse(403, [
            'error' => 'booking_not_owned',
            'message' => 'You can only cancel your own bookings.',
        ]);
    }

    if (($row['status'] ?? '') === 'cancelled') {
        ssaApiJsonResponse(409, [
            'error' => 'booking_already_cancelled',
            'message' => 'This booking has already been cancelled.',
        ]);
    }

    if (($row['status'] ?? '') === 'subscription') {
        ssaApiJsonResponse(409, [
            'error' => 'subscription_cancellation_not_supported',
            'message' => 'Subscription bookings cannot be cancelled from the app.',
        ]);
    }

    $rangeCancel = isset($row['range_cancel']) && is_numeric($row['range_cancel']) ? (int)$row['range_cancel'] : 0;

    if ($rangeCancel <= 0) {
        ssaApiJsonResponse(409, [
            'error' => 'booking_not_cancellable',
            'message' => 'This booking cannot be cancelled online.',
        ]);
    }

    if (empty($row['date']) || empty($row['time_start'])) {
        ssaApiJsonResponse(409, [
            'error' => 'booking_not_cancellable',
            'message' => 'This booking cannot be cancelled because its reservation details are missing.',
        ]);
    }

    $reservationStart = ssaApiBuildDateTime((string)$row['date'], ssaApiNormaliseTimeValue($row['time_start']));
    $now = new DateTimeImmutable('now', new DateTimeZone(SSA_API_TIMEZONE));
    $latestAllowedCancelTime = $now->modify('+' . $rangeCancel . ' seconds');

    if ($reservationStart <= $latestAllowedCancelTime) {
        ssaApiJsonResponse(409, [
            'error' => 'cancellation_window_closed',
            'message' => 'This booking cannot be cancelled anymore online.',
        ]);
    }

    $pdo->beginTransaction();

    try {
        $updateStatement = $pdo->prepare(
            'UPDATE bs_bookings
             SET status = :newStatus
             WHERE bid = :bid
               AND uid = :uid
               AND status <> :existingCancelledStatus'
        );

        $updateStatement->execute([
            'newStatus' => 'cancelled',
            'existingCancelledStatus' => 'cancelled',
            'bid' => $bookingId,
            'uid' => $user['uid'],
        ]);

        if ($updateStatement->rowCount() < 1) {
            $pdo->rollBack();

            ssaApiJsonResponse(409, [
                'error' => 'booking_not_cancelled',
                'message' => 'The booking could not be cancelled.',
            ]);
        }

        $pdo->commit();

        ssaApiJsonResponse(200, [
            'status' => 'ok',
            'cancelled' => true,
            'bookingId' => $bookingId,
        ]);
    } catch (Throwable $innerException) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $innerException;
    }
} catch (Throwable $exception) {
    error_log('SSA API cancel booking failed: ' . $exception->getMessage());

    ssaApiJsonResponse(500, [
        'error' => 'cancel_booking_failed',
        'message' => 'Unable to cancel the booking.',
    ]);
}