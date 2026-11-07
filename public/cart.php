<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once ROOT_PATH . '/app/cart.php';

$pdo = db();
if (is_post()) {
    verify_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);

    if ($productId === false || $productId === null || $productId < 1) {
        flash('error', 'That cart item is not valid.');
        redirect('cart.php');
    }

    if ($action === 'remove') {
        unset($_SESSION['cart'][$productId]);
        flash('success', 'Item removed from your cart.');
        redirect('cart.php');
    }

    $stmt = $pdo->prepare("SELECT id, stock_quantity FROM products WHERE id = :id AND status = 'active' LIMIT 1");
    $stmt->execute(['id' => $productId]);
    $product = $stmt->fetch();
    if (!$product || (int) $product['stock_quantity'] < 1) {
        unset($_SESSION['cart'][$productId]);
        flash('error', 'That item is currently unavailable.');
        redirect('cart.php');
    }

    $stock = (int) $product['stock_quantity'];
    $currentCart = is_array($_SESSION['cart'] ?? null) ? $_SESSION['cart'] : [];
    $quantity = filter_var($_POST['quantity'] ?? 1, FILTER_VALIDATE_INT);
    $quantity = $quantity === false || $quantity === null ? 1 : max(1, $quantity);

    if ($action === 'add') {
        $quantity += (int) ($currentCart[$productId] ?? 0);
    } elseif ($action !== 'update') {
        flash('error', 'That cart action is not valid.');
        redirect('cart.php');
    }

    $_SESSION['cart'] = $currentCart;
    $_SESSION['cart'][$productId] = min($quantity, $stock);
    flash('success', $quantity > $stock ? 'Cart quantity was adjusted to available stock.' : 'Cart updated.');
    redirect('cart.php');
}

$items = get_cart_items($pdo);
$subtotal = get_cart_subtotal($items);
$deliveryFee = get_delivery_fee($subtotal);
$pageTitle = 'Your cart';
require ROOT_PATH . '/app/views/header.php';
?>
<section class="checkout-head">
    <p class="market-kicker">YOUR PICKS</p>
    <h1>Your cart</h1>
    <p class="muted">Review quantities and stock before continuing.</p>
</section>
<?php if (!$items): ?>
    <section class="empty-cart-state">
        <h2>Your cart is empty</h2>
        <p class="muted">Find something good and it will show up here.</p>
        <a class="button primary" href="<?= e($config['base_url']) ?>/index.php#products">Continue shopping</a>
    </section>
<?php else: ?>
    <section class="checkout-layout">
        <div class="cart-list">
            <?php foreach ($items as $item): ?>
                <form class="cart-line" method="post" action="<?= e($config['base_url']) ?>/cart.php">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="product_id" value="<?= (int) $item['id'] ?>">
                    <img src="<?= e($item['image_url']) ?>" alt="<?= e($item['name']) ?>">
                    <div class="cart-line-info">
                        <p class="product-category"><?= e($item['category']) ?></p>
                        <h2><?= e($item['name']) ?></h2>
                        <p class="muted">UGX <?= number_format($item['price']) ?> each · <?= (int) $item['stock_quantity'] ?> available</p>
                    </div>
                    <label class="cart-quantity-label">Qty
                        <input type="number" name="quantity" value="<?= (int) $item['quantity'] ?>" min="1" max="<?= (int) $item['stock_quantity'] ?>" required>
                    </label>
                    <strong class="cart-line-total">UGX <?= number_format($item['line_total']) ?></strong>
                    <div class="cart-line-actions">
                        <button class="button secondary" type="submit" name="action" value="update">Update</button>
                        <button class="text-button" type="submit" name="action" value="remove">Remove</button>
                    </div>
                </form>
            <?php endforeach; ?>
        </div>
        <aside class="order-summary">
            <h2>Order summary</h2>
            <div><span>Subtotal</span><strong>UGX <?= number_format($subtotal) ?></strong></div>
            <div><span>Standard delivery</span><strong>UGX <?= number_format($deliveryFee) ?></strong></div>
            <div class="summary-total"><span>Total</span><strong>UGX <?= number_format($subtotal + $deliveryFee) ?></strong></div>
            <a class="button primary full" href="<?= e($config['base_url']) ?>/checkout.php">Continue to delivery</a>
            <a class="summary-back" href="<?= e($config['base_url']) ?>/index.php#products">Continue shopping</a>
        </aside>
    </section>
<?php endif; ?>
<?php require ROOT_PATH . '/app/views/footer.php'; ?>