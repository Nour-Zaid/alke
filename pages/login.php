<?php
session_start();
include '../config/db.php';

if (isset($_SESSION['user_id'])) {
    header("Location: /alke/index.php");
    exit();
}

$errorMessage = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $loginValue = isset($_POST['login']) ? trim($_POST['login']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if ($loginValue === '' || $password === '') {
        $errorMessage = 'Please enter email/phone and password.';
    } else {
        if (preg_match('/^[0-9\-\+\s\(\)]+$/', $loginValue)) {
            $errorMessage = 'Phone login is not available yet because phone is not stored in database.';
        } else {
            $phoneColumnExists = false;
            $columnCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'phone'");
            if ($columnCheck && $columnCheck->num_rows > 0) {
                $phoneColumnExists = true;
            }

            $loginSql = $phoneColumnExists
                ? "SELECT id, name, email, phone, password FROM users WHERE email = ?"
                : "SELECT id, name, email, password FROM users WHERE email = ?";

            $stmt = $conn->prepare($loginSql);
            if (!$stmt) {
                $errorMessage = 'Login prepare failed: ' . $conn->error;
            } else {
                $stmt->bind_param("s", $loginValue);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result && $result->num_rows === 1) {
                    $user = $result->fetch_assoc();

                    if (password_verify($password, $user['password'])) {
                        $_SESSION['user_id'] = (int)$user['id'];
                        $_SESSION['user_name'] = isset($user['name']) ? (string)$user['name'] : '';
                        $_SESSION['user_email'] = isset($user['email']) ? (string)$user['email'] : '';
                        $_SESSION['user_phone'] = isset($user['phone']) ? (string)$user['phone'] : '';

                        header("Location: /alke/index.php");
                        exit();
                    } else {
                        $errorMessage = 'Invalid login details.';
                    }
                } else {
                    $errorMessage = 'Invalid login details.';
                }

                $stmt->close();
            }
        }
    }
}

include '../includes/header.php';
?>

<main class="products-page">
  <section class="section products-section">
    <div class="container" style="max-width: 520px;">
      <div class="section-title">
        <h2>Login</h2>
        <p>Login to continue to checkout</p>
      </div>

      <?php if (!empty($errorMessage)): ?>
        <div class="checkout-alert">
          <p><?php echo htmlspecialchars($errorMessage); ?></p>
        </div>
      <?php endif; ?>

      <div class="checkout-card">
        <form method="POST" action="/alke/pages/login.php" class="checkout-form">
          <div class="checkout-field">
            <label for="loginInput">Email or Phone</label>
            <input type="text" id="loginInput" name="login" required>
          </div>

          <div class="checkout-field">
            <label for="loginPassword">Password</label>
            <input type="password" id="loginPassword" name="password" required>
          </div>

          <div class="checkout-actions">
            <button type="submit" class="btn">Login</button>
            <a href="/alke/pages/register.php" class="btn checkout-secondary-btn">Create Account</a>
          </div>
        </form>
      </div>
    </div>
  </section>
</main>

<?php include '../includes/footer.php'; ?>
