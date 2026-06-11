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

<main class="products-page">
  <section class="section products-section">
    <div class="container profile-container">
      <div class="section-title">
        <h2>My Profile</h2>
        <p>Manage your account information and review your order history</p>
      </div>

      <?php if (!empty($userError)): ?>
        <div class="checkout-alert">
          <p><?php echo htmlspecialchars($userError); ?></p>
        </div>
      <?php else: ?>
        <div class="profile-section profile-clean-card">
          <div class="profile-header">
            <h3 class="checkout-card-title profile-title">Account Details</h3>
            <button type="button" id="profileEditBtn" class="btn profile-edit-btn">Edit</button>
          </div>

          <div class="profile-field-row">
            <p class="profile-label">Name</p>
            <p class="profile-value" id="profile-name-value"><?php echo htmlspecialchars($displayName); ?></p>
          </div>

          <div class="profile-field-row">
            <p class="profile-label">Email</p>
            <p class="profile-value" id="profile-email-value"><?php echo htmlspecialchars($displayEmail); ?></p>
          </div>

          <div class="profile-field-row">
            <p class="profile-label">Phone</p>
            <p class="profile-value" id="profile-phone-value"><?php echo htmlspecialchars($displayPhone); ?></p>
          </div>
        </div>

        <div class="profile-section profile-clean-card">
          <h3 class="checkout-card-title profile-title">Order History</h3>

          <?php if (empty($orders)): ?>
            <p class="no-products profile-empty-message">You have not placed any orders yet.</p>
          <?php else: ?>
            <div class="checkout-summary-list">
              <?php foreach ($orders as $order): ?>
                <?php $orderId = (int)$order['id']; ?>
                <div class="checkout-summary-item profile-order-item profile-order-block profile-order-minimal">
                  <p><strong>Order ID:</strong> #<?php echo $orderId; ?></p>
                  <p><strong>Total:</strong> $<?php echo number_format((float)$order['total_price'], 2); ?></p>
                  <p><strong>Status:</strong> <?php echo htmlspecialchars((string)$order['status']); ?></p>
                  <p><strong>Date:</strong> <?php echo htmlspecialchars((string)$order['created_at']); ?></p>

                  <?php if (!empty($orderItemsByOrder[$orderId])): ?>
                    <div class="profile-order-items">
                      <p><strong>Items:</strong></p>
                      <?php foreach ($orderItemsByOrder[$orderId] as $item): ?>
                        <p class="profile-order-item-line">
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

<?php if (empty($userError)): ?>
<div id="profileEditModal" class="modal-overlay">
  <div class="modal-content profile-modal-content" role="dialog" aria-modal="true" aria-labelledby="profileEditModalTitle">
    <div class="modal-header">
      <h3 id="profileEditModalTitle">Edit Account Details</h3>
      <button type="button" id="profileModalClose" class="modal-close" aria-label="Close">×</button>
    </div>

    <form id="profileModalForm">
      <div class="modal-body">
        <div class="checkout-field">
          <label for="modal-name-input" class="profile-label">Name</label>
          <input type="text" id="modal-name-input" name="name" value="<?php echo htmlspecialchars($displayName); ?>" required>
        </div>

        <div class="checkout-field">
          <label for="modal-email-input" class="profile-label">Email</label>
          <input type="email" id="modal-email-input" name="email" value="<?php echo htmlspecialchars($displayEmail); ?>" required>
        </div>

        <div class="checkout-field">
          <label for="modal-phone-input" class="profile-label">Phone</label>
          <input type="text" id="modal-phone-input" name="phone" value="<?php echo htmlspecialchars($displayPhone); ?>">
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" id="profileModalCancel" class="btn profile-cancel-btn">Cancel</button>
        <button type="submit" class="btn profile-save-btn">Save</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div id="profile-toast" class="toast-notification profile-toast" role="status" aria-live="polite" aria-atomic="true">
  <p id="profile-toast-message">Profile updated successfully.</p>
</div>

<script>
(function () {
  function initProfileModal() {
    const editBtn = document.getElementById('profileEditBtn');
    const modal = document.getElementById('profileEditModal');
    const closeBtn = document.getElementById('profileModalClose');
    const cancelBtn = document.getElementById('profileModalCancel');
    const form = document.getElementById('profileModalForm');

    const nameValue = document.getElementById('profile-name-value');
    const emailValue = document.getElementById('profile-email-value');
    const phoneValue = document.getElementById('profile-phone-value');

    const nameInput = document.getElementById('modal-name-input');
    const emailInput = document.getElementById('modal-email-input');
    const phoneInput = document.getElementById('modal-phone-input');

    const toast = document.getElementById('profile-toast');
    const toastMessage = document.getElementById('profile-toast-message');
    let toastTimer = null;

    if (!modal) return;

    function showToast(message, isError) {
      if (!toast || !toastMessage) return;
      toastMessage.textContent = message;
      toast.classList.remove('toast-show', 'toast-hide', 'toast-success', 'toast-error');
      toast.classList.add(isError ? 'toast-error' : 'toast-success');
      toast.classList.add('toast-show');

      if (toastTimer) clearTimeout(toastTimer);
      toastTimer = setTimeout(function () {
        toast.classList.remove('toast-show');
        toast.classList.add('toast-hide');
      }, 2800);
    }

    function openModal() {
      modal.classList.add('is-visible');
      modal.style.display = 'flex';
      document.body.style.overflow = 'hidden';
    }

    function closeModal() {
      modal.classList.remove('is-visible');
      modal.style.display = '';
      document.body.style.overflow = '';
    }

    if (editBtn) {
      editBtn.addEventListener('click', function (e) {
        e.preventDefault();
        openModal();
      });
    }

    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

    modal.addEventListener('click', function (e) {
      if (e.target === modal) {
        closeModal();
      }
    });

    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();

        fetch('/alke/pages/update_profile.php', {
          method: 'POST',
          body: new FormData(form)
        })
          .then(function (response) {
            return response.json();
          })
          .then(function (data) {
            if (data && data.success) {
              const profile = data.profile || {};
              const newName = profile.name || 'Not available';
              const newEmail = profile.email || 'Not available';
              const newPhone = profile.phone || 'Not available';

              if (nameValue) nameValue.textContent = newName;
              if (emailValue) emailValue.textContent = newEmail;
              if (phoneValue) phoneValue.textContent = newPhone;

              if (nameInput) nameInput.value = profile.name || '';
              if (emailInput) emailInput.value = profile.email || '';
              if (phoneInput) phoneInput.value = profile.phone || '';

              closeModal();
              showToast(data.message || 'Profile updated successfully.', false);
            } else {
              showToast((data && data.message) ? data.message : 'Unable to update profile.', true);
            }
          })
          .catch(function () {
            showToast('Request failed. Please try again.', true);
          });
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initProfileModal);
  } else {
    initProfileModal();
  }
})();
</script>

<?php include '../includes/footer.php'; ?>
