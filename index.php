<?php
include 'config/db.php';
include 'includes/header.php';

$featuredResult = $conn->query("SELECT * FROM products LIMIT 4");
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
              <a href="/alke/pages/product.php?id=<?php echo (int)$row['id']; ?>">
                <img
                  src="/alke/testblackshirt.jpeg"
                  alt="<?php echo htmlspecialchars($row['name']); ?>"
                  class="product-image"
                >
              </a>
              <div class="product-body">
                <h3 class="product-name">
                  <a href="/alke/pages/product.php?id=<?php echo (int)$row['id']; ?>">
                    <?php echo htmlspecialchars($row['name']); ?>
                  </a>
                </h3>
                <p class="product-price">$<?php echo number_format((float)$row['price'], 2); ?></p>
              </div>
            </article>
          <?php endwhile; ?>
        </div>
      <?php else: ?>
        <p class="no-products">No featured products available right now.</p>
      <?php endif; ?>
    </div>
  </section>
</main>

<?php include 'includes/footer.php'; ?>
