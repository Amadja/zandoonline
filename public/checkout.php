<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once ROOT_PATH . '/app/cart.php';

$pdo = db();
$accountUser = current_user();
$items = get_cart_items($pdo);
if (!$items) {
    flash('error', 'Add an available item to your cart before checkout.');
    redirect('cart.php');
}

$errors = [];
$customerName = $accountUser['full_name'] ?? '';
$customerEmail = $accountUser['email'] ?? '';
$customerPhone = $accountUser['phone'] ?? '';
$paymentMethod = 'mobile_money';
$paymentProvider = is_string($_POST['payment_provider'] ?? null) ? $_POST['payment_provider'] : '';
$paymentPhoneInput = is_string($_POST['payment_phone'] ?? null) ? trim($_POST['payment_phone']) : ($accountUser['phone'] ?? '');
$paymentPhone = normalize_ugandan_phone($paymentPhoneInput) ?? '';
$latitudeInput = '';
$longitudeInput = '';
$googleMapsUrl = '';

if (is_post()) {
    verify_csrf();
    $customerName = trim(is_string($_POST['customer_name'] ?? null) ? $_POST['customer_name'] : '');
    $customerEmail = strtolower(trim(is_string($_POST['customer_email'] ?? null) ? $_POST['customer_email'] : ''));
    $customerPhone = trim(is_string($_POST['customer_phone'] ?? null) ? $_POST['customer_phone'] : '');
    $paymentPhoneInput = trim(is_string($_POST['payment_phone'] ?? null) ? $_POST['payment_phone'] : '');
    $paymentPhone = normalize_ugandan_phone($paymentPhoneInput) ?? '';
    $deliveryAddress = trim(is_string($_POST['delivery_address'] ?? null) ? $_POST['delivery_address'] : '');
    $deliveryDistrict = trim(is_string($_POST['delivery_district'] ?? null) ? $_POST['delivery_district'] : '');
    $deliveryNotes = trim(is_string($_POST['delivery_notes'] ?? null) ? $_POST['delivery_notes'] : '');
    $latitudeInput = trim(is_string($_POST['delivery_latitude'] ?? null) ? $_POST['delivery_latitude'] : '');
    $longitudeInput = trim(is_string($_POST['delivery_longitude'] ?? null) ? $_POST['delivery_longitude'] : '');
    $googleMapsUrl = trim(is_string($_POST['google_maps_url'] ?? null) ? $_POST['google_maps_url'] : '');
    $deliveryLatitude = null;
    $deliveryLongitude = null;

    if (mb_strlen($customerName) < 2 || mb_strlen($customerName) > 120) $errors[] = 'Enter your name (2 to 120 characters).';
    if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL) || strlen($customerEmail) > 190) $errors[] = 'Enter a valid email address.';
    if (!preg_match('/^\+?[0-9][0-9\s().-]{6,19}$/', $customerPhone)) $errors[] = 'Enter a valid phone number, including country code if needed.';
    if (mb_strlen($deliveryAddress) < 5 || mb_strlen($deliveryAddress) > 255) $errors[] = 'Enter a delivery address (5 to 255 characters).';
    if (mb_strlen($deliveryDistrict) < 2 || mb_strlen($deliveryDistrict) > 100) $errors[] = 'Enter your district.';
    if (mb_strlen($deliveryNotes) > 500) $errors[] = 'Delivery notes must be 500 characters or fewer.';
    if (($latitudeInput === '') !== ($longitudeInput === '')) {
        $errors[] = 'Both map coordinates are required when sharing a location pin.';
    } elseif ($latitudeInput !== '') {
        $latitude = filter_var($latitudeInput, FILTER_VALIDATE_FLOAT);
        $longitude = filter_var($longitudeInput, FILTER_VALIDATE_FLOAT);
        if ($latitude === false || $longitude === false || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            $errors[] = 'The shared location coordinates are not valid.';
        } else {
            $deliveryLatitude = $latitude;
            $deliveryLongitude = $longitude;
        }
    }
    if ($googleMapsUrl !== '') {
        $mapUrlParts = parse_url($googleMapsUrl);
        $mapHost = strtolower(is_array($mapUrlParts) ? ($mapUrlParts['host'] ?? '') : '');
        $isGoogleMapsHost = $mapHost === 'google.com'
            || str_ends_with($mapHost, '.google.com')
            || in_array($mapHost, ['goo.gl', 'maps.app.goo.gl'], true);
        if (strlen($googleMapsUrl) > 1000 || !filter_var($googleMapsUrl, FILTER_VALIDATE_URL) || ($mapUrlParts['scheme'] ?? '') !== 'https' || !$isGoogleMapsHost) {
            $errors[] = 'Enter a valid secure Google Maps share link.';
        }
    }
    if (!in_array($paymentProvider, ['mtn', 'airtel'], true)) $errors[] = 'Choose MTN or Airtel Mobile Money.';
    if ($paymentPhone === '') $errors[] = 'Enter a valid Ugandan number to receive the Mobile Money prompt.';

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $lockedItems = [];
            $subtotal = 0;
            $productStmt = $pdo->prepare("SELECT id, name, price, stock_quantity FROM products WHERE id = :id AND status = 'active' FOR UPDATE");

            foreach ($items as $item) {
                $productStmt->execute(['id' => $item['id']]);
                $product = $productStmt->fetch();
                if (!$product || (int) $product['stock_quantity'] < (int) $item['quantity']) {
                    throw new DomainException('Stock changed while you were checking out. Review your cart and try again.');
                }

                $product['quantity'] = (int) $item['quantity'];
                $product['price'] = (int) $product['price'];
                $product['line_total'] = $product['price'] * $product['quantity'];
                $subtotal += $product['line_total'];
                $lockedItems[] = $product;
            }

            $deliveryFee = get_delivery_fee($subtotal);
            if ($deliveryLatitude !== null && $googleMapsUrl === '') {
                $googleMapsUrl = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($deliveryLatitude . ',' . $deliveryLongitude);
            }
            $orderNumber = 'ZO' . date('ymd') . strtoupper(bin2hex(random_bytes(3)));
            $paymentStatus = 'pending';
            $paymentPromptExpiresAt = date('Y-m-d H:i:s', time() + 240);
            $orderStmt = $pdo->prepare(
                'INSERT INTO orders (order_number, user_id, customer_name, customer_email, customer_phone, delivery_address, delivery_district, delivery_notes, delivery_latitude, delivery_longitude, google_maps_url, payment_method, payment_provider, payment_phone, payment_status, payment_prompt_status, payment_prompt_requested_at, payment_prompt_expires_at, subtotal, delivery_fee, total) VALUES (:number, :user_id, :name, :email, :phone, :address, :district, :notes, :latitude, :longitude, :maps_url, :method, :provider, :payment_phone, :payment_status, :prompt_status, NOW(), :prompt_expires, :subtotal, :delivery_fee, :total)'
            );
            $orderStmt->execute([
                'number' => $orderNumber,
                'user_id' => $accountUser['id'] ?? null,
                'name' => $customerName,
                'email' => $customerEmail,
                'phone' => $customerPhone,
                'address' => $deliveryAddress,
                'district' => $deliveryDistrict,
                'notes' => $deliveryNotes !== '' ? $deliveryNotes : null,
                'latitude' => $deliveryLatitude,
                'longitude' => $deliveryLongitude,
                'maps_url' => $googleMapsUrl !== '' ? $googleMapsUrl : null,
                'method' => $paymentMethod,
                'provider' => $paymentProvider,
                'payment_phone' => $paymentPhone,
                'payment_status' => $paymentStatus,
                'prompt_status' => 'requested',
                'prompt_expires' => $paymentPromptExpiresAt,
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'total' => $subtotal + $deliveryFee,
            ]);
            $orderId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity) VALUES (:order_id, :product_id, :name, :price, :quantity)');
            $stockStmt = $pdo->prepare('UPDATE products SET stock_quantity = stock_quantity - :decrement_quantity WHERE id = :id AND stock_quantity >= :minimum_quantity');
            foreach ($lockedItems as $item) {
                $itemStmt->execute([
                    'order_id' => $orderId,
                    'product_id' => $item['id'],
                    'name' => $item['name'],
                    'price' => $item['price'],
                    'quantity' => $item['quantity'],
                ]);
                $stockStmt->execute([
                    'decrement_quantity' => $item['quantity'],
                    'minimum_quantity' => $item['quantity'],
                    'id' => $item['id'],
                ]);
            }

            if ($accountUser) {
                create_customer_notification(
                    $pdo,
                    (int) $accountUser['id'],
                    $orderId,
                    'order_received',
                    'Order received',
                    'We have received order ' . $orderNumber . ' and requested a ' . strtoupper($paymentProvider) . ' Mobile Money prompt to ' . $paymentPhone . '. The admin will send it shortly. Approve it on your phone within 4 minutes. Never share your Mobile Money PIN.'
                );
            }

            $pdo->commit();
            $_SESSION['cart'] = [];
            $_SESSION['last_order_id'] = $orderId;
            redirect('order_complete.php');
        } catch (DomainException $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', $exception->getMessage());
            redirect('cart.php');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log($exception->getMessage());
            $errors[] = 'We could not place your order. Please check your details and try again.';
        }
    }
}

$items = get_cart_items($pdo);
if (!$items) redirect('cart.php');
$subtotal = get_cart_subtotal($items);
$deliveryFee = get_delivery_fee($subtotal);
$pageTitle = 'Delivery and payment';
require ROOT_PATH . '/app/views/header.php';
?>
<section class="checkout-head">
    <p class="market-kicker">ALMOST THERE</p>
    <h1>Delivery and payment</h1>
    <p class="muted">Where should we bring your order?</p>
</section>
<?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>
<form class="checkout-layout" method="post" action="<?= e($config['base_url']) ?>/checkout.php">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <div class="checkout-main">
        <section class="checkout-panel">
            <h2>Contact details</h2>
            <div class="checkout-fields">
                <label>Full name<input name="customer_name" maxlength="120" autocomplete="name" required value="<?= e($customerName) ?>"></label>
                <label>Email address<input type="email" name="customer_email" maxlength="190" autocomplete="email" required value="<?= e($customerEmail) ?>"></label>
                <label>Phone number<input type="tel" name="customer_phone" maxlength="30" autocomplete="tel" placeholder="+256 7XX XXX XXX" required value="<?= e($customerPhone) ?>"></label>
            </div>
        </section>
        <section class="checkout-panel">
            <h2>Delivery address</h2>
            <div class="checkout-fields">
                <label class="field-wide">Street, building, or landmark<input name="delivery_address" maxlength="255" autocomplete="street-address" required value="<?= old('delivery_address') ?>"></label>
                <label>District<input name="delivery_district" maxlength="100" autocomplete="address-level1" required value="<?= old('delivery_district') ?>"></label>
                <div class="location-tools field-wide">
                    <button class="button secondary" id="share-location" type="button">Use my current location</button>
                    <p class="location-status" id="location-status" role="status">Optional. Your browser will ask permission before sharing a location pin.</p>
                    <input type="hidden" name="delivery_latitude" id="delivery-latitude" value="<?= e($latitudeInput) ?>">
                    <input type="hidden" name="delivery_longitude" id="delivery-longitude" value="<?= e($longitudeInput) ?>">
                    <a class="map-preview" id="map-preview" href="<?= e($googleMapsUrl) ?>" target="_blank" rel="noopener noreferrer"<?= $googleMapsUrl === '' ? ' hidden' : '' ?>>Preview shared location in Google Maps</a>
                </div>
                <label class="field-wide">Google Maps link <span class="optional-label">Optional. Paste a dropped pin or shared location link.</span><input type="url" name="google_maps_url" id="google-maps-url" maxlength="1000" placeholder="https://maps.app.goo.gl/..." value="<?= e($googleMapsUrl) ?>"></label>
                <label class="field-wide">Delivery notes <span class="optional-label">Optional</span><textarea name="delivery_notes" rows="3" maxlength="500" placeholder="Gate details or a useful landmark"><?= old('delivery_notes') ?></textarea></label>
            </div>
        </section>
        <section class="checkout-panel payment-panel">
            <h2>Pay with Mobile Money</h2>
            <label>Network
                <select name="payment_provider" id="payment-provider" required>
                    <option value="">Choose MTN or Airtel</option>
                    <option value="mtn" <?= $paymentProvider === 'mtn' ? 'selected' : '' ?>>MTN Mobile Money</option>
                    <option value="airtel" <?= $paymentProvider === 'airtel' ? 'selected' : '' ?>>Airtel Money</option>
                </select>
            </label>
            <label>Number to receive the prompt
                <input type="tel" name="payment_phone" id="payment-phone" maxlength="30" autocomplete="tel" placeholder="0770 123 456" required value="<?= e($paymentPhoneInput) ?>">
            </label>
            <p class="payment-disclaimer">The admin will manually send the prompt to this number. Approve it on your phone within 4 minutes when it arrives. Never enter or share your Mobile Money PIN on this website.</p>
        </section>
    </div>
    <aside class="order-summary checkout-summary">
        <h2>Your order</h2>
        <div class="summary-items">
            <?php foreach ($items as $item): ?>
                <div><span><?= (int) $item['quantity'] ?> × <?= e($item['name']) ?></span><strong>UGX <?= number_format($item['line_total']) ?></strong></div>
            <?php endforeach; ?>
        </div>
        <div><span>Subtotal</span><strong>UGX <?= number_format($subtotal) ?></strong></div>
        <div><span>Standard delivery</span><strong>UGX <?= number_format($deliveryFee) ?></strong></div>
        <div class="summary-total"><span>Total</span><strong>UGX <?= number_format($subtotal + $deliveryFee) ?></strong></div>
        <button class="button primary full" type="submit">Place order &amp; request payment</button>
        <a class="summary-back" href="<?= e($config['base_url']) ?>/cart.php">Back to cart</a>
    </aside>
</form>
<script>
const locationButton = document.getElementById('share-location');
const locationStatus = document.getElementById('location-status');
const latitudeInput = document.getElementById('delivery-latitude');
const longitudeInput = document.getElementById('delivery-longitude');
const mapsUrlInput = document.getElementById('google-maps-url');
const mapPreview = document.getElementById('map-preview');
locationButton.addEventListener('click', () => {
    if (!navigator.geolocation) {
        locationStatus.textContent = 'Location sharing is not available in this browser. You can paste a Google Maps link instead.';
        return;
    }

    locationButton.disabled = true;
    locationStatus.textContent = 'Waiting for location permission...';
    navigator.geolocation.getCurrentPosition(position => {
        const latitude = position.coords.latitude.toFixed(7);
        const longitude = position.coords.longitude.toFixed(7);
        const mapUrl = new URL('https://www.google.com/maps/search/');
        mapUrl.searchParams.set('api', '1');
        mapUrl.searchParams.set('query', `${latitude},${longitude}`);
        latitudeInput.value = latitude;
        longitudeInput.value = longitude;
        mapsUrlInput.value = mapUrl.toString();
        mapPreview.href = mapUrl.toString();
        mapPreview.hidden = false;
        locationStatus.textContent = `Location added (about ${Math.round(position.coords.accuracy)}m accuracy). Keep the written address above for delivery.`;
        locationButton.disabled = false;
    }, error => {
        locationStatus.textContent = error.code === error.PERMISSION_DENIED
            ? 'Location permission was denied. You can paste a Google Maps link instead.'
            : 'Could not get your location. You can paste a Google Maps link instead.';
        locationButton.disabled = false;
    }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
});
</script>
<?php require ROOT_PATH . '/app/views/footer.php'; ?>