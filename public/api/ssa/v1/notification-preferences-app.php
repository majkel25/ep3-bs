<?php

declare(strict_types=1);

/**
 * GET/POST /api/ssa/v1/notification-preferences-app.php
 *
 * Persists the app-side push notification preference map for the calling user.
 * Keys match PushNotificationPreferenceKey.rawValue strings in the iOS app:
 *   daily_booking_reminders, weekly_summary_reminders, upcoming_tournaments,
 *   club_news, match_recordings_posted, coaching_bookings, membership_updates
 *
 * Stored as JSON blob in bs_users_meta key 'ssa.notification.preferences'.
 *
 * GET  – returns preferences (all keys default to true if not set)
 * POST – accepts { preferences: { [key: string]: bool } } and saves
 *
 * Auth: Auth0 bearer token.
 */

require_once __DIR__ . '/_auth0.php';
require_once __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET' && $method !== 'POST') {
    ssaApiJsonResponse(405, ['error' => 'method_not_allowed', 'message' => 'Only GET and POST are allowed.']);
}

$claims   = ssaApiRequireAuth0Claims();
$auth0Sub = trim((string)($claims['sub'] ?? ''));

if ($auth0Sub === '') {
    ssaApiJsonResponse(401, ['error' => 'missing_auth0_subject', 'message' => 'Auth0 token missing subject.']);
}

const ALLOWED_PREF_KEYS = [
    'daily_booking_reminders',
    'weekly_summary_reminders',
    'upcoming_tournaments',
    'club_news',
    'match_recordings_posted',
    'coaching_bookings',
    'membership_updates',
    'booking_amended_active_week',
];

const META_KEY = 'ssa.notification.preferences';

try {
    $pdo = ssaApiCreatePdo();

    // Resolve uid.
    $linkStmt = $pdo->prepare(
        'SELECT uid FROM ssa_auth0_user_links WHERE auth0_sub = :sub AND revoked_at IS NULL LIMIT 1'
    );
    $linkStmt->execute(['sub' => $auth0Sub]);
    $link = $linkStmt->fetch();

    if (!is_array($link) || (int)($link['uid'] ?? 0) <= 0) {
        ssaApiJsonResponse(403, ['error' => 'not_linked', 'message' => 'No linked account found.']);
    }

    $uid = (int)$link['uid'];

    if ($method === 'GET') {
        $val    = ssaApiGetUserMetaValue($pdo, $uid, META_KEY);
        $stored = ($val !== null) ? json_decode($val, true) : [];
        if (!is_array($stored)) {
            $stored = [];
        }

        // New keys that should default to false (opt-in) rather than true (opt-out).
        $defaultOffKeys = ['booking_amended_active_week'];

        // Build full map; unset keys use true for legacy keys, false for new opt-in keys.
        $prefs = [];
        foreach (ALLOWED_PREF_KEYS as $key) {
            if (isset($stored[$key])) {
                $prefs[$key] = (bool)$stored[$key];
            } else {
                $prefs[$key] = !in_array($key, $defaultOffKeys, true);
            }
        }

        ssaApiJsonResponse(200, ['status' => 'ok', 'preferences' => $prefs]);
    }

    // POST — save preferences.
    $rawBody = (string)file_get_contents('php://input');
    $body    = [];
    if ($rawBody !== '') {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $body = $decoded;
        }
    }

    $incoming = isset($body['preferences']) && is_array($body['preferences']) ? $body['preferences'] : [];

    // Whitelist and cast.
    $toSave = [];
    foreach (ALLOWED_PREF_KEYS as $key) {
        if (array_key_exists($key, $incoming)) {
            $toSave[$key] = (bool)$incoming[$key];
        }
    }

    if (empty($toSave)) {
        ssaApiJsonResponse(400, ['error' => 'missing_preferences', 'message' => 'No valid preference keys provided.']);
    }

    // Merge with existing.
    $existing = ssaApiGetUserMetaValue($pdo, $uid, META_KEY);
    $merged   = ($existing !== null) ? json_decode($existing, true) : [];
    if (!is_array($merged)) {
        $merged = [];
    }
    $merged = array_merge($merged, $toSave);

    $json = json_encode($merged, JSON_UNESCAPED_UNICODE);

    if ($existing !== null) {
        $upd = $pdo->prepare(
            "UPDATE bs_users_meta SET value = :val WHERE uid = :uid AND `key` = :key"
        );
        $upd->execute(['val' => $json, 'uid' => $uid, 'key' => META_KEY]);
    } else {
        $ins = $pdo->prepare(
            "INSERT INTO bs_users_meta (uid, `key`, value) VALUES (:uid, :key, :val)"
        );
        $ins->execute(['uid' => $uid, 'key' => META_KEY, 'val' => $json]);
    }

    ssaApiJsonResponse(200, ['status' => 'ok', 'message' => 'Preferences saved.']);
} catch (Throwable $e) {
    error_log('SSA notification-preferences-app.php failed: ' . $e->getMessage());
    ssaApiJsonResponse(500, ['error' => 'server_error', 'message' => 'Unable to process preferences.']);
}
