<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_role('admin');

$pdo = db();
$format = is_string($_GET['format'] ?? null) ? $_GET['format'] : 'html';

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inventory_report.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Name','Stock','Price','Status']);
    $rows = $pdo->query("SELECT id,name,stock_quantity,price,status FROM products ORDER BY name")->fetchAll();
    foreach ($rows as $r) { fputcsv($out, $r); }
    fclose($out);
    exit;
}

$products = $pdo->query("SELECT COUNT(*) c, COALESCE(SUM(stock_quantity),0) stock, COALESCE(SUM(price*stock_quantity),0) value FROM products")->fetch();
$low = $pdo->query("SELECT COUNT(*) c FROM products WHERE stock_quantity <= 5")->fetchColumn();
$pageTitle = 'Reports';
require ROOT_PATH . '/app/views/header.php';
require ROOT_PATH . '/app/views/admin_nav.php';
?>
<section class="admin-console">
  <div class="admin-page-heading"><div><p class="market-kicker">REPORTS</p><h1>Inventory & analysis</h1></div></div>
  <section class="admin-panel">
    <p>Products: <?= (int)$products['c'] ?> · Total stock: <?= (int)$products['stock'] ?> · Inventory value: <?= number_format((float)$products['value'],2) ?> · Low stock (≤5): <?= (int)$low ?></p>
    <a class="button primary" href="?format=csv">Download inventory CSV</a>
  </section>
</section>
<?php require ROOT_PATH . '/app/views/footer.php'; ?>
