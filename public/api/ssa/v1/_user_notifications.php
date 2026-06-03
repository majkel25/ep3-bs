<?php

declare(strict_types=1);

require_once __DIR__ . '/_db.php';

function ssaUserNotificationsEnsureTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ssa_user_notifications (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          uid INT UNSIGNED NOT NULL,
          type VARCHAR(64) NOT NULL,
          title VARCHAR(255) NOT NULL,
          message TEXT NOT NULL,
          screen VARCHAR(64) NULL,
          entity_id VARCHAR(64) NULL,
          payload_json JSON NULL,
          read_at DATETIME NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

          PRIMARY KEY (id),
          KEY idx_ssa_user_notifications_uid_created (uid, created_at),
          KEY idx_ssa_user_notifications_uid_read (uid, read_at),
          KEY idx_ssa_user_notifications_type (type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ssaUserNotificationsCreate(
    PDO $pdo,
    int $uid,
    string $type,
    string $title,
    string $message,
    ?string $screen = null,
    ?string $entityId = null,
    ?array $payload = null
): int {
    if (!$pdo->inTransaction()) {
        ssaUserNotificationsEnsureTable($pdo);
    }

    $payloadJson = null;
    if ($payload !== null) {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $payloadJson = $encoded === false ? null : $encoded;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO ssa_user_notifications
            (uid, type, title, message, screen, entity_id, payload_json, created_at)
         VALUES
            (:uid, :type, :title, :message, :screen, :entityId, :payloadJson, UTC_TIMESTAMP())'
    );
    $stmt->execute([
        'uid' => $uid,
        'type' => $type,
        'title' => $title,
        'message' => $message,
        'screen' => $screen,
        'entityId' => $entityId,
        'payloadJson' => $payloadJson,
    ]);

    return (int)$pdo->lastInsertId();
}

function ssaUserNotificationsRowForApi(array $row): array
{
    $createdAt = isset($row['created_at_iso']) && $row['created_at_iso'] !== null
        ? (string)$row['created_at_iso']
        : (string)($row['created_at'] ?? '');
    $readAt = isset($row['read_at_iso']) && $row['read_at_iso'] !== null
        ? (string)$row['read_at_iso']
        : null;

    return [
        'id' => (string)$row['id'],
        'type' => (string)$row['type'],
        'title' => (string)$row['title'],
        'message' => (string)$row['message'],
        'created_at' => $createdAt,
        'read_at' => $readAt,
        'is_read' => $readAt !== null,
        'screen' => $row['screen'] !== null ? (string)$row['screen'] : null,
        'entity_id' => $row['entity_id'] !== null ? (string)$row['entity_id'] : null,
        'action_type' => $row['screen'] !== null ? (string)$row['screen'] : null,
        'action_url' => $row['screen'] !== null ? (string)$row['screen'] : null,
    ];
}
