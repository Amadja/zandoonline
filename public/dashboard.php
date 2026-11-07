<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$user = require_login();
if ($user['role'] === 'delivery') redirect('delivery_app.php');
if (is_post()) {
    verify_csrf();
    $phone = normalize_ugandan_phone(is_string($_POST['phone'] ?? null) ? $_POST['phone'] : '');

    if ($phone === null) {
        flash('error', 'Enter a valid Ugandan phone number, such as 0700 123 456.');
    } else {
        try {
            $stmt = db()->prepare('UPDATE users SET phone = :phone WHERE id = :id');
            $stmt->execute(['phone' => $phone, 'id' => $user['id']]);
            flash('success', 'Your login phone number has been updated.');
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                flash('error', 'That phone number is already linked to another account.');
            } else {
                error_log($exception->getMessage());
                flash('error', 'We could not update your phone number. Please try again.');
            }
        }
    }

    redirect('dashboard.php');
}
$pageTitle = 'Dashboard';
require ROOT_PATH . '/app/views/header.php';
?>
<section class="dashboard-head">
    <p class="eyebrow"><?= $user['role'] === 'admin' ? 'OPERATIONS CONSOLE' : ($user['role'] === 'seller' ? 'SELLER WORKSPACE' : 'YOUR SHOPPING') ?></p>
    <h1><?= $user['role'] === 'admin' ? 'Store operations' : ($user['role'] === 'seller' ? 'Welcome, ' . e($user['full_name']) : 'Your account, ' . e($user['full_name'])) ?></h1>
    <p class="muted">You are signed in as <strong><?= e(ucfirst($user['role'])) ?></strong>.</p>
</section>
<?php if ($user['role'] === 'admin'): ?>
    <?php
    require ROOT_PATH . '/app/views/admin_nav.php';
    $pdo = db();
    $metrics = [
        'orders_today' => (int) $pdo->query('SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()')->fetchColumn(),
        'delivery_queue' => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE order_status NOT IN ('delivered','cancelled')")->fetchColumn(),
        'payment_review' => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE payment_status = 'pending'")->fetchColumn(),
        'low_stock' => (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active' AND stock_quantity <= 5")->fetchColumn(),
    ];
    $recentOrders = $pdo->query('SELECT id, order_number, customer_name, order_status, payment_status, total FROM orders ORDER BY created_at DESC LIMIT 5')->fetchAll();
    $lowStockProducts = $pdo->query("SELECT id, name, stock_quantity FROM products WHERE status = 'active' AND stock_quantity <= 5 ORDER BY stock_quantity, name LIMIT 5")->fetchAll();
    ?>
    <section class="admin-console">
        <div class="admin-page-heading">
            <div><p class="market-kicker">OPERATIONS</p><h2>Store overview</h2><p>Orders, delivery progress, and stock that needs attention.</p></div>
            <a class="button primary" href="<?= e($config['base_url']) ?>/admin_inventory.php">Add product</a>
        </div>
        <div class="admin-metrics">
            <article><span>Orders today</span><strong><?= $metrics['orders_today'] ?></strong></article>
            <article><span>In delivery queue</span><strong><?= $metrics['delivery_queue'] ?></strong></article>
            <article><span>Payments to confirm</span><strong><?= $metrics['payment_review'] ?></strong></article>
            <article class="<?= $metrics['low_stock'] > 0 ? 'metric-warning' : '' ?>"><span>Low-stock products</span><strong><?= $metrics['low_stock'] ?></strong></article>
        </div>
        <div class="admin-overview-grid">
            <section class="admin-panel">
                <div class="admin-panel-heading"><h3>Recent orders</h3><a href="<?= e($config['base_url']) ?>/admin_orders.php">View all</a></div>
                <?php if (!$recentOrders): ?>
                    <p class="muted">New orders will appear here.</p>
                <?php else: ?>
                    <div class="admin-table-wrap"><table class="admin-table">
                        <thead><tr><th>Order</th><th>Customer</th><th>Payment</th><th>Delivery</th><th>Total</th></tr></thead>
                        <tbody><?php foreach ($recentOrders as $order): ?>
                            <tr>
                                <td><a href="<?= e($config['base_url']) ?>/admin_orders.php"><?= e($order['order_number']) ?></a></td>
                                <td><?= e($order['customer_name']) ?></td>
                                <td><?= e(ucfirst($order['payment_status'])) ?></td>
                                <td><?= e(ucwords(str_replace('_', ' ', $order['order_status']))) ?></td>
                                <td>UGX <?= number_format((int) $order['total']) ?></td>
                            </tr>
                        <?php endforeach; ?></tbody>
                    </table></div>
                <?php endif; ?>
            </section>
            <section class="admin-panel">
                <div class="admin-panel-heading"><h3>Low stock</h3><a href="<?= e($config['base_url']) ?>/admin_inventory.php?stock=low">Review inventory</a></div>
                <?php if (!$lowStockProducts): ?>
                    <p class="muted">No active products are low on stock.</p>
                <?php else: ?>
                    <ul class="low-stock-list"><?php foreach ($lowStockProducts as $product): ?>
                        <li><a href="<?= e($config['base_url']) ?>/admin_inventory.php#product-<?= (int) $product['id'] ?>"><?= e($product['name']) ?></a><strong><?= (int) $product['stock_quantity'] ?> left</strong></li>
                    <?php endforeach; ?></ul>
                <?php endif; ?>
            </section>
        </div>
        <div class="admin-quick-actions"><a href="<?= e($config['base_url']) ?>/admin_orders.php">Process deliveries</a><a href="<?= e($config['base_url']) ?>/admin_inventory.php">Manage inventory</a></div>
    </section>
<?php else: ?>
    <section class="dashboard-card account-phone-card">
        <h2>Login phone number</h2>
        <p><?= $user['phone'] ? 'Current number: ' . e($user['phone']) : 'Add a phone number to use it instead of your email when logging in.' ?></p>
        <form method="post" class="account-phone-form">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <label>Ugandan phone number<input type="tel" name="phone" maxlength="30" autocomplete="tel" placeholder="0700 123 456" required value="<?= e($user['phone']) ?>"></label>
            <button class="button primary" type="submit">Save phone number</button>
        </form>
    </section>
<?php if ($user['role'] === 'seller'): ?>
    <?php
    $stmt = db()->prepare('SELECT business_name, verification_status FROM seller_profiles WHERE user_id = :id');
    $stmt->execute(['id' => $user['id']]);
    $seller = $stmt->fetch();
    $storeStmt = db()->prepare('SELECT store_name, status FROM stores WHERE seller_user_id = :id ORDER BY id DESC LIMIT 1');
    $storeStmt->execute(['id' => $user['id']]);
    $store = $storeStmt->fetch();
    ?>
    <section class="seller-workspace">
        <div class="seller-status-band"><div><p class="market-kicker">STORE REVIEW</p><h2><?= e($store['store_name'] ?? $seller['business_name'] ?? 'Your store') ?></h2><p><?= e($seller['business_name'] ?? 'Business profile not set') ?></p></div><span class="status pending"><?= e(ucfirst($seller['verification_status'] ?? 'pending')) ?></span></div>
        <div class="seller-workspace-grid">
            <article class="seller-module"><span class="seller-module-mark">01</span><h3>Store profile</h3><p>Your store is <?= e($store['status'] ?? 'pending') ?> and your seller verification is <?= e($seller['verification_status'] ?? 'pending') ?>.</p></article>
            <article class="seller-module seller-module-muted"><span class="seller-module-mark">02</span><h3>Products</h3><p>Product listing tools will appear here after the seller catalog is enabled.</p></article>
            <article class="seller-module seller-module-muted"><span class="seller-module-mark">03</span><h3>Orders</h3><p>Store orders and fulfillment updates will appear here when the seller module is enabled.</p></article>
        </div>
    </section>
<?php else: ?>
    <?php
    $orderStmt = db()->prepare('SELECT id, order_number, order_status, payment_status, total, created_at FROM orders WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 5');
    $orderStmt->execute(['user_id' => $user['id']]);
    $recentCustomerOrders = $orderStmt->fetchAll();
    $activeOrderCountStmt = db()->prepare("SELECT COUNT(*) FROM orders WHERE user_id = :user_id AND order_status NOT IN ('delivered','cancelled')");
    $activeOrderCountStmt->execute(['user_id' => $user['id']]);
    $activeOrderCount = (int) $activeOrderCountStmt->fetchColumn();
    $unreadCount = unread_customer_notification_count(db(), (int) $user['id']);
    ?>
    <section class="customer-hub">
        <div class="customer-action-grid">
            <a class="customer-action primary-action" href="<?= e($config['base_url']) ?>/index.php#products"><span>01</span><strong>Continue shopping</strong><small>Explore products from the marketplace</small></a>
            <a class="customer-action" href="<?= e($config['base_url']) ?>/inbox.php"><span>02</span><strong>Order inbox<?php if ($unreadCount > 0): ?> <b><?= $unreadCount ?> new</b><?php endif; ?></strong><small>Delivery and payment updates</small></a>
            <a class="customer-action" href="<?= e($config['base_url']) ?>/cart.php"><span>03</span><strong>Your cart</strong><small><?= get_cart_count() ?> items saved for checkout</small></a>
        </div>
        <section class="customer-orders-panel">
            <div class="admin-panel-heading"><h2>Recent orders</h2><span><?= $activeOrderCount ?> in progress</span></div>
            <?php if (!$recentCustomerOrders): ?>
                <div class="customer-first-order"><p>Your orders and delivery progress will appear here.</p><a class="button primary" href="<?= e($config['base_url']) ?>/index.php#products">Browse products</a></div>
            <?php else: ?>
                <div class="customer-order-list">
                    <?php foreach ($recentCustomerOrders as $customerOrder): ?>
                        <a class="customer-order-row" href="<?= e($config['base_url']) ?>/order_complete.php?order=<?= (int) $customerOrder['id'] ?>">
                            <span><strong><?= e($customerOrder['order_number']) ?></strong><small><?= e(date('M j, Y', strtotime($customerOrder['created_at']))) ?></small></span>
                            <span class="customer-order-status"><?= e(ucwords(str_replace('_', ' ', $customerOrder['order_status']))) ?></span>
                            <strong>UGX <?= number_format((int) $customerOrder['total']) ?></strong>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </section>
<?php endif; ?>
<?php endif; ?>
<?php require ROOT_PATH . '/app/views/footer.php'; ?>
