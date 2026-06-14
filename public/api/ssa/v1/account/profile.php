<?php
require_once __DIR__ . '/../_auth0.php';
require_once __DIR__ . '/../_db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ssaApiJsonResponse(405, [
        'error' => 'method_not_allowed',
        'message' => 'POST required.',
    ]);
}

$claims = ssaApiRequireAuth0Claims();

$auth0Sub = isset($claims['sub']) ? trim((string)$claims['sub']) : '';
if ($auth0Sub === '') {
    ssaApiJsonResponse(401, [
        'error' => 'missing_auth0_subject',
        'message' => 'Auth0 token does not contain a subject.',
    ]);
}

// --- Parse and validate request body ---

$rawBody = file_get_contents('php://input');
$body    = json_decode($rawBody, true);

if (!is_array($body)) {
    ssaApiJsonResponse(400, [
        'error' => 'invalid_json',
        'message' => 'Request body must be valid JSON.',
    ]);
}

// Phone is the only updatable field for now.
if (!array_key_exists('phone', $body)) {
    ssaApiJsonResponse(400, [
        'error' => 'missing_field',
        'message' => 'Field "phone" is required.',
    ]);
}

$rawPhone = (string)($body['phone'] ?? '');
$phone    = trim($rawPhone);

// Allow empty string to clear phone, but if non-empty, validate format and length.
if ($phone !== '') {
    if (strlen($phone) < 3 || strlen($phone) > 30) {
        ssaApiJsonResponse(400, [
            'error' => 'invalid_phone',
            'message' => 'Phone number must be between 3 and 30 characters.',
        ]);
    }

    // Accept digits, spaces, +, /, (, ), - as used by the booking system.
    if (!preg_match('/^([ +\/\(\)\-0-9])+$/u', $phone)) {
        ssaApiJsonResponse(400, [
            'error' => 'invalid_phone',
            'message' => 'Phone number contains invalid characters. Only digits, spaces, +, -, (, ) are allowed.',
        ]);
    }
}

// --- Resolve uid from Auth0 link ---

try {
    $pdo = ssaApiCreatePdo();

    $stmt = $pdo->prepare(
        'SELECT uid FROM ssa_auth0_user_links
         WHERE auth0_sub = :auth0Sub AND revoked_at IS NULL
         LIMIT 1'
    );
    $stmt->execute(['auth0Sub' => $auth0Sub]);
    $link = $stmt->fetch();

    if (!$link) {
        ssaApiJsonResponse(403, [
            'error' => 'account_not_linked',
            'message' => 'No booking account is linked to this Auth0 identity.',
        ]);
    }

    $uid = isset($link['uid']) ? (int)$link['uid'] : 0;
    if ($uid <= 0) {
        ssaApiJsonResponse(403, [
            'error' => 'account_not_linked',
            'message' => 'Linked account does not have a valid user ID.',
        ]);
    }

    // --- Upsert phone in bs_users_meta ---
    // bs_users_meta has no unique constraint on (uid, key), so we must
    // check whether a row already exists and INSERT or UPDATE accordingly.

    $existingRow = $pdo->prepare(
        'SELECT umid FROM bs_users_meta WHERE uid = :uid AND `key` = :key LIMIT 1'
    );
    $existingRow->execute(['uid' => $uid, 'key' => 'phone']);
    $existing = $existingRow->fetch();

    if ($existing) {
        if ($phone === '') {
            // Clear the value by deleting the row, matching booking-system behaviour.
            $del = $pdo->prepare(
                'DELETE FROM bs_users_meta WHERE uid = :uid AND `key` = :key'
            );
            $del->execute(['uid' => $uid, 'key' => 'phone']);
        } else {
            $upd = $pdo->prepare(
                'UPDATE bs_users_meta SET value = :value WHERE uid = :uid AND `key` = :key'
            );
            $upd->execute(['value' => $phone, 'uid' => $uid, 'key' => 'phone']);
        }
    } elseif ($phone !== '') {
        $ins = $pdo->prepare(
            'INSERT INTO bs_users_meta (uid, `key`, value) VALUES (:uid, :key, :value)'
        );
        $ins->execute(['uid' => $uid, 'key' => 'phone', 'value' => $phone]);
    }

    // Return the stored value (null when cleared).
    ssaApiJsonResponse(200, [
        'status' => 'ok',
        'phone'  => $phone !== '' ? $phone : null,
    ]);
} catch (Throwable $ex) {
    error_log('SSA API account/profile: failed: ' . $ex->getMessage());

    ssaApiJsonResponse(500, [
        'error' => 'profile_update_failed',
        'message' => 'Unable to update profile.',
    ]);
}
