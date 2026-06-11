<?php
require_once __DIR__ . '/includes/auth.php';
include __DIR__ . '/../config/db.php';

$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($userId <= 0) {
    header('Location: /alke/admin/users.php');
    exit;
}

// Fetch user info
$stmt = $conn->prepare("SELECT id, name, email FROM users WHERE id = ?");
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    header('Location: /alke/admin/users.php');
    exit;
}

// Fetch all orders for this user
$stmt = $conn->prepare("
    SELECT id, total_price, status, created_at
    FROM orders
    WHERE user_id = ?
    ORDER BY created_at DESC
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$orders = $stmt->get_result();
$stmt->close();

// Fetch all order items for this user's orders in one query
$itemsStmt = $conn->prepare("
    SELECT oi.order_id, p.name AS product_name, p.price, oi.quantity
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    WHERE oi.order_id IN (
        SELECT id FROM orders WHERE user_id = ?
    )
    ORDER BY oi.order_id DESC
");
$itemsStmt->bind_param('i', $userId);
$itemsStmt->execute();
$itemsResult = $itemsStmt->get_result();
$itemsStmt->close();

// Group items by order_id
$itemsByOrder = [];
while ($row = $itemsResult->fetch_assoc()) {
    $itemsByOrder[$row['order_id']][] = $row;
}

$pageTitle  = htmlspecialchars($user['name']) . "'s Orders";
$activePage = 'users';

include __DIR__ . '/includes/header.php';
?>

<!-- Back link + user summary -->
<div style="margin-bottom: 20px;">
  <a href="/alke/admin/users.php" class="btn btn-sm btn-outline">← Back to Users</a>
</div>

<div class="admin-section" style="margin-bottom: 24px;">
  <div class="admin-section-body" style="display:flex; gap: 32px; flex-wrap: wrap;">
    <div>
      <div style="font-size:0.75rem; color:#999; text-transform:uppercase; letter-spacing:.05em; margin-bottom:4px;">Customer</div>
      <div style="font-size:1.1rem; font-weight:700;"><?= htmlspecialchars($user['name']) ?></div>
    </div>
    <div>
      <div style="font-size:0.75rem; color:#999; text-transform:uppercase; letter-spacing:.05em; margin-bottom:4px;">Email</div>
      <div><?= htmlspecialchars($user['email']) ?></div>
    </div>
    <div>
      <div style="font-size:0.75rem; color:#999; text-transform:uppercase; letter-spacing:.05em; margin-bottom:4px;">Total Orders</div>
      <div style="font-weight:700;"><?= $orders ? $orders->num_rows : 0 ?></div>
    </div>
  </div>
</div>

<!-- Orders list -->
<?php if ($orders && $orders->num_rows > 0): ?>
  <?php while ($order = $orders->fetch_assoc()):
    $oid   = (int)$order['id'];
    $items = $itemsByOrder[$oid] ?? [];
  ?>
    <div class="admin-section">
      <div class="admin-section-header">
        <div style="display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
          <span style="font-weight:700;">Order #<?= $oid ?></span>
          <span class="badge badge-<?= htmlspecialchars($order['status']) ?>"><?= htmlspecialchars($order['status']) ?></span>
          <span style="color:#888; font-size:0.82rem;"><?= date('F j, Y \a\t g:i A', strtotime($order['created_at'])) ?></span>
        </div>
        <span style="font-weight:700; font-size:1rem;">$<?= number_format((float)$order['total_price'], 2) ?></span>
      </div>

      <?php if (!empty($items)): ?>
        <table class="admin-table">
          <thead>
            <tr>
              <th>Product</th>
              <th>Unit Price</th>
              <th>Quantity</th>
              <th>Subtotal</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $item): ?>
              <tr>
                <td><?= htmlspecialchars($item['product_name']) ?></td>
                <td>$<?= number_format((float)$item['price'], 2) ?></td>
                <td><?= (int)$item['quantity'] ?></td>
                <td>$<?= number_format((float)$item['price'] * (int)$item['quantity'], 2) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <p class="empty-state">No items found for this order.</p>
      <?php endif; ?>
    </div>
  <?php endwhile; ?>
<?php else: ?>
  <div class="admin-section">
    <p class="empty-state">This user has no orders yet.</p>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
