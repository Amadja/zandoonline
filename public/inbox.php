<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$user = require_login();
$pdo = db();

if (is_post()) {
    verify_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if ($action === 'mark_read') {
        $notificationId = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT);
        if ($notificationId) {
            $stmt = $pdo->prepare('UPDATE customer_notifications SET read_at = NOW() WHERE id = :id AND user_id = :user_id AND read_at IS NULL');
            $stmt->execute(['id' => $notificationId, 'user_id' => $user['id']]);
        }
    } elseif ($action === 'mark_all_read') {
        $stmt = $pdo->prepare('UPDATE customer_notifications SET read_at = NOW() WHERE user_id = :user_id AND read_at IS NULL');
        $stmt->execute(['user_id' => $user['id']]);
    } else {
        flash('error', 'That inbox action is not valid.');
        redirect('inbox.php');
    }
    redirect('inbox.php');
}

$stmt = $pdo->prepare('SELECT id, order_id, notification_type, title, message, read_at, created_at FROM customer_notifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 100');
$stmt->execute(['user_id' => $user['id']]);
$notifications = $stmt->fetchAll();
$unreadCount = unread_customer_notification_count($pdo, (int) $user['id']);
$pageTitle = 'Your inbox';
require ROOT_PATH . '/app/views/header.php';
?>
<section class="inbox-page">
    <div class="inbox-heading">
        <div><p class="market-kicker">ACCOUNT UPDATES</p><h1>Your inbox</h1><p>Order and delivery updates from Zandoonline.</p></div>
        <?php if ($unreadCount > 0): ?>
            <form method="post" action="<?= e($config['base_url']) ?>/inbox.php">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="mark_all_read">
                <button class="button secondary" type="submit">Mark all read</button>
            </form>
        <?php endif; ?>
    </div>
    <?php if (!$notifications): ?>
        <section class="empty-cart-state"><h2>You're all caught up</h2><p class="muted">Order confirmations and delivery updates will appear here.</p></section>
    <?php else: ?>
        <section class="notification-list" aria-label="Notifications">
            <?php foreach ($notifications as $notification): ?>
                <article class="notification-item<?= $notification['read_at'] === null ? ' unread' : '' ?>">
                    <div class="notification-copy">
                        <div class="notification-title-row"><h2><?= e($notification['title']) ?></h2><?php if ($notification['read_at'] === null): ?><span class="unread-label">New</span><?php endif; ?></div>
                        <p><?= nl2br(e($notification['message'])) ?></p>
                        <time datetime="<?= e($notification['created_at']) ?>"><?= e(date('M j, Y · H:i', strtotime($notification['created_at']))) ?></time>
                        <?php if ($notification['order_id']): ?><a class="notification-order-link" href="<?= e($config['base_url']) ?>/order_complete.php?order=<?= (int) $notification['order_id'] ?>">Order update</a><?php endif; ?>
                    </div>
                    <?php if ($notification['read_at'] === null): ?>
                        <form method="post" action="<?= e($config['base_url']) ?>/inbox.php">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="mark_read">
                            <input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>">
                            <button class="text-button" type="submit">Mark read</button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</section>
<?php require ROOT_PATH . '/app/views/footer.php'; ?>