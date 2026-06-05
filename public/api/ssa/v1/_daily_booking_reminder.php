<?php

declare(strict_types=1);

require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_push_apns.php';

if (!defined('SSA_DAILY_BOOKING_REMINDER_RULE_KEY')) {
    define('SSA_DAILY_BOOKING_REMINDER_RULE_KEY', 'daily_booking_reminders_8am');
}
if (!defined('SSA_DAILY_BOOKING_REMINDER_EVENT_TYPE')) {
    define('SSA_DAILY_BOOKING_REMINDER_EVENT_TYPE', 'daily_booking_reminder');
}
if (!defined('SSA_DAILY_BOOKING_REMINDER_PREFERENCE_KEY')) {
    define('SSA_DAILY_BOOKING_REMINDER_PREFERENCE_KEY', 'daily_booking_reminders');
}
if (!defined('SSA_DAILY_BOOKING_REMINDER_TITLE')) {
    define('SSA_DAILY_BOOKING_REMINDER_TITLE', 'Surrey Snooker Academy');
}

function ssaDailyBookingReminderNow(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone(SSA_API_TIMEZONE));
}

function ssaDailyBookingReminderEnsureLocalTimeGuard(DateTimeImmutable $now, bool $force): void
{
    if ($force) {
        return;
    }

    if ($now->format('H') !== '08') {
        throw new RuntimeException(
            'Outside daily booking reminder window. Local time is ' .
            $now->format('Y-m-d H:i:s T') .
            '; expected Europe/London hour 08. Use force=true for manual testing.'
        );
    }
}

function ssaDailyBookingReminderEnsureLogTable(PDO $pdo): void
{
    // Fresh install: create with booking_signature_hash and the new unique key.
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS ssa_push_notification_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid INT UNSIGNED NOT NULL,
    rule_key VARCHAR(96) NOT NULL,
    notification_date DATE NOT NULL,
    booking_signature_hash CHAR(64) NULL,
    event_type VARCHAR(96) NOT NULL,
    payload_json TEXT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'sending',
    token_count INT UNSIGNED NOT NULL DEFAULT 0,
    success_count INT UNSIGNED NOT NULL DEFAULT 0,
    failure_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_message VARCHAR(512) NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ssa_push_log_rule_date_sig (uid, rule_key, notification_date, booking_signature_hash),
    KEY idx_ssa_push_notification_log_rule_key (rule_key),
    KEY idx_ssa_push_notification_log_notification_date (notification_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Migration A: add booking_signature_hash column if missing (existing table).
    $colExists = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'ssa_push_notification_log'
           AND COLUMN_NAME = 'booking_signature_hash'"
    )->fetchColumn();
    if ($colExists === 0) {
        $pdo->exec(
            'ALTER TABLE ssa_push_notification_log
             ADD COLUMN booking_signature_hash CHAR(64) NULL AFTER notification_date'
        );
    }

    // Migration B: drop old narrow unique key (uid, rule_key, notification_date) if it exists.
    $oldKeyExists = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'ssa_push_notification_log'
           AND INDEX_NAME = 'uq_ssa_push_notification_log_rule_date'"
    )->fetchColumn();
    if ($oldKeyExists > 0) {
        $pdo->exec(
            'ALTER TABLE ssa_push_notification_log
             DROP INDEX uq_ssa_push_notification_log_rule_date'
        );
    }

    // Migration C: add new wide unique key if missing.
    $newKeyExists = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'ssa_push_notification_log'
           AND INDEX_NAME = 'uq_ssa_push_log_rule_date_sig'"
    )->fetchColumn();
    if ($newKeyExists === 0) {
        $pdo->exec(
            'ALTER TABLE ssa_push_notification_log
             ADD UNIQUE KEY uq_ssa_push_log_rule_date_sig (uid, rule_key, notification_date, booking_signature_hash)'
        );
    }
}

function ssaDailyBookingReminderFetchBookings(PDO $pdo, string $date, ?int $uid): array
{
    $sql = 'SELECT
            r.rid,
            r.bid,
            r.date,
            r.time_start,
            r.time_end,
            b.uid,
            b.sid,
            b.status AS booking_status,
            s.name AS table_name
        FROM bs_reservations r
        INNER JOIN bs_bookings b ON b.bid = r.bid
        LEFT JOIN bs_squares s ON s.sid = b.sid
        INNER JOIN ssa_auth0_user_links l ON l.uid = b.uid AND l.revoked_at IS NULL
        WHERE r.date = :date
          AND b.status <> :cancelledStatus';

    $params = [
        'date' => $date,
        'cancelledStatus' => 'cancelled',
    ];

    if ($uid !== null) {
        $sql .= ' AND b.uid = :uid';
        $params['uid'] = $uid;
    }

    $sql .= ' GROUP BY r.rid, r.bid, r.date, r.time_start, r.time_end, b.uid, b.sid, b.status, s.name
        ORDER BY b.uid ASC, r.time_start ASC, r.rid ASC';

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    $byUid = [];

    foreach ($rows as $row) {
        $rowUid = isset($row['uid']) ? (int)$row['uid'] : 0;
        if ($rowUid <= 0) {
            continue;
        }
        $byUid[$rowUid][] = $row;
    }

    return $byUid;
}

function ssaDailyBookingReminderFetchTokens(PDO $pdo, array $uids): array
{
    $uids = array_values(array_unique(array_filter(array_map('intval', $uids), static fn (int $uid): bool => $uid > 0)));

    if ($uids === []) {
        return [];
    }

    $placeholders = [];
    $params = ['platform' => 'ios'];

    foreach ($uids as $index => $uid) {
        $key = 'uid' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $uid;
    }

    $sql = 'SELECT
            t.id,
            t.uid,
            t.auth0_sub,
            t.device_token,
            t.device_token_hash,
            t.platform,
            t.environment,
            t.last_seen_at
        FROM ssa_push_tokens t
        INNER JOIN ssa_auth0_user_links l ON l.uid = t.uid
            AND l.revoked_at IS NULL
        WHERE t.enabled = 1
          AND t.platform = :platform
          AND t.uid IN (' . implode(', ', $placeholders) . ')
        ORDER BY t.uid ASC, t.last_seen_at DESC, t.updated_at DESC, t.id DESC';

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    $tokensByUid = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $uid = isset($row['uid']) ? (int)$row['uid'] : 0;
        if ($uid <= 0) {
            continue;
        }
        $tokensByUid[$uid][] = $row;
    }

    return $tokensByUid;
}

function ssaDailyBookingReminderNormaliseTableName(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return 'Table';
    }
    if (stripos($raw, 'table') === 0) {
        return $raw;
    }
    return 'Table ' . $raw;
}

function ssaDailyBookingReminderSignature(array $bookings): string
{
    $parts = [];
    foreach ($bookings as $b) {
        $parts[] = implode(':', [
            (int)($b['bid'] ?? 0),
            (int)($b['rid'] ?? 0),
            trim((string)($b['table_name'] ?? '')),
            trim((string)($b['time_start'] ?? '')),
            trim((string)($b['time_end'] ?? '')),
        ]);
    }
    sort($parts);
    return hash('sha256', implode('|', $parts));
}

function ssaDailyBookingReminderPreferenceAllows(PDO $pdo, int $uid, string $preferenceKey): bool
{
    // iOS preferences are currently local-only. When backend preference sync is
    // added, this is the single rule hook to enforce $preferenceKey per uid.
    unset($pdo, $uid, $preferenceKey);
    return true;
}

function ssaDailyBookingReminderBody(array $bookings): string
{
    $first = $bookings[0] ?? [];
    $tableName = ssaDailyBookingReminderNormaliseTableName((string)($first['table_name'] ?? ''));

    $timeStart = ssaApiNormaliseTimeValue($first['time_start'] ?? null) ?? 'time TBC';
    $timeEnd = ssaApiNormaliseTimeValue($first['time_end'] ?? null) ?? 'time TBC';

    $extraCount = count($bookings) - 1;

    if ($extraCount === 0) {
        return 'You have a booking today: ' . $tableName . ', ' . $timeStart . "\u{2013}" . $timeEnd;
    }

    $total = $extraCount + 1;
    return 'You have ' . $total . ' bookings today. Tap to view My Bookings.';
}

function ssaDailyBookingReminderPayload(array $bookings, string $date): array
{
    $first = $bookings[0] ?? [];

    $payload = [
        'type' => SSA_DAILY_BOOKING_REMINDER_EVENT_TYPE,
        'screen' => 'myBookings',
        'date' => $date,
    ];

    if (isset($first['bid']) && (int)$first['bid'] > 0) {
        $payload['bookingId'] = (string)(int)$first['bid'];
    }

    if (isset($first['rid']) && (int)$first['rid'] > 0) {
        $payload['reservationId'] = (string)(int)$first['rid'];
    }

    return $payload;
}

function ssaDailyBookingReminderReserveLog(PDO $pdo, int $uid, string $date, array $payload, string $signatureHash): ?int
{
    $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($jsonPayload === false) {
        $jsonPayload = null;
    }

    $statement = $pdo->prepare(
        'INSERT IGNORE INTO ssa_push_notification_log
            (uid, rule_key, notification_date, booking_signature_hash, event_type, payload_json, status, created_at, updated_at)
         VALUES
            (:uid, :ruleKey, :notificationDate, :signatureHash, :eventType, :payloadJson, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    );

    $statement->execute([
        'uid' => $uid,
        'ruleKey' => SSA_DAILY_BOOKING_REMINDER_RULE_KEY,
        'notificationDate' => $date,
        'signatureHash' => $signatureHash,
        'eventType' => SSA_DAILY_BOOKING_REMINDER_EVENT_TYPE,
        'payloadJson' => $jsonPayload,
        'status' => 'sending',
    ]);

    if ($statement->rowCount() <= 0) {
        return null;
    }

    return (int)$pdo->lastInsertId();
}

function ssaDailyBookingReminderUpdateLog(PDO $pdo, int $logId, array $sendResult): void
{
    $status = ((int)($sendResult['successCount'] ?? 0)) > 0 ? 'sent' : 'failed';
    $errorMessage = null;

    if ($status === 'failed') {
        $firstReason = null;
        foreach (($sendResult['results'] ?? []) as $result) {
            if (!empty($result['reason'])) {
                $firstReason = (string)$result['reason'];
                break;
            }
        }
        $errorMessage = $firstReason !== null ? substr($firstReason, 0, 512) : 'No APNs token accepted the notification.';
    }

    $sentAtSql = $status === 'sent' ? 'UTC_TIMESTAMP()' : 'sent_at';

    $statement = $pdo->prepare(
        'UPDATE ssa_push_notification_log
         SET status = :status,
             token_count = :tokenCount,
             success_count = :successCount,
             failure_count = :failureCount,
             error_message = :errorMessage,
             sent_at = ' . $sentAtSql . ',
             updated_at = UTC_TIMESTAMP()
         WHERE id = :id'
    );

    $statement->execute([
        'status' => $status,
        'tokenCount' => (int)($sendResult['tokenCount'] ?? 0),
        'successCount' => (int)($sendResult['successCount'] ?? 0),
        'failureCount' => (int)($sendResult['failureCount'] ?? 0),
        'errorMessage' => $errorMessage,
        'id' => $logId,
    ]);
}

function ssaDailyBookingReminderSummariseBookings(array $bookings): array
{
    return array_map(static function (array $booking): array {
        return [
            'bookingId' => isset($booking['bid']) ? (int)$booking['bid'] : null,
            'reservationId' => isset($booking['rid']) ? (int)$booking['rid'] : null,
            'tableName' => $booking['table_name'] ?? null,
            'timeStart' => ssaApiNormaliseTimeValue($booking['time_start'] ?? null),
            'timeEnd' => ssaApiNormaliseTimeValue($booking['time_end'] ?? null),
        ];
    }, $bookings);
}

/**
 * Run the full daily booking reminder send cycle.
 *
 * $options keys:
 *   dryRun  bool   — inspect only; do not send or write idempotency rows
 *   uid     ?int   — limit to one booking user for safe manual testing
 *   force   bool   — bypass the Europe/London 08:00 time guard
 *
 * Returns the summary array (same shape as the CLI JSON output).
 */
function ssaDailyBookingReminderRun(PDO $pdo, DateTimeImmutable $now, array $options): array
{
    $dryRun = (bool)($options['dryRun'] ?? false);
    $uid = isset($options['uid']) && $options['uid'] !== null ? (int)$options['uid'] : null;
    $force = (bool)($options['force'] ?? false);

    if (!$force && $now->format('H') !== '08') {
        return [
            'status' => 'skipped',
            'skippedReason' => 'outside_reminder_window',
            'localTime' => $now->format('Y-m-d H:i:s T'),
            'timezone' => SSA_API_TIMEZONE,
            'message' => 'Outside daily booking reminder window. Expected Europe/London hour 08. Use force=true for manual testing.',
        ];
    }

    ssaDailyBookingReminderEnsureLogTable($pdo);

    $date = $now->format('Y-m-d');
    $bookingsByUid = ssaDailyBookingReminderFetchBookings($pdo, $date, $uid);
    $tokensByUid = ssaDailyBookingReminderFetchTokens($pdo, array_keys($bookingsByUid));

    $summary = [
        'status' => 'ok',
        'dryRun' => $dryRun,
        'forced' => $force,
        'timezone' => SSA_API_TIMEZONE,
        'localTime' => $now->format('Y-m-d H:i:s T'),
        'notificationDate' => $date,
        'ruleKey' => SSA_DAILY_BOOKING_REMINDER_RULE_KEY,
        'preferenceKey' => SSA_DAILY_BOOKING_REMINDER_PREFERENCE_KEY,
        'eligibleUserCount' => count($bookingsByUid),
        'processed' => [],
    ];

    foreach ($bookingsByUid as $bookingUid => $bookings) {
        $bookingUid = (int)$bookingUid;
        $tokens = $tokensByUid[$bookingUid] ?? [];
        $payload = ssaDailyBookingReminderPayload($bookings, $date);
        $body = ssaDailyBookingReminderBody($bookings);
        $signatureHash = ssaDailyBookingReminderSignature($bookings);
        $preferenceAllows = ssaDailyBookingReminderPreferenceAllows($pdo, $bookingUid, SSA_DAILY_BOOKING_REMINDER_PREFERENCE_KEY);

        $item = [
            'uid' => $bookingUid,
            'bookingCount' => count($bookings),
            'tokenCount' => count($tokens),
            'preferenceAllows' => $preferenceAllows,
            'title' => SSA_DAILY_BOOKING_REMINDER_TITLE,
            'body' => $body,
            'bookingSignatureHash' => $signatureHash,
            'payload' => $payload,
            'bookings' => ssaDailyBookingReminderSummariseBookings($bookings),
            'sent' => false,
            'skippedReason' => null,
        ];

        if (!$preferenceAllows) {
            $item['skippedReason'] = 'preference_disabled';
            $summary['processed'][] = $item;
            continue;
        }

        if (count($tokens) === 0) {
            $item['skippedReason'] = 'no_enabled_ios_tokens';
            $summary['processed'][] = $item;
            continue;
        }

        if ($dryRun) {
            $item['skippedReason'] = 'dry_run';
            $summary['processed'][] = $item;
            continue;
        }

        $logId = ssaDailyBookingReminderReserveLog($pdo, $bookingUid, $date, $payload, $signatureHash);
        if ($logId === null) {
            $item['skippedReason'] = 'already_sent_or_reserved_for_rule_date';
            $summary['processed'][] = $item;
            continue;
        }

        $sendResult = ssaPushSendToTokenRows($tokens, SSA_DAILY_BOOKING_REMINDER_TITLE, $body, $payload);
        ssaDailyBookingReminderUpdateLog($pdo, $logId, $sendResult);

        $item['sent'] = ((int)$sendResult['successCount']) > 0;
        $item['sendResult'] = $sendResult;
        $summary['processed'][] = $item;
    }

    return $summary;
}
