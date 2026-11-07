<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_role('admin');

$pdo = db();
if (is_post()) {
    verify_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : 'update_order';
    if ($action === 'mark_payment_prompt_sent') {
        $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id, order_number, user_id, payment_method, payment_status, payment_prompt_status, payment_prompt_expires_at FROM orders WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $orderId]);
            $paymentOrder = $orderId ? $stmt->fetch() : false;
            if (!$paymentOrder || $paymentOrder['payment_method'] !== 'mobile_money' || $paymentOrder['payment_status'] !== 'pending' || $paymentOrder['payment_prompt_status'] !== 'requested') {
                throw new DomainException('This payment request is not waiting for a prompt.');
            }
            if (!$paymentOrder['payment_prompt_expires_at'] || strtotime($paymentOrder['payment_prompt_expires_at']) <= time()) {
                $expire = $pdo->prepare("UPDATE orders SET payment_prompt_status = 'expired' WHERE id = :id");
                $expire->execute(['id' => $orderId]);
                $pdo->commit();
                flash('error', 'The four-minute approval window expired. Start a new window before sending another prompt.');
                redirect('admin_orders.php?view=payments');
            }

            $sent = $pdo->prepare("UPDATE orders SET payment_prompt_status = 'sent', payment_prompt_sent_at = NOW() WHERE id = :id AND payment_prompt_status = 'requested'");
            $sent->execute(['id' => $orderId]);
            if ($paymentOrder['user_id'] !== null) {
                create_customer_notification($pdo, (int) $paymentOrder['user_id'], (int) $orderId, 'payment_update', 'Mobile Money prompt sent', 'The admin sent a Mobile Money prompt for order ' . $paymentOrder['order_number'] . '. Approve it on your phone before the timer expires. Never share your PIN.');
            }
            $pdo->commit();
        } catch (DomainException $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', $exception->getMessage());
            redirect('admin_orders.php?view=payments');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log($exception->getMessage());
            flash('error', 'The prompt status could not be updated. Please try again.');
            redirect('admin_orders.php?view=payments');
        }
        flash('success', 'Prompt marked as sent. Confirm payment after checking the Mobile Money account.');
        redirect('admin_orders.php?view=payments');
    }

    if ($action === 'reissue_payment_prompt') {
        $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id, order_number, user_id, payment_method, payment_status, payment_prompt_status, payment_prompt_expires_at FROM orders WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $orderId]);
            $paymentOrder = $orderId ? $stmt->fetch() : false;
            $windowExpired = $paymentOrder && (!$paymentOrder['payment_prompt_expires_at'] || strtotime($paymentOrder['payment_prompt_expires_at']) <= time());
            if (!$paymentOrder || $paymentOrder['payment_method'] !== 'mobile_money' || $paymentOrder['payment_status'] !== 'pending' || !$windowExpired || !in_array($paymentOrder['payment_prompt_status'], ['requested', 'sent', 'expired'], true)) {
                throw new DomainException('This payment request is not eligible for a new approval window.');
            }

            $expiresAt = date('Y-m-d H:i:s', time() + 240);
            $update = $pdo->prepare("UPDATE orders SET payment_prompt_status = 'requested', payment_prompt_requested_at = NOW(), payment_prompt_sent_at = NULL, payment_prompt_expires_at = :expires_at WHERE id = :id");
            $update->execute(['expires_at' => $expiresAt, 'id' => $orderId]);
            if ($paymentOrder['user_id'] !== null) {
                create_customer_notification($pdo, (int) $paymentOrder['user_id'], (int) $orderId, 'payment_update', 'New payment approval window', 'The admin reopened a four-minute Mobile Money approval window for order ' . $paymentOrder['order_number'] . '. The admin will send a new prompt to your selected number. Never share your PIN.');
            }
            $pdo->commit();
        } catch (DomainException $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', $exception->getMessage());
            redirect('admin_orders.php?view=payments');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log($exception->getMessage());
            flash('error', 'A new payment window could not be opened.');
            redirect('admin_orders.php?view=payments');
        }
        flash('success', 'A new four-minute payment window is open. Send the prompt through the operator now.');
        redirect('admin_orders.php?view=payments');
    }

    if ($action === 'save_delivery_pin') {
        $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
        $latitudeInput = trim(is_string($_POST['delivery_latitude'] ?? null) ? $_POST['delivery_latitude'] : '');
        $longitudeInput = trim(is_string($_POST['delivery_longitude'] ?? null) ? $_POST['delivery_longitude'] : '');
        $googleMapsUrl = trim(is_string($_POST['google_maps_url'] ?? null) ? $_POST['google_maps_url'] : '');

        if (!$orderId || $latitudeInput === '' || $longitudeInput === '') {
            flash('error', 'Enter both map coordinates to save the delivery destination.');
            redirect('admin_orders.php#order-' . (int) $orderId);
        }
        $latitude = filter_var($latitudeInput, FILTER_VALIDATE_FLOAT);
        $longitude = filter_var($longitudeInput, FILTER_VALIDATE_FLOAT);
        if ($latitude === false || $longitude === false || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            flash('error', 'Destination coordinates are not valid.');
            redirect('admin_orders.php#order-' . $orderId);
        }

        if ($googleMapsUrl !== '') {
            $mapUrlParts = parse_url($googleMapsUrl);
            $mapHost = strtolower(is_array($mapUrlParts) ? ($mapUrlParts['host'] ?? '') : '');
            $isGoogleMapsHost = $mapHost === 'google.com'
                || str_ends_with($mapHost, '.google.com')
                || in_array($mapHost, ['goo.gl', 'maps.app.goo.gl'], true);
            if (strlen($googleMapsUrl) > 1000 || !filter_var($googleMapsUrl, FILTER_VALIDATE_URL) || ($mapUrlParts['scheme'] ?? '') !== 'https' || !$isGoogleMapsHost) {
                flash('error', 'Enter a valid secure Google Maps link.');
                redirect('admin_orders.php#order-' . $orderId);
            }
        } else {
            $googleMapsUrl = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($latitude . ',' . $longitude);
        }

        $pinUpdate = $pdo->prepare('UPDATE orders SET delivery_latitude = :latitude, delivery_longitude = :longitude, google_maps_url = :maps_url WHERE id = :id');
        $pinUpdate->execute(['latitude' => $latitude, 'longitude' => $longitude, 'maps_url' => $googleMapsUrl, 'id' => $orderId]);
        flash('success', 'Delivery destination saved. The assigned delivery person can now open the route.');
        redirect('admin_orders.php#order-' . $orderId);
    }

    if ($action === 'assign_delivery') {
        $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
        $deliveryUserId = ($_POST['delivery_user_id'] ?? '') === '' ? null : filter_var($_POST['delivery_user_id'], FILTER_VALIDATE_INT);
        $orderStmt = $pdo->prepare('SELECT id, order_number, user_id, order_status, delivery_user_id, delivery_latitude, delivery_longitude FROM orders WHERE id = :id LIMIT 1');
        $orderStmt->execute(['id' => $orderId]);
        $order = $orderId ? $orderStmt->fetch() : false;
        $deliveryUser = false;
        if ($deliveryUserId) {
            $staffStmt = $pdo->prepare("SELECT id, full_name, phone FROM users WHERE id = :id AND role = 'delivery' AND status = 'active' LIMIT 1");
            $staffStmt->execute(['id' => $deliveryUserId]);
            $deliveryUser = $staffStmt->fetch();
        }

        $sameAssignedDriver = $order && $deliveryUserId && (int) $deliveryUserId === (int) $order['delivery_user_id'];
        $assignmentCanBeEdited = $order && in_array($order['order_status'], ['ready_for_pickup', 'assigned'], true);
        $pinCanBeUpdatedForCurrentDriver = $sameAssignedDriver && in_array($order['order_status'], ['accepted', 'picked_up'], true);
        $legacyUnassignedDelivery = $order && $order['order_status'] === 'out_for_delivery' && empty($order['delivery_user_id']) && $deliveryUser;
        if (!$order || ($deliveryUserId && !$deliveryUser) || (!$assignmentCanBeEdited && !$pinCanBeUpdatedForCurrentDriver && !$legacyUnassignedDelivery)) {
            flash('error', 'Assign an active delivery person when an order is ready for pickup. Started deliveries cannot be reassigned.');
            redirect('admin_orders.php');
        }

        $latitudeInput = trim(is_string($_POST['delivery_latitude'] ?? null) ? $_POST['delivery_latitude'] : '');
        $longitudeInput = trim(is_string($_POST['delivery_longitude'] ?? null) ? $_POST['delivery_longitude'] : '');
        $latitude = $order['delivery_latitude'];
        $longitude = $order['delivery_longitude'];
        if (($latitudeInput === '') !== ($longitudeInput === '')) {
            flash('error', 'Provide both destination coordinates or leave both blank.');
            redirect('admin_orders.php');
        }
        if ($latitudeInput !== '') {
            $latitude = filter_var($latitudeInput, FILTER_VALIDATE_FLOAT);
            $longitude = filter_var($longitudeInput, FILTER_VALIDATE_FLOAT);
            if ($latitude === false || $longitude === false || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
                flash('error', 'Destination coordinates are not valid.');
                redirect('admin_orders.php');
            }
        }

        $nextStatus = $order['order_status'];
        if (in_array($order['order_status'], ['ready_for_pickup', 'assigned'], true)) {
            $nextStatus = $deliveryUser ? 'assigned' : 'ready_for_pickup';
        }
        $pdo->beginTransaction();
        try {
            $assign = $pdo->prepare('UPDATE orders SET delivery_user_id = :delivery_user_id, delivery_assigned_at = :assigned_at, courier_name = :courier_name, courier_phone = :courier_phone, delivery_latitude = :latitude, delivery_longitude = :longitude, order_status = :order_status WHERE id = :id');
            $assign->execute([
                'delivery_user_id' => $deliveryUser['id'] ?? null,
                'assigned_at' => $deliveryUser ? date('Y-m-d H:i:s') : null,
                'courier_name' => $deliveryUser['full_name'] ?? null,
                'courier_phone' => $deliveryUser['phone'] ?? null,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'order_status' => $nextStatus,
                'id' => $orderId,
            ]);
            if ($order['user_id'] !== null && $deliveryUser && (int) $order['delivery_user_id'] !== (int) $deliveryUser['id']) {
                create_customer_notification($pdo, (int) $order['user_id'], (int) $orderId, 'order_update', 'Delivery person assigned', 'A delivery person has been assigned to order ' . $order['order_number'] . '.');
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log($exception->getMessage());
            flash('error', 'The delivery assignment could not be saved.');
            redirect('admin_orders.php');
        }
        flash('success', $deliveryUser ? 'Delivery assigned to ' . $deliveryUser['full_name'] . '.' : 'Delivery assignment removed.');
        redirect('admin_orders.php');
    }

    $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
    $paymentStatus = is_string($_POST['payment_status'] ?? null) ? $_POST['payment_status'] : '';
    $orderStatus = is_string($_POST['order_status'] ?? null) ? $_POST['order_status'] : '';
    $orderStmt = $pdo->prepare('SELECT id, order_number, user_id, payment_method, payment_status, order_status, delivery_user_id FROM orders WHERE id = :id LIMIT 1');
    $orderStmt->execute(['id' => $orderId]);
    $order = $orderId ? $orderStmt->fetch() : false;

    $allowedPaymentStatuses = $order && $order['payment_method'] === 'mobile_money'
        ? ['pending', 'paid', 'failed']
        : ['unpaid', 'paid'];
    $adminTransitions = [
        'pending' => ['processing', 'cancelled'],
        'processing' => ['ready_for_pickup', 'cancelled'],
        'ready_for_pickup' => ['cancelled'],
        'assigned' => ['cancelled'],
        'accepted' => [],
        'picked_up' => [],
        'out_for_delivery' => [],
        'delivered' => [],
        'cancelled' => [],
    ];
    $allowedOrderStatuses = $order ? array_merge([$order['order_status']], $adminTransitions[$order['order_status']] ?? []) : [];

    if (!$order || !in_array($paymentStatus, $allowedPaymentStatuses, true) || !in_array($orderStatus, $allowedOrderStatuses, true)) {
        flash('error', 'Those order updates are not valid.');
        redirect('admin_orders.php');
    }
    if ($order['order_status'] !== $orderStatus && !in_array($orderStatus, $adminTransitions[$order['order_status']] ?? [], true)) {
        flash('error', 'Orders must follow the delivery workflow. Assign a delivery person at Ready for pickup.');
        redirect('admin_orders.php');
    }

    $pdo->beginTransaction();
    try {
        $paymentPromptStatus = $paymentStatus === 'paid'
            ? 'confirmed'
            : ($paymentStatus === 'failed' ? 'expired' : $order['payment_prompt_status']);
        $update = $pdo->prepare('UPDATE orders SET payment_status = :payment_status, payment_prompt_status = :prompt_status, order_status = :order_status WHERE id = :id');
        $update->execute([
            'payment_status' => $paymentStatus,
            'prompt_status' => $paymentPromptStatus,
            'order_status' => $orderStatus,
            'id' => $orderId,
        ]);

        if ($order['user_id'] !== null && $order['order_status'] !== $orderStatus) {
            $updates = [
                'processing' => ['Order being prepared', 'We are preparing order ' . $order['order_number'] . '.'],
                'ready_for_pickup' => ['Order being assigned to delivery guy', 'Order ' . $order['order_number'] . ' is being assigned to a delivery person.'],
                'cancelled' => ['Order cancelled', 'Order ' . $order['order_number'] . ' was cancelled by the store.'],
            ];
            if (isset($updates[$orderStatus])) {
                create_customer_notification($pdo, (int) $order['user_id'], (int) $orderId, 'order_update', $updates[$orderStatus][0], $updates[$orderStatus][1]);
            }
        }

        if ($orderStatus === 'cancelled' && $order['order_status'] !== 'cancelled') {
            $itemStmt = $pdo->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = :order_id AND product_id IS NOT NULL FOR UPDATE');
            $itemStmt->execute(['order_id' => $orderId]);
            $restoreStock = $pdo->prepare('UPDATE products SET stock_quantity = stock_quantity + :quantity WHERE id = :id');
            foreach ($itemStmt->fetchAll() as $item) {
                $restoreStock->execute(['quantity' => $item['quantity'], 'id' => $item['product_id']]);
            }
            $unassign = $pdo->prepare('UPDATE orders SET delivery_user_id = NULL, delivery_assigned_at = NULL, courier_name = NULL, courier_phone = NULL WHERE id = :id');
            $unassign->execute(['id' => $orderId]);
        }

        if ($order['user_id'] !== null && $order['payment_status'] !== $paymentStatus && in_array($paymentStatus, ['paid', 'failed'], true)) {
            $title = $paymentStatus === 'paid' ? 'Payment confirmed' : 'Payment needs attention';
            $message = $paymentStatus === 'paid'
                ? 'Manual payment for order ' . $order['order_number'] . ' has been confirmed.'
                : 'We could not confirm payment for order ' . $order['order_number'] . '. Please contact the store.';
            create_customer_notification($pdo, (int) $order['user_id'], (int) $orderId, 'payment_update', $title, $message);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log($exception->getMessage());
        flash('error', 'The order update could not be saved. Please try again.');
        redirect('admin_orders.php');
    }
    flash('success', 'Order ' . $orderId . ' was updated.');
    redirect('admin_orders.php');
}

$deliveryStaff = $pdo->query("SELECT id, full_name FROM users WHERE role = 'delivery' AND status = 'active' ORDER BY full_name")->fetchAll();
$orderViews = [
    'all' => 'All orders',
    'processing' => 'Needs processing',
    'ready' => 'Order being assigned to delivery guy',
    'delivery' => 'In delivery',
    'payments' => 'Payment requests',
    'complete' => 'Completed',
];
$activeView = is_string($_GET['view'] ?? null) && isset($orderViews[$_GET['view']]) ? $_GET['view'] : 'all';
$orderSearch = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$orderSearch = mb_substr($orderSearch, 0, 100);
$viewCounts = [
    'all' => (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
    'processing' => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE order_status IN ('pending','processing')")->fetchColumn(),
    'ready' => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE order_status = 'ready_for_pickup'")->fetchColumn(),
    'delivery' => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE order_status IN ('assigned','accepted','picked_up','out_for_delivery')")->fetchColumn(),
    'payments' => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE payment_method = 'mobile_money' AND payment_status = 'pending' AND payment_prompt_status IN ('requested','sent','expired')")->fetchColumn(),
    'complete' => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE order_status IN ('delivered','cancelled')")->fetchColumn(),
];
$where = [];
$params = [];
if ($orderSearch !== '') {
    $where[] = '(order_number LIKE :number_term OR customer_name LIKE :name_term OR customer_email LIKE :email_term OR customer_phone LIKE :phone_term)';
    $searchPattern = '%' . $orderSearch . '%';
    $params = ['number_term' => $searchPattern, 'name_term' => $searchPattern, 'email_term' => $searchPattern, 'phone_term' => $searchPattern];
}
$viewConditions = [
    'processing' => "order_status IN ('pending','processing')",
    'ready' => "order_status = 'ready_for_pickup'",
    'delivery' => "order_status IN ('assigned','accepted','picked_up','out_for_delivery')",
    'payments' => "payment_method = 'mobile_money' AND payment_status = 'pending' AND payment_prompt_status IN ('requested','sent','expired')",
    'complete' => "order_status IN ('delivered','cancelled')",
];
if (isset($viewConditions[$activeView])) $where[] = $viewConditions[$activeView];
$ordersSql = 'SELECT * FROM orders';
if ($where) $ordersSql .= ' WHERE ' . implode(' AND ', $where);
$ordersSql .= ' ORDER BY CASE order_status WHEN \'pending\' THEN 0 WHEN \'processing\' THEN 1 WHEN \'ready_for_pickup\' THEN 2 WHEN \'assigned\' THEN 3 WHEN \'accepted\' THEN 4 WHEN \'picked_up\' THEN 5 WHEN \'out_for_delivery\' THEN 6 ELSE 7 END, created_at DESC LIMIT 200';
$ordersStmt = $pdo->prepare($ordersSql);
$ordersStmt->execute($params);
$orders = $ordersStmt->fetchAll();
$itemsByOrder = [];
if ($orders) {
    $orderIds = array_column($orders, 'id');
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $itemStmt = $pdo->prepare("SELECT order_id, product_name, quantity FROM order_items WHERE order_id IN ($placeholders) ORDER BY id");
    $itemStmt->execute($orderIds);
    foreach ($itemStmt->fetchAll() as $item) {
        $itemsByOrder[(int) $item['order_id']][] = $item;
    }
}

$pageTitle = 'Manage orders';
require ROOT_PATH . '/app/views/header.php';
require ROOT_PATH . '/app/views/admin_nav.php';
?>
<section class="checkout-head">
    <p class="market-kicker">ZANDOONLINE ADMIN</p>
    <h1>Manage orders</h1>
    <p class="muted">Process each order in sequence, then assign a registered delivery person when it is ready.</p>
</section>
<section class="orders-toolbar">
    <form class="orders-search" method="get" action="<?= e($config['base_url']) ?>/admin_orders.php">
        <label>Find an order<input type="search" name="q" placeholder="Order number, customer, email, phone" value="<?= e($orderSearch) ?>"></label>
        <input type="hidden" name="view" value="<?= e($activeView) ?>">
        <button class="button secondary" type="submit">Search</button>
    </form>
    <nav class="orders-view-tabs" aria-label="Order queues">
        <?php foreach ($orderViews as $viewKey => $viewLabel): ?>
            <a class="<?= $activeView === $viewKey ? 'active-view' : '' ?>" href="<?= e($config['base_url']) ?>/admin_orders.php?view=<?= e($viewKey) ?><?= $orderSearch !== '' ? '&q=' . rawurlencode($orderSearch) : '' ?>"><?= e($viewLabel) ?><span><?= $viewCounts[$viewKey] ?></span></a>
        <?php endforeach; ?>
    </nav>
    <p class="orders-result-count"><?= count($orders) ?> orders shown<?= count($orders) === 200 ? ' · refine your search for more' : '' ?></p>
</section>
<?php if (!$orders): ?>
    <section class="empty-cart-state"><h2><?= $orderSearch !== '' ? 'No matching orders' : 'No orders in this queue' ?></h2><p class="muted">Choose another queue or adjust your search.</p></section>
<?php else: ?>
    <section class="admin-orders">
        <?php foreach ($orders as $order): ?>
            <?php
            $promptExpired = $order['payment_method'] === 'mobile_money'
                && $order['payment_status'] === 'pending'
                && ($order['payment_prompt_status'] === 'expired' || (in_array($order['payment_prompt_status'], ['requested', 'sent'], true) && (!$order['payment_prompt_expires_at'] || strtotime($order['payment_prompt_expires_at']) <= time())));
            $promptStateLabel = $promptExpired ? 'Window expired' : ucwords(str_replace('_', ' ', $order['payment_prompt_status'] ?? ''));
            ?>
            <article class="admin-order-card" id="order-<?= (int) $order['id'] ?>">
                <header class="admin-order-heading admin-order-summary">
                    <div class="order-primary"><p class="market-kicker"><?= e($order['order_number']) ?></p><h2><?= e($order['customer_name']) ?></h2><time datetime="<?= e($order['created_at']) ?>"><?= e(date('M j, Y · H:i', strtotime($order['created_at']))) ?></time></div>
                    <div class="order-summary-state"><span class="order-status-chip status-<?= e($order['order_status']) ?>"><?= e(ucwords(str_replace('_', ' ', $order['order_status']))) ?></span><span class="order-payment-label"><?= e(ucfirst($order['payment_status'])) ?></span><?php if ($order['payment_method'] === 'mobile_money' && $order['payment_prompt_status']): ?><span class="prompt-state-chip<?= $promptExpired ? ' prompt-expired' : '' ?>"><?= e(strtoupper((string) $order['payment_provider'])) ?> · <?= e($promptStateLabel) ?></span><?php endif; ?></div>
                    <strong class="order-summary-total">UGX <?= number_format((int) $order['total']) ?></strong>
                </header>
                <?php if ($activeView === 'payments' && $order['payment_status'] === 'pending'): ?>
                    <?php if ($promptExpired): ?>
                        <form class="payment-prompt-quick-action" method="post" action="<?= e($config['base_url']) ?>/admin_orders.php?view=payments">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="reissue_payment_prompt">
                            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                            <span><strong><?= e(strtoupper((string) $order['payment_provider'])) ?> · <?= e($order['payment_phone'] ?? 'No number') ?></strong><small>Approval window expired. Contact the customer, then open a fresh four-minute window.</small></span>
                            <button class="button secondary" type="submit">Start new 4-minute window</button>
                        </form>
                    <?php elseif ($order['payment_prompt_status'] === 'requested'): ?>
                        <form class="payment-prompt-quick-action" method="post" action="<?= e($config['base_url']) ?>/admin_orders.php?view=payments">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="mark_payment_prompt_sent">
                            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                            <span><strong><?= e(strtoupper((string) $order['payment_provider'])) ?> prompt to <?= e($order['payment_phone'] ?? 'No number') ?></strong><small>Send it through your operator, then record it here. Expires <?= e($order['payment_prompt_expires_at'] ? date('H:i', strtotime($order['payment_prompt_expires_at'])) : 'soon') ?>.</small></span>
                            <button class="button primary" type="submit">Mark prompt sent</button>
                        </form>
                    <?php elseif ($order['payment_prompt_status'] === 'sent'): ?>
                        <p class="payment-prompt-sent">Prompt marked sent to <?= e($order['payment_phone'] ?? '') ?> · Check operator records before marking paid.</p>
                    <?php endif; ?>
                <?php endif; ?>
                <details class="order-detail-expander">
                    <summary>View details and actions</summary>
                    <div class="order-detail-content">
                <div class="admin-order-details">
                    <div><strong>Contact</strong><span><?= e($order['customer_phone']) ?></span><span><?= e($order['customer_email']) ?></span></div>
                    <div><strong>Delivery</strong><span><?= e($order['delivery_address']) ?></span><span><?= e($order['delivery_district']) ?></span>
                        <?php if ($order['google_maps_url']): ?><a href="<?= e($order['google_maps_url']) ?>" target="_blank" rel="noopener noreferrer">Open map</a><?php endif; ?>
                    </div>
                    <div><strong>Payment</strong><span><?= $order['payment_method'] === 'cash_on_delivery' ? 'Cash on delivery' : e(strtoupper((string) $order['payment_provider'])) . ' Mobile Money' ?></span><span><?= e(ucfirst($order['payment_status'])) ?></span><?php if ($order['payment_method'] === 'mobile_money'): ?><span>Prompt number: <?= e($order['payment_phone'] ?? 'Not set') ?></span><span>Prompt: <?= e(ucwords(str_replace('_', ' ', $order['payment_prompt_status'] ?? 'requested'))) ?></span><?php endif; ?></div>
                    <div><strong>Total</strong><span>UGX <?= number_format((int) $order['total']) ?></span></div>
                </div>
                <?php if ($order['courier_name'] || $order['courier_phone']): ?><p class="courier-summary">Delivery contact: <strong><?= e($order['courier_name'] ?? 'Not set') ?></strong> <?= e($order['courier_phone'] ?? '') ?></p><?php endif; ?>
                <form class="delivery-pin-form" method="post" action="<?= e($config['base_url']) ?>/admin_orders.php#order-<?= (int) $order['id'] ?>">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_delivery_pin">
                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                    <strong>Delivery destination</strong>
                    <?php if ($order['delivery_latitude'] === null || $order['delivery_longitude'] === null): ?><span class="pin-missing-label">No map pin saved</span><?php else: ?><span class="pin-saved-label">Map pin saved</span><?php endif; ?>
                    <label>Latitude<input type="number" name="delivery_latitude" min="-90" max="90" step="0.0000001" value="<?= e((string) ($order['delivery_latitude'] ?? '')) ?>" placeholder="0.3476" required></label>
                    <label>Longitude<input type="number" name="delivery_longitude" min="-180" max="180" step="0.0000001" value="<?= e((string) ($order['delivery_longitude'] ?? '')) ?>" placeholder="32.5825" required></label>
                    <label class="pin-url-field">Google Maps link <span class="optional-label">Optional</span><input type="url" name="google_maps_url" maxlength="1000" value="<?= e($order['google_maps_url'] ?? '') ?>" placeholder="https://maps.app.goo.gl/..."></label>
                    <button class="button primary" type="submit">Save destination pin</button>
                </form>
                <?php if (in_array($order['order_status'], ['ready_for_pickup', 'assigned', 'accepted', 'picked_up'], true) || ($order['order_status'] === 'out_for_delivery' && empty($order['delivery_user_id']))): ?>
                    <form class="delivery-assignment-form" method="post" action="<?= e($config['base_url']) ?>/admin_orders.php">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="assign_delivery">
                        <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                        <?php if (in_array($order['order_status'], ['ready_for_pickup', 'assigned'], true) || ($order['order_status'] === 'out_for_delivery' && empty($order['delivery_user_id']))): ?>
                            <label>Assign delivery person
                                <select name="delivery_user_id"><option value="">Unassigned</option><?php foreach ($deliveryStaff as $staff): ?><option value="<?= (int) $staff['id'] ?>" <?= (int) $order['delivery_user_id'] === (int) $staff['id'] ? 'selected' : '' ?>><?= e($staff['full_name']) ?></option><?php endforeach; ?></select>
                            </label>
                        <?php else: ?>
                            <input type="hidden" name="delivery_user_id" value="<?= (int) $order['delivery_user_id'] ?>">
                            <p class="courier-summary">Assigned delivery person: <?= e($order['courier_name'] ?? 'Delivery staff') ?></p>
                        <?php endif; ?>
                        <button class="button secondary" type="submit"><?= in_array($order['order_status'], ['ready_for_pickup', 'assigned'], true) || ($order['order_status'] === 'out_for_delivery' && empty($order['delivery_user_id'])) ? 'Save assignment' : 'Save map pin' ?></button>
                    </form>
                <?php elseif ($order['delivery_user_id']): ?>
                    <p class="courier-summary">Assigned delivery account: <?= e($order['courier_name'] ?? 'Delivery staff') ?></p>
                <?php endif; ?>
                <ul class="admin-order-items">
                    <?php foreach ($itemsByOrder[(int) $order['id']] ?? [] as $item): ?>
                        <li><?= (int) $item['quantity'] ?> × <?= e($item['product_name']) ?></li>
                    <?php endforeach; ?>
                </ul>
                <nav class="order-document-actions" aria-label="Order documents">
                    <a href="<?= e($config['base_url']) ?>/admin_order_document.php?order=<?= (int) $order['id'] ?>&type=invoice" target="_blank" rel="noopener noreferrer">Print invoice / PDF</a>
                    <a href="<?= e($config['base_url']) ?>/admin_order_document.php?order=<?= (int) $order['id'] ?>&type=delivery" target="_blank" rel="noopener noreferrer">Print delivery note</a>
                    <?php if ($order['payment_status'] === 'paid'): ?><a href="<?= e($config['base_url']) ?>/admin_order_document.php?order=<?= (int) $order['id'] ?>&type=receipt" target="_blank" rel="noopener noreferrer">Print payment receipt</a><?php endif; ?>
                </nav>
                <?php if ($activeView !== 'payments' && $order['payment_method'] === 'mobile_money' && $order['payment_status'] === 'pending' && $order['payment_prompt_status'] === 'requested'): ?>
                    <form class="payment-prompt-action" method="post" action="<?= e($config['base_url']) ?>/admin_orders.php?view=payments">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="mark_payment_prompt_sent">
                        <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                        <p>Send the <?= e(strtoupper((string) $order['payment_provider'])) ?> prompt to <strong><?= e($order['payment_phone'] ?? '') ?></strong> using your Mobile Money operator tool.</p>
                        <button class="button primary" type="submit">Mark prompt sent</button>
                    </form>
                <?php elseif ($order['payment_method'] === 'mobile_money' && $order['payment_prompt_status'] === 'sent' && $order['payment_status'] === 'pending'): ?>
                    <p class="payment-prompt-sent">Prompt marked sent at <?= e($order['payment_prompt_sent_at'] ? date('H:i', strtotime($order['payment_prompt_sent_at'])) : 'time unavailable') ?> · Confirm payment after checking your operator account.</p>
                <?php endif; ?>
                <form class="admin-order-update" method="post" action="<?= e($config['base_url']) ?>/admin_orders.php">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                    <label>Payment status
                        <select name="payment_status">
                            <?php $paymentOptions = $order['payment_method'] === 'mobile_money' ? ['pending', 'paid', 'failed'] : ['unpaid', 'paid']; ?>
                            <?php foreach ($paymentOptions as $status): ?>
                                <option value="<?= e($status) ?>" <?= $order['payment_status'] === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <?php
                    $adminStatusOptions = [
                        'pending' => ['pending', 'processing', 'cancelled'],
                        'processing' => ['processing', 'ready_for_pickup', 'cancelled'],
                        'ready_for_pickup' => ['ready_for_pickup', 'cancelled'],
                        'assigned' => ['assigned', 'cancelled'],
                        'assigned' => ['assigned', 'cancelled'],
                    ];
                    $availableStatuses = $adminStatusOptions[$order['order_status']] ?? [$order['order_status']];
                    ?>
                    <label>Order status
                        <select name="order_status">
                            <?php foreach ($availableStatuses as $status): ?>
                                <option value="<?= e($status) ?>" <?= $order['order_status'] === $status ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $status))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="button primary" type="submit">Save updates</button>
                </form>
                    </div>
                </details>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
<?php require ROOT_PATH . '/app/views/footer.php'; ?>