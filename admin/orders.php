<?php
require_once __DIR__ . '/includes/auth.php';
include __DIR__ . '/../config/db.php';

$pageTitle  = 'Orders';
$activePage = 'orders';

$message     = '';
$messageType = '';

$validStatuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];

/* ── Handle status update ───────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    $orderId   = (int)($_POST['order_id'] ?? 0);
    $newStatus = trim($_POST['status'] ?? '');

    if ($orderId > 0 && in_array($newStatus, $validStatuses)) {
        $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $stmt->bind_param('si', $newStatus, $orderId);
        $ok = $stmt->execute();
        $stmt->close();
        $message     = $ok ? "Order #$orderId updated to \"$newStatus\"." : 'Failed to update order.';
        $messageType = $ok ? 'success' : 'danger';
    }
}

/* ── Fetch all orders ───────────────────────────────── */
$orders = $conn->query("
    SELECT o.id, u.name AS customer, u.email,
           o.total_price, o.status, o.created_at,
           COUNT(oi.id) AS item_count
    FROM orders o
    JOIN users u ON o.user_id = u.id
    LEFT JOIN order_items oi ON oi.order_id = o.id
    GROUP BY o.id
    ORDER BY o.created_at DESC
");

include __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?>
  <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="admin-section">
  <div class="admin-section-header">
    <h2>All Orders (<?= $orders ? $orders->num_rows : 0 ?>)</h2>
  </div>

  <?php if ($orders && $orders->num_rows > 0): ?>
    <div style="overflow-x: auto;">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Order #</th>
            <th>Customer</th>
            <th>Email</th>
            <th>Items</th>
            <th>Total</th>
            <th>Status</th>
            <th>Date</th>
            <th>Update Status</th>
          </tr>
        </thead>
        <tbody>
          <?php while ($row = $orders->fetch_assoc()):
            $cnt = (int)$row['item_count'];
          ?>
            <tr>
              <td><strong>#<?= (int)$row['id'] ?></strong></td>
              <td><?= htmlspecialchars($row['customer']) ?></td>
              <td style="color:#888;"><?= htmlspecialchars($row['email']) ?></td>
              <td><?= $cnt ?> <?= $cnt === 1 ? 'item' : 'items' ?></td>
              <td>$<?= number_format((float)$row['total_price'], 2) ?></td>
              <td>
                <span class="badge badge-<?= htmlspecialchars($row['status']) ?>">
                  <?= htmlspecialchars($row['status']) ?>
                </span>
              </td>
              <td style="white-space:nowrap; color:#888;">
                <?= date('M j, Y', strtotime($row['created_at'])) ?>
              </td>
              <td>
                <form method="POST" class="status-form">
                  <input type="hidden" name="action"   value="update_status">
                  <input type="hidden" name="order_id" value="<?= (int)$row['id'] ?>">
                  <select name="status" class="form-control">
                    <?php foreach ($validStatuses as $s): ?>
                      <option value="<?= $s ?>" <?= $row['status'] === $s ? 'selected' : '' ?>>
                        <?= ucfirst($s) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn btn-sm btn-success">Save</button>
                </form>
              </td>
            </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p class="empty-state">No orders yet.</p>
  <?php endif; ?>
</div>

<!-- Order status guide -->
<div class="admin-section">
  <div class="admin-section-header"><h2>Status Guide</h2></div>
  <div class="admin-section-body">
    <table class="admin-table">
      <thead><tr><th>Status</th><th>Meaning</th></tr></thead>
      <tbody>
        <tr><td><span class="badge badge-pending">pending</span></td><td>Order placed, not yet reviewed</td></tr>
        <tr><td><span class="badge badge-processing">processing</span></td><td>Being prepared / packed</td></tr>
        <tr><td><span class="badge badge-shipped">shipped</span></td><td>Dispatched to customer</td></tr>
        <tr><td><span class="badge badge-delivered">delivered</span></td><td>Customer received the order</td></tr>
        <tr><td><span class="badge badge-cancelled">cancelled</span></td><td>Order was cancelled</td></tr>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
