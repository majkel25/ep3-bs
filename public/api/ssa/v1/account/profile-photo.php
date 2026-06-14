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

// --- Resolve uid and scoreboard member ID ---

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

    $rawMemberId = ssaApiGetUserMetaValue($pdo, $uid, 'scoreboard.member_id');
    if ($rawMemberId === null || !ctype_digit($rawMemberId) || (int)$rawMemberId <= 0) {
        ssaApiJsonResponse(422, [
            'error' => 'scoreboard_member_not_linked',
            'message' => 'Your account is not linked to a scoreboard member profile. Contact the club to have your profile set up.',
        ]);
    }

    $scoreboardMemberId = (int)$rawMemberId;
} catch (Throwable $ex) {
    error_log('SSA API profile-photo: DB lookup failed: ' . $ex->getMessage());
    ssaApiJsonResponse(500, [
        'error' => 'db_lookup_failed',
        'message' => 'Unable to look up account details.',
    ]);
}

// --- Validate uploaded file ---

if (!isset($_FILES['photo']) || $_FILES['photo']['error'] === UPLOAD_ERR_NO_FILE) {
    ssaApiJsonResponse(400, [
        'error' => 'missing_file',
        'message' => 'A file field named "photo" is required.',
    ]);
}

$file = $_FILES['photo'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds the server upload limit.',
        UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the form size limit.',
        UPLOAD_ERR_PARTIAL    => 'The uploaded file was only partially uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
    ];
    ssaApiJsonResponse(400, [
        'error' => 'upload_error',
        'message' => $uploadErrors[$file['error']] ?? 'Upload failed with error code ' . $file['error'] . '.',
    ]);
}

$maxBytes = 5 * 1024 * 1024;
if ($file['size'] <= 0) {
    ssaApiJsonResponse(400, ['error' => 'empty_file', 'message' => 'The uploaded file is empty.']);
}
if ($file['size'] > $maxBytes) {
    ssaApiJsonResponse(400, ['error' => 'file_too_large', 'message' => 'Photo is too large.']);
}

$allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
$detectedMime = mime_content_type($file['tmp_name']);
if ($detectedMime === false || !in_array(strtolower($detectedMime), $allowedMimeTypes, true)) {
    ssaApiJsonResponse(400, [
        'error' => 'unsupported_image',
        'message' => 'Unsupported photo format.',
    ]);
}

// --- Forward to scoreboard internal upload endpoint ---

$scoreboardInternalUrl = getenv('SSA_SCOREBOARD_INTERNAL_URL');
if ($scoreboardInternalUrl === false || trim($scoreboardInternalUrl) === '') {
    error_log('SSA API profile-photo: SSA_SCOREBOARD_INTERNAL_URL is not configured.');
    ssaApiJsonResponse(503, [
        'error' => 'scoreboard_url_missing',
        'message' => 'Photo service is not configured.',
    ]);
}
$scoreboardInternalUrl = rtrim(trim($scoreboardInternalUrl), '/');

$internalApiKey = getenv('SSA_INTERNAL_API_KEY');
if ($internalApiKey === false || trim($internalApiKey) === '') {
    error_log('SSA API profile-photo: SSA_INTERNAL_API_KEY is not configured.');
    ssaApiJsonResponse(503, [
        'error' => 'internal_key_missing',
        'message' => 'Photo service is not configured.',
    ]);
}

$uploadUrl = $scoreboardInternalUrl . '/api/internal/player-photos/' . $scoreboardMemberId . '/upload';
error_log('SSA API profile-photo: forwarding to scoreboard memberId=' . $scoreboardMemberId . ' url=' . $uploadUrl);

$curlFile = new CURLFile($file['tmp_name'], $detectedMime, 'photo');

$ch = curl_init($uploadUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => ['photo' => $curlFile],
    CURLOPT_HTTPHEADER     => [
        'X-Internal-Api-Key: ' . $internalApiKey,
        'Accept: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
]);

$responseBody = curl_exec($ch);
$httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError    = curl_error($ch);
curl_close($ch);

if ($curlError !== '') {
    error_log('SSA API profile-photo: cURL error forwarding to scoreboard: ' . $curlError);
    ssaApiJsonResponse(502, [
        'error'   => 'scoreboard_forward_failed',
        'message' => 'Photo service rejected the upload.',
    ]);
}

error_log('SSA API profile-photo: scoreboard responded HTTP ' . $httpCode . ' for memberId=' . $scoreboardMemberId);

if ($httpCode === 401 || $httpCode === 403) {
    ssaApiJsonResponse(503, [
        'error'   => 'scoreboard_auth_failed',
        'message' => 'Photo service is not configured.',
    ]);
}

if ($httpCode === 400) {
    $decoded = json_decode($responseBody, true);
    ssaApiJsonResponse(400, [
        'error'   => $decoded['error'] ?? 'upload_rejected',
        'message' => $decoded['message'] ?? 'Photo service rejected the upload.',
    ]);
}

if ($httpCode !== 200) {
    error_log('SSA API profile-photo: scoreboard unexpected HTTP ' . $httpCode . ' preview=' . substr((string)$responseBody, 0, 200));
    ssaApiJsonResponse(502, [
        'error'                      => 'scoreboard_forward_failed',
        'message'                    => 'Photo service rejected the upload.',
        'scoreboard_http_status'     => $httpCode,
        'scoreboard_response_preview' => substr((string)$responseBody, 0, 200),
    ]);
}

$scoreboardBaseUrl = ssaApiGetScoreboardBaseUrl();
$profilePhotoUrl   = $scoreboardBaseUrl !== null
    ? $scoreboardBaseUrl . '/api/player-photos/' . $scoreboardMemberId . '/processed'
    : null;

ssaApiJsonResponse(200, [
    'status'              => 'ok',
    'scoreboardMemberId'  => $scoreboardMemberId,
    'profilePhotoUrl'     => $profilePhotoUrl,
]);
