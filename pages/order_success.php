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
                      Qty: <?php echo (int)$item['quantity']; ?> × $<?php echo number_format((float)$item['price'], 2); ?>
                    </p>
                  </div>
                </div>
                <p class="checkout-item-subtotal">
                  $<?php echo number_format(((float)$item['price']) * ((int)$item['quantity']), 2); ?>
                </p>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="checkout-total">
            <span>Total</span>
            <strong>$<?php echo number_format((float)$order['total_price'], 2); ?></strong>
          </div>

          <p class="checkout-review-payment">
            <span>Payment Method</span>
            <strong><?php echo htmlspecialchars($orderPaymentLabel); ?></strong>
          </p>

          <?php if ($orderPayment === 'cliq'): ?>
            <div class="cliq-instructions">
              <h4>Complete your CLIQ payment</h4>
              <p>Please send <strong>$<?php echo number_format((float)$order['total_price'], 2); ?></strong> via CLIQ to:</p>
              <p class="cliq-alias"><?php echo htmlspecialchars($cliqAlias); ?></p>
              <p>Use <strong>Order #<?php echo (int)$order['id']; ?></strong> as the payment reference. Your order will be processed once payment is confirmed.</p>
            </div>
          <?php else: ?>
            <div class="cliq-instructions cod-note">
              <p>You chose <strong>Cash on Delivery</strong> — please have <strong>$<?php echo number_format((float)$order['total_price'], 2); ?></strong> ready when your order arrives.</p>
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
