<?php
include 'config/db.php';
include 'includes/header.php';

/* Featured: prefer in-stock items, newest first */
$featuredResult = $conn->query("SELECT * FROM products ORDER BY (stock > 0) DESC, id DESC LIMIT 4");

/* Categories with product counts */
$homeCategories = [];
$catResult = $conn->query("
    SELECT c.id, c.name, COUNT(p.id) AS product_count
    FROM categories c
    LEFT JOIN products p ON p.category_id = c.id
    GROUP BY c.id, c.name
    ORDER BY c.name ASC
");
if ($catResult) {
    while ($c = $catResult->fetch_assoc()) {
        $homeCategories[] = $c;
    }
}
?>

<main>
  <section class="hero">
    <div class="container">
      <div class="hero-content">
        <h1>New Collection</h1>
        <p>Discover modern premium essentials curated for every season and every style.</p>
        <a href="/alke/pages/products.php" class="btn">Shop Now</a>
      </div>
    </div>
  </section>

  <?php if (!empty($homeCategories)): ?>
  <section class="section">
    <div class="container">
      <div class="section-title">
        <h2>Shop by Category</h2>
        <p>Find what you're looking for</p>
      </div>

      <div class="category-grid">
        <?php foreach ($homeCategories as $cat): ?>
          <a href="/alke/pages/products.php?category=<?php echo (int)$cat['id']; ?>" class="category-card">
            <h3><?php echo alke_esc($cat['name']); ?></h3>
            <p><?php echo (int)$cat['product_count']; ?> item<?php echo (int)$cat['product_count'] === 1 ? '' : 's'; ?></p>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <section class="section">
    <div class="container">
      <div class="section-title">
        <h2>Featured Products</h2>
        <p>Handpicked essentials from Alke Clothes</p>
      </div>

      <?php if ($featuredResult && $featuredResult->num_rows > 0): ?>
        <div class="products-grid">
          <?php while ($row = $featuredResult->fetch_assoc()): ?>
            <article class="product-card">
              <a href="/alke/pages/product.php?id=<?php echo (int)$row['id']; ?>" class="product-card-link">
                <div class="product-image-wrap">
                  <img
                    src="<?php echo alke_esc(alke_product_image($row)); ?>"
                    alt="<?php echo alke_esc($row['name']); ?>"
                    class="product-image"
                    loading="lazy"
                  >
                  <?php if ((int)$row['stock'] <= 0): ?>
                    <span class="stock-badge out">Out of Stock</span>
                  <?php endif; ?>
                </div>
                <div class="product-body">
                  <h3 class="product-name"><?php echo alke_esc($row['name']); ?></h3>
                  <p class="product-price">$<?php echo number_format((float)$row['price'], 2); ?></p>
                </div>
              </a>
            </article>
          <?php endwhile; ?>
        </div>

        <div style="text-align:center; margin-top:36px;">
          <a href="/alke/pages/products.php" class="btn">View All Products</a>
        </div>
      <?php else: ?>
        <p class="no-products">No featured products available right now.</p>
      <?php endif; ?>
    </div>
  </section>
</main>

<?php include 'includes/footer.php'; ?>
