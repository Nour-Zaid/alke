<?php
session_start();
include '../config/db.php';

include '../includes/header.php';

$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$paymentMethods = [
    'cod'  => 'Cash on Delivery',
    'cliq' => 'Pay with CLIQ',
];

// CLIQ payee details — replace with your real CLIQ alias/number before going live.
$cliqAlias = 'ALKESTORE';

// Access control for guest checkout: a visitor may only view the order they
// just placed in this session. A logged-in user may view their own orders.
$isSessionOrder = isset($_SESSION['last_order_id']) && (int)$_SESSION['last_order_id'] === $orderId;
$isLoggedIn     = isset($_SESSION['user_id']);

$order = null;
$orderItems = [];
$errorMessage = '';

if ($orderId <= 0 || (!$isSessionOrder && !$isLoggedIn)) {
    $errorMessage = 'Invalid order ID';
} else {
    if ($isSessionOrder) {
        $stmtOrder = $conn->prepare("SELECT * FROM orders WHERE id = ?");
        $stmtOrder->bind_param("i", $orderId);
    } else {
        $userId = (int)$_SESSION['user_id'];
        $stmtOrder = $conn->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
        $stmtOrder->bind_param("ii", $orderId, $userId);
    }
    $stmtOrder->execute();
    $orderResult = $stmtOrder->get_result();

    if ($orderResult && $orderResult->num_rows > 0) {
        $order = $orderResult->fetch_assoc();

        $stmtItems = $conn->prepare("
            SELECT oi.quantity, p.name, p.price
            FROM order_items oi
            INNER JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = ?
        ");
        $stmtItems->bind_param("i", $orderId);
        $stmtItems->execute();
        $itemsResult = $stmtItems->get_result();

        if ($itemsResult) {
            while ($row = $itemsResult->fetch_assoc()) {
                $orderItems[] = $row;
            }
        }

        $stmtItems->close();
    } else {
        $errorMessage = 'Invalid order ID';
    }

    $stmtOrder->close();
}

/* Handle CLIQ payment-proof screenshot upload */
$proofMessage = '';
$proofError   = false;
if ($order && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['payment_proof'])) {
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $f = $_FILES['payment_proof'];

    if (!function_exists('alke_csrf_check') || !alke_csrf_check()) {
        $proofMessage = 'Your session expired. Please try again.'; $proofError = true;
    } elseif (!$isSessionOrder && !$isLoggedIn) {
        $proofMessage = 'You are not allowed to upload for this order.'; $proofError = true;
    } elseif ($f['error'] !== UPLOAD_ERR_OK) {
        $proofMessage = 'Upload failed. Please choose a file and try again.'; $proofError = true;
    } elseif ($f['size'] > 5 * 1024 * 1024) {
        $proofMessage = 'That image is too large (max 5 MB).'; $proofError = true;
    } else {
        $info = @getimagesize($f['tmp_name']);
        $mime = $info['mime'] ?? '';
        if (!isset($allowed[$mime])) {
            $proofMessage = 'Please upload an image (JPG, PNG or WebP).'; $proofError = true;
        } else {
            $dir = __DIR__ . '/../assets/uploads/proofs';
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            $fname = 'order_' . (int)$order['id'] . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
            if (move_uploaded_file($f['tmp_name'], $dir . '/' . $fname)) {
                $rel = 'uploads/proofs/' . $fname;
                $stmt = $conn->prepare("UPDATE orders SET payment_proof = ? WHERE id = ?");
                $oid = (int)$order['id'];
                $stmt->bind_param('si', $rel, $oid);
                $stmt->execute();
                $stmt->close();
                $order['payment_proof'] = $rel;
                $proofMessage = 'Payment screenshot received — thank you! We will confirm your order shortly.';
            } else {
                $proofMessage = 'Could not save the file. Please try again.'; $proofError = true;
            }
        }
    }
}
?>

<main class="products-page">
  <section class="section products-section">
    <div class="container">
      <div class="section-title">
        <h2>Order Confirmed</h2>
        <p>Thank you for your order</p>
      </div>

      <?php if (!empty($errorMessage)): ?>
        <div class="checkout-alert">
          <p><?php echo htmlspecialchars($errorMessage); ?></p>
        </div>
        <div class="checkout-empty-action">
          <a href="/alke/pages/products.php" class="btn">Go to Shop</a>
        </div>
      <?php else: ?>
        <?php
          $orderPayment = $order['payment_method'] ?? 'cod';
          $orderPaymentLabel = $paymentMethods[$orderPayment] ?? 'Cash on Delivery';
        ?>
        <div class="checkout-card" style="max-width: 800px; margin: 0 auto;">
          <div class="order-confirmed-badge">✓</div>
          <h3 class="checkout-card-title" style="text-align:center;">Thank you for your order</h3>
          <p style="text-align:center;">Your order has been placed successfully.</p>
          <p style="text-align:center;"><strong>Order ID:</strong> #<?php echo (int)$order['id']; ?></p>

          <div class="checkout-summary-list" style="margin-top: 20px;">
            <?php foreach ($orderItems as $item): ?>
              <div class="checkout-summary-item">
                <div class="checkout-item-left">
                  <div>
                    <p class="checkout-item-name"><?php echo htmlspecialchars($item['name']); ?></p>
                    <p class="checkout-item-meta">
                      Qty: <?php echo (int)$item['quantity']; ?> × JD <?php echo number_format((float)$item['price'], 2); ?>
                    </p>
                  </div>
                </div>
                <p class="checkout-item-subtotal">
                  JD <?php echo number_format(((float)$item['price']) * ((int)$item['quantity']), 2); ?>
                </p>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="checkout-total">
            <span>Total</span>
            <strong>JD <?php echo number_format((float)$order['total_price'], 2); ?></strong>
          </div>

          <p class="checkout-review-payment">
            <span>Payment Method</span>
            <strong><?php echo htmlspecialchars($orderPaymentLabel); ?></strong>
          </p>

          <?php if ($orderPayment === 'cliq'): ?>
            <div class="cliq-instructions">
              <h4>Complete your CLIQ payment</h4>
              <p>Please send <strong>JD <?php echo number_format((float)$order['total_price'], 2); ?></strong> via CLIQ to:</p>
              <p class="cliq-alias"><?php echo htmlspecialchars($cliqAlias); ?></p>
              <p>Use <strong>Order #<?php echo (int)$order['id']; ?></strong> as the payment reference. Your order will be processed once payment is confirmed.</p>

              <?php if (!empty($proofMessage)): ?>
                <p class="proof-message <?php echo $proofError ? 'is-error' : 'is-ok'; ?>">
                  <?php echo htmlspecialchars($proofMessage); ?>
                </p>
              <?php endif; ?>

              <?php if (!empty($order['payment_proof'])): ?>
                <div class="proof-uploaded">
                  <p><strong>✓ Screenshot uploaded.</strong> We'll verify your payment and confirm the order.</p>
                  <a href="/alke/assets/<?php echo htmlspecialchars($order['payment_proof']); ?>" target="_blank" rel="noopener">
                    <img src="/alke/assets/<?php echo htmlspecialchars($order['payment_proof']); ?>" alt="Your payment screenshot" class="proof-thumb">
                  </a>
                </div>
              <?php else: ?>
                <form method="POST" action="/alke/pages/order_success.php?id=<?php echo (int)$order['id']; ?>" enctype="multipart/form-data" class="proof-form">
                  <?php echo alke_csrf_field(); ?>
                  <label for="paymentProof"><strong>Upload your CLIQ payment screenshot</strong> so we can confirm it:</label>
                  <input type="file" id="paymentProof" name="payment_proof" accept="image/png,image/jpeg,image/webp" required>
                  <button type="submit" class="btn">Upload Screenshot</button>
                </form>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div class="cliq-instructions cod-note">
              <p>You chose <strong>Cash on Delivery</strong> — please have <strong>JD <?php echo number_format((float)$order['total_price'], 2); ?></strong> ready when your order arrives.</p>
            </div>
          <?php endif; ?>

          <div class="checkout-actions" style="margin-top: 20px;">
            <a href="/alke/pages/products.php" class="btn">Continue Shopping</a>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </section>
</main>

<?php include '../includes/footer.php'; ?>
