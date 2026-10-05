<?php

function sendPlatformMessage(PDO $pdo, int $senderId, int $recipientId, string $subject, string $body): void
{
    $statement = $pdo->prepare(
        "INSERT INTO messages (sender_id, recipient_id, subject, body)
         VALUES (?, ?, ?, ?)"
    );
    $statement->execute([$senderId, $recipientId, $subject, $body]);
}

function messageEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
