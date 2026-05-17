<?php
require_once __DIR__ . '/includes/auth.php';
include __DIR__ . '/../config/db.php';

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';

// Summary counts
$totalProducts = (int)$conn->query("SELECT COUNT(*) FROM products")->fetch_row()[0];
$totalOrders   = (int)$conn->query("SELECT COUNT(*) FROM orders")->fetch_row()[0];
$totalRevenue  = (float)$conn->query("SELECT COALESCE(SUM(total_price),0) FROM orders")->fetch_row()[0];
$totalUsers    = (int)$conn->query("SELECT COUNT(*) FROM users")->fetch_row()[0];

// Recent 10 orders
$recentOrders = $conn->query("
    SELECT o.id, u.name AS customer, o.total_price, o.status, o.created_at
    FROM orders o
    JOIN users u ON o.user_id = u.id
    ORDER BY o.created_at DESC
    LIMIT 10
");

// Low-stock products (stock <= 5)
$lowStock = $conn->query("
    SELECT id, name, stock FROM products WHERE stock <= 5 ORDER BY stock ASC LIMIT 6
");

include __DIR__ . '/includes/header.php';
?>

<!-- Stat cards -->
<div class="stats-row">
  <div class="stat-card">
    <div class="stat-icon">👕</div>
    <div class="stat-label">Total Products</div>
    <div class="stat-value"><?= $totalProducts ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">📦</div>
    <div class="stat-label">Total Orders</div>
    <div class="stat-value"><?= $totalOrders ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">💰</div>
    <div class="stat-label">Total Revenue</div>
    <div class="stat-value">$<?= number_format($totalRevenue, 2) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">👤</div>
    <div class="stat-label">Registered Users</div>
    <div class="stat-value"><?= $totalUsers ?></div>
  </div>
</div>

<!-- Recent orders -->
<div class="admin-section">
  <div class="admin-section-header">
    <h2>Recent Orders</h2>
    <a href="/alke/admin/orders.php" class="btn btn-sm btn-outline">View All</a>
  </div>
  <?php if ($recentOrders && $recentOrders->num_rows > 0): ?>
    <table class="admin-table">
      <thead>
        <tr>
          <th>Order #</th>
          <th>Customer</th>
          <th>Total</th>
          <th>Status</th>
          <th>Date</th>
        </tr>
      </thead>
      <tbody>
        <?php while ($row = $recentOrders->fetch_assoc()): ?>
          <tr>
            <td><strong>#<?= (int)$row['id'] ?></strong></td>
            <td><?= htmlspecialchars($row['customer']) ?></td>
            <td>$<?= number_format((float)$row['total_price'], 2) ?></td>
            <td><span class="badge badge-<?= htmlspecialchars($row['status']) ?>"><?= htmlspecialchars($row['status']) ?></span></td>
            <td><?= date('M j, Y', strtotime($row['created_at'])) ?></td>
          </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="empty-state">No orders yet.</p>
  <?php endif; ?>
</div>

<!-- Low stock warning -->
<?php if ($lowStock && $lowStock->num_rows > 0): ?>
<div class="admin-section">
  <div class="admin-section-header">
    <h2>⚠️ Low Stock Products</h2>
    <a href="/alke/admin/products.php" class="btn btn-sm btn-outline">Manage Products</a>
  </div>
  <table class="admin-table">
    <thead>
      <tr><th>Product</th><th>Stock Remaining</th></tr>
    </thead>
    <tbody>
      <?php while ($p = $lowStock->fetch_assoc()): ?>
        <tr>
          <td><?= htmlspecialchars($p['name']) ?></td>
          <td>
            <span style="color: <?= (int)$p['stock'] === 0 ? '#dc3545' : '#d97706' ?>; font-weight: 600;">
              <?= (int)$p['stock'] === 0 ? 'Out of stock' : (int)$p['stock'] . ' left' ?>
            </span>
          </td>
        </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
