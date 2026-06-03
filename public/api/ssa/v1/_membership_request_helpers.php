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

function ssaMembershipJson(?array $payload): ?string
{
    if ($payload === null) {
        return null;
    }

    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
    return $encoded === false ? null : $encoded;
}

function ssaMembershipPendingAdminRequest(
    PDO $pdo,
    int $uid,
    string $requestType,
    ?string $targetKey = null,
    ?int $targetId = null,
    bool $matchTarget = true
): ?array {
    $sql = 'SELECT id, uid, auth0_sub, request_type, status, target_key, target_id, payload_json, requested_at
            FROM ssa_admin_requests
            WHERE uid = :uid
              AND request_type = :requestType
              AND status = :status';
    $params = [
        'uid' => $uid,
        'requestType' => $requestType,
        'status' => 'pending',
    ];

    if ($matchTarget) {
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
    $payloadJson = ssaMembershipJson($payload);

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

function ssaMembershipUpdatePendingAdminRequest(
    PDO $pdo,
    int $requestId,
    ?string $targetKey,
    ?int $targetId,
    array $payload
): void {
    $stmt = $pdo->prepare(
        'UPDATE ssa_admin_requests
         SET target_key = :targetKey,
             target_id = :targetId,
             payload_json = :payloadJson
         WHERE id = :id AND status = :status'
    );
    $stmt->execute([
        'id' => $requestId,
        'status' => 'pending',
        'targetKey' => $targetKey,
        'targetId' => $targetId,
        'payloadJson' => ssaMembershipJson($payload),
    ]);
}

function ssaMembershipCancelPendingAdminRequest(PDO $pdo, int $requestId): void
{
    $stmt = $pdo->prepare(
        'UPDATE ssa_admin_requests
         SET status = :cancelled
         WHERE id = :id AND status = :pending'
    );
    $stmt->execute([
        'id' => $requestId,
        'cancelled' => 'cancelled',
        'pending' => 'pending',
    ]);
}

function ssaMembershipRequestForApi(?array $request, ?array $extra = null): ?array
{
    if (!$request) {
        return null;
    }

    $payload = [];
    if (isset($request['payload_json']) && $request['payload_json'] !== null && $request['payload_json'] !== '') {
        $decoded = json_decode((string)$request['payload_json'], true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    $result = [
        'id' => (int)$request['id'],
        'status' => (string)$request['status'],
        'requestedAt' => $request['requested_at'] ?? null,
    ];

    foreach ($payload as $key => $value) {
        if (!array_key_exists($key, $result)) {
            $result[$key] = $value;
        }
    }

    if ($extra) {
        foreach ($extra as $key => $value) {
            $result[$key] = $value;
        }
    }

    return $result;
}
