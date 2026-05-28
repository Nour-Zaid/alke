<?php
session_start();
include '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    $_SESSION['redirect_after_login'] = '/alke/pages/checkout.php';
    header("Location: /alke/pages/login.php");
    exit();
}

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$cartItems = [];
$totalPrice = 0;
$orderPlaced = false;
$errorMessage = '';

if (!empty($_SESSION['cart'])) {
    $productIds = array_map('intval', array_keys($_SESSION['cart']));
    if (!empty($productIds)) {
        $idsList = implode(',', $productIds);
        $result = $conn->query("SELECT id, name, price, image FROM products WHERE id IN ($idsList)");

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $productId = (int)$row['id'];
                $quantity = isset($_SESSION['cart'][$productId]) ? (int)$_SESSION['cart'][$productId] : 1;
                $quantity = max(1, $quantity);

                $dbImage = isset($row['image']) ? trim($row['image']) : '';
                $imagePath = '/alke/testblackshirt.jpeg';
                if (!empty($dbImage) && file_exists(__DIR__ . '/../assets/' . $dbImage)) {
                    $imagePath = '/alke/assets/' . $dbImage;
                }

                $subtotal = ((float)$row['price']) * $quantity;
                $totalPrice += $subtotal;

                $cartItems[] = [
                    'id' => $productId,
                    'name' => $row['name'],
                    'price' => (float)$row['price'],
                    'quantity' => $quantity,
                    'image_path' => $imagePath,
                    'subtotal' => $subtotal
                ];
            }
        }
    }
}

/*
  Simple payment hook placeholder for future gateway integration.
  You can replace this with gateway preparation/intent creation later.
*/
function preparePaymentPayload($orderId, $amount, $customerName, $customerEmail)
{
    return [
        'order_id' => $orderId,
        'amount' => $amount,
        'customer_name' => $customerName,
        'customer_email' => $customerEmail,
        'currency' => 'USD',
        'gateway_status' => 'not_configured'
    ];
}

// Step 1: Validate form fields and store in session for review
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['place_order'])) {
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $address = isset($_POST['address']) ? trim($_POST['address']) : '';
    $city = isset($_POST['city']) ? trim($_POST['city']) : '';
    $country = isset($_POST['country']) ? trim($_POST['country']) : '';
    $postalCode = isset($_POST['postal_code']) ? trim($_POST['postal_code']) : '';

    $_SESSION['user_phone'] = $phone;

    if (empty($cartItems)) {
        $errorMessage = 'Your cart is empty.';
    } elseif ($name === '' || $email === '' || $phone === '' || $address === '' || $city === '' || $country === '' || $postalCode === '') {
        $errorMessage = 'Please fill all checkout details.';
    } else {
        $_SESSION['pending_order'] = compact('name', 'email', 'phone', 'address', 'city', 'country', 'postalCode');
        $orderPlaced = true;
    }
}

// Step 2: User confirmed — insert order into DB and redirect to success
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['confirm_order'])) {
    if (empty($cartItems) || empty($_SESSION['pending_order'])) {
        $errorMessage = 'Session expired. Please fill checkout details again.';
    } else {
        $status = 'pending';
        $user_id = (int)$_SESSION['user_id'];
        $stmtOrder = $conn->prepare("INSERT INTO orders (user_id, total_price, status) VALUES (?, ?, ?)");

        if (!$stmtOrder) {
            $errorMessage = 'Order prepare failed: ' . $conn->error;
        } else {
            $stmtOrder->bind_param("ids", $user_id, $totalPrice, $status);

            if ($stmtOrder->execute()) {
                $order_id = (int)$conn->insert_id;

                $stmtItem = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity) VALUES (?, ?, ?)");
                if (!$stmtItem) {
                    $errorMessage = 'Order item prepare failed: ' . $conn->error;
                } else {
                    $itemsSaved = true;

                    foreach ($cartItems as $item) {
                        $pid = (int)$item['id'];
                        $qty = (int)$item['quantity'];

                        $stmtItem->bind_param("iii", $order_id, $pid, $qty);
                        if (!$stmtItem->execute()) {
                            $itemsSaved = false;
                            $errorMessage = 'Order item insert failed: ' . $stmtItem->error;
                            break;
                        }
                    }

                    $stmtItem->close();

                    if ($itemsSaved) {
                        $_SESSION['cart'] = [];
                        unset($_SESSION['pending_order']);
                        header("Location: order_success.php?id=" . $order_id);
                        exit();
                    }
                }
            } else {
                $errorMessage = 'Order insert failed: ' . $stmtOrder->error;
            }

            $stmtOrder->close();
        }
    }
}

include '../includes/header.php';
?>

<main class="products-page">
  <section class="section products-section">
    <div class="container">
      <div class="section-title">
        <h2>Checkout</h2>
        <p>Complete your order</p>
      </div>

      <?php if ($orderPlaced): ?>
        <div class="checkout-card" style="max-width: 800px; margin: 0 auto;">
          <h3 class="checkout-card-title">Review Your Order</h3>
          <p class="checkout-card-subtitle">Please confirm your order details before placing it.</p>

          <div class="checkout-summary-list" style="margin-top: 20px;">
            <?php foreach ($cartItems as $item): ?>
              <div class="checkout-summary-item">
                <div class="checkout-item-left">
                  <img src="<?php echo htmlspecialchars($item['image_path']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" class="checkout-item-thumb">
                  <div>
                    <p class="checkout-item-name"><?php echo htmlspecialchars($item['name']); ?></p>
                    <p class="checkout-item-meta">Qty: <?php echo (int)$item['quantity']; ?> × $<?php echo number_format((float)$item['price'], 2); ?></p>
                  </div>
                </div>
                <p class="checkout-item-subtotal">$<?php echo number_format((float)$item['subtotal'], 2); ?></p>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="checkout-total">
            <span>Total</span>
            <strong>$<?php echo number_format((float)$totalPrice, 2); ?></strong>
          </div>

          <form method="POST" action="/alke/pages/checkout.php" class="checkout-actions" style="margin-top: 20px;">
            <input type="hidden" name="confirm_order" value="1">
            <button type="submit" class="btn">Confirm Order</button>
            <a href="/alke/pages/checkout.php" class="btn checkout-secondary-btn">Edit Order</a>
          </form>
        </div>
      <?php else: ?>
        <?php if (!empty($errorMessage)): ?>
          <div class="checkout-alert">
            <p><?php echo htmlspecialchars($errorMessage); ?></p>
          </div>
        <?php endif; ?>

        <?php if (!empty($cartItems)): ?>
          <div class="checkout-layout">
            <div class="checkout-card">
              <h3 class="checkout-card-title">Customer Details</h3>
              <p class="checkout-card-subtitle">Enter your information to place the order.</p>

              <form method="POST" action="/alke/pages/checkout.php" class="checkout-form">
                <div class="checkout-field">
                  <label for="checkoutName">Name</label>
                  <input type="text" id="checkoutName" name="name" value="<?php echo isset($_SESSION['user_name']) ? htmlspecialchars($_SESSION['user_name']) : ''; ?>" required>
                </div>

                <div class="checkout-field">
                  <label for="checkoutEmail">Email</label>
                  <input type="email" id="checkoutEmail" name="email" required>
                </div>

                <div class="checkout-field">
                  <label for="checkoutPhone">Phone Number</label>
                  <input type="text" id="checkoutPhone" name="phone" value="<?php echo isset($_SESSION['user_phone']) ? htmlspecialchars($_SESSION['user_phone']) : ''; ?>" required>
                </div>

                <div class="checkout-field">
                  <label for="checkoutAddress">Address</label>
                  <input type="text" id="checkoutAddress" name="address" required>
                </div>

                <div class="checkout-field">
                  <label for="checkoutCity">City</label>
                  <input type="text" id="checkoutCity" name="city" required>
                </div>

                <div class="checkout-field">
                  <label for="checkoutCountry">Country</label>
                  <input type="text" id="checkoutCountry" name="country" required>
                </div>

                <div class="checkout-field">
                  <label for="checkoutPostal">Postal Code</label>
                  <input type="text" id="checkoutPostal" name="postal_code" required>
                </div>

                <div class="checkout-actions">
                  <button type="submit" name="place_order" class="btn">Place Order</button>
                  <a href="/alke/pages/cart.php" class="btn checkout-secondary-btn">Back to Cart</a>
                </div>
              </form>
            </div>

            <div class="checkout-card">
              <h3 class="checkout-card-title">Order Summary</h3>
              <div class="checkout-summary-list">
                <?php foreach ($cartItems as $item): ?>
                  <div class="checkout-summary-item">
                    <div class="checkout-item-left">
                      <img src="<?php echo htmlspecialchars($item['image_path']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" class="checkout-item-thumb">
                      <div>
                        <p class="checkout-item-name"><?php echo htmlspecialchars($item['name']); ?></p>
                        <p class="checkout-item-meta">Qty: <?php echo (int)$item['quantity']; ?> × $<?php echo number_format((float)$item['price'], 2); ?></p>
                      </div>
                    </div>
                    <p class="checkout-item-subtotal">$<?php echo number_format((float)$item['subtotal'], 2); ?></p>
                  </div>
                <?php endforeach; ?>
              </div>

              <div class="checkout-total">
                <span>Total</span>
                <strong>$<?php echo number_format((float)$totalPrice, 2); ?></strong>
              </div>
            </div>
          </div>
        <?php else: ?>
          <p class="no-products">Your cart is empty. Add products before checkout.</p>
          <div class="checkout-empty-action">
            <a href="/alke/pages/products.php" class="btn">Go to Shop</a>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </section>
</main>

<?php include '../includes/footer.php'; ?>
