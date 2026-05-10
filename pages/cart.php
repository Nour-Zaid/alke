<?php
session_start();
include '../config/db.php';
include '../includes/header.php';

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['remove_item'])) {
        $removeId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
        if ($removeId > 0 && isset($_SESSION['cart'][$removeId])) {
            unset($_SESSION['cart'][$removeId]);
        }
        header('Location: /alke/pages/cart.php');
        exit;
    }

    if (isset($_POST['increase_qty'])) {
        $updateId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
        if ($updateId > 0 && isset($_SESSION['cart'][$updateId])) {
            $_SESSION['cart'][$updateId] += 1;
        }
        header('Location: /alke/pages/cart.php');
        exit;
    }

    if (isset($_POST['decrease_qty'])) {
        $updateId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
        if ($updateId > 0 && isset($_SESSION['cart'][$updateId])) {
            $currentQty = (int)$_SESSION['cart'][$updateId];
            if ($currentQty <= 1) {
                unset($_SESSION['cart'][$updateId]);
            } else {
                $_SESSION['cart'][$updateId] = $currentQty - 1;
            }
        }
        header('Location: /alke/pages/cart.php');
        exit;
    }

    if (isset($_POST['clear_cart'])) {
        $_SESSION['cart'] = [];
        header('Location: /alke/pages/cart.php');
        exit;
    }
}

$cartItems = [];
$totalPrice = 0;

if (!empty($_SESSION['cart'])) {
    $productIds = array_keys($_SESSION['cart']);
    $safeIds = array_map('intval', $productIds);

    if (!empty($safeIds)) {
        $idsList = implode(',', $safeIds);
        $query = "SELECT * FROM products WHERE id IN ($idsList)";
        $result = $conn->query($query);

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $productId = (int)$row['id'];
                $quantity = isset($_SESSION['cart'][$productId]) ? (int)$_SESSION['cart'][$productId] : 1;
                $price = (float)$row['price'];
                $subtotal = $price * $quantity;

                $dbImage = isset($row['image']) ? trim($row['image']) : '';
                $imagePath = '/alke/testblackshirt.jpeg';
                if (!empty($dbImage) && file_exists(__DIR__ . '/../assets/' . $dbImage)) {
                    $imagePath = '/alke/assets/' . $dbImage;
                }

                $row['image_path'] = $imagePath;
                $row['quantity'] = $quantity;
                $row['subtotal'] = $subtotal;

                $cartItems[] = $row;
                $totalPrice += $subtotal;
            }
        }
    }
}
?>

<main class="products-page">
  <section class="section products-section">
    <div class="container">
      <div class="section-title">
        <h2>Your Cart</h2>
        <p>Review your selected items</p>
      </div>

      <?php if (!empty($cartItems)): ?>
        <div class="cart-table-wrap">
          <table class="cart-table">
            <thead>
              <tr>
                <th>Product</th>
                <th>Name</th>
                <th>Price</th>
                <th>Qty</th>
                <th>Subtotal</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($cartItems as $item): ?>
                <tr>
                  <td>
                    <a href="/alke/pages/product.php?id=<?php echo (int)$item['id']; ?>" class="cart-thumb-link" aria-label="View <?php echo htmlspecialchars($item['name']); ?> details">
                      <img
                        src="<?php echo htmlspecialchars($item['image_path']); ?>"
                        alt="<?php echo htmlspecialchars($item['name']); ?>"
                        class="cart-thumb"
                      >
                    </a>
                  </td>
                  <td><?php echo htmlspecialchars($item['name']); ?></td>
                  <td>$<?php echo number_format((float)$item['price'], 2); ?></td>
                  <td>
                    <div class="qty-control">
                      <form method="POST" action="/alke/pages/cart.php">
                        <input type="hidden" name="product_id" value="<?php echo (int)$item['id']; ?>">
                        <button type="submit" name="decrease_qty" class="qty-btn">−</button>
                      </form>
                      <span class="qty-value"><?php echo (int)$item['quantity']; ?></span>
                      <form method="POST" action="/alke/pages/cart.php">
                        <input type="hidden" name="product_id" value="<?php echo (int)$item['id']; ?>">
                        <button type="submit" name="increase_qty" class="qty-btn">+</button>
                      </form>
                    </div>
                  </td>
                  <td>$<?php echo number_format((float)$item['subtotal'], 2); ?></td>
                  <td>
                    <form method="POST" action="/alke/pages/cart.php">
                      <input type="hidden" name="product_id" value="<?php echo (int)$item['id']; ?>">
                      <button type="submit" name="remove_item" class="btn product-btn">Remove</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="cart-summary">
          <h3>Total: $<?php echo number_format((float)$totalPrice, 2); ?></h3>

          <div class="product-details-actions">
            <form method="POST" action="/alke/pages/cart.php">
              <button type="submit" name="clear_cart" class="btn product-btn">Clear Cart</button>
            </form>
            <a href="/alke/pages/products.php" class="btn">Continue Shopping</a>
            <a href="/alke/pages/checkout.php" class="btn">Proceed to Checkout</a>
          </div>
        </div>
      <?php else: ?>
        <p class="no-products">Your cart is empty. Add products from the shop page.</p>
        <div style="text-align:center; margin-top: 16px;">
          <a href="/alke/pages/products.php" class="btn">Go to Shop</a>
        </div>
      <?php endif; ?>
    </div>
  </section>
</main>

<?php include '../includes/footer.php'; ?>
