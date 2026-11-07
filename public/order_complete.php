<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$currentUser = current_user();
$requestedOrderId = filter_var($_GET['order'] ?? null, FILTER_VALIDATE_INT);
$orderId = $requestedOrderId ?: filter_var($_SESSION['last_order_id'] ?? null, FILTER_VALIDATE_INT);
if ($orderId === false || $orderId === null || $orderId < 1) {
    flash('error', 'No recent order was found.');
    redirect('index.php');
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $orderId]);
$order = $stmt->fetch();
if (!$order) {
    unset($_SESSION['last_order_id']);
    flash('error', 'That order could not be found.');
    redirect('index.php');
}
if ($order['user_id'] !== null && (int) $order['user_id'] !== (int) ($currentUser['id'] ?? 0)) {
    http_response_code(403);
    exit('You do not have permission to view this order.');
}
if ($order['user_id'] === null && (int) ($_SESSION['last_order_id'] ?? 0) !== $orderId) {
    http_response_code(403);
    exit('You do not have permission to view this order.');
}

$stmt = $pdo->prepare('SELECT product_name, unit_price, quantity FROM order_items WHERE order_id = :id ORDER BY id');
$stmt->execute(['id' => $orderId]);
$orderItems = $stmt->fetchAll();
$paymentPromptExpiry = $order['payment_prompt_expires_at'] ? strtotime($order['payment_prompt_expires_at']) : 0;
$pageTitle = 'Order received';
require ROOT_PATH . '/app/views/header.php';
?>
<section class="order-confirmation">
    <p class="market-kicker">ORDER RECEIVED</p>
    <h1>Thank you, <?= e($order['customer_name']) ?>.</h1>
    <p class="confirmation-lead">Your order <strong><?= e($order['order_number']) ?></strong> has been saved.</p>
    <div class="confirmation-grid">
        <section class="checkout-panel">
            <h2>Order items</h2>
            <?php foreach ($orderItems as $item): ?>
                <div class="receipt-item"><span><?= (int) $item['quantity'] ?> × <?= e($item['product_name']) ?></span><strong>UGX <?= number_format((int) $item['unit_price'] * (int) $item['quantity']) ?></strong></div>
            <?php endforeach; ?>
            <div class="receipt-item"><span>Subtotal</span><strong>UGX <?= number_format((int) $order['subtotal']) ?></strong></div>
            <div class="receipt-item"><span>Standard delivery</span><strong>UGX <?= number_format((int) $order['delivery_fee']) ?></strong></div>
            <div class="receipt-item receipt-total"><span>Total</span><strong>UGX <?= number_format((int) $order['total']) ?></strong></div>
        </section>
        <section class="checkout-panel">
            <h2>Delivery details</h2>
            <p><strong><?= e($order['customer_name']) ?></strong></p>
            <p><?= e($order['customer_phone']) ?><br><?= e($order['customer_email']) ?></p>
            <p><?= e($order['delivery_address']) ?><br><?= e($order['delivery_district']) ?></p>
            <?php if ($order['google_maps_url']): ?>
                <p><a class="map-preview" href="<?= e($order['google_maps_url']) ?>" target="_blank" rel="noopener noreferrer">Open delivery location in Google Maps</a></p>
            <?php endif; ?>
            <?php if ($order['delivery_notes']): ?><p class="muted">Note: <?= e($order['delivery_notes']) ?></p><?php endif; ?>
            <h2 class="payment-summary-title">Payment</h2>
            <?php if ($order['payment_method'] === 'mobile_money'): ?>
                <section class="payment-request-panel" id="payment-request-panel" data-expires-at="<?= $paymentPromptExpiry ? $paymentPromptExpiry * 1000 : 0 ?>" data-prompt-status="<?= e($order['payment_prompt_status'] ?? 'requested') ?>">
                    <p class="payment-network-label"><?= e(strtoupper((string) $order['payment_provider'])) ?> MOBILE MONEY</p>
                    <strong class="payment-destination-number"><?= e($order['payment_phone'] ?? $order['customer_phone']) ?></strong>
                    <div class="payment-countdown" aria-label="Payment approval countdown">
                        <svg viewBox="0 0 120 120" aria-hidden="true"><circle class="countdown-track" cx="60" cy="60" r="50"></circle><circle class="countdown-progress" id="countdown-progress" cx="60" cy="60" r="50"></circle></svg>
                        <span id="payment-countdown" role="timer" aria-live="polite">04:00</span>
                    </div>
                    <strong class="payment-request-state" id="payment-request-state"><?php if ($order['payment_prompt_status'] === 'sent'): ?>Prompt sent. Check the phone above.<?php elseif ($order['payment_status'] === 'paid'): ?>Payment confirmed.<?php else: ?>Payment request sent to the admin.<?php endif; ?></strong>
                    <p class="payment-approval-instructions">When the MTN/Airtel prompt arrives on that phone, approve it there with your Mobile Money PIN. Never enter or share your PIN on this website.</p>
                    <p class="payment-disclaimer" id="payment-expiry-message">The request expires four minutes after checkout. The admin sends the prompt manually.</p>
                </section>
            <?php else: ?>
                <p><strong>Cash on delivery</strong></p><p class="muted">Payment is due when your order arrives.</p>
            <?php endif; ?>
        </section>
    </div>
    <a class="button primary" href="<?= e($config['base_url']) ?>/index.php">Continue shopping</a>
</section>
<?php if ($order['payment_method'] === 'mobile_money'): ?>
<script>
const paymentPanel = document.getElementById('payment-request-panel');
const countdownText = document.getElementById('payment-countdown');
const countdownProgress = document.getElementById('countdown-progress');
const paymentState = document.getElementById('payment-request-state');
const expiryMessage = document.getElementById('payment-expiry-message');
const expiryTime = Number(paymentPanel.dataset.expiresAt);
const countdownDuration = 240;
const circumference = 2 * Math.PI * 50;
countdownProgress.style.strokeDasharray = `${circumference} ${circumference}`;

function updatePaymentCountdown() {
    const remaining = Math.max(0, Math.ceil((expiryTime - Date.now()) / 1000));
    const minutes = Math.floor(remaining / 60).toString().padStart(2, '0');
    const seconds = (remaining % 60).toString().padStart(2, '0');
    countdownText.textContent = `${minutes}:${seconds}`;
    countdownProgress.style.strokeDashoffset = circumference * (1 - remaining / countdownDuration);
    if (remaining === 0 && paymentPanel.dataset.promptStatus !== 'confirmed') {
        paymentState.textContent = 'Approval window expired';
        expiryMessage.textContent = 'Contact the store admin to request a new Mobile Money prompt.';
        paymentPanel.classList.add('payment-expired');
    }
    if (paymentPanel.dataset.promptStatus === 'confirmed') {
        paymentState.textContent = 'Payment confirmed';
        expiryMessage.textContent = 'The admin confirmed your payment.';
        paymentPanel.classList.add('payment-confirmed');
    }
}

updatePaymentCountdown();
window.setInterval(updatePaymentCountdown, 1000);
</script>
<?php endif; ?>
<?php require ROOT_PATH . '/app/views/footer.php'; ?>