<?php
session_start();
include '../config/db.php';

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
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
                <form method="POST" action="/alke/pages/products.php" class="add-to-cart-form" data-product-id="<?php echo (int)$row['id']; ?>">
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

<div id="toast-notification" class="toast-notification" role="status" aria-live="polite" aria-atomic="true">
  <p id="toast-message">Product added to cart!</p>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const forms = document.querySelectorAll('.add-to-cart-form');
  const toast = document.getElementById('toast-notification');
  const toastMessage = document.getElementById('toast-message');
  let toastTimer = null;

  function updateCartBadge(count) {
    const badge = document.querySelector('.cart-count-badge');
    const toggleBtn = document.getElementById('cartDrawerToggle');
    if (badge) {
      if (Number(count) > 0) {
        badge.textContent = count;
      } else {
        badge.remove();
      }
    } else if (toggleBtn && Number(count) > 0) {
      const span = document.createElement('span');
      span.className = 'cart-count-badge';
      span.textContent = count;
      toggleBtn.appendChild(span);
    }
  }

  function showToast(message, isError) {
    if (!toast || !toastMessage) return;

    toastMessage.textContent = message;
    toast.classList.remove('toast-show', 'toast-hide', 'toast-success', 'toast-error');
    toast.classList.add(isError ? 'toast-error' : 'toast-success');
    toast.classList.add('toast-show');

    if (toastTimer) {
      clearTimeout(toastTimer);
    }

    toastTimer = setTimeout(function () {
      toast.classList.remove('toast-show');
      toast.classList.add('toast-hide');
    }, 3000);
  }

  forms.forEach(function(form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();

      const productId = form.getAttribute('data-product-id');
      const formData = new FormData();
      formData.append('action', 'add');
      formData.append('product_id', productId);
      formData.append('quantity', '1');

      fetch('/alke/pages/update_cart.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data && data.success) {
          updateCartBadge(data.cart_count);
          if (window.refreshMiniCart && data.mini_cart) {
            window.refreshMiniCart(data.mini_cart);
          }
          showToast('✓ Product added to cart!', false);
        } else {
          showToast('Could not add item to cart.', true);
        }
      })
      .catch(() => {
        showToast('Request failed. Please try again.', true);
      });
    });
  });
});
</script>

<?php include '../includes/footer.php'; ?>
