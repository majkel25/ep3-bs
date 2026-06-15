<?php

declare(strict_types=1);

/**
 * GET  /api/ssa/v1/admin/user-detail.php?uid=123
 * POST /api/ssa/v1/admin/user-detail.php
 *
 * GET  – returns full member detail for admin panel.
 * POST – updates fullName, phone, and/or accountStatus.
 *        Email changes are rejected (email_change_not_supported) because
 *        the Auth0 identity email is managed in the Auth0 dashboard and
 *        no Auth0 Management API is configured on this backend.
 *
 * Auth: Admin or Club Owner only. Member/Coach receive 403.
 *
 * GET response shape:
 *   uid, fullName, email, phone, userType, accountStatus, accountStatusLabel,
 *   scoreboardMemberId, scoreboardMemberName, scoreboardMemberRef,
 *   profilePhotoUrl, hasProfilePhoto,
 *   profileCompleteness { hasPhone, hasEmail, hasScoreboardMapping, hasProfilePhoto },
 *   emailChangeSupported (always false)
 *
 * POST body (JSON):
 *   uid           int     required
 *   fullName      string  optional
 *   phone         string  optional (empty string = clear phone)
 *   accountStatus string  optional – 'enabled' | 'disabled'
 *   email         string  optional – always rejected
 *
 * Scoreboard member name is fetched from the scoreboard internal API when
 * SSA_SCOREBOARD_INTERNAL_URL and SSA_INTERNAL_API_KEY env vars are set.
 * If they are not set (or the call fails), scoreboardMemberName is null.
 */

require_once __DIR__ . '/../_auth0.php';
require_once __DIR__ . '/../_db.php';
require_once __DIR__ . '/../_mail.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET' && $method !== 'POST') {
    ssaApiJsonResponse(405, ['error' => 'method_not_allowed', 'message' => 'Only GET and POST are allowed.']);
}

$claims   = ssaApiRequireAuth0Claims();
$auth0Sub = trim((string)($claims['sub'] ?? ''));

if ($auth0Sub === '') {
    ssaApiJsonResponse(401, ['error' => 'missing_auth0_subject', 'message' => 'Auth0 token missing subject.']);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Fetch member info from scoreboard internal API.
 * Returns null silently if not configured or call fails.
 */
function ssaAdminFetchScoreboardMember(int $scoreboardMemberId): ?array
{
    $internalUrl = (string)getenv('SSA_SCOREBOARD_INTERNAL_URL');
    $apiKey      = (string)getenv('SSA_INTERNAL_API_KEY');

    if ($internalUrl === '' || $apiKey === '') {
        return null;
    }

    $url = rtrim($internalUrl, '/') . '/api/internal/members/' . $scoreboardMemberId;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['X-Internal-Api-Key: ' . $apiKey, 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);

    $body    = curl_exec($ch);
    $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err     = curl_error($ch);
    curl_close($ch);

    if ($err !== '' || $code !== 200 || $body === false) {
        return null;
    }

    $decoded = json_decode((string)$body, true);
    return is_array($decoded) ? $decoded : null;
}

function ssaAdminBuildUserDetail(PDO $pdo, array $userRow, int $callerUid): array
{
    $uid      = (int)$userRow['uid'];
    $fullName = isset($userRow['alias']) && $userRow['alias'] !== '' ? (string)$userRow['alias'] : null;
    $email    = isset($userRow['email']) && $userRow['email'] !== '' ? (string)$userRow['email'] : null;
    $status   = isset($userRow['status']) ? (string)$userRow['status'] : 'enabled';

    $statusLabelMap = [
        'enabled'  => 'Active',
        'disabled' => 'Disabled',
    ];
    $statusLabel = $statusLabelMap[$status] ?? ucfirst($status);

    $phone    = ssaApiGetUserMetaValue($pdo, $uid, 'phone');
    $phone    = ($phone !== null && trim($phone) !== '') ? trim($phone) : null;
    $userType = ssaApiGetUserMetaValue($pdo, $uid, 'ssa.user_type') ?? 'member';

    $rawSbId            = ssaApiGetUserMetaValue($pdo, $uid, 'scoreboard.member_id');
    $scoreboardMemberId = ($rawSbId !== null && ctype_digit($rawSbId) && (int)$rawSbId > 0)
        ? (int)$rawSbId
        : null;

    $scoreboardBase      = ssaApiGetScoreboardBaseUrl();
    $profilePhotoUrl     = null;
    $scoreboardMemberName = null;
    $scoreboardMemberRef  = null;

    if ($scoreboardMemberId !== null) {
        if ($scoreboardBase !== null) {
            $profilePhotoUrl = $scoreboardBase . '/api/player-photos/' . $scoreboardMemberId . '/processed';
        }
        $sbMember = ssaAdminFetchScoreboardMember($scoreboardMemberId);
        if ($sbMember !== null) {
            $scoreboardMemberName = $sbMember['name'] ?? null;
            $scoreboardMemberRef  = $sbMember['accountingRef'] ?? null;
        }
    }

    $hasProfilePhoto = $profilePhotoUrl !== null;

    return [
        'uid'                 => $uid,
        'fullName'            => $fullName,
        'email'               => $email,
        'phone'               => $phone,
        'userType'            => $userType,
        'accountStatus'       => $status,
        'accountStatusLabel'  => $statusLabel,
        'scoreboardMemberId'  => $scoreboardMemberId,
        'scoreboardMemberName' => $scoreboardMemberName,
        'scoreboardMemberRef'  => $scoreboardMemberRef,
        'profilePhotoUrl'     => $profilePhotoUrl,
        'hasProfilePhoto'     => $hasProfilePhoto,
        'emailChangeSupported' => false,
        'profileCompleteness' => [
            'hasPhone'             => $phone !== null,
            'hasEmail'             => $email !== null,
            'hasScoreboardMapping' => $scoreboardMemberId !== null,
            'hasProfilePhoto'      => $hasProfilePhoto,
        ],
    ];
}

// ── Main ──────────────────────────────────────────────────────────────────────

try {
    $pdo = ssaApiCreatePdo();

    // Resolve caller uid and role.
    $linkStmt = $pdo->prepare(
        'SELECT uid FROM ssa_auth0_user_links WHERE auth0_sub = :sub AND revoked_at IS NULL LIMIT 1'
    );
    $linkStmt->execute(['sub' => $auth0Sub]);
    $link = $linkStmt->fetch();

    if (!is_array($link) || (int)($link['uid'] ?? 0) <= 0) {
        ssaApiJsonResponse(403, ['error' => 'not_linked', 'message' => 'No linked account found.']);
    }

    $callerUid  = (int)$link['uid'];
    $callerType = ssaApiGetUserMetaValue($pdo, $callerUid, 'ssa.user_type') ?? 'member';

    if (!in_array($callerType, ['admin', 'club_owner'], true)) {
        ssaApiJsonResponse(403, ['error' => 'forbidden', 'message' => 'Admin or Owner access required.']);
    }

    // ── GET ───────────────────────────────────────────────────────────────────

    if ($method === 'GET') {
        $targetUid = isset($_GET['uid']) && is_numeric($_GET['uid']) ? (int)$_GET['uid'] : null;

        if ($targetUid === null || $targetUid <= 0) {
            ssaApiJsonResponse(400, ['error' => 'missing_uid', 'message' => 'uid query parameter is required.']);
        }

        $userStmt = $pdo->prepare(
            'SELECT uid, alias, email, status FROM bs_users WHERE uid = :uid LIMIT 1'
        );
        $userStmt->execute(['uid' => $targetUid]);
        $userRow = $userStmt->fetch();

        if (!is_array($userRow)) {
            ssaApiJsonResponse(404, ['error' => 'user_not_found', 'message' => 'User not found.']);
        }

        $detail = ssaAdminBuildUserDetail($pdo, $userRow, $callerUid);

        ssaApiJsonResponse(200, array_merge(['status' => 'ok'], $detail));
    }

    // ── POST (update) ─────────────────────────────────────────────────────────

    $rawBody = (string)file_get_contents('php://input');
    $body    = [];
    if ($rawBody !== '') {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $body = $decoded;
        }
    }

    // Always reject email changes.
    if (array_key_exists('email', $body)) {
        ssaApiJsonResponse(400, [
            'error'   => 'email_change_not_supported',
            'message' => 'Email addresses cannot be changed here. Use the Auth0 dashboard to update the Auth0 login email, and the booking admin to update the booking email.',
        ]);
    }

    $targetUid = isset($body['uid']) && is_numeric($body['uid']) ? (int)$body['uid'] : null;

    if ($targetUid === null || $targetUid <= 0) {
        ssaApiJsonResponse(400, ['error' => 'missing_uid', 'message' => 'uid is required.']);
    }

    // Load current user row.
    $userStmt = $pdo->prepare('SELECT uid, alias, email, status FROM bs_users WHERE uid = :uid LIMIT 1');
    $userStmt->execute(['uid' => $targetUid]);
    $userRow = $userStmt->fetch();

    if (!is_array($userRow)) {
        ssaApiJsonResponse(404, ['error' => 'user_not_found', 'message' => 'User not found.']);
    }

    $currentAlias  = (string)($userRow['alias'] ?? '');
    $currentEmail  = (string)($userRow['email'] ?? '');
    $currentStatus = (string)($userRow['status'] ?? 'enabled');

    $changedFields = [];

    // Validate and stage fullName.
    $newFullName = null;
    if (array_key_exists('fullName', $body)) {
        $raw = trim((string)($body['fullName'] ?? ''));
        if ($raw === '') {
            ssaApiJsonResponse(400, ['error' => 'invalid_full_name', 'message' => 'Full name cannot be empty.']);
        }
        if (strlen($raw) < 2 || strlen($raw) > 64) {
            ssaApiJsonResponse(400, ['error' => 'invalid_full_name', 'message' => 'Full name must be between 2 and 64 characters.']);
        }
        if ($raw !== $currentAlias) {
            $newFullName     = $raw;
            $changedFields[] = 'fullName';
        }
    }

    // Validate and stage phone.
    $newPhone = null;
    if (array_key_exists('phone', $body)) {
        $raw = trim((string)($body['phone'] ?? ''));
        if ($raw !== '') {
            if (strlen($raw) < 3 || strlen($raw) > 30) {
                ssaApiJsonResponse(400, ['error' => 'invalid_phone', 'message' => 'Phone must be between 3 and 30 characters.']);
            }
            if (!preg_match('/^([ +\/\(\)\-0-9])+$/u', $raw)) {
                ssaApiJsonResponse(400, ['error' => 'invalid_phone', 'message' => 'Phone contains invalid characters.']);
            }
        }
        $currentPhone = ssaApiGetUserMetaValue($pdo, $targetUid, 'phone') ?? '';
        if ($raw !== $currentPhone) {
            $newPhone        = $raw;
            $changedFields[] = 'phone';
        }
    }

    // Validate and stage accountStatus.
    $newStatus = null;
    if (array_key_exists('accountStatus', $body)) {
        $raw = trim((string)($body['accountStatus'] ?? ''));
        if (!in_array($raw, ['enabled', 'disabled'], true)) {
            ssaApiJsonResponse(400, ['error' => 'invalid_status', 'message' => 'accountStatus must be "enabled" or "disabled".']);
        }
        // Guard: cannot disable yourself.
        if ($targetUid === $callerUid && $raw === 'disabled') {
            ssaApiJsonResponse(400, ['error' => 'cannot_disable_self', 'message' => 'You cannot disable your own account.']);
        }
        if ($raw !== $currentStatus) {
            $newStatus       = $raw;
            $changedFields[] = 'accountStatus';
        }
    }

    if (empty($changedFields)) {
        $detail = ssaAdminBuildUserDetail($pdo, $userRow, $callerUid);
        ssaApiJsonResponse(200, array_merge(['status' => 'ok', 'message' => 'No changes detected.'], $detail));
    }

    // Apply fullName.
    if ($newFullName !== null) {
        $pdo->prepare('UPDATE bs_users SET alias = :alias WHERE uid = :uid')
            ->execute(['alias' => $newFullName, 'uid' => $targetUid]);

        // Keep ssa_auth0_user_links.linked_alias in sync.
        $pdo->prepare(
            'UPDATE ssa_auth0_user_links SET linked_alias = :alias, updated_at = NOW()
             WHERE uid = :uid AND revoked_at IS NULL'
        )->execute(['alias' => $newFullName, 'uid' => $targetUid]);
    }

    // Apply phone.
    if ($newPhone !== null) {
        $existingRow = ssaApiGetUserMetaValue($pdo, $targetUid, 'phone');
        if ($existingRow !== null) {
            if ($newPhone === '') {
                $pdo->prepare("DELETE FROM bs_users_meta WHERE uid = :uid AND `key` = 'phone'")
                    ->execute(['uid' => $targetUid]);
            } else {
                $pdo->prepare("UPDATE bs_users_meta SET value = :val WHERE uid = :uid AND `key` = 'phone'")
                    ->execute(['val' => $newPhone, 'uid' => $targetUid]);
            }
        } elseif ($newPhone !== '') {
            $pdo->prepare("INSERT INTO bs_users_meta (uid, `key`, value) VALUES (:uid, 'phone', :val)")
                ->execute(['uid' => $targetUid, 'val' => $newPhone]);
        }
    }

    // Apply accountStatus.
    if ($newStatus !== null) {
        $pdo->prepare('UPDATE bs_users SET status = :status WHERE uid = :uid')
            ->execute(['status' => $newStatus, 'uid' => $targetUid]);
        $userRow['status'] = $newStatus;
    }

    // Update userRow for response.
    if ($newFullName !== null) {
        $userRow['alias'] = $newFullName;
    }

    // Send notification email (non-fatal).
    if ($currentEmail !== '' && !empty($changedFields)) {
        try {
            $recipientName = $newFullName ?? ($currentAlias !== '' ? $currentAlias : 'Member');
            $lines         = ["Your Surrey Snooker Academy account details were updated by a club administrator:\n"];

            if (in_array('fullName', $changedFields, true)) {
                $lines[] = '  Full name: ' . $newFullName;
            }
            if (in_array('phone', $changedFields, true)) {
                $lines[] = '  Phone: ' . ($newPhone !== '' ? $newPhone : '(removed)');
            }
            if (in_array('accountStatus', $changedFields, true)) {
                $statusLabelMap = ['enabled' => 'Active', 'disabled' => 'Disabled'];
                $lines[] = '  Account status: ' . ($statusLabelMap[$newStatus] ?? $newStatus);
            }

            $lines[] = "\nIf you did not authorise this change, please contact Surrey Snooker Academy.";

            ssaApiSendMail(
                $currentEmail,
                $recipientName,
                'Surrey Snooker Academy account updated by administrator',
                implode("\n", $lines)
            );
        } catch (Throwable $mailEx) {
            error_log('SSA admin/user-detail POST: notification email failed: ' . $mailEx->getMessage());
        }
    }

    $detail = ssaAdminBuildUserDetail($pdo, $userRow, $callerUid);
    ssaApiJsonResponse(200, array_merge(['status' => 'ok'], $detail));
} catch (Throwable $e) {
    $sqlState = ($e instanceof \PDOException && is_array($e->errorInfo))
        ? ($e->errorInfo[0] ?? 'unknown')
        : 'n/a';
    error_log(sprintf('SSA admin/user-detail.php FAILED [%s] SQLSTATE=%s message=%s',
        get_class($e), $sqlState, $e->getMessage()));
    ssaApiJsonResponse(500, ['error' => 'server_error', 'message' => 'Unable to process request.']);
}
