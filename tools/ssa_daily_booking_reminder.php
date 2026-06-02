<?php

declare(strict_types=1);

/**
 * CLI-only daily booking reminder push sender for SSA iOS users.
 *
 * Safe defaults:
 * - Requires Europe/London 08:00 local time unless --force is supplied.
 * - --dry-run never sends APNs pushes and never writes idempotency rows.
 * - --uid=<uid> limits work to one linked booking user for safe testing.
 * - One combined notification per user/day; never one push per booking.
 *
 * Shared business logic lives in public/api/ssa/v1/_daily_booking_reminder.php.
 */

// Stub must be defined before loading _daily_booking_reminder.php, which loads
// _db.php. _db.php references ssaApiJsonResponse inside function bodies; the
// stub converts those error paths into thrown exceptions for CLI callers.
if (!function_exists('ssaApiJsonResponse')) {
    function ssaApiJsonResponse(int $statusCode, array $payload): void
    {
        throw new RuntimeException(
            'API JSON response called during CLI script: HTTP ' .
            $statusCode .
            ' ' .
            json_encode($payload, JSON_UNESCAPED_SLASHES)
        );
    }
}

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../public/api/ssa/v1/_daily_booking_reminder.php';

function ssaDailyBookingReminderUsage(): string
{
    return <<<TXT
Usage: php tools/ssa_daily_booking_reminder.php [--dry-run] [--uid=<uid>] [--force]

Options:
  --dry-run     Inspect eligible users/bookings/tokens without sending or writing idempotency rows.
  --uid=<uid>   Limit to one booking user id. Required for first live manual test.
  --force       Bypass the Europe/London 08:00 local-time guard for manual testing.
  --help        Show this help.
TXT;
}

function ssaDailyBookingReminderParseOptions(array $argv): array
{
    $options = [
        'dryRun' => false,
        'uid' => null,
        'force' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            echo ssaDailyBookingReminderUsage();
            exit(0);
        }

        if ($arg === '--dry-run') {
            $options['dryRun'] = true;
            continue;
        }

        if ($arg === '--force') {
            $options['force'] = true;
            continue;
        }

        if (strpos($arg, '--uid=') === 0) {
            $uid = substr($arg, strlen('--uid='));
            if ($uid === '' || !ctype_digit($uid) || (int)$uid <= 0) {
                throw new InvalidArgumentException('--uid must be a positive integer.');
            }
            $options['uid'] = (int)$uid;
            continue;
        }

        throw new InvalidArgumentException('Unknown option: ' . $arg);
    }

    return $options;
}

try {
    $options = ssaDailyBookingReminderParseOptions($argv);
    $now = ssaDailyBookingReminderNow();
    $pdo = ssaApiCreatePdo();
    $summary = ssaDailyBookingReminderRun($pdo, $now, $options);
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAILED: ' . $exception->getMessage() . "\n");
    exit(1);
}
