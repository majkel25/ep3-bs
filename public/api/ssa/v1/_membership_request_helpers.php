<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth0.php';
require_once __DIR__ . '/_db.php';

function ssaMembershipRequireLinkedUid(PDO $pdo, array $claims): array
{
    $auth0Sub = isset($claims['sub']) ? trim((string)$claims['sub']) : '';

    if ($auth0Sub === '') {
        ssaApiJsonResponse(401, [
            'error' => 'missing_auth0_subject',
            'message' => 'Auth0 token does not contain a subject.',
        ]);
    }

    $linkStmt = $pdo->prepare(
        'SELECT uid FROM ssa_auth0_user_links
         WHERE auth0_sub = :auth0Sub AND revoked_at IS NULL
         LIMIT 1'
    );
    $linkStmt->execute(['auth0Sub' => $auth0Sub]);
    $link = $linkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$link || (int)($link['uid'] ?? 0) <= 0) {
        ssaApiJsonResponse(403, [
            'error' => 'booking_account_not_linked',
            'message' => 'No linked booking account was found for this app login.',
        ]);
    }

    return [
        'uid' => (int)$link['uid'],
        'auth0Sub' => $auth0Sub,
    ];
}

function ssaMembershipReadJsonBody(): array
{
    $rawBody = (string)file_get_contents('php://input');
    if (trim($rawBody) === '') {
        ssaApiJsonResponse(400, [
            'error' => 'empty_body',
            'message' => 'Request body must contain JSON.',
        ]);
    }

    $body = json_decode($rawBody, true);
    if (!is_array($body)) {
        ssaApiJsonResponse(400, [
            'error' => 'invalid_json',
            'message' => 'Request body must be valid JSON.',
        ]);
    }

    return $body;
}

function ssaMembershipPendingAdminRequest(
    PDO $pdo,
    int $uid,
    string $requestType,
    ?string $targetKey,
    ?int $targetId
): ?array {
    $sql = 'SELECT id, status, requested_at
            FROM ssa_admin_requests
            WHERE uid = :uid
              AND request_type = :requestType
              AND status = :status';
    $params = [
        'uid' => $uid,
        'requestType' => $requestType,
        'status' => 'pending',
    ];

    if ($targetKey === null) {
        $sql .= ' AND target_key IS NULL';
    } else {
        $sql .= ' AND target_key = :targetKey';
        $params['targetKey'] = $targetKey;
    }

    if ($targetId === null) {
        $sql .= ' AND target_id IS NULL';
    } else {
        $sql .= ' AND target_id = :targetId';
        $params['targetId'] = $targetId;
    }

    $sql .= ' ORDER BY requested_at DESC, id DESC LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function ssaMembershipCreateAdminRequest(
    PDO $pdo,
    int $uid,
    string $auth0Sub,
    string $requestType,
    ?string $targetKey,
    ?int $targetId,
    array $payload
): int {
    $existing = ssaMembershipPendingAdminRequest($pdo, $uid, $requestType, $targetKey, $targetId);
    if ($existing) {
        return (int)$existing['id'];
    }

    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($payloadJson === false) {
        $payloadJson = null;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO ssa_admin_requests
            (uid, auth0_sub, request_type, status, target_key, target_id, payload_json, requested_at)
         VALUES
            (:uid, :auth0Sub, :requestType, :status, :targetKey, :targetId, :payloadJson, UTC_TIMESTAMP())'
    );
    $stmt->execute([
        'uid' => $uid,
        'auth0Sub' => $auth0Sub,
        'requestType' => $requestType,
        'status' => 'pending',
        'targetKey' => $targetKey,
        'targetId' => $targetId,
        'payloadJson' => $payloadJson,
    ]);

    return (int)$pdo->lastInsertId();
}
