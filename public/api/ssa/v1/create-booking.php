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

    $tableId = ssaApiRequirePositiveIntValue($body['tableId'] ?? null, 'tableId');
    $date = ssaApiRequireDateValue($body['date'] ?? '', 'date');
    $timeStart = ssaApiRequireTimeValue($body['timeStart'] ?? '', 'timeStart');
    $timeEnd = ssaApiRequireTimeValue($body['timeEnd'] ?? '', 'timeEnd');

    $pdo = ssaApiCreatePdo();
    $user = ssaApiRequireLinkedBookingUser($pdo, $claims);
    $square = ssaApiFetchEnabledSquare($pdo, $tableId);

    $rangeCheck = ssaApiBookingRangeChecks($square, $date, $timeStart, $timeEnd);

    if (!$rangeCheck['ok']) {
        ssaApiJsonResponse(400, [
            'error' => $rangeCheck['error'],
            'message' => $rangeCheck['message'],
        ]);
    }

    $activeLimit = ssaApiValidateActiveBookingLimit($pdo, $square, $user['uid']);

    if (!$activeLimit['ok']) {
        ssaApiJsonResponse(409, [
            'error' => $activeLimit['error'],
            'message' => $activeLimit['message'],
        ]);
    }

    $pdo->beginTransaction();

    try {
        if (ssaApiHasBlockingEvent($pdo, $tableId, $rangeCheck['start'], $rangeCheck['end'])) {
            $pdo->rollBack();

            ssaApiJsonResponse(409, [
                'error' => 'slot_not_available',
                'message' => 'This time slot is no longer available.',
            ]);
        }

        $availability = ssaApiValidateCapacityAndOverlap($pdo, $square, $tableId, $date, $timeStart, $timeEnd, 1);

        if (!$availability['ok']) {
            $pdo->rollBack();

            ssaApiJsonResponse(409, [
                'error' => $availability['error'],
                'message' => $availability['message'],
            ]);
        }

        $bookingStatement = $pdo->prepare(
            'INSERT INTO bs_bookings
                (uid, sid, status, status_billing, visibility, quantity, created)
             VALUES
                (:uid, :sid, :status, :statusBilling, :visibility, :quantity, :created)'
        );

        $bookingStatement->execute([
            'uid' => $user['uid'],
            'sid' => $tableId,
            'status' => 'single',
            'statusBilling' => 'pending',
            'visibility' => 'public',
            'quantity' => 1,
            'created' => date('Y-m-d H:i:s'),
        ]);

        $bookingId = (int)$pdo->lastInsertId();

        if ($bookingId <= 0) {
            throw new RuntimeException('Booking insert did not return a valid id.');
        }

        $reservationStatement = $pdo->prepare(
            'INSERT INTO bs_reservations
                (bid, date, time_start, time_end)
             VALUES
                (:bid, :date, :timeStart, :timeEnd)'
        );

        $reservationStatement->execute([
            'bid' => $bookingId,
            'date' => $date,
            'timeStart' => $timeStart,
            'timeEnd' => $timeEnd,
        ]);

        $reservationId = (int)$pdo->lastInsertId();

        if ($reservationId <= 0) {
            throw new RuntimeException('Reservation insert did not return a valid id.');
        }

        $pdo->commit();

        $bookingRow = [
            'bid' => $bookingId,
            'uid' => $user['uid'],
            'sid' => $tableId,
            'status' => 'single',
            'status_billing' => 'pending',
            'visibility' => 'public',
            'quantity' => 1,
        ];

        $reservationRow = [
            'rid' => $reservationId,
            'bid' => $bookingId,
            'date' => $date,
            'time_start' => $timeStart,
            'time_end' => $timeEnd,
        ];

        ssaApiJsonResponse(201, [
            'status' => 'ok',
            'created' => true,
            'booking' => ssaApiBookingPayload($bookingRow, $reservationRow, $square),
        ]);
    } catch (Throwable $innerException) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $innerException;
    }
} catch (Throwable $exception) {
    error_log('SSA API create booking failed: ' . $exception->getMessage());

    ssaApiJsonResponse(500, [
        'error' => 'create_booking_failed',
        'message' => 'Unable to create the booking.',
    ]);
}