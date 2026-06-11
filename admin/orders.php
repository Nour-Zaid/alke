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

/* ── Fetch every item for every order in one query ──── */
$allItems = [];
$itemsResult = $conn->query("
    SELECT oi.order_id, p.name AS product_name, p.image,
           oi.quantity, p.price,
           (oi.quantity * p.price) AS subtotal
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    ORDER BY oi.order_id, p.name
");
if ($itemsResult) {
    while ($item = $itemsResult->fetch_assoc()) {
        $allItems[(int)$item['order_id']][] = $item;
    }
}

include __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?>
  <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="admin-section">
  <div class="admin-section-header">
    <h2>All Orders (<?= $orders ? $orders->num_rows : 0 ?>)</h2>
    <span style="font-size:0.8rem; color:#888;">Click an order row to see what was ordered</span>
  </div>

  <?php if ($orders && $orders->num_rows > 0): ?>
    <div style="overflow-x: auto;">
      <table class="admin-table" style="border-collapse: collapse;">
        <thead>
          <tr>
            <th style="width:32px;"></th>
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
            $oid  = (int)$row['id'];
            $cnt  = (int)$row['item_count'];
            $items = $allItems[$oid] ?? [];

            // Calculate total from items if DB value is zero / non-numeric
            $total = is_numeric($row['total_price']) ? (float)$row['total_price'] : 0;
            if ($total <= 0) {
                foreach ($items as $it) { $total += (float)$it['subtotal']; }
            }
          ?>
            <!-- Order summary row -->
            <tr class="order-summary-row"
                data-order="<?= $oid ?>"
                style="cursor:<?= $cnt > 0 ? 'pointer' : 'default' ?>;"
                title="<?= $cnt > 0 ? 'Click to see items' : '' ?>">

              <td style="text-align:center; color:#aaa; font-size:0.85rem; user-select:none;">
                <?php if ($cnt > 0): ?>
                  <span class="expand-icon" id="icon-<?= $oid ?>">▶</span>
                <?php endif; ?>
              </td>

              <td><strong>#<?= $oid ?></strong></td>
              <td><?= htmlspecialchars($row['customer']) ?></td>
              <td style="color:#888;"><?= htmlspecialchars($row['email']) ?></td>
              <td>
                <?php if ($cnt > 0): ?>
                  <span class="items-pill"><?= $cnt ?> <?= $cnt === 1 ? 'item' : 'items' ?></span>
                <?php else: ?>
                  <span style="color:#aaa;">—</span>
                <?php endif; ?>
              </td>
              <td><strong>$<?= number_format($total, 2) ?></strong></td>
              <td>
                <span class="badge badge-<?= htmlspecialchars($row['status']) ?>">
                  <?= htmlspecialchars($row['status']) ?>
                </span>
              </td>
              <td style="white-space:nowrap; color:#888;">
                <?= date('M j, Y', strtotime($row['created_at'])) ?>
              </td>
              <td onclick="event.stopPropagation();">
                <form method="POST" class="status-form">
                  <input type="hidden" name="action"   value="update_status">
                  <input type="hidden" name="order_id" value="<?= $oid ?>">
                  <select name="status" class="form-control">
                    <?php foreach ($validStatuses as $s): ?>
                      <option value="<?= $s ?>" <?= $row['status'] === $s ? 'selected' : '' ?>>
                        <?= ucfirst($s) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn btn-sm btn-success" style="margin-top:4px;">Save</button>
                </form>
              </td>
            </tr>

            <!-- Expandable items detail row -->
            <?php if ($cnt > 0): ?>
            <tr class="order-detail-row" id="detail-<?= $oid ?>" style="display:none;">
              <td colspan="9" style="padding:0; background:#f8fafc; border-bottom:2px solid #e2e8f0;">
                <div style="padding:14px 20px 14px 48px;">
                  <table style="width:100%; border-collapse:collapse; font-size:0.875rem;">
                    <thead>
                      <tr style="border-bottom:1px solid #dee2e6; color:#555;">
                        <th style="padding:6px 10px; text-align:left; font-weight:600;">Product</th>
                        <th style="padding:6px 10px; text-align:center; font-weight:600;">Qty</th>
                        <th style="padding:6px 10px; text-align:right; font-weight:600;">Unit Price</th>
                        <th style="padding:6px 10px; text-align:right; font-weight:600;">Subtotal</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($items as $item):
                        $dbImg  = trim($item['image'] ?? '');
                        $imgSrc = '/alke/testblackshirt.jpeg';
                        if (!empty($dbImg) && file_exists(__DIR__ . '/../assets/' . $dbImg)) {
                            $imgSrc = '/alke/assets/' . $dbImg;
                        }
                      ?>
                        <tr style="border-bottom:1px solid #f0f0f0;">
                          <td style="padding:8px 10px;">
                            <div style="display:flex; align-items:center; gap:10px;">
                              <img src="<?= htmlspecialchars($imgSrc) ?>" alt=""
                                   style="width:36px; height:46px; object-fit:cover; border-radius:3px; background:#eee; flex-shrink:0;">
                              <span style="font-weight:500;"><?= htmlspecialchars($item['product_name']) ?></span>
                            </div>
                          </td>
                          <td style="padding:8px 10px; text-align:center; color:#555;">
                            <?= (int)$item['quantity'] ?>
                          </td>
                          <td style="padding:8px 10px; text-align:right; color:#555;">
                            $<?= number_format((float)$item['price'], 2) ?>
                          </td>
                          <td style="padding:8px 10px; text-align:right; font-weight:600;">
                            $<?= number_format((float)$item['subtotal'], 2) ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                      <tr style="border-top:2px solid #dee2e6;">
                        <td colspan="3" style="padding:8px 10px; text-align:right; font-weight:600; color:#555;">Order Total:</td>
                        <td style="padding:8px 10px; text-align:right; font-weight:700; font-size:1rem;">
                          $<?= number_format($total, 2) ?>
                        </td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              </td>
            </tr>
            <?php endif; ?>

          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p class="empty-state">No orders yet.</p>
  <?php endif; ?>
</div>

<!-- Status guide -->
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

<style>
.order-summary-row:hover td {
  background: #f0f4ff;
}
.items-pill {
  display: inline-block;
  background: #e8edf5;
  color: #334;
  padding: 2px 9px;
  border-radius: 10px;
  font-size: 0.8rem;
  font-weight: 600;
}
.expand-icon {
  display: inline-block;
  transition: transform 0.2s;
  font-size: 0.7rem;
}
.expand-icon.open {
  transform: rotate(90deg);
}
.order-detail-row td {
  transition: none;
}
</style>

<script>
document.querySelectorAll('.order-summary-row').forEach(function (row) {
  var orderId = row.dataset.order;
  var detailRow = document.getElementById('detail-' + orderId);
  var icon = document.getElementById('icon-' + orderId);

  if (!detailRow) return;

  row.addEventListener('click', function (e) {
    if (e.target.closest('.status-form')) return;

    var isOpen = detailRow.style.display !== 'none';
    detailRow.style.display = isOpen ? 'none' : 'table-row';
    if (icon) icon.classList.toggle('open', !isOpen);
  });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
