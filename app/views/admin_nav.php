<?php
$adminPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$adminLinks = [
    'dashboard.php' => ['Overview', 'dashboard.php'],
    'admin_orders.php' => ['Orders', 'admin_orders.php'],
    'admin_inventory.php' => ['Inventory', 'admin_inventory.php'],
    'admin_reports.php' => ['Reports', 'admin_reports.php'],
    'admin_delivery_staff.php' => ['Delivery team', 'admin_delivery_staff.php'],
];
?>
<nav class="admin-tabs" aria-label="Admin console">
    <?php foreach ($adminLinks as $page => [$label, $target]): ?>
        <a href="<?= e($config['base_url']) ?>/<?= e($target) ?>"<?= $adminPage === $page ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>