<?php
session_start();
include '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /alke/pages/login.php");
    exit();
}

include '../includes/header.php';

$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$userId = (int)$_SESSION['user_id'];
$order = null;
$orderItems = [];
$errorMessage = '';

if ($orderId <= 0) {
    $errorMessage = 'Invalid order ID';
} else {
    $stmtOrder = $conn->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
    $stmtOrder->bind_param("ii", $orderId, $userId);
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
        <div class="checkout-card" style="max-width: 800px; margin: 0 auto;">
          <h3 class="checkout-card-title">Thank you for your order</h3>
          <p><strong>Order ID:</strong> #<?php echo (int)$order['id']; ?></p>

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

          <div class="checkout-actions" style="margin-top: 20px;">
            <a href="/alke/pages/products.php" class="btn">Continue Shopping</a>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </section>
</main>

<?php include '../includes/footer.php'; ?>
