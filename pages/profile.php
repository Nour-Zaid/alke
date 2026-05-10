<?php
session_start();
include '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /alke/pages/login.php");
    exit();
}

$userId = (int)$_SESSION['user_id'];
$user = null;
$userError = '';
$orders = [];
$orderItemsByOrder = [];

/* Fetch user info */
$phoneColumnExists = false;
$columnCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'phone'");
if ($columnCheck && $columnCheck->num_rows > 0) {
    $phoneColumnExists = true;
}

$userSql = $phoneColumnExists
    ? "SELECT name, email, phone FROM users WHERE id = ?"
    : "SELECT name, email FROM users WHERE id = ?";

$stmtUser = $conn->prepare($userSql);
if (!$stmtUser) {
    $userError = 'Profile load failed: ' . $conn->error;
} else {
    $stmtUser->bind_param("i", $userId);
    $stmtUser->execute();
    $userResult = $stmtUser->get_result();

    if ($userResult && $userResult->num_rows === 1) {
        $row = $userResult->fetch_assoc();
        if (is_array($row)) {
            $user = $row;
            if (!$phoneColumnExists) {
                $user['phone'] = '';
            }
            if (!isset($user['email']) || trim((string)$user['email']) === '') {
                $user['email'] = isset($_SESSION['user_email']) ? (string)$_SESSION['user_email'] : '';
            }
        } else {
            $userError = 'User data is invalid.';
        }
    } else {
        $userError = 'User not found.';
    }

    $stmtUser->close();
}

/* Fetch user orders */
if ($user && empty($userError)) {
    $stmtOrders = $conn->prepare("SELECT id, total_price, status, created_at FROM orders WHERE user_id = ? ORDER BY created_at DESC");
    if ($stmtOrders) {
        $stmtOrders->bind_param("i", $userId);
        $stmtOrders->execute();
        $ordersResult = $stmtOrders->get_result();

        if ($ordersResult) {
            while ($orderRow = $ordersResult->fetch_assoc()) {
                if (is_array($orderRow)) {
                    $orderId = (int)$orderRow['id'];
                    $orders[] = $orderRow;
                    $orderItemsByOrder[$orderId] = [];
                }
            }
        }

        $stmtOrders->close();
    }

    /* Optional: fetch order items + product names */
    if (!empty($orders)) {
        $stmtItems = $conn->prepare("
            SELECT oi.order_id, oi.quantity, p.name, p.price
            FROM order_items oi
            INNER JOIN products p ON oi.product_id = p.id
            INNER JOIN orders o ON o.id = oi.order_id
            WHERE o.user_id = ?
            ORDER BY oi.order_id DESC
        ");
        if ($stmtItems) {
            $stmtItems->bind_param("i", $userId);
            $stmtItems->execute();
            $itemsResult = $stmtItems->get_result();

            if ($itemsResult) {
                while ($itemRow = $itemsResult->fetch_assoc()) {
                    $oid = (int)$itemRow['order_id'];
                    if (!isset($orderItemsByOrder[$oid])) {
                        $orderItemsByOrder[$oid] = [];
                    }
                    $orderItemsByOrder[$oid][] = $itemRow;
                }
            }

            $stmtItems->close();
        }
    }
}

include '../includes/header.php';
?>

<main class="products-page">
  <section class="section products-section">
    <div class="container" style="max-width: 900px;">
      <div class="section-title">
        <h2>My Profile</h2>
        <p>View your account information</p>
      </div>

      <?php if (!empty($userError)): ?>
        <div class="checkout-alert">
          <p><?php echo htmlspecialchars($userError); ?></p>
        </div>
      <?php else: ?>
        <?php
          $displayName = 'Not available';
          $displayEmail = 'Not available';
          $displayPhone = 'Not available';

          if (is_array($user)) {
              if (isset($user['name']) && trim((string)$user['name']) !== '') {
                  $displayName = (string)$user['name'];
              } elseif (isset($_SESSION['user_name']) && trim((string)$_SESSION['user_name']) !== '') {
                  $displayName = (string)$_SESSION['user_name'];
              }

              if (isset($user['email']) && trim((string)$user['email']) !== '') {
                  $displayEmail = (string)$user['email'];
              } elseif (isset($_SESSION['user_email']) && trim((string)$_SESSION['user_email']) !== '') {
                  $displayEmail = (string)$_SESSION['user_email'];
              }

              if (isset($user['phone']) && trim((string)$user['phone']) !== '') {
                  $displayPhone = (string)$user['phone'];
              } elseif (isset($_SESSION['user_phone']) && trim((string)$_SESSION['user_phone']) !== '') {
                  $displayPhone = (string)$_SESSION['user_phone'];
              } elseif (isset($_POST['phone']) && trim((string)$_POST['phone']) !== '') {
                  $displayPhone = (string)$_POST['phone'];
              }
          } else {
              if (isset($_SESSION['user_name']) && trim((string)$_SESSION['user_name']) !== '') {
                  $displayName = (string)$_SESSION['user_name'];
              }
              if (isset($_SESSION['user_email']) && trim((string)$_SESSION['user_email']) !== '') {
                  $displayEmail = (string)$_SESSION['user_email'];
              }
              if (isset($_SESSION['user_phone']) && trim((string)$_SESSION['user_phone']) !== '') {
                  $displayPhone = (string)$_SESSION['user_phone'];
              }
          }
        ?>
        <div class="checkout-card profile-card" style="margin-bottom: 18px;">
          <h3 class="checkout-card-title profile-title">Account Details</h3>
          <div class="profile-grid">
            <div class="profile-item">
              <p class="profile-label">Name</p>
              <p class="profile-value"><?php echo htmlspecialchars($displayName); ?></p>
            </div>
            <div class="profile-item">
              <p class="profile-label">Email</p>
              <p class="profile-value"><?php echo htmlspecialchars($displayEmail); ?></p>
            </div>
            <div class="profile-item">
              <p class="profile-label">Phone</p>
              <p class="profile-value"><?php echo htmlspecialchars($displayPhone); ?></p>
            </div>
          </div>
        </div>

        <div class="checkout-card profile-card">
          <h3 class="checkout-card-title profile-title">My Orders</h3>

          <?php if (empty($orders)): ?>
            <p class="no-products" style="margin: 10px 0 0;">You have not placed any orders yet.</p>
          <?php else: ?>
            <div class="checkout-summary-list">
              <?php foreach ($orders as $order): ?>
                <?php $orderId = (int)$order['id']; ?>
                <div class="checkout-summary-item profile-order-item" style="display:block;">
                  <p><strong>Order ID:</strong> #<?php echo $orderId; ?></p>
                  <p><strong>Total:</strong> $<?php echo number_format((float)$order['total_price'], 2); ?></p>
                  <p><strong>Status:</strong> <?php echo htmlspecialchars((string)$order['status']); ?></p>
                  <p><strong>Date:</strong> <?php echo htmlspecialchars((string)$order['created_at']); ?></p>

                  <?php if (!empty($orderItemsByOrder[$orderId])): ?>
                    <div style="margin-top:10px;">
                      <p><strong>Items:</strong></p>
                      <?php foreach ($orderItemsByOrder[$orderId] as $item): ?>
                        <p style="margin-left:12px;">
                          - <?php echo htmlspecialchars((string)$item['name']); ?>
                          (Qty: <?php echo (int)$item['quantity']; ?>)
                          @ $<?php echo number_format((float)$item['price'], 2); ?>
                        </p>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>
</main>

<?php include '../includes/footer.php'; ?>
