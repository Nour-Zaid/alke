<?php
session_start();
include '../config/db.php';
include '../includes/helpers.php';

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    $productIdPost = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    $quantityPost  = isset($_POST['quantity']) ? max(1, (int)$_POST['quantity']) : 1;

    if (alke_csrf_check() && $productIdPost > 0) {
        // Validate against stock before adding
        $stmtStock = $conn->prepare("SELECT stock FROM products WHERE id = ? LIMIT 1");
        if ($stmtStock) {
            $stmtStock->bind_param('i', $productIdPost);
            $stmtStock->execute();
            $resStock = $stmtStock->get_result();
            if ($resStock && ($rowStock = $resStock->fetch_assoc())) {
                $stock   = (int)$rowStock['stock'];
                $current = isset($_SESSION['cart'][$productIdPost]) ? (int)$_SESSION['cart'][$productIdPost] : 0;
                $newQty  = min($stock, $current + $quantityPost);

                if ($newQty > 0) {
                    $_SESSION['cart'][$productIdPost] = $newQty;
                }
            }
            $stmtStock->close();
        }
    }

    header('Location: /alke/pages/product.php?id=' . $productIdPost . '&added=1');
    exit;
}

include '../includes/header.php';

$product   = null;
$sizesArr  = [];
$colorsArr = [];
$related   = [];
$productId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$justAdded = isset($_GET['added']);

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

    // Related products: same category first, otherwise just other products
    if ($product) {
        $catId = (int)($product['category_id'] ?? 0);
        $relStmt = $conn->prepare("
            SELECT * FROM products
            WHERE id != ?
            ORDER BY (category_id = ?) DESC, RAND()
            LIMIT 4
        ");
        if ($relStmt) {
            $relStmt->bind_param('ii', $productId, $catId);
            $relStmt->execute();
            $relRes = $relStmt->get_result();
            if ($relRes) {
                while ($r = $relRes->fetch_assoc()) {
                    $related[] = $r;
                }
            }
            $relStmt->close();
        }
    }
}
?>

<main class="product-details-page">
  <section class="section">
    <div class="container">
      <?php if ($product): ?>
        <?php
          $stock     = (int)$product['stock'];
          $inStock   = $stock > 0;
          $maxQty    = min(10, max(1, $stock));
          $imagePath = alke_product_image($product);
        ?>

        <nav class="breadcrumbs" aria-label="Breadcrumb">
          <a href="/alke/index.php">Home</a>
          <span>›</span>
          <a href="/alke/pages/products.php">Shop</a>
          <?php if (!empty($product['category_name'])): ?>
            <span>›</span>
            <a href="/alke/pages/products.php?category=<?php echo (int)$product['category_id']; ?>">
              <?php echo alke_esc($product['category_name']); ?>
            </a>
          <?php endif; ?>
          <span>›</span>
          <span class="breadcrumb-current"><?php echo alke_esc($product['name']); ?></span>
        </nav>

        <?php if ($justAdded): ?>
          <div class="checkout-alert success-alert">
            <p>✓ Added to your cart. <a href="/alke/pages/cart.php">View cart</a></p>
          </div>
        <?php endif; ?>

        <div class="product-details-card">
          <div class="product-details-image-wrap">
            <img
              src="<?php echo alke_esc($imagePath); ?>"
              alt="<?php echo alke_esc($product['name']); ?>"
              class="product-details-image"
            >
          </div>

          <div class="product-details-content">
            <p class="product-details-label">Alke Clothes</p>
            <h1 class="product-details-title"><?php echo alke_esc($product['name']); ?></h1>
            <p class="product-details-price">$<?php echo number_format((float)$product['price'], 2); ?></p>

            <p class="product-details-description">
              <?= !empty($product['description']) ? nl2br(alke_esc($product['description'])) : 'Premium fashion essential crafted for everyday comfort and timeless style.' ?>
            </p>

            <div class="product-details-meta">
              <?php if (!empty($product['category_name'])): ?>
              <p><strong>Category:</strong> <?= alke_esc($product['category_name']) ?></p>
              <?php endif; ?>
              <p><strong>Availability:</strong>
                <?php if ($inStock && $stock <= 5): ?>
                  <span style="color:#d97706; font-weight:600;">Low stock — only <?php echo $stock; ?> left</span>
                <?php elseif ($inStock): ?>
                  <span style="color:#16a34a; font-weight:600;">In Stock</span>
                <?php else: ?>
                  <span style="color:#dc3545; font-weight:600;">Out of Stock</span>
                <?php endif; ?>
              </p>
            </div>

            <form method="POST" action="/alke/pages/product.php?id=<?php echo (int)$product['id']; ?>" class="product-buy-form">
              <?php echo alke_csrf_field(); ?>
              <input type="hidden" name="product_id" value="<?php echo (int)$product['id']; ?>">

              <div class="product-options">
                <?php if (!empty($colorsArr)): ?>
                <div class="option-group">
                  <label for="productColor">Color</label>
                  <select id="productColor" name="color">
                    <?php foreach ($colorsArr as $cl): ?>
                      <option value="<?= alke_esc($cl) ?>"><?= alke_esc($cl) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <?php endif; ?>

                <?php if (!empty($sizesArr)): ?>
                <div class="option-group">
                  <label for="productSize">Size</label>
                  <select id="productSize" name="size">
                    <?php foreach ($sizesArr as $sz): ?>
                      <option value="<?= alke_esc($sz) ?>"><?= alke_esc($sz) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <?php endif; ?>

                <div class="option-group">
                  <label for="productQty">Quantity</label>
                  <select id="productQty" name="quantity" <?php echo $inStock ? '' : 'disabled'; ?>>
                    <?php for ($q = 1; $q <= $maxQty; $q++): ?>
                      <option value="<?php echo $q; ?>"><?php echo $q; ?></option>
                    <?php endfor; ?>
                  </select>
                </div>
              </div>

              <div class="product-details-actions">
                <?php if ($inStock): ?>
                  <button type="submit" name="add_to_cart" class="btn">Add to Cart</button>
                <?php else: ?>
                  <button type="button" class="btn" disabled>Out of Stock</button>
                <?php endif; ?>
                <a href="/alke/pages/products.php" class="btn">Back to Shop</a>
              </div>
            </form>
          </div>
        </div>

        <?php if (!empty($related)): ?>
          <div class="section-title" style="margin-top:64px;">
            <h2>You May Also Like</h2>
          </div>
          <div class="products-grid">
            <?php foreach ($related as $rel): ?>
              <article class="product-card">
                <a href="/alke/pages/product.php?id=<?php echo (int)$rel['id']; ?>" class="product-card-link">
                  <div class="product-image-wrap">
                    <img
                      src="<?php echo alke_esc(alke_product_image($rel)); ?>"
                      alt="<?php echo alke_esc($rel['name']); ?>"
                      class="product-image"
                      loading="lazy"
                    >
                    <?php if ((int)$rel['stock'] <= 0): ?>
                      <span class="stock-badge out">Out of Stock</span>
                    <?php endif; ?>
                  </div>
                  <div class="product-body">
                    <h3 class="product-name"><?php echo alke_esc($rel['name']); ?></h3>
                    <p class="product-price">$<?php echo number_format((float)$rel['price'], 2); ?></p>
                  </div>
                </a>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
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
