<?php
session_start();
include '../config/db.php';

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    $productId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    $quantity = isset($_POST['quantity']) ? max(1, (int)$_POST['quantity']) : 1;

    if ($productId > 0) {
        if (isset($_SESSION['cart'][$productId])) {
            $_SESSION['cart'][$productId] += $quantity;
        } else {
            $_SESSION['cart'][$productId] = $quantity;
        }
    }

    header('Location: /alke/pages/products.php');
    exit;
}

include '../includes/header.php';
$result = $conn->query("SELECT * FROM products ORDER BY id DESC LIMIT 8");
?>

<main class="products-page">
  <section class="section products-section">
    <div class="container">
      <div class="section-title">
        <h2>Shop All Products</h2>
        <p>Discover our latest fashion essentials</p>
      </div>

      <?php if ($result && $result->num_rows > 0): ?>
        <div class="products-grid shop-grid">
          <?php while ($row = $result->fetch_assoc()): ?>
            <?php
              $dbImage = isset($row['image']) ? trim($row['image']) : '';
              $imagePath = '/alke/testblackshirt.jpeg';
              if (!empty($dbImage) && file_exists(__DIR__ . '/../assets/' . $dbImage)) {
                  $imagePath = '/alke/assets/' . $dbImage;
              }
            ?>
            <article class="product-card">
              <a href="product.php?id=<?php echo (int)$row['id']; ?>" class="product-card-link">
                <img
                  src="<?php echo htmlspecialchars($imagePath); ?>"
                  alt="<?php echo htmlspecialchars($row['name']); ?>"
                  class="product-image"
                >
                <div class="product-body">
                  <h3 class="product-name"><?php echo htmlspecialchars($row['name']); ?></h3>
                  <p class="product-price">$<?php echo number_format((float)$row['price'], 2); ?></p>
                </div>
              </a>

              <div class="product-card-actions">
                <form method="POST" action="/alke/pages/products.php">
                  <input type="hidden" name="product_id" value="<?php echo (int)$row['id']; ?>">
                  <input type="hidden" name="quantity" value="1">
                  <button type="submit" name="add_to_cart" class="btn product-btn">Add to Cart</button>
                </form>
              </div>
            </article>
          <?php endwhile; ?>
        </div>
      <?php else: ?>
        <p class="no-products">No products available right now. Please check back soon.</p>
      <?php endif; ?>
    </div>
  </section>
</main>

<?php include '../includes/footer.php'; ?>
