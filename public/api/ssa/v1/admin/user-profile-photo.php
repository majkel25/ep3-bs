<?php

declare(strict_types=1);

/**
 * POST /api/ssa/v1/admin/user-profile-photo.php
 *
 * Admin/Owner can upload a profile photo for any member.
 * Resolves the selected member's scoreboard.member_id and forwards
 * the image to the scoreboard internal upload endpoint — identical
 * to account/profile-photo.php but for any uid, not just the caller.
 *
 * Multipart form fields:
 *   uid    int        – target member uid (required)
 *   photo  file       – JPEG/PNG/WebP, max 5 MB (required)
 *
 * Response:
 *   status, uid, scoreboardMemberId, profilePhotoUrl, hasProfilePhoto
 *
 * Env vars:
 *   SSA_SCOREBOARD_INTERNAL_URL
 *   SSA_INTERNAL_API_KEY
 *   SSA_SCOREBOARD_BASE_URL
 */

require_once __DIR__ . '/../_auth0.php';
require_once __DIR__ . '/../_db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ssaApiJsonResponse(405, ['error' => 'method_not_allowed', 'message' => 'POST required.']);
}

$claims   = ssaApiRequireAuth0Claims();
$auth0Sub = trim((string)($claims['sub'] ?? ''));

if ($auth0Sub === '') {
    ssaApiJsonResponse(401, ['error' => 'missing_auth0_subject', 'message' => 'Auth0 token missing subject.']);
}

try {
    $pdo = ssaApiCreatePdo();

    // Resolve caller uid and check role.
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

    // Resolve target uid — use POST field (multipart), not body JSON.
    $targetUid = isset($_POST['uid']) && is_numeric($_POST['uid']) ? (int)$_POST['uid'] : null;

    if ($targetUid === null || $targetUid <= 0) {
        ssaApiJsonResponse(400, ['error' => 'missing_uid', 'message' => 'uid field is required.']);
    }

    // Verify target exists in booking DB.
    $userStmt = $pdo->prepare('SELECT uid FROM bs_users WHERE uid = :uid LIMIT 1');
    $userStmt->execute(['uid' => $targetUid]);
    if (!$userStmt->fetch()) {
        ssaApiJsonResponse(404, ['error' => 'user_not_found', 'message' => 'Target user not found.']);
    }

    // Resolve selected member's scoreboard mapping.
    $rawMemberId = ssaApiGetUserMetaValue($pdo, $targetUid, 'scoreboard.member_id');
    if ($rawMemberId === null || !ctype_digit($rawMemberId) || (int)$rawMemberId <= 0) {
        ssaApiJsonResponse(422, [
            'error'   => 'scoreboard_mapping_required',
            'message' => 'This member is not linked to a scoreboard profile. Set a scoreboard mapping first.',
        ]);
    }

    $scoreboardMemberId = (int)$rawMemberId;

    error_log(sprintf(
        'SSA admin/user-profile-photo: callerUid=%d targetUid=%d scoreboardMemberId=%d',
        $callerUid, $targetUid, $scoreboardMemberId
    ));
} catch (Throwable $ex) {
    error_log('SSA admin/user-profile-photo: DB lookup failed: ' . $ex->getMessage());
    ssaApiJsonResponse(500, ['error' => 'db_lookup_failed', 'message' => 'Unable to look up account details.']);
}

// Validate uploaded file.
if (!isset($_FILES['photo']) || $_FILES['photo']['error'] === UPLOAD_ERR_NO_FILE) {
    ssaApiJsonResponse(400, ['error' => 'missing_file', 'message' => 'A file field named "photo" is required.']);
}

$file = $_FILES['photo'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    $errCode = $file['error'];
    if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
        ssaApiJsonResponse(400, ['error' => 'file_too_large', 'message' => 'Photo is too large.']);
    }
    ssaApiJsonResponse(400, ['error' => 'upload_error', 'message' => 'Upload failed (code ' . $errCode . ').']);
}

$maxBytes = 5 * 1024 * 1024;
if ($file['size'] <= 0) {
    ssaApiJsonResponse(400, ['error' => 'empty_file', 'message' => 'The uploaded file is empty.']);
}
if ($file['size'] > $maxBytes) {
    ssaApiJsonResponse(400, ['error' => 'file_too_large', 'message' => 'Photo is too large.']);
}

$allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
$detectedMime     = mime_content_type($file['tmp_name']);
if ($detectedMime === false || !in_array(strtolower($detectedMime), $allowedMimeTypes, true)) {
    ssaApiJsonResponse(400, ['error' => 'unsupported_image', 'message' => 'Unsupported photo format. Use JPEG, PNG, or WebP.']);
}

error_log(sprintf(
    'SSA admin/user-profile-photo: validated photo for targetUid=%d scoreboardMemberId=%d size=%d mime=%s',
    $targetUid, $scoreboardMemberId, $file['size'], $detectedMime
));

// Forward to scoreboard internal upload.
$scoreboardInternalUrl = getenv('SSA_SCOREBOARD_INTERNAL_URL');
if ($scoreboardInternalUrl === false || trim($scoreboardInternalUrl) === '') {
    error_log('SSA admin/user-profile-photo: SSA_SCOREBOARD_INTERNAL_URL not configured.');
    ssaApiJsonResponse(503, ['error' => 'scoreboard_url_missing', 'message' => 'Photo service is not configured.']);
}
$scoreboardInternalUrl = rtrim(trim($scoreboardInternalUrl), '/');

$internalApiKey = getenv('SSA_INTERNAL_API_KEY');
if ($internalApiKey === false || trim($internalApiKey) === '') {
    error_log('SSA admin/user-profile-photo: SSA_INTERNAL_API_KEY not configured.');
    ssaApiJsonResponse(503, ['error' => 'internal_key_missing', 'message' => 'Photo service is not configured.']);
}

$uploadUrl  = $scoreboardInternalUrl . '/api/internal/player-photos/' . $scoreboardMemberId . '/upload';
$curlFile   = new CURLFile($file['tmp_name'], $detectedMime, 'photo');

$ch = curl_init($uploadUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => ['photo' => $curlFile],
    CURLOPT_HTTPHEADER     => ['X-Internal-Api-Key: ' . $internalApiKey, 'Accept: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
]);

$responseBody = curl_exec($ch);
$httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError    = curl_error($ch);
curl_close($ch);

if ($curlError !== '') {
    error_log('SSA admin/user-profile-photo: curl error: ' . $curlError);
    ssaApiJsonResponse(502, ['error' => 'scoreboard_forward_failed', 'message' => 'Photo service rejected the upload.']);
}

error_log('SSA admin/user-profile-photo: scoreboard HTTP ' . $httpCode . ' targetUid=' . $targetUid . ' scoreboardMemberId=' . $scoreboardMemberId);

if ($httpCode === 401 || $httpCode === 403) {
    ssaApiJsonResponse(503, ['error' => 'scoreboard_auth_failed', 'message' => 'Photo service is not configured.']);
}

if ($httpCode === 400) {
    $decoded = json_decode($responseBody, true);
    ssaApiJsonResponse(400, [
        'error'   => $decoded['error'] ?? 'upload_rejected',
        'message' => $decoded['message'] ?? 'Photo service rejected the upload.',
    ]);
}

if ($httpCode !== 200) {
    error_log('SSA admin/user-profile-photo: unexpected HTTP ' . $httpCode . ' preview=' . substr((string)$responseBody, 0, 200));
    ssaApiJsonResponse(502, [
        'error'   => 'scoreboard_forward_failed',
        'message' => 'Photo service returned an unexpected response.',
    ]);
}

$scoreboardBase  = ssaApiGetScoreboardBaseUrl();
$profilePhotoUrl = $scoreboardBase !== null
    ? $scoreboardBase . '/api/player-photos/' . $scoreboardMemberId . '/processed'
    : null;

ssaApiJsonResponse(200, [
    'status'             => 'ok',
    'uid'                => $targetUid,
    'scoreboardMemberId' => $scoreboardMemberId,
    'profilePhotoUrl'    => $profilePhotoUrl,
    'hasProfilePhoto'    => $profilePhotoUrl !== null,
]);
