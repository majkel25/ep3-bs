<?php

declare(strict_types=1);

/**
 * Protected cron endpoint: run daily booking push reminders.
 *
 * Called by the GitHub Actions scheduled workflow. Requires the
 * X-SSA-Cron-Secret header to match the SSA_CRON_SECRET env var.
 *
 * Optional JSON body parameters for manual/test invocations:
 *   dryRun  bool   — inspect only; no APNs sends, no idempotency rows written
 *   uid     int    — limit to one booking user
 *   force   bool   — bypass the Europe/London 08:00 local-time guard
 *
 * In scheduled use, the workflow sends no body (dryRun=false, uid=null, force=false).
 */

require_once __DIR__ . '/_auth0.php';
require_once __DIR__ . '/_daily_booking_reminder.php';

// Only called by the cron workflow — no Auth0 token required, just the shared secret.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ssaApiJsonResponse(405, [
        'error' => 'method_not_allowed',
        'message' => 'Only POST is allowed for this endpoint.',
    ]);
}

$expectedSecret = ssaPushEnvValue('SSA_CRON_SECRET');

if ($expectedSecret === null || $expectedSecret === '') {
    error_log('SSA cron endpoint: SSA_CRON_SECRET is not configured.');
    ssaApiJsonResponse(500, [
        'error' => 'cron_secret_not_configured',
        'message' => 'The cron secret is not configured on this backend.',
    ]);
}

$providedSecret = isset($_SERVER['HTTP_X_SSA_CRON_SECRET'])
    ? trim((string)$_SERVER['HTTP_X_SSA_CRON_SECRET'])
    : '';

if (!hash_equals($expectedSecret, $providedSecret)) {
    ssaApiJsonResponse(403, [
        'error' => 'invalid_cron_secret',
        'message' => 'The X-SSA-Cron-Secret header is missing or incorrect.',
    ]);
}

// Parse optional JSON body params.
$options = ['dryRun' => false, 'uid' => null, 'force' => false];

$rawBody = (string)file_get_contents('php://input');
if ($rawBody !== '') {
    $body = json_decode($rawBody, true);
    if (is_array($body)) {
        if (isset($body['dryRun'])) {
            $options['dryRun'] = (bool)$body['dryRun'];
        }
        if (isset($body['uid']) && is_int($body['uid']) && $body['uid'] > 0) {
            $options['uid'] = $body['uid'];
        }
        if (isset($body['force'])) {
            $options['force'] = (bool)$body['force'];
        }
    }
}

try {
    $pdo = ssaApiCreatePdo();
    $now = ssaDailyBookingReminderNow();
    $summary = ssaDailyBookingReminderRun($pdo, $now, $options);
    ssaApiJsonResponse(200, $summary);
} catch (RuntimeException $exception) {
    error_log('SSA cron endpoint runtime error: ' . $exception->getMessage());
    ssaApiJsonResponse(500, [
        'error' => 'reminder_run_failed',
        'message' => $exception->getMessage(),
    ]);
} catch (Throwable $exception) {
    error_log('SSA cron endpoint unexpected error: ' . $exception->getMessage());
    ssaApiJsonResponse(500, [
        'error' => 'reminder_run_failed',
        'message' => 'An unexpected error occurred running the daily booking reminders.',
    ]);
}
