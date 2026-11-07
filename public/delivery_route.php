<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$deliveryUser = require_role('delivery');
$pdo = db();
$orderId = filter_var($_POST['order_id'] ?? $_GET['order'] ?? null, FILTER_VALIDATE_INT);
if (!$orderId || $orderId < 1) redirect('delivery_app.php');

if (is_post()) {
    verify_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, order_number, user_id, order_status, courier_name, courier_phone, delivery_latitude, delivery_longitude, delivery_code_hash, delivery_code_expires_at, delivery_code_requested_at, delivery_code_attempts FROM orders WHERE id = :id AND delivery_user_id = :delivery_user_id FOR UPDATE');
        $stmt->execute(['id' => $orderId, 'delivery_user_id' => $deliveryUser['id']]);
        $order = $stmt->fetch();
        $transitions = [
            'assigned' => ['accepted', 'Delivery accepted', 'A delivery person accepted order '],
            'accepted' => ['picked_up', 'Package picked up', 'The delivery person picked up order '],
            'picked_up' => ['out_for_delivery', 'Your package is arriving today', 'Your package is on the way with '],
        ];

        if (!$order) {
            throw new DomainException('This delivery is no longer assigned to your account.');
        }

        if ($action === 'request_delivery_code') {
            if ($order['order_status'] !== 'out_for_delivery' || $order['user_id'] === null) {
                throw new DomainException('A delivery code can only be sent to a registered customer after the route has started.');
            }
            $lastRequested = $order['delivery_code_requested_at'] ? strtotime($order['delivery_code_requested_at']) : false;
            if ($lastRequested && $lastRequested > time() - 60) {
                throw new DomainException('Please wait one minute before requesting another delivery code.');
            }

            $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $expiresAt = date('Y-m-d H:i:s', time() + 600);
            $update = $pdo->prepare('UPDATE orders SET delivery_code_hash = :code_hash, delivery_code_expires_at = :expires_at, delivery_code_requested_at = NOW(), delivery_code_attempts = 0 WHERE id = :id AND delivery_user_id = :delivery_user_id');
            $update->execute([
                'code_hash' => password_hash($code, PASSWORD_DEFAULT),
                'expires_at' => $expiresAt,
                'id' => $orderId,
                'delivery_user_id' => $deliveryUser['id'],
            ]);
            create_customer_notification(
                $pdo,
                (int) $order['user_id'],
                (int) $orderId,
                'order_update',
                'Delivery confirmation code',
                'Your delivery confirmation code is ' . $code . '. It expires in 10 minutes. Give it to the delivery person only after you have received your package.'
            );
            $pdo->commit();
            flash('success', 'A delivery confirmation code was sent to the customer inbox.');
            redirect('delivery_route.php?order=' . $orderId);
        }

        if ($action === 'confirm_delivery') {
            $submittedCode = is_string($_POST['delivery_code'] ?? null) ? trim($_POST['delivery_code']) : '';
            if ($order['order_status'] !== 'out_for_delivery' || !preg_match('/^[0-9]{4}$/', $submittedCode)) {
                throw new DomainException('Enter the four-digit customer delivery code.');
            }
            if (!$order['delivery_code_hash'] || !$order['delivery_code_expires_at'] || strtotime($order['delivery_code_expires_at']) <= time()) {
                throw new DomainException('The delivery code has expired or has not been requested. Request a new code.');
            }
            if ((int) $order['delivery_code_attempts'] >= 5) {
                throw new DomainException('Too many incorrect attempts. Request a new delivery code after the cooldown.');
            }

            if (!password_verify($submittedCode, $order['delivery_code_hash'])) {
                $attempts = (int) $order['delivery_code_attempts'] + 1;
                $update = $pdo->prepare('UPDATE orders SET delivery_code_attempts = :attempts, delivery_code_hash = :code_hash, delivery_code_expires_at = :expires_at WHERE id = :id');
                $update->execute([
                    'attempts' => $attempts,
                    'code_hash' => $attempts >= 5 ? null : $order['delivery_code_hash'],
                    'expires_at' => $attempts >= 5 ? null : $order['delivery_code_expires_at'],
                    'id' => $orderId,
                ]);
                $pdo->commit();
                flash('error', $attempts >= 5 ? 'Too many incorrect attempts. Request a new delivery code after the cooldown.' : 'That delivery code is incorrect.');
                redirect('delivery_route.php?order=' . $orderId);
            }

            $update = $pdo->prepare("UPDATE orders SET order_status = 'delivered', delivered_at = NOW(), delivery_code_hash = NULL, delivery_code_expires_at = NULL, delivery_code_attempts = 0 WHERE id = :id AND delivery_user_id = :delivery_user_id AND order_status = 'out_for_delivery'");
            $update->execute(['id' => $orderId, 'delivery_user_id' => $deliveryUser['id']]);
            if ($order['user_id'] !== null) {
                create_customer_notification($pdo, (int) $order['user_id'], (int) $orderId, 'order_update', 'Package delivered', 'Your delivery code was confirmed and order ' . $order['order_number'] . ' is now marked delivered.');
            }
            $pdo->commit();
            flash('success', 'Delivery confirmed. The order is now marked delivered.');
            redirect('delivery_app.php');
        }

        $nextStatus = is_string($_POST['next_status'] ?? null) ? $_POST['next_status'] : '';
        if (!isset($transitions[$order['order_status']]) || $transitions[$order['order_status']][0] !== $nextStatus) {
            throw new DomainException('This delivery step is no longer available. Refresh your assigned deliveries.');
        }
        if ($nextStatus === 'out_for_delivery' && ($order['delivery_latitude'] === null || $order['delivery_longitude'] === null)) {
            throw new DomainException('Ask the admin to add the customer map pin before starting the route.');
        }

        $update = $pdo->prepare('UPDATE orders SET order_status = :status WHERE id = :id AND delivery_user_id = :delivery_user_id');
        $update->execute(['status' => $nextStatus, 'id' => $orderId, 'delivery_user_id' => $deliveryUser['id']]);
        if ($order['user_id'] !== null) {
            if ($nextStatus === 'out_for_delivery') {
                $message = 'Your package is on the way with ' . ($order['courier_name'] ?: $deliveryUser['full_name']) . ' (' . ($order['courier_phone'] ?: $deliveryUser['phone']) . ').';
            } elseif ($nextStatus === 'delivered') {
                $message = 'Your delivery person marked order ' . $order['order_number'] . ' as delivered.';
            } else {
                $message = $transitions[$order['order_status']][2] . $order['order_number'] . '.';
            }
            create_customer_notification($pdo, (int) $order['user_id'], (int) $orderId, 'order_update', $transitions[$order['order_status']][1], $message);
        }
        $pdo->commit();
        flash('success', 'Delivery status updated.');
    } catch (DomainException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $exception->getMessage());
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log($exception->getMessage());
        flash('error', 'The delivery update could not be saved. Please try again.');
    }
    redirect('delivery_route.php?order=' . $orderId);
}

$stmt = $pdo->prepare(
    'SELECT o.*, u.full_name AS customer_name, u.phone AS account_phone FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE o.id = :id AND o.delivery_user_id = :delivery_user_id LIMIT 1'
);
$stmt->execute(['id' => $orderId, 'delivery_user_id' => $deliveryUser['id']]);
$order = $stmt->fetch();
if (!$order) {
    http_response_code(404);
    exit('This delivery is not assigned to your account.');
}

$itemStmt = $pdo->prepare('SELECT product_name, quantity FROM order_items WHERE order_id = :id ORDER BY id');
$itemStmt->execute(['id' => $orderId]);
$items = $itemStmt->fetchAll();
$nextSteps = [
    'assigned' => ['accepted', 'Accept delivery'],
    'accepted' => ['picked_up', 'Confirm package pickup'],
    'picked_up' => ['out_for_delivery', 'Start delivery route'],
];
$nextStep = $nextSteps[$order['order_status']] ?? null;
$pageTitle = 'Delivery route';
require ROOT_PATH . '/app/views/header.php';
?>
<section class="delivery-route-app" id="delivery-route-app" data-latitude="<?= e((string) ($order['delivery_latitude'] ?? '')) ?>" data-longitude="<?= e((string) ($order['delivery_longitude'] ?? '')) ?>">
    <header class="route-order-heading">
        <div class="route-top-actions">
            <a class="route-back" href="<?= e($config['base_url']) ?>/delivery_app.php">&larr; My deliveries</a>
            <form method="post" action="<?= e($config['base_url']) ?>/logout.php">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <button class="delivery-logout-button" type="submit">Log out</button>
            </form>
        </div>
        <div class="route-order-number"><?= e($order['order_number']) ?></div>
        <h1><?= e($order['customer_name']) ?></h1>
        <p><?= e($order['delivery_address']) ?>, <?= e($order['delivery_district']) ?></p>
        <a class="route-call-customer" href="tel:<?= e($order['customer_phone']) ?>">Call customer · <?= e($order['customer_phone']) ?></a>
    </header>
    <div class="route-map-layout">
        <section class="delivery-map-panel" aria-label="Delivery map">
            <div id="delivery-map" class="delivery-map"></div>
            <?php if ($order['delivery_latitude'] === null || $order['delivery_longitude'] === null): ?>
                <div class="map-missing-pin"><strong>Map pin needed</strong><span>Ask the admin to add the customer's location before starting the route.</span></div>
            <?php endif; ?>
        </section>
        <aside class="route-guidance-panel">
            <div class="route-current-status"><span>DELIVERY STATUS</span><strong><?= e(ucwords(str_replace('_', ' ', $order['order_status']))) ?></strong></div>
            <div class="route-guidance" id="route-guidance" aria-live="polite">
                <p id="route-distance">Ready when you are</p>
                <h2 id="route-instruction"><?= $order['delivery_latitude'] === null ? 'Waiting for a customer map pin' : 'Start location to get route' ?></h2>
                <p id="route-detail">The map stays inside this delivery app. Allow location access while you navigate.</p>
            </div>
            <button type="button" class="button secondary full" id="start-navigation"<?= $order['delivery_latitude'] === null ? ' disabled' : '' ?>>Use my location</button>
            <?php if ($nextStep): ?>
                <form class="delivery-step-form" method="post" action="<?= e($config['base_url']) ?>/delivery_route.php?order=<?= (int) $order['id'] ?>">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                    <input type="hidden" name="next_status" value="<?= e($nextStep[0]) ?>">
                    <button class="button primary full" type="submit"<?= $nextStep[0] === 'out_for_delivery' && $order['delivery_latitude'] === null ? ' disabled' : '' ?>><?= e($nextStep[1]) ?></button>
                </form>
            <?php elseif ($order['order_status'] === 'out_for_delivery'): ?>
                <section class="delivery-code-panel">
                    <h2>Customer confirmation</h2>
                    <?php if ($order['delivery_code_expires_at'] && strtotime($order['delivery_code_expires_at']) > time()): ?>
                        <p>A code was sent to the customer's inbox. Ask the customer to read it to you only after handing over the package.</p>
                        <p class="delivery-code-expiry">Code expires <?= e(date('H:i', strtotime($order['delivery_code_expires_at']))) ?> · <?= max(0, 5 - (int) $order['delivery_code_attempts']) ?> attempts remaining</p>
                    <?php else: ?>
                        <p>Request a one-time code after you hand the package to the customer.</p>
                    <?php endif; ?>
                    <?php if ($order['user_id'] !== null): ?>
                        <form class="request-delivery-code-form" method="post" action="<?= e($config['base_url']) ?>/delivery_route.php?order=<?= (int) $order['id'] ?>">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                            <input type="hidden" name="action" value="request_delivery_code">
                            <button class="button secondary full" type="submit">Request customer code</button>
                        </form>
                        <form class="confirm-delivery-code-form" method="post" action="<?= e($config['base_url']) ?>/delivery_route.php?order=<?= (int) $order['id'] ?>">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                            <input type="hidden" name="action" value="confirm_delivery">
                            <label>Four-digit customer code<input name="delivery_code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{4}" maxlength="4" placeholder="0000" required></label>
                            <button class="button primary full" type="submit">Confirm delivery</button>
                        </form>
                    <?php else: ?>
                        <p class="delivery-code-warning">This guest order has no customer inbox for a PIN. Ask the admin to resolve this order manually.</p>
                    <?php endif; ?>
                </section>
            <?php else: ?>
                <p class="delivery-complete-note">This delivery is complete.</p>
            <?php endif; ?>
            <div class="route-order-items"><strong>Package contents</strong><?php foreach ($items as $item): ?><span><?= (int) $item['quantity'] ?> × <?= e($item['product_name']) ?></span><?php endforeach; ?></div>
        </aside>
    </div>
</section>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="<?= e($config['base_url']) ?>/assets/js/delivery-route.js" defer></script>
<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('<?= e($config['base_url']) ?>/delivery-sw.js', { scope: '<?= e($config['base_url']) ?>/' }).catch(() => {});
}
</script>
<?php require ROOT_PATH . '/app/views/footer.php'; ?>