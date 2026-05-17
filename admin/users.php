<?php
require_once __DIR__ . '/includes/auth.php';
include __DIR__ . '/../config/db.php';

$pageTitle  = 'Users';
$activePage = 'users';

$users = $conn->query("
    SELECT u.id, u.name, u.email,
           COUNT(DISTINCT o.id) AS order_count,
           COALESCE(SUM(o.total_price), 0) AS total_spent
    FROM users u
    LEFT JOIN orders o ON o.user_id = u.id
    GROUP BY u.id
    ORDER BY u.id DESC
");

include __DIR__ . '/includes/header.php';
?>

<div class="admin-section">
  <div class="admin-section-header">
    <h2>Registered Users (<?= $users ? $users->num_rows : 0 ?>)</h2>
  </div>

  <?php if ($users && $users->num_rows > 0): ?>
    <table class="admin-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Name</th>
          <th>Email</th>
          <th>Orders</th>
          <th>Total Spent</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php while ($u = $users->fetch_assoc()): ?>
          <tr>
            <td><?= (int)$u['id'] ?></td>
            <td><?= htmlspecialchars($u['name']) ?></td>
            <td style="color:#888;"><?= htmlspecialchars($u['email']) ?></td>
            <td><?= (int)$u['order_count'] ?></td>
            <td>$<?= number_format((float)$u['total_spent'], 2) ?></td>
            <td>
              <?php if ((int)$u['order_count'] > 0): ?>
                <a href="/alke/admin/user_orders.php?id=<?= (int)$u['id'] ?>" class="btn btn-sm btn-outline">View Orders</a>
              <?php else: ?>
                <span style="color:#ccc; font-size:0.8rem;">No orders</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="empty-state">No users registered yet.</p>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
