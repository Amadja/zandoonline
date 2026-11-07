<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once ROOT_PATH . '/app/cart.php';
$pdo = db();
$pageTitle = 'Discover great finds';
$searchQuery = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$searchQuery = mb_substr($searchQuery, 0, 100);
$stmt = $pdo->prepare("SELECT id, name, description, category, price, image_url, stock_quantity FROM products WHERE status = 'active' AND stock_quantity > 0 AND (name LIKE :name_term OR category LIKE :category_term) ORDER BY created_at DESC LIMIT 20");
$stmt->execute(['name_term' => '%' . $searchQuery . '%', 'category_term' => '%' . $searchQuery . '%']);
$pageProducts = $stmt->fetchAll();
$categories = $pdo->query("SELECT DISTINCT category FROM products WHERE status = 'active' AND stock_quantity > 0 ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
array_unshift($categories, 'All items');
require ROOT_PATH . '/app/views/header.php';
?>
<section class="market-hero">
    <div class="market-hero-copy">
        <p class="market-kicker">A GOOD DAY TO DISCOVER</p>
        <h1>Find your next<br><span>favorite thing.</span></h1>
        <p>Fresh picks from independent sellers, all in one place.</p>
        <form class="market-search" method="get" action="<?= e($config['base_url']) ?>/index.php" role="search">
            <label class="visually-hidden" for="market-search">Search products and categories</label>
            <input id="market-search" type="search" name="q" placeholder="Search phones, fashion, home..." value="<?= e($searchQuery) ?>">
            <button type="submit">Search</button>
        </form>
        <a class="market-browse-link" href="#products">Explore today's picks <span aria-hidden="true">&#8595;</span></a>
    </div>
    <div class="market-hero-image">
        <img src="https://images.unsplash.com/photo-1441986300917-64674bd600d8?auto=format&fit=crop&w=1200&q=85" alt="A bright clothing and lifestyle store">
        <div class="market-image-label"><span>GOOD FINDS,</span><strong>close to home.</strong></div>
    </div>
</section>
<section class="category-section" aria-label="Shop by category">
    <div class="section-heading compact-heading"><div><p class="market-kicker">A LITTLE BIT OF EVERYTHING</p><h2>Shop by category</h2></div></div>
    <div class="category-list">
        <?php foreach ($categories as $index => $category): ?>
            <button class="category-button<?= $index === 0 ? ' selected' : '' ?>" type="button" data-category="<?= e($category) ?>">
                <span class="category-mark mark-<?= $index ?>" aria-hidden="true"><?= e($index === 0 ? 'All' : strtoupper(substr($category, 0, 1))) ?></span>
                <span><?= e($category) ?></span>
            </button>
        <?php endforeach; ?>
    </div>
</section>
<section class="product-section" id="products">
    <div class="section-heading product-heading">
        <div><p class="market-kicker">HANDPICKED FOR YOU</p><h2><?= $searchQuery !== '' ? 'Search results' : "Today's good finds" ?></h2></div>
        <a class="cart-button" href="<?= e($config['base_url']) ?>/cart.php">Your cart <span><?= get_cart_count() ?></span></a>
    </div>
    <div class="product-grid" id="product-grid">
        <?php foreach ($pageProducts as $product): ?>
            <article class="product-card" data-category="<?= e($product['category']) ?>" data-name="<?= e(strtolower($product['name'] . ' ' . $product['category'])) ?>">
                <div class="product-image-wrap">
                    <img src="<?= e($product['image_url']) ?>" alt="<?= e($product['name']) ?>" loading="lazy">
                    <form class="add-to-cart-form" method="post" action="<?= e($config['base_url']) ?>/cart.php">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                        <input type="hidden" name="quantity" value="1">
                        <button class="quick-add" type="submit" aria-label="Add <?= e($product['name']) ?> to cart">+</button>
                    </form>
                </div>
                <div class="product-details">
                    <p class="product-category"><?= e($product['category']) ?></p>
                    <h3><?= e($product['name']) ?></h3>
                    <?php if ($product['description']): ?><p class="product-description"><?= e($product['description']) ?></p><?php endif; ?>
                    <p class="product-rating"><span aria-hidden="true">&#9733;</span> 4.7 <span class="review-count">(36)</span></p>
                    <div class="product-price"><strong>UGX <?= number_format((int) $product['price']) ?></strong></div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <p class="empty-products" id="empty-products"<?= count($pageProducts) > 0 ? ' hidden' : '' ?>>No products match that search yet.</p>
</section>
<script>
const productCards = [...document.querySelectorAll('.product-card')];
const categoryButtons = [...document.querySelectorAll('.category-button')];
const searchInput = document.getElementById('market-search');
let activeCategory = 'All items';

function filterProducts() {
    const query = searchInput.value.trim().toLowerCase();
    let visibleCount = 0;
    productCards.forEach(card => {
        const matchesCategory = activeCategory === 'All items' || card.dataset.category === activeCategory;
        const matchesQuery = card.dataset.name.includes(query);
        card.hidden = !matchesCategory || !matchesQuery;
        if (!card.hidden) visibleCount++;
    });
    document.getElementById('empty-products').hidden = visibleCount > 0;
}

categoryButtons.forEach(button => button.addEventListener('click', () => {
    activeCategory = button.dataset.category;
    categoryButtons.forEach(item => item.classList.toggle('selected', item === button));
    filterProducts();
}));
searchInput.addEventListener('input', filterProducts);

filterProducts();
</script>
<section class="hero">
    <div>
        <p class="eyebrow">WELCOME TO ZANDOONLINE</p>
        <h1>Everything you need.<br><span>All in one place.</span></h1>
        <p class="hero-copy">Discover products from independent sellers and shop conveniently from one marketplace.</p>
        <div class="hero-actions">
            <a class="button primary" href="<?= e($config['base_url']) ?>/register.php">Join Zandoonline</a>
            <a class="button secondary" href="<?= e($config['base_url']) ?>/login.php">Sign in</a>
        </div>
    </div>
    <div class="hero-art" aria-hidden="true">
        <div class="art-circle"></div>
        <div class="art-card">Z<br><small>SHOP</small></div>
        <div class="art-tag">Discover more</div>
    </div>
</section>
<section class="intro-grid">
    <article class="info-card"><div class="info-icon">🛍️</div><h2>Shop in one place</h2><p>Explore products from different independent sellers.</p></article>
    <article class="info-card"><div class="info-icon">🏪</div><h2>Sell with us</h2><p>Create a seller account and prepare your store for review.</p></article>
    <article class="info-card"><div class="info-icon">🔒</div><h2>Built with security</h2><p>Account passwords are securely hashed and forms use CSRF protection.</p></article>
</section>
<?php require ROOT_PATH . '/app/views/footer.php'; ?>
