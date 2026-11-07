<?php
declare(strict_types=1);

function create_customer_notification(PDO $pdo, int $userId, ?int $orderId, string $type, string $title, string $message): void
{
    $allowedTypes = ['order_received', 'order_update', 'payment_update'];
    if (!in_array($type, $allowedTypes, true)) {
        throw new InvalidArgumentException('Invalid customer notification type.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO customer_notifications (user_id, order_id, notification_type, title, message) VALUES (:user_id, :order_id, :type, :title, :message)'
    );
    $stmt->execute([
        'user_id' => $userId,
        'order_id' => $orderId,
        'type' => $type,
        'title' => $title,
        'message' => $message,
    ]);
}

function unread_customer_notification_count(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM customer_notifications WHERE user_id = :user_id AND read_at IS NULL');
    $stmt->execute(['user_id' => $userId]);
    return (int) $stmt->fetchColumn();
}