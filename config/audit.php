<?php
function auditLog(PDO $pdo, ?int $userId, string $action, ?string $entityType = null, ?int $entityId = null, ?array $oldValues = null, ?array $newValues = null): void
{
    $statement = $pdo->prepare(
        "INSERT INTO audit_logs
            (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $statement->execute([
        $userId,
        $action,
        $entityType,
        $entityId,
        $oldValues === null ? null : json_encode($oldValues, JSON_UNESCAPED_UNICODE),
        $newValues === null ? null : json_encode($newValues, JSON_UNESCAPED_UNICODE),
        $_SERVER["REMOTE_ADDR"] ?? null,
        $_SERVER["HTTP_USER_AGENT"] ?? null,
    ]);
}
