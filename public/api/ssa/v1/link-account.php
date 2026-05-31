<?php
require_once __DIR__ . '/_auth0.php';
require_once __DIR__ . '/_db.php';

$claims = ssaApiRequireAuth0Claims();

function ssaApiReadJsonBody(): array
{
    $rawBody = file_get_contents('php://input');

    if ($rawBody === false || trim($rawBody) === '') {
        ssaApiJsonResponse(400, [
            'error' => 'empty_body',
            'message' => 'Request body must contain JSON.',
        ]);
    }

    $data = json_decode($rawBody, true);

    if (!is_array($data)) {
        ssaApiJsonResponse(400, [
            'error' => 'invalid_json',
            'message' => 'Request body must be valid JSON.',
        ]);
    }

    return $data;
}

function ssaApiNormaliseEmail(string $email): string
{
    return strtolower(trim($email));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ssaApiJsonResponse(405, [
        'error' => 'method_not_allowed',
        'message' => 'Use POST for account linking.',
    ]);
}

$auth0Sub = isset($claims['sub']) ? trim((string)$claims['sub']) : '';

if ($auth0Sub === '') {
    ssaApiJsonResponse(401, [
        'error' => 'missing_auth0_subject',
        'message' => 'Auth0 token does not contain a subject.',
    ]);
}

$auth0Email = isset($claims['email']) ? trim((string)$claims['email']) : null;

$body = ssaApiReadJsonBody();

$email = isset($body['email']) ? ssaApiNormaliseEmail((string)$body['email']) : '';
$password = isset($body['password']) ? (string)$body['password'] : '';

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    ssaApiJsonResponse(400, [
        'error' => 'invalid_email',
        'message' => 'A valid booking account email is required.',
    ]);
}

if ($password === '') {
    ssaApiJsonResponse(400, [
        'error' => 'missing_password',
        'message' => 'Booking account password is required.',
    ]);
}

try {
    $pdo = ssaApiCreatePdo();

    $pdo->beginTransaction();

    $userStatement = $pdo->prepare(
        'SELECT
            uid,
            alias,
            status,
            email,
            pw
         FROM bs_users
         WHERE LOWER(TRIM(email)) = :email
           AND status = :enabledStatus
         LIMIT 1'
    );

    $userStatement->execute([
        'email' => $email,
        'enabledStatus' => 'enabled',
    ]);

    $user = $userStatement->fetch();

    if (!$user || !isset($user['pw']) || !password_verify($password, (string)$user['pw'])) {
        $pdo->rollBack();

        ssaApiJsonResponse(401, [
            'error' => 'invalid_booking_credentials',
            'message' => 'The booking account email or password is incorrect.',
        ]);
    }

    $uid = (int)$user['uid'];
    $linkedEmail = ssaApiNormaliseEmail((string)$user['email']);
    $linkedAlias = isset($user['alias']) ? trim((string)$user['alias']) : null;

    $auth0LinkStatement = $pdo->prepare(
        'SELECT
            id,
            auth0_sub,
            uid,
            linked_email,
            linked_alias
         FROM ssa_auth0_user_links
         WHERE auth0_sub = :auth0Sub
         LIMIT 1'
    );

    $auth0LinkStatement->execute([
        'auth0Sub' => $auth0Sub,
    ]);

    $existingAuth0Link = $auth0LinkStatement->fetch();

    if ($existingAuth0Link) {
        if ((int)$existingAuth0Link['uid'] === $uid) {
            $pdo->commit();

            ssaApiJsonResponse(200, [
                'status' => 'ok',
                'linked' => true,
                'alreadyLinked' => true,
                'user' => [
                    'uid' => $uid,
                    'alias' => $existingAuth0Link['linked_alias'],
                    'email' => $existingAuth0Link['linked_email'],
                ],
            ]);
        }

        $pdo->rollBack();

        ssaApiJsonResponse(409, [
            'error' => 'auth0_account_already_linked',
            'message' => 'This Auth0 account is already linked to a different booking account.',
        ]);
    }

    $uidLinkStatement = $pdo->prepare(
        'SELECT
            id,
            auth0_sub,
            uid,
            linked_email,
            linked_alias
         FROM ssa_auth0_user_links
         WHERE uid = :uid
         LIMIT 1'
    );

    $uidLinkStatement->execute([
        'uid' => $uid,
    ]);

    $existingUidLink = $uidLinkStatement->fetch();

    if ($existingUidLink) {
        $pdo->rollBack();

        ssaApiJsonResponse(409, [
            'error' => 'booking_account_already_linked',
            'message' => 'This booking account is already linked to another Auth0 account.',
        ]);
    }

    $insertStatement = $pdo->prepare(
        'INSERT INTO ssa_auth0_user_links
            (auth0_sub, uid, auth0_email, linked_email, linked_alias)
         VALUES
            (:auth0Sub, :uid, :auth0Email, :linkedEmail, :linkedAlias)'
    );

    $insertStatement->execute([
        'auth0Sub' => $auth0Sub,
        'uid' => $uid,
        'auth0Email' => $auth0Email,
        'linkedEmail' => $linkedEmail,
        'linkedAlias' => $linkedAlias,
    ]);

    $pdo->commit();

    ssaApiJsonResponse(200, [
        'status' => 'ok',
        'linked' => true,
        'alreadyLinked' => false,
        'user' => [
            'uid' => $uid,
            'alias' => $linkedAlias,
            'email' => $linkedEmail,
        ],
    ]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('SSA API link account failed: ' . $exception->getMessage());

    ssaApiJsonResponse(500, [
        'error' => 'link_account_failed',
        'message' => 'Unable to link the booking account.',
    ]);
}