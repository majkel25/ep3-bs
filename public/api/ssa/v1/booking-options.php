<?php
require_once __DIR__ . '/_auth0.php';
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_booking_write.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ssaApiJsonResponse(405, [
        'error' => 'method_not_allowed',
        'message' => 'Only GET is allowed for this endpoint.',
    ]);
}

$claims = ssaApiRequireAuth0Claims();

try {
    $pdo = ssaApiCreatePdo();
    $user = ssaApiRequireLinkedBookingUser($pdo, $claims);

    $tableId = ssaApiRequiredGetIntParam('tableId');
    $date = ssaApiRequiredGetDateParam('date');
    $timeStart = ssaApiRequiredGetTimeParam('timeStart');

    $square = ssaApiFetchEnabledSquare($pdo, $tableId);

    $startSeconds = ssaApiTimeToSeconds($timeStart);
    $dayEndSeconds = ssaApiTimeToSeconds($square['time_end'] ?? null);
    $blockSeconds = isset($square['time_block']) && is_numeric($square['time_block']) ? (int)$square['time_block'] : 1800;

    if ($startSeconds === null || $dayEndSeconds === null || $blockSeconds <= 0) {
        ssaApiJsonResponse(400, [
            'error' => 'invalid_time',
            'message' => 'The requested start time is invalid.',
        ]);
    }

    $activeLimit = ssaApiValidateActiveBookingLimit($pdo, $square, $user['uid']);

    if (!$activeLimit['ok']) {
        ssaApiJsonResponse(409, [
            'error' => $activeLimit['error'],
            'message' => $activeLimit['message'],
        ]);
    }

    $options = [];

    for ($candidateEnd = $startSeconds + $blockSeconds; $candidateEnd <= $dayEndSeconds; $candidateEnd += $blockSeconds) {
        $timeEnd = ssaApiSecondsToTime($candidateEnd);

        $rangeCheck = ssaApiBookingRangeChecks($square, $date, $timeStart, $timeEnd);

        if (!$rangeCheck['ok']) {
            if (in_array($rangeCheck['error'], ['duration_too_long', 'outside_table_hours'], true)) {
                break;
            }

            if (count($options) === 0) {
                ssaApiJsonResponse(409, [
                    'error' => $rangeCheck['error'],
                    'message' => $rangeCheck['message'],
                ]);
            }

            break;
        }

        if (ssaApiHasBlockingEvent($pdo, $tableId, $rangeCheck['start'], $rangeCheck['end'])) {
            break;
        }

        $availability = ssaApiValidateCapacityAndOverlap($pdo, $square, $tableId, $date, $timeStart, $timeEnd, 1);

        if (!$availability['ok']) {
            break;
        }

        $durationSeconds = $candidateEnd - $startSeconds;

        $options[] = [
            'timeEnd' => $timeEnd,
            'durationMinutes' => (int)round($durationSeconds / 60),
            'label' => ssaApiDurationLabel($durationSeconds),
        ];
    }

    ssaApiJsonResponse(200, [
        'status' => 'ok',
        'source' => 'live_database',
        'timezone' => SSA_API_TIMEZONE,
        'tableId' => $tableId,
        'date' => $date,
        'timeStart' => $timeStart,
        'count' => count($options),
        'options' => $options,
    ]);
} catch (Throwable $exception) {
    error_log('SSA API booking options failed: ' . $exception->getMessage());

    ssaApiJsonResponse(500, [
        'error' => 'booking_options_failed',
        'message' => 'Unable to calculate booking options.',
    ]);
}