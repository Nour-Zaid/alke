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
        } else {
            $userError = 'User data is invalid.';
        }
    } else {
        $userError = 'User not found.';
    }

    $stmtUser->close();
}

$displayName = 'Not available';
$displayEmail = 'Not available';
$displayPhone = '';

if (is_array($user)) {
    if (isset($user['name']) && trim((string)$user['name']) !== '') {
        $displayName = (string)$user['name'];
    }
    if (isset($user['email']) && trim((string)$user['email']) !== '') {
        $displayEmail = (string)$user['email'];
    }
    if (isset($user['phone']) && trim((string)$user['phone']) !== '') {
        $displayPhone = (string)$user['phone'];
    }
}

include '../includes/header.php';
?>

<main class="products-page">
  <section class="section products-section">
    <div class="container profile-container">
      <div class="section-title">
        <h2>Edit Profile</h2>
        <p>Update your account details</p>
      </div>

      <?php if (!empty($userError)): ?>
        <div class="checkout-alert">
          <p><?php echo htmlspecialchars($userError); ?></p>
        </div>
      <?php else: ?>
        <div class="profile-section profile-clean-card">
          <form id="edit-profile-form" class="profile-edit-standalone-form">
            <div class="profile-field-row">
              <label class="profile-label" for="profile-name-input">Name</label>
              <div class="checkout-field">
                <input type="text" id="profile-name-input" name="name" value="<?php echo htmlspecialchars($displayName); ?>" required>
              </div>
            </div>

            <div class="profile-field-row">
              <label class="profile-label" for="profile-email-input">Email</label>
              <div class="checkout-field">
                <input type="email" id="profile-email-input" name="email" value="<?php echo htmlspecialchars($displayEmail); ?>" required>
              </div>
            </div>

            <div class="profile-field-row">
              <label class="profile-label" for="profile-phone-input">Phone</label>
              <div class="checkout-field">
                <input type="text" id="profile-phone-input" name="phone" value="<?php echo htmlspecialchars($displayPhone); ?>">
              </div>
            </div>

            <div class="profile-actions-top">
              <a href="/alke/pages/profile.php" class="btn profile-cancel-btn">Cancel</a>
              <button type="submit" class="btn profile-save-btn">Save</button>
            </div>
          </form>
        </div>
      <?php endif; ?>
    </div>
  </section>
</main>

<div id="profile-toast" class="toast-notification profile-toast" role="status" aria-live="polite" aria-atomic="true">
  <p id="profile-toast-message">Profile updated successfully.</p>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('edit-profile-form');
  const toast = document.getElementById('profile-toast');
  const toastMessage = document.getElementById('profile-toast-message');
  let toastTimer = null;

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
    }, 2500);
  }

  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();

      fetch('/alke/pages/update_profile.php', {
        method: 'POST',
        body: new FormData(form)
      })
        .then(response => response.json())
        .then(data => {
          if (data && data.success) {
            showToast(data.message || 'Profile updated successfully.', false);
            setTimeout(function () {
              window.location.href = '/alke/pages/profile.php';
            }, 700);
          } else {
            showToast((data && data.message) ? data.message : 'Unable to update profile.', true);
          }
        })
        .catch(() => {
          showToast('Request failed. Please try again.', true);
        });
    });
  }
});
</script>

<?php include '../includes/footer.php'; ?>
