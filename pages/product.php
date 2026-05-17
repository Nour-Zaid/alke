<?php
session_start();
include '../config/db.php';

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    $productIdPost = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    $quantityPost = isset($_POST['quantity']) ? max(1, (int)$_POST['quantity']) : 1;

    if ($productIdPost > 0) {
        if (isset($_SESSION['cart'][$productIdPost])) {
            $_SESSION['cart'][$productIdPost] += $quantityPost;
        } else {
            $_SESSION['cart'][$productIdPost] = $quantityPost;
        }
    }

    header('Location: /alke/pages/product.php?id=' . $productIdPost);
    exit;
}

include '../includes/header.php';

$product   = null;
$sizesArr  = [];
$colorsArr = [];
$productId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($productId > 0) {
    $stmt = $conn->prepare("
        SELECT p.*, c.name AS category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.id = ? LIMIT 1
    ");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $product   = $result->fetch_assoc();
        $sizesArr  = !empty($product['sizes'])  ? array_filter(array_map('trim', explode(',', $product['sizes'])))  : [];
        $colorsArr = !empty($product['colors']) ? array_filter(array_map('trim', explode(',', $product['colors']))) : [];
    }

    $stmt->close();
}
?>

<main class="product-details-page">
  <section class="section">
    <div class="container">
      <?php if ($product): ?>
        <div class="product-details-card">
          <div class="product-details-image-wrap">
            <?php
              $dbImage = isset($product['image']) ? trim($product['image']) : '';
              $imagePath = '/alke/testblackshirt.jpeg';
              if (!empty($dbImage) && file_exists(__DIR__ . '/../assets/' . $dbImage)) {
                  $imagePath = '/alke/assets/' . $dbImage;
              }
            ?>
            <img
              src="<?php echo htmlspecialchars($imagePath); ?>"
              alt="<?php echo htmlspecialchars($product['name']); ?>"
              class="product-details-image"
            >
          </div>

          <div class="product-details-content">
            <p class="product-details-label">Alke Clothes</p>
            <h1 class="product-details-title"><?php echo htmlspecialchars($product['name']); ?></h1>
            <p class="product-details-price">$<?php echo number_format((float)$product['price'], 2); ?></p>

            <p class="product-details-description">
              <?= !empty($product['description']) ? nl2br(htmlspecialchars($product['description'])) : 'Premium fashion essential crafted for everyday comfort and timeless style.' ?>
            </p>

            <div class="product-details-meta">
              <?php if (!empty($product['category_name'])): ?>
              <p><strong>Category:</strong> <?= htmlspecialchars($product['category_name']) ?></p>
              <?php endif; ?>
              <p><strong>Availability:</strong>
                <?php if ((int)$product['stock'] > 0): ?>
                  <span style="color:#16a34a; font-weight:600;">In Stock</span>
                <?php else: ?>
                  <span style="color:#dc3545; font-weight:600;">Out of Stock</span>
                <?php endif; ?>
              </p>
            </div>

            <div class="product-options">
              <?php if (!empty($colorsArr)): ?>
              <div class="option-group">
                <label for="productColor">Color</label>
                <select id="productColor" name="color">
                  <?php foreach ($colorsArr as $cl): ?>
                    <option value="<?= htmlspecialchars($cl) ?>"><?= htmlspecialchars($cl) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <?php endif; ?>

              <?php if (!empty($sizesArr)): ?>
              <div class="option-group">
                <label for="productSize">Size</label>
                <select id="productSize" name="size">
                  <?php foreach ($sizesArr as $sz): ?>
                    <option value="<?= htmlspecialchars($sz) ?>"><?= htmlspecialchars($sz) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <?php endif; ?>

              <div class="option-group">
                <label for="productQty">Quantity</label>
                <select id="productQty" name="productQty">
                  <option value="1" selected>1</option>
                  <option value="2">2</option>
                  <option value="3">3</option>
                  <option value="4">4</option>
                  <option value="5">5</option>
                </select>
              </div>
            </div>

            <div class="product-details-actions">
              <form method="POST" action="/alke/pages/product.php?id=<?php echo (int)$product['id']; ?>">
                <input type="hidden" name="product_id" value="<?php echo (int)$product['id']; ?>">
                <input type="hidden" name="quantity" id="selectedQty" value="1">
                <button type="submit" name="add_to_cart" class="btn">Add to Cart</button>
              </form>
              <a href="/alke/pages/products.php" class="btn">Back to Shop</a>
            </div>
          </div>
        </div>
      <?php else: ?>
        <div class="no-products">
          <h2>Product not found</h2>
          <p>The product you are looking for does not exist or was removed.</p>
          <a href="/alke/pages/products.php" class="btn">Go to Shop</a>
        </div>
      <?php endif; ?>
    </div>
  </section>
</main>

<?php include '../includes/footer.php'; ?>
