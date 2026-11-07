<?php
$pageTitle = $pageTitle ?? 'Zandoonline';
$user = current_user();
$unreadNotifications = $user && $user['role'] !== 'admin'
    ? unread_customer_notification_count(db(), (int) $user['id'])
    : 0;
$isDeliveryUser = $user && $user['role'] === 'delivery';
$isAdminUser = $user && $user['role'] === 'admin';
$searchTerm = is_string($_GET['q'] ?? null) ? $_GET['q'] : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | Zandoonline</title>
    <link rel="stylesheet" href="<?= e($config['base_url']) ?>/assets/css/style.css">
    <?php if ($isDeliveryUser): ?>
        <meta name="theme-color" content="#183c36">
        <link rel="manifest" href="<?= e($config['base_url']) ?>/delivery-manifest.webmanifest">
        <link rel="apple-touch-icon" href="<?= e($config['base_url']) ?>/assets/icons/delivery.svg">
        <link rel="stylesheet" href="<?= e($config['base_url']) ?>/assets/css/delivery.css">
        <?php if ($pageTitle === 'Delivery route'): ?><link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""><?php endif; ?>
    <?php endif; ?>
</head>
<body>
<header class="site-header">
    <a class="brand<?= $isDeliveryUser ? ' delivery-brand' : ($isAdminUser ? ' admin-brand' : '') ?>" href="<?= e($config['base_url']) ?>/<?= $isDeliveryUser ? 'delivery_app.php' : ($isAdminUser ? 'dashboard.php' : 'index.php') ?>"><?= $isDeliveryUser ? 'Zando<span>Deliveries</span>' : ($isAdminUser ? 'Zando<span>Operations</span>' : 'Zando<span>online</span>') ?></a>
    <?php if (!$isDeliveryUser && !$isAdminUser): ?>
        <form class="header-search" method="get" action="<?= e($config['base_url']) ?>/index.php" role="search">
            <label class="visually-hidden" for="site-search">Search products</label>
            <input id="site-search" type="search" name="q" placeholder="Search products..." value="<?= e($searchTerm) ?>">
            <button type="submit">Search</button>
        </form>
    <?php endif; ?>
    <nav>
        <?php if ($isDeliveryUser): ?>
            <a class="delivery-nav-link" href="<?= e($config['base_url']) ?>/delivery_app.php">My deliveries</a>
            <form class="nav-form" method="post" action="<?= e($config['base_url']) ?>/logout.php">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <button class="nav-button" type="submit">Log out</button>
            </form>
        <?php elseif ($isAdminUser): ?>
            <a class="admin-header-link" href="<?= e($config['base_url']) ?>/dashboard.php">Console</a>
            <a class="admin-header-link" href="<?= e($config['base_url']) ?>/index.php">Storefront</a>
            <form class="nav-form" method="post" action="<?= e($config['base_url']) ?>/logout.php">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <button class="nav-button" type="submit">Log out</button>
            </form>
        <?php else: ?>
            <a href="<?= e($config['base_url']) ?>/index.php">Home</a>
            <a class="header-cart" href="<?= e($config['base_url']) ?>/cart.php" aria-label="Cart, <?= get_cart_count() ?> items">Cart <span><?= get_cart_count() ?></span></a>
            <?php if ($user): ?>
                <?php if ($user['role'] !== 'admin'): ?><a class="header-inbox" href="<?= e($config['base_url']) ?>/inbox.php" aria-label="Inbox, <?= $unreadNotifications ?> unread">Inbox<?php if ($unreadNotifications > 0): ?> <span><?= $unreadNotifications ?></span><?php endif; ?></a><?php endif; ?>
                <a href="<?= e($config['base_url']) ?>/dashboard.php">Dashboard</a>
                <form class="nav-form" method="post" action="<?= e($config['base_url']) ?>/logout.php">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <button class="nav-button" type="submit">Log out</button>
                </form>
            <?php else: ?>
                <a href="<?= e($config['base_url']) ?>/login.php">Log in</a>
                <a class="nav-cta" href="<?= e($config['base_url']) ?>/register.php">Create account</a>
            <?php endif; ?>
        <?php endif; ?>
    </nav>
</header>
<main class="container">
<?php if ($message = flash('success')): ?>
    <div class="alert success"><?= e($message) ?></div>
<?php endif; ?>
<?php if ($message = flash('error')): ?>
    <div class="alert error"><?= e($message) ?></div>
<?php endif; ?>
