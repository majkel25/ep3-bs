<?php
require_once __DIR__ . '/../_auth0.php';
require_once __DIR__ . '/../_db.php';
require_once __DIR__ . '/../_mail.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ssaApiJsonResponse(405, [
        'error'   => 'method_not_allowed',
        'message' => 'POST required.',
    ]);
}

$claims = ssaApiRequireAuth0Claims();

$auth0Sub = isset($claims['sub']) ? trim((string)$claims['sub']) : '';
if ($auth0Sub === '') {
    ssaApiJsonResponse(401, [
        'error'   => 'missing_auth0_subject',
        'message' => 'Auth0 token does not contain a subject.',
    ]);
}

// --- Parse and validate request body ---

$rawBody = file_get_contents('php://input');
$body    = json_decode($rawBody, true);

if (!is_array($body)) {
    ssaApiJsonResponse(400, [
        'error'   => 'invalid_json',
        'message' => 'Request body must be valid JSON.',
    ]);
}

// Email is not updatable via this endpoint.
if (array_key_exists('email', $body)) {
    ssaApiJsonResponse(400, [
        'error'   => 'email_not_updatable',
        'message' => 'Email address cannot be changed through this endpoint.',
    ]);
}

if (!array_key_exists('fullName', $body) && !array_key_exists('phone', $body)) {
    ssaApiJsonResponse(400, [
        'error'   => 'missing_fields',
        'message' => 'At least one of "fullName" or "phone" is required.',
    ]);
}

// Validate fullName if provided.
$newFullName = null;
if (array_key_exists('fullName', $body)) {
    $raw = trim((string)($body['fullName'] ?? ''));
    if ($raw === '') {
        ssaApiJsonResponse(400, [
            'error'   => 'invalid_full_name',
            'message' => 'Full name cannot be empty.',
        ]);
    }
    if (strlen($raw) < 2 || strlen($raw) > 64) {
        ssaApiJsonResponse(400, [
            'error'   => 'invalid_full_name',
            'message' => 'Full name must be between 2 and 64 characters.',
        ]);
    }
    $newFullName = $raw;
}

// Validate phone if provided.
$newPhone = null;
if (array_key_exists('phone', $body)) {
    $raw = trim((string)($body['phone'] ?? ''));
    if ($raw !== '') {
        if (strlen($raw) < 3 || strlen($raw) > 30) {
            ssaApiJsonResponse(400, [
                'error'   => 'invalid_phone',
                'message' => 'Phone number must be between 3 and 30 characters.',
            ]);
        }
        if (!preg_match('/^([ +\/\(\)\-0-9])+$/u', $raw)) {
            ssaApiJsonResponse(400, [
                'error'   => 'invalid_phone',
                'message' => 'Phone number contains invalid characters. Only digits, spaces, +, -, (, ) are allowed.',
            ]);
        }
    }
    $newPhone = $raw; // empty string = clear phone
}

// --- Resolve uid and existing values ---

try {
    $pdo = ssaApiCreatePdo();

    $stmt = $pdo->prepare(
        'SELECT id, uid, linked_email, linked_alias
         FROM ssa_auth0_user_links
         WHERE auth0_sub = :auth0Sub AND revoked_at IS NULL
         LIMIT 1'
    );
    $stmt->execute(['auth0Sub' => $auth0Sub]);
    $link = $stmt->fetch();

    if (!$link) {
        ssaApiJsonResponse(403, [
            'error'   => 'account_not_linked',
            'message' => 'No booking account is linked to this Auth0 identity.',
        ]);
    }

    $uid          = isset($link['uid']) ? (int)$link['uid'] : 0;
    $linkId       = isset($link['id']) ? (int)$link['id'] : 0;
    $userEmail    = isset($link['linked_email']) ? (string)$link['linked_email'] : null;
    $currentAlias = isset($link['linked_alias']) ? (string)$link['linked_alias'] : null;

    if ($uid <= 0) {
        ssaApiJsonResponse(403, [
            'error'   => 'account_not_linked',
            'message' => 'Linked account does not have a valid user ID.',
        ]);
    }

    // Current phone from bs_users_meta.
    $rawCurrentPhone = ssaApiGetUserMetaValue($pdo, $uid, 'phone');
    $currentPhone    = ($rawCurrentPhone !== null && trim($rawCurrentPhone) !== '')
        ? trim($rawCurrentPhone)
        : null;

    // --- Detect which fields actually changed ---

    $changedFields = [];

    if ($newFullName !== null && $newFullName !== $currentAlias) {
        $changedFields[] = 'fullName';
    }

    if ($newPhone !== null) {
        $existingPhone = $currentPhone ?? '';
        if ($newPhone !== $existingPhone) {
            $changedFields[] = 'phone';
        }
    }

    // Nothing to do.
    if (empty($changedFields)) {
        ssaApiJsonResponse(200, [
            'status'   => 'ok',
            'fullName' => $currentAlias,
            'phone'    => $currentPhone,
        ]);
    }

    // --- Apply changes ---

    if (in_array('fullName', $changedFields, true)) {
        $updAlias = $pdo->prepare('UPDATE bs_users SET alias = :alias WHERE uid = :uid');
        $updAlias->execute(['alias' => $newFullName, 'uid' => $uid]);

        $updLink = $pdo->prepare(
            'UPDATE ssa_auth0_user_links SET linked_alias = :alias, updated_at = NOW() WHERE id = :id'
        );
        $updLink->execute(['alias' => $newFullName, 'id' => $linkId]);
    }

    if (in_array('phone', $changedFields, true)) {
        $existingRow = $pdo->prepare(
            'SELECT umid FROM bs_users_meta WHERE uid = :uid AND `key` = :key LIMIT 1'
        );
        $existingRow->execute(['uid' => $uid, 'key' => 'phone']);
        $existing = $existingRow->fetch();

        if ($existing) {
            if ($newPhone === '') {
                $del = $pdo->prepare('DELETE FROM bs_users_meta WHERE uid = :uid AND `key` = :key');
                $del->execute(['uid' => $uid, 'key' => 'phone']);
            } else {
                $upd = $pdo->prepare(
                    'UPDATE bs_users_meta SET value = :value WHERE uid = :uid AND `key` = :key'
                );
                $upd->execute(['value' => $newPhone, 'uid' => $uid, 'key' => 'phone']);
            }
        } elseif ($newPhone !== '') {
            $ins = $pdo->prepare(
                'INSERT INTO bs_users_meta (uid, `key`, value) VALUES (:uid, :key, :value)'
            );
            $ins->execute(['uid' => $uid, 'key' => 'phone', 'value' => $newPhone]);
        }
    }

    // --- Confirmation email ---

    $finalFullName = in_array('fullName', $changedFields, true) ? $newFullName : ($currentAlias ?? 'Member');
    $finalPhone    = in_array('phone', $changedFields, true)
        ? ($newPhone !== '' ? $newPhone : null)
        : $currentPhone;

    if ($userEmail !== null && $userEmail !== '') {
        try {
            $lines = ["The following details on your Surrey Snooker Academy account were updated:\n"];

            if (in_array('fullName', $changedFields, true)) {
                $lines[] = "  Full name: $newFullName";
            }
            if (in_array('phone', $changedFields, true)) {
                $lines[] = '  Phone: ' . ($newPhone !== '' ? $newPhone : '(removed)');
            }

            $lines[] = "\nIf you did not make this change, please contact Surrey Snooker Academy.";

            ssaApiSendMail(
                $userEmail,
                $finalFullName,
                'Surrey Snooker Academy account details updated',
                implode("\n", $lines)
            );
        } catch (Throwable $mailEx) {
            error_log('SSA API account/profile: confirmation email failed: ' . $mailEx->getMessage());
        }
    }

    ssaApiJsonResponse(200, [
        'status'   => 'ok',
        'fullName' => $finalFullName,
        'phone'    => $finalPhone,
    ]);
} catch (Throwable $ex) {
    error_log('SSA API account/profile: failed: ' . $ex->getMessage());

    ssaApiJsonResponse(500, [
        'error'   => 'profile_update_failed',
        'message' => 'Unable to update profile.',
    ]);
}
