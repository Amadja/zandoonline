<?php
declare(strict_types=1);

function get_cart_items(PDO $pdo): array
{
    $savedCart = $_SESSION['cart'] ?? [];
    if (!is_array($savedCart)) {
        $savedCart = [];
    }

    $cart = [];
    foreach ($savedCart as $productId => $quantity) {
        if (ctype_digit((string) $productId) && (int) $productId > 0 && is_numeric($quantity) && (int) $quantity > 0) {
            $cart[(int) $productId] = (int) $quantity;
        }
    }

    if (!$cart) {
        $_SESSION['cart'] = [];
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($cart), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, name, category, price, stock_quantity, image_url FROM products WHERE status = 'active' AND id IN ($placeholders)"
    );
    $stmt->execute(array_keys($cart));

    $productsById = [];
    foreach ($stmt->fetchAll() as $product) {
        $productsById[(int) $product['id']] = $product;
    }

    $items = [];
    $cleanCart = [];
    foreach ($cart as $productId => $quantity) {
        if (!isset($productsById[$productId])) {
            continue;
        }

        $product = $productsById[$productId];
        $stock = (int) $product['stock_quantity'];
        if ($stock < 1) {
            continue;
        }

        $quantity = min($quantity, $stock);
        $cleanCart[$productId] = $quantity;
        $product['quantity'] = $quantity;
        $product['price'] = (int) $product['price'];
        $product['line_total'] = $product['price'] * $quantity;
        $items[] = $product;
    }

    $_SESSION['cart'] = $cleanCart;
    return $items;
}

function get_cart_count(): int
{
    $cart = $_SESSION['cart'] ?? [];
    if (!is_array($cart)) {
        return 0;
    }

    return array_sum(array_map(static fn ($quantity): int => is_numeric($quantity) ? max(0, (int) $quantity) : 0, $cart));
}

function get_cart_subtotal(array $items): int
{
    return array_sum(array_column($items, 'line_total'));
}

function get_delivery_fee(int $subtotal): int
{
    return $subtotal > 0 ? 5000 : 0;
}