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

$product = null;
$productId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($productId > 0) {
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $product = $result->fetch_assoc();
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
              Premium fashion essential crafted for everyday comfort and timeless style.
              This is a placeholder product description that can be replaced with real product details later.
            </p>

            <div class="product-details-meta">
              <p><strong>Product ID:</strong> <?php echo (int)$product['id']; ?></p>
              <p><strong>Availability:</strong> In Stock</p>
            </div>

            <div class="product-options">
              <div class="option-group">
                <label for="productColor">Color</label>
                <select id="productColor" name="productColor">
                  <option value="black">Black</option>
                  <option value="white">White</option>
                  <option value="gray">Gray</option>
                  <option value="beige">Beige</option>
                </select>
              </div>

              <div class="option-group">
                <label for="productSize">Size</label>
                <select id="productSize" name="productSize">
                  <option value="s">Small (S)</option>
                  <option value="m" selected>Medium (M)</option>
                  <option value="l">Large (L)</option>
                  <option value="xl">Extra Large (XL)</option>
                </select>
              </div>

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
