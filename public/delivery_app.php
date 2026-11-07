<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$deliveryUser = require_role('delivery');
$stmt = db()->prepare(
    "SELECT o.id, o.order_number, o.customer_name, o.customer_phone, o.delivery_address, o.delivery_district, o.delivery_latitude, o.delivery_longitude, o.order_status, o.created_at, COUNT(oi.id) AS line_count, COALESCE(SUM(oi.quantity), 0) AS item_count
     FROM orders o
     LEFT JOIN order_items oi ON oi.order_id = o.id
     WHERE o.delivery_user_id = :delivery_user_id AND o.order_status NOT IN ('delivered','cancelled')
     GROUP BY o.id
     ORDER BY CASE o.order_status WHEN 'out_for_delivery' THEN 0 WHEN 'picked_up' THEN 1 WHEN 'accepted' THEN 2 ELSE 3 END, o.delivery_assigned_at, o.created_at"
);
$stmt->execute(['delivery_user_id' => $deliveryUser['id']]);
$deliveries = $stmt->fetchAll();
$pageTitle = 'My deliveries';
require ROOT_PATH . '/app/views/header.php';
?>
<section class="delivery-app-shell">
    <header class="delivery-app-heading">
        <div><p class="market-kicker">PRIVATE DELIVERY APP</p><h1>My deliveries</h1><p>Hi <?= e($deliveryUser['full_name']) ?>. Only orders assigned to you appear here.</p></div>
        <div class="delivery-app-actions">
            <div class="delivery-count"><strong><?= count($deliveries) ?></strong><span>active</span></div>
            <form method="post" action="<?= e($config['base_url']) ?>/logout.php">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <button class="delivery-logout-button" type="submit">Log out</button>
            </form>
        </div>
    </header>
    <?php if (!$deliveries): ?>
        <section class="delivery-empty"><h2>No assigned deliveries</h2><p>Your next delivery will appear here after the admin assigns it to you.</p></section>
    <?php else: ?>
        <section class="delivery-queue" aria-label="Assigned deliveries">
            <?php foreach ($deliveries as $delivery): ?>
                <article class="delivery-job-card">
                    <div class="delivery-job-topline"><strong><?= e($delivery['order_number']) ?></strong><span class="delivery-status"><?= e(ucwords(str_replace('_', ' ', $delivery['order_status']))) ?></span></div>
                    <h2><?= e($delivery['customer_name']) ?></h2>
                    <p class="delivery-job-address"><?= e($delivery['delivery_address']) ?>, <?= e($delivery['delivery_district']) ?></p>
                    <div class="delivery-job-meta"><a href="tel:<?= e($delivery['customer_phone']) ?>">Call customer · <?= e($delivery['customer_phone']) ?></a><span><?= (int) $delivery['item_count'] ?> items</span></div>
                    <?php if ($delivery['delivery_latitude'] === null || $delivery['delivery_longitude'] === null): ?>
                        <p class="delivery-pin-warning">This order has no map pin yet. Ask the admin to add the customer's location before starting the route.</p>
                    <?php endif; ?>
                    <a class="button primary delivery-job-action" href="<?= e($config['base_url']) ?>/delivery_route.php?order=<?= (int) $delivery['id'] ?>">Open delivery</a>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</section>
<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('<?= e($config['base_url']) ?>/delivery-sw.js', { scope: '<?= e($config['base_url']) ?>/' }).catch(() => {});
}
</script>
<?php require ROOT_PATH . '/app/views/footer.php'; ?>