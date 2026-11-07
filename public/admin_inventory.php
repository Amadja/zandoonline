<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once ROOT_PATH . '/app/product_images.php';
require_role('admin');

$pdo = db();
if (is_post()) {
    verify_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    if ($action === 'create') {
        $name = trim(is_string($_POST['name'] ?? null) ? $_POST['name'] : '');
        $category = trim(is_string($_POST['category'] ?? null) ? $_POST['category'] : '');
        $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_INT);
        $stock = filter_var($_POST['stock_quantity'] ?? null, FILTER_VALIDATE_INT);
        $imageUrl = trim(is_string($_POST['image_url'] ?? null) ? $_POST['image_url'] : '');
        $imageParts = parse_url($imageUrl);
        $description = trim(is_string($_POST['description'] ?? null) ? $_POST['description'] : '');
        $imageUpload = is_array($_FILES['image'] ?? null) ? $_FILES['image'] : [];
        $hasImageUpload = ($imageUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        if (mb_strlen($name) < 2 || mb_strlen($name) > 190) {
            flash('error', 'Product name must be between 2 and 190 characters.');
            redirect('admin_inventory.php');
        }
        if (mb_strlen($category) < 2 || mb_strlen($category) > 100) {
            flash('error', 'Category must be between 2 and 100 characters.');
            redirect('admin_inventory.php');
        }
        if ($price === false || $price < 1 || $stock === false || $stock < 0) {
            flash('error', 'Enter a valid price and a stock quantity of zero or more.');
            redirect('admin_inventory.php');
        }
        if (mb_strlen($description) > 5000) {
            flash('error', 'Product descriptions must be 5,000 characters or fewer.');
            redirect('admin_inventory.php');
        }
        if ($imageUrl !== '' && (strlen($imageUrl) > 500 || !filter_var($imageUrl, FILTER_VALIDATE_URL) || ($imageParts['scheme'] ?? '') !== 'https')) {
            flash('error', 'Enter a valid HTTPS product image URL.');
            redirect('admin_inventory.php');
        }
        if (!$hasImageUpload && $imageUrl === '') {
            flash('error', 'Upload a product image or provide an HTTPS image URL.');
            redirect('admin_inventory.php');
        }

        $slugBase = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        $slug = substr($slugBase !== '' ? $slugBase : 'product', 0, 200) . '-' . bin2hex(random_bytes(4));
        $storedImage = null;
        try {
            $storedImage = save_product_image($imageUpload);
            $stmt = $pdo->prepare('INSERT INTO products (name, slug, description, category, price, stock_quantity, image_url, status) VALUES (:name, :slug, :description, :category, :price, :stock, :image, "active")');
            $stmt->execute([
                'name' => $name,
                'slug' => $slug,
                'description' => $description !== '' ? $description : null,
                'category' => $category,
                'price' => $price,
                'stock' => $stock,
                'image' => $storedImage ?? $imageUrl,
            ]);
        } catch (Throwable $exception) {
            delete_product_image($storedImage);
            error_log($exception->getMessage());
            flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'Product could not be added. Please try again.');
            redirect('admin_inventory.php');
        }
        flash('success', 'Product added to the catalog.');
        redirect('admin_inventory.php');
    }

    if ($action === 'update_product') {
        $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
        $stmt = $pdo->prepare('SELECT image_url, price, stock_quantity, status FROM products WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $productId]);
        $existingProduct = $productId ? $stmt->fetch() : false;
        if (!$existingProduct) {
            flash('error', 'That product no longer exists.');
            redirect('admin_inventory.php');
        }

        $name = trim(is_string($_POST['name'] ?? null) ? $_POST['name'] : '');
        $category = trim(is_string($_POST['category'] ?? null) ? $_POST['category'] : '');
        $description = trim(is_string($_POST['description'] ?? null) ? $_POST['description'] : '');
        $imageUrlInput = trim(is_string($_POST['image_url'] ?? null) ? $_POST['image_url'] : '');
        $imageUpload = is_array($_FILES['image'] ?? null) ? $_FILES['image'] : [];
        $hasImageUpload = ($imageUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $price = filter_var($_POST['price'] ?? $existingProduct['price'], FILTER_VALIDATE_INT);
        $stock = filter_var($_POST['stock_quantity'] ?? $existingProduct['stock_quantity'], FILTER_VALIDATE_INT);
        $status = is_string($_POST['status'] ?? null) ? $_POST['status'] : $existingProduct['status'];

        if (mb_strlen($name) < 2 || mb_strlen($name) > 190 || mb_strlen($category) < 2 || mb_strlen($category) > 100 || mb_strlen($description) > 5000) {
            flash('error', 'Check the product name, category, and description lengths.');
            redirect('admin_inventory.php');
        }
        if ($price === false || $price < 1 || $stock === false || $stock < 0 || !in_array($status, ['draft', 'active', 'archived'], true)) {
            flash('error', 'Enter a valid price, stock quantity, and listing status.');
            redirect('admin_inventory.php');
        }
        $imageParts = parse_url($imageUrlInput);
        if ($imageUrlInput !== '' && (strlen($imageUrlInput) > 500 || !filter_var($imageUrlInput, FILTER_VALIDATE_URL) || ($imageParts['scheme'] ?? '') !== 'https')) {
            flash('error', 'Enter a valid HTTPS image URL or upload an image file.');
            redirect('admin_inventory.php');
        }

        $storedImage = null;
        try {
            $storedImage = save_product_image($imageUpload);
            $imageUrl = $storedImage ?? ($imageUrlInput !== '' ? $imageUrlInput : $existingProduct['image_url']);
            $update = $pdo->prepare('UPDATE products SET name = :name, category = :category, description = :description, price = :price, stock_quantity = :stock, status = :status, image_url = :image WHERE id = :id');
            $update->execute([
                'name' => $name,
                'category' => $category,
                'description' => $description !== '' ? $description : null,
                'price' => $price,
                'stock' => $stock,
                'status' => $status,
                'image' => $imageUrl,
                'id' => $productId,
            ]);
            if ($imageUrl !== $existingProduct['image_url']) {
                delete_product_image($existingProduct['image_url']);
            }
        } catch (Throwable $exception) {
            delete_product_image($storedImage);
            error_log($exception->getMessage());
            flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'Product could not be updated. Please try again.');
            redirect('admin_inventory.php#edit-product-' . $productId);
        }
        flash('success', 'Product details updated.');
        redirect('admin_inventory.php#product-' . $productId);
    }

    if ($action === 'delete_product') {
        $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
        $stmt = $pdo->prepare('SELECT image_url FROM products WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $productId]);
        $product = $productId ? $stmt->fetch() : false;
        if (!$product) {
            flash('error', 'That product no longer exists.');
            redirect('admin_inventory.php');
        }

        $delete = $pdo->prepare('DELETE FROM products WHERE id = :id');
        $delete->execute(['id' => $productId]);
        delete_product_image($product['image_url']);
        flash('success', 'Product deleted. Existing order records are retained.');
        redirect('admin_inventory.php');
    }

    if ($action === 'save_inventory') {
        $postedProducts = $_POST['products'] ?? null;
        if (!is_array($postedProducts) || count($postedProducts) > 200) {
            flash('error', 'The inventory update was not valid.');
            redirect('admin_inventory.php');
        }

        $updates = [];
        foreach ($postedProducts as $productId => $values) {
            $id = filter_var($productId, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1 || !is_array($values)) {
                flash('error', 'The inventory update was not valid.');
                redirect('admin_inventory.php');
            }
            $price = filter_var($values['price'] ?? null, FILTER_VALIDATE_INT);
            $stock = filter_var($values['stock_quantity'] ?? null, FILTER_VALIDATE_INT);
            $status = is_string($values['status'] ?? null) ? $values['status'] : '';
            if ($price === false || $price < 1 || $stock === false || $stock < 0 || !in_array($status, ['draft', 'active', 'archived'], true)) {
                flash('error', 'Each product needs a valid price, stock quantity, and listing status.');
                redirect('admin_inventory.php');
            }
            $updates[] = ['id' => $id, 'price' => $price, 'stock' => $stock, 'status' => $status];
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE products SET price = :price, stock_quantity = :stock, status = :status WHERE id = :id');
            foreach ($updates as $update) {
                $stmt->execute($update);
            }
            $pdo->commit();
            flash('success', 'Inventory changes saved.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log($exception->getMessage());
            flash('error', 'Inventory could not be saved. Please try again.');
        }
        redirect('admin_inventory.php');
    }

    flash('error', 'That inventory action is not valid.');
    redirect('admin_inventory.php');
}

$search = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$search = mb_substr($search, 0, 100);
$lowStockOnly = ($_GET['stock'] ?? '') === 'low';
$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(name LIKE :name_term OR category LIKE :category_term)';
    $params['name_term'] = '%' . $search . '%';
    $params['category_term'] = '%' . $search . '%';
}
if ($lowStockOnly) {
    $where[] = "status = 'active' AND stock_quantity <= 5";
}
$sql = 'SELECT id, name, description, category, price, stock_quantity, status, image_url FROM products';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= $lowStockOnly ? ' ORDER BY stock_quantity, name LIMIT 200' : ' ORDER BY name LIMIT 200';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();
$productCount = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
$lowStockCount = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active' AND stock_quantity <= 5")->fetchColumn();
$pageTitle = 'Manage inventory';
require ROOT_PATH . '/app/views/header.php';
require ROOT_PATH . '/app/views/admin_nav.php';
?>
<section class="admin-console">
    <div class="admin-page-heading">
        <div><p class="market-kicker">CATALOG OPERATIONS</p><h1>Inventory</h1><p>Update prices, stock levels, and product visibility.</p></div>
        <div class="inventory-summary"><span><?= $productCount ?> products</span><strong class="<?= $lowStockCount > 0 ? 'metric-warning-text' : '' ?>"><?= $lowStockCount ?> low stock</strong></div>
    </div>
    <form class="inventory-toolbar" method="get" action="<?= e($config['base_url']) ?>/admin_inventory.php">
        <label class="inventory-search">Search catalog<input type="search" name="q" placeholder="Product name or category" value="<?= e($search) ?>"></label>
        <label>Stock filter
            <select name="stock"><option value="all" <?= !$lowStockOnly ? 'selected' : '' ?>>All products</option><option value="low" <?= $lowStockOnly ? 'selected' : '' ?>>Low stock (5 or fewer)</option></select>
        </label>
        <button class="button secondary" type="submit">Filter</button>
    </form>
    <section class="admin-panel inventory-panel">
        <div class="admin-panel-heading"><h2>Catalog stock</h2><span>Up to 200 products shown</span></div>
        <?php if (!$products): ?>
            <p class="muted inventory-empty">No products match this filter.</p>
        <?php else: ?>
            <form method="post" action="<?= e($config['base_url']) ?>/admin_inventory.php">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_inventory">
                <div class="admin-table-wrap"><table class="admin-table inventory-table">
                    <thead><tr><th>Product</th><th>Category</th><th>Price (UGX)</th><th>Stock</th><th>Listing</th><th>Details</th></tr></thead>
                    <tbody><?php foreach ($products as $product): ?>
                        <tr id="product-<?= (int) $product['id'] ?>">
                            <td><div class="inventory-product"><img src="<?= e($product['image_url']) ?>" alt=""><strong><?= e($product['name']) ?></strong></div></td>
                            <td><?= e($product['category']) ?></td>
                            <td><label class="visually-hidden" for="price-<?= (int) $product['id'] ?>">Price for <?= e($product['name']) ?></label><input id="price-<?= (int) $product['id'] ?>" type="number" name="products[<?= (int) $product['id'] ?>][price]" min="1" step="1" value="<?= (int) $product['price'] ?>" required></td>
                            <td><label class="visually-hidden" for="stock-<?= (int) $product['id'] ?>">Stock for <?= e($product['name']) ?></label><input id="stock-<?= (int) $product['id'] ?>" type="number" name="products[<?= (int) $product['id'] ?>][stock_quantity]" min="0" step="1" value="<?= (int) $product['stock_quantity'] ?>" required></td>
                            <td><label class="visually-hidden" for="status-<?= (int) $product['id'] ?>">Listing status for <?= e($product['name']) ?></label><select id="status-<?= (int) $product['id'] ?>" name="products[<?= (int) $product['id'] ?>][status]"><?php foreach (['active', 'draft', 'archived'] as $status): ?><option value="<?= e($status) ?>" <?= $product['status'] === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option><?php endforeach; ?></select></td>
                            <td><a href="#edit-product-<?= (int) $product['id'] ?>">Edit</a></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div>
                <div class="inventory-save-row"><span>Changes apply when you save.</span><button class="button primary" type="submit">Save inventory</button></div>
            </form>
        <?php endif; ?>
    </section>
    <?php if ($products): ?>
        <section class="product-edit-list">
            <div class="admin-panel-heading"><h2>Product details and images</h2><span>Edit descriptions, photos, and catalog labels</span></div>
            <?php foreach ($products as $product): ?>
                <details class="product-edit-panel" id="edit-product-<?= (int) $product['id'] ?>">
                    <summary><span><?= e($product['name']) ?></span><span><?= e($product['category']) ?></span></summary>
                    <form class="product-details-form" method="post" enctype="multipart/form-data" action="<?= e($config['base_url']) ?>/admin_inventory.php">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="update_product">
                        <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                        <label>Product name<input name="name" maxlength="190" value="<?= e($product['name']) ?>" required></label>
                        <label>Category<input name="category" maxlength="100" value="<?= e($product['category']) ?>" required></label>
                        <label class="details-wide">Description<textarea name="description" maxlength="5000" rows="3"><?= e($product['description'] ?? '') ?></textarea></label>
                        <div class="current-product-image"><img src="<?= e($product['image_url']) ?>" alt="Current image for <?= e($product['name']) ?>"></div>
                        <label>Upload replacement image<input type="file" name="image" accept="image/jpeg,image/png,image/webp"><small>JPEG, PNG, or WebP; maximum 5 MB.</small></label>
                        <label class="details-wide">Or HTTPS image URL<input type="url" name="image_url" maxlength="500" value="<?= str_starts_with($product['image_url'], 'https://') ? e($product['image_url']) : '' ?>" placeholder="Leave blank to keep the current image"></label>
                        <div class="product-detail-actions"><button class="button primary" type="submit">Save product details</button></div>
                    </form>
                    <form class="delete-product-form" method="post" action="<?= e($config['base_url']) ?>/admin_inventory.php" onsubmit="return confirm('Delete this product? Past order records will remain.');">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete_product">
                        <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                        <button class="danger-button" type="submit">Delete product</button>
                    </form>
                </details>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
    <section class="admin-panel add-product-panel">
        <div class="admin-panel-heading"><h2>Add product</h2><span>New products are published immediately.</span></div>
        <form class="add-product-form" method="post" enctype="multipart/form-data" action="<?= e($config['base_url']) ?>/admin_inventory.php">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create">
            <label>Product name<input name="name" maxlength="190" required></label>
            <label>Category<input name="category" maxlength="100" placeholder="Electronics" required></label>
            <label>Price (UGX)<input type="number" name="price" min="1" step="1" required></label>
            <label>Starting stock<input type="number" name="stock_quantity" min="0" step="1" required></label>
            <label class="add-product-image">Description<textarea name="description" maxlength="5000" rows="3"></textarea></label>
            <label>Product image<input type="file" name="image" accept="image/jpeg,image/png,image/webp"><small>JPEG, PNG, or WebP; maximum 5 MB.</small></label>
            <label>Or HTTPS image URL<input type="url" name="image_url" maxlength="500" placeholder="https://..."></label>
            <button class="button primary" type="submit">Add product</button>
        </form>
    </section>
</section>
<?php require ROOT_PATH . '/app/views/footer.php'; ?>