<?php
require_once __DIR__ . '/includes/auth.php';
include __DIR__ . '/../config/db.php';

$pageTitle  = 'Coupons';
$activePage = 'coupons';

admin_require_csrf(); // reject POST without a valid CSRF token

$message     = '';
$messageType = '';

// Self-healing: create the table if it doesn't exist yet.
$conn->query("
    CREATE TABLE IF NOT EXISTS coupons (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(50) NOT NULL UNIQUE,
        type ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
        value DECIMAL(10,2) NOT NULL DEFAULT 0,
        min_order DECIMAL(10,2) NOT NULL DEFAULT 0,
        max_uses INT NOT NULL DEFAULT 0,
        used_count INT NOT NULL DEFAULT 0,
        expires_at DATE DEFAULT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_coupon') {
        $code      = strtoupper(trim($_POST['code'] ?? ''));
        $type      = ($_POST['type'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
        $value     = round((float)($_POST['value'] ?? 0), 2);
        $minOrder  = max(0, round((float)($_POST['min_order'] ?? 0), 2));
        $maxUses   = max(0, (int)($_POST['max_uses'] ?? 0));
        $expiresAt = trim($_POST['expires_at'] ?? '');
        $active    = isset($_POST['active']) ? 1 : 0;

        // Validate
        if ($code === '' || !preg_match('/^[A-Z0-9_-]{2,50}$/', $code)) {
            $message = 'Code must be 2–50 characters: letters, numbers, - or _.';
            $messageType = 'danger';
        } elseif ($value <= 0) {
            $message = 'Value must be greater than zero.';
            $messageType = 'danger';
        } elseif ($type === 'percent' && $value > 100) {
            $message = 'A percentage discount cannot exceed 100%.';
            $messageType = 'danger';
        } elseif ($expiresAt !== '' && !DateTime::createFromFormat('Y-m-d', $expiresAt)) {
            $message = 'Expiry date must be in YYYY-MM-DD format.';
            $messageType = 'danger';
        } else {
            $exp = $expiresAt !== '' ? $expiresAt : null;
            $stmt = $conn->prepare(
                "INSERT INTO coupons (code, type, value, min_order, max_uses, expires_at, active)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('ssddisi', $code, $type, $value, $minOrder, $maxUses, $exp, $active);
            if ($stmt->execute()) {
                $message = "Coupon \"$code\" created.";
                $messageType = 'success';
            } else {
                $message = ($conn->errno === 1062)
                    ? "A coupon with code \"$code\" already exists."
                    : 'Could not create coupon.';
                $messageType = 'danger';
            }
            $stmt->close();
        }
    } elseif ($action === 'toggle_coupon') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("UPDATE coupons SET active = 1 - active WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $message = 'Coupon updated.';
        $messageType = 'success';
    } elseif ($action === 'delete_coupon') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM coupons WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $message = 'Coupon deleted.';
        $messageType = 'success';
    }
}

$coupons = $conn->query("SELECT * FROM coupons ORDER BY created_at DESC, id DESC");
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<?php if ($message): ?>
  <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="admin-section">
  <div class="admin-section-header"><h2>Create Coupon</h2></div>
  <div class="admin-section-body">
    <form method="POST">
      <?= alke_csrf_field() ?>
      <input type="hidden" name="action" value="add_coupon">
      <div class="form-row form-row-3">
        <div class="form-group">
          <label for="cCode">Code *</label>
          <input type="text" id="cCode" name="code" class="form-control" required placeholder="WELCOME10" style="text-transform:uppercase;">
        </div>
        <div class="form-group">
          <label for="cType">Type</label>
          <select id="cType" name="type" class="form-control">
            <option value="percent">Percentage (%)</option>
            <option value="fixed">Fixed amount (JD)</option>
          </select>
        </div>
        <div class="form-group">
          <label for="cValue">Value *</label>
          <input type="number" id="cValue" name="value" class="form-control" required min="0.01" step="0.01" placeholder="10">
        </div>
      </div>
      <div class="form-row form-row-3">
        <div class="form-group">
          <label for="cMin">Min order (JD)</label>
          <input type="number" id="cMin" name="min_order" class="form-control" min="0" step="0.01" placeholder="0">
        </div>
        <div class="form-group">
          <label for="cMax">Max uses <span style="color:#aaa;font-weight:400;">(0 = unlimited)</span></label>
          <input type="number" id="cMax" name="max_uses" class="form-control" min="0" step="1" placeholder="0">
        </div>
        <div class="form-group">
          <label for="cExp">Expires <span style="color:#aaa;font-weight:400;">(optional)</span></label>
          <input type="date" id="cExp" name="expires_at" class="form-control">
        </div>
      </div>
      <div class="form-row">
        <label class="check-label"><input type="checkbox" name="active" checked> Active</label>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn">Create Coupon</button>
      </div>
    </form>
  </div>
</div>

<div class="admin-section">
  <div class="admin-section-header"><h2>All Coupons (<?= $coupons ? $coupons->num_rows : 0 ?>)</h2></div>

  <?php if ($coupons && $coupons->num_rows > 0): ?>
    <table class="admin-table">
      <thead>
        <tr>
          <th>Code</th><th>Discount</th><th>Min order</th><th>Uses</th><th>Expires</th><th>Status</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php while ($c = $coupons->fetch_assoc()): ?>
          <tr>
            <td data-label="Code"><strong><?= htmlspecialchars($c['code']) ?></strong></td>
            <td data-label="Discount">
              <?= $c['type'] === 'percent'
                    ? (float)$c['value'] . '%'
                    : 'JD ' . number_format((float)$c['value'], 2) ?>
            </td>
            <td data-label="Min order"><?= (float)$c['min_order'] > 0 ? 'JD ' . number_format((float)$c['min_order'], 2) : '—' ?></td>
            <td data-label="Uses"><?= (int)$c['used_count'] ?> / <?= (int)$c['max_uses'] === 0 ? '∞' : (int)$c['max_uses'] ?></td>
            <td data-label="Expires"><?= !empty($c['expires_at']) ? htmlspecialchars($c['expires_at']) : '—' ?></td>
            <td data-label="Status">
              <span class="badge badge-<?= (int)$c['active'] === 1 ? 'delivered' : 'cancelled' ?>">
                <?= (int)$c['active'] === 1 ? 'active' : 'inactive' ?>
              </span>
            </td>
            <td data-label="Actions" style="white-space:nowrap;">
              <form method="POST" style="display:inline;">
                <?= alke_csrf_field() ?>
                <input type="hidden" name="action" value="toggle_coupon">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline"><?= (int)$c['active'] === 1 ? 'Disable' : 'Enable' ?></button>
              </form>
              <form method="POST" style="display:inline;" onsubmit="return confirm('Delete coupon <?= htmlspecialchars($c['code'], ENT_QUOTES) ?>?');">
                <?= alke_csrf_field() ?>
                <input type="hidden" name="action" value="delete_coupon">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
              </form>
            </td>
          </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="empty-state">No coupons yet. Create one above.</p>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
