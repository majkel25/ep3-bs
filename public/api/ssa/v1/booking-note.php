<?php
require_once __DIR__ . '/_auth0.php';
require_once __DIR__ . '/_booking_write.php';
require_once __DIR__ . '/_db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ssaApiJsonResponse(405, [
        'error'   => 'method_not_allowed',
        'message' => 'Only POST is allowed for this endpoint.',
    ]);
}

$claims = ssaApiRequireAuth0Claims();

try {
    $body      = ssaApiReadJsonBodyArray();
    $bookingId = ssaApiRequirePositiveIntValue($body['bookingId'] ?? null, 'bookingId');

    $rawNote = isset($body['note']) ? (string)$body['note'] : '';
    $note    = trim($rawNote);

    // Reject control characters (0x00–0x08, 0x0A–0x1F, 0x7F).
    // 0x09 (tab) is also disallowed — this enforces a clean single-line constraint.
    if ($note !== '' && preg_match('/[\x00-\x1F\x7F]/', $note)) {
        ssaApiJsonResponse(400, [
            'error'   => 'invalid_note',
            'message' => 'The note must not contain line breaks or control characters.',
        ]);
    }

    if (mb_strlen($note, 'UTF-8') > 100) {
        ssaApiJsonResponse(400, [
            'error'   => 'note_too_long',
            'message' => 'The note must not exceed 100 characters.',
        ]);
    }

    $pdo = ssaApiCreatePdo();

    // Ensure the private-notes table exists. DDL is executed outside any
    // transaction to avoid the MySQL implicit-commit that would break PDO.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS ssa_booking_private_notes (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id BIGINT UNSIGNED NOT NULL,
            uid        BIGINT UNSIGNED NOT NULL,
            note       VARCHAR(100)    NOT NULL DEFAULT \'\',
            created_at DATETIME        NOT NULL,
            updated_at DATETIME        NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_booking_private_note (booking_id, uid),
            KEY idx_uid (uid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $user = ssaApiRequireLinkedBookingUser($pdo, $claims);
    $uid  = (int)$user['uid'];

    // Verify the booking exists and belongs to the authenticated user.
    $bookingCheck = $pdo->prepare(
        'SELECT bid, uid FROM bs_bookings WHERE bid = :bid LIMIT 1'
    );
    $bookingCheck->execute(['bid' => $bookingId]);
    $booking = $bookingCheck->fetch();

    if (!$booking) {
        ssaApiJsonResponse(404, [
            'error'   => 'booking_not_found',
            'message' => 'The requested booking was not found.',
        ]);
    }

    if ((int)$booking['uid'] !== $uid) {
        ssaApiJsonResponse(403, [
            'error'   => 'booking_not_owned',
            'message' => 'You can only add notes to your own bookings.',
        ]);
    }

    $now = (new DateTimeImmutable('now', new DateTimeZone(SSA_API_TIMEZONE)))->format('Y-m-d H:i:s');

    if ($note === '') {
        $delete = $pdo->prepare(
            'DELETE FROM ssa_booking_private_notes
             WHERE booking_id = :bid AND uid = :uid'
        );
        $delete->execute(['bid' => $bookingId, 'uid' => $uid]);

        error_log('SSA booking-note removed: bookingId=' . $bookingId . ' uid=' . $uid);

        ssaApiJsonResponse(200, [
            'status'    => 'ok',
            'bookingId' => $bookingId,
            'note'      => null,
            'message'   => 'Booking note removed.',
        ]);
    }

    // Upsert: insert or update the note. Named params must be unique in PDO.
    $upsert = $pdo->prepare(
        'INSERT INTO ssa_booking_private_notes (booking_id, uid, note, created_at, updated_at)
         VALUES (:bid, :uid, :note, :created_at, :updated_at)
         ON DUPLICATE KEY UPDATE
             note       = VALUES(note),
             updated_at = VALUES(updated_at)'
    );
    $upsert->execute([
        'bid'        => $bookingId,
        'uid'        => $uid,
        'note'       => $note,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Log the action without logging note content in production.
    error_log('SSA booking-note updated: bookingId=' . $bookingId . ' uid=' . $uid . ' chars=' . mb_strlen($note, 'UTF-8'));

    ssaApiJsonResponse(200, [
        'status'    => 'ok',
        'bookingId' => $bookingId,
        'note'      => $note,
        'message'   => null,
    ]);
} catch (Throwable $exception) {
    error_log('SSA API booking-note endpoint failed: ' . $exception->getMessage());

    ssaApiJsonResponse(500, [
        'error'   => 'booking_note_failed',
        'message' => 'Unable to update the booking note.',
    ]);
}
