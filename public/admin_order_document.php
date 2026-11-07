<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_role('admin');

$orderId = filter_var($_GET['order'] ?? null, FILTER_VALIDATE_INT);
$documentType = is_string($_GET['type'] ?? null) ? $_GET['type'] : '';
if (!$orderId || !in_array($documentType, ['invoice', 'receipt', 'delivery'], true)) {
    http_response_code(404);
    exit('Document not found.');
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $orderId]);
$order = $stmt->fetch();
if (!$order) {
    http_response_code(404);
    exit('Order not found.');
}
if ($documentType === 'receipt' && $order['payment_status'] !== 'paid') {
    http_response_code(403);
    exit('A payment receipt can only be issued after the admin confirms payment.');
}

$stmt = $pdo->prepare('SELECT product_name, unit_price, quantity FROM order_items WHERE order_id = :id ORDER BY id');
$stmt->execute(['id' => $orderId]);
$items = $stmt->fetchAll();
$documentTitles = [
    'invoice' => 'Order Invoice',
    'receipt' => 'Payment Receipt',
    'delivery' => 'Customer Delivery Note',
];
$documentTitle = $documentTitles[$documentType];
$pageTitle = $documentTitle . ' ' . $order['order_number'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | Zandoonline</title>
    <link rel="stylesheet" href="<?= e($config['base_url']) ?>/assets/css/order-document.css">
</head>
<body>
<div class="document-toolbar">
    <a href="<?= e($config['base_url']) ?>/admin_orders.php">Back to orders</a>
    <button type="button" onclick="window.print()">Print / Save as PDF</button>
</div>
<main class="order-document document-<?= e($documentType) ?>">
    <header class="document-header">
        <div><p class="document-brand">ZANDO<span>ONLINE</span></p><p class="document-business">Online marketplace · Uganda</p></div>
        <div class="document-title-block"><p><?= e(strtoupper($documentTitle)) ?></p><h1><?= e($order['order_number']) ?></h1><time datetime="<?= e($order['created_at']) ?>">Order date: <?= e(date('M j, Y', strtotime($order['created_at']))) ?></time></div>
    </header>

    <?php if ($documentType !== 'delivery'): ?>
        <section class="document-parties">
            <div><span><?= $documentType === 'receipt' ? 'RECEIVED FROM' : 'BILL TO' ?></span><strong><?= e($order['customer_name']) ?></strong><p><?= e($order['customer_email']) ?><br><?= e($order['customer_phone']) ?></p></div>
            <div><span>PAYMENT</span><strong><?= $order['payment_method'] === 'cash_on_delivery' ? 'Cash on delivery' : e(strtoupper((string) $order['payment_provider'])) . ' Mobile Money' ?></strong><p>Status: <?= e(ucfirst($order['payment_status'])) ?></p></div>
        </section>
    <?php else: ?>
        <section class="delivery-recipient">
            <span>DELIVER TO</span>
            <strong><?= e($order['customer_name']) ?></strong>
            <p><?= e($order['customer_phone']) ?><br><?= e($order['delivery_address']) ?><br><?= e($order['delivery_district']) ?></p>
            <?php if ($order['delivery_notes']): ?><p><strong>Delivery instructions:</strong> <?= e($order['delivery_notes']) ?></p><?php endif; ?>
            <?php if ($order['google_maps_url']): ?><a href="<?= e($order['google_maps_url']) ?>">Open delivery pin in Google Maps</a><?php endif; ?>
        </section>
        <section class="courier-info"><span>DELIVERY PERSON</span><strong><?= e($order['courier_name'] ?: 'Not assigned') ?></strong><p><?= e($order['courier_phone'] ?: 'Contact store for delivery details') ?></p></section>
    <?php endif; ?>

    <?php if ($documentType !== 'delivery'): ?>
        <table class="document-items">
            <thead><tr><th>Item</th><th>Unit price</th><th>Qty</th><th>Amount</th></tr></thead>
            <tbody><?php foreach ($items as $item): ?>
                <tr><td><?= e($item['product_name']) ?></td><td>UGX <?= number_format((int) $item['unit_price']) ?></td><td><?= (int) $item['quantity'] ?></td><td>UGX <?= number_format((int) $item['unit_price'] * (int) $item['quantity']) ?></td></tr>
            <?php endforeach; ?></tbody>
        </table>
        <section class="document-totals">
            <div><span>Subtotal</span><strong>UGX <?= number_format((int) $order['subtotal']) ?></strong></div>
            <div><span>Standard delivery</span><strong>UGX <?= number_format((int) $order['delivery_fee']) ?></strong></div>
            <div class="grand-total"><span><?= $documentType === 'receipt' ? 'Amount received' : 'Total due' ?></span><strong>UGX <?= number_format((int) $order['total']) ?></strong></div>
        </section>
        <?php if ($documentType === 'receipt'): ?><p class="receipt-confirmation">Payment confirmed by Zandoonline administration.</p><?php endif; ?>
    <?php else: ?>
        <section class="delivery-items">
            <h2>Package contents</h2>
            <table class="document-items"><thead><tr><th>Item</th><th>Quantity</th></tr></thead><tbody>
                <?php foreach ($items as $item): ?><tr><td><?= e($item['product_name']) ?></td><td><?= (int) $item['quantity'] ?></td></tr><?php endforeach; ?>
            </tbody></table>
        </section>
        <section class="delivery-order-meta"><span>Payment method</span><strong><?= $order['payment_method'] === 'cash_on_delivery' ? 'Cash on delivery' : e(strtoupper((string) $order['payment_provider'])) . ' Mobile Money' ?></strong></section>
    <?php endif; ?>
    <footer class="document-footer"><p><?= $documentType === 'delivery' ? 'Please confirm receipt of the package with the customer.' : 'Thank you for shopping with Zandoonline.' ?></p><span>Generated <?= e(date('M j, Y H:i')) ?></span></footer>
</main>
</body>
</html>