<?php
session_start();
include '../config/db.php';
include '../includes/helpers.php';

if (isset($_SESSION['user_id'])) {
    header("Location: /alke/index.php");
    exit();
}

$errorMessage = '';
$errorHtml = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $loginValue = isset($_POST['login']) ? trim($_POST['login']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if (!alke_csrf_check()) {
        $errorMessage = 'Your session expired. Please try again.';
    } elseif (!alke_rate_limit('login', 5, 300)) {
        $errorMessage = 'Too many login attempts. Please wait a few minutes and try again.';
    } elseif ($loginValue === '' || $password === '') {
        $errorMessage = 'Please enter email/phone and password.';
    } else {
        if (preg_match('/^[0-9\-\+\s\(\)]+$/', $loginValue)) {
            $errorMessage = 'Please log in with your email address.';
        } else {
            $colCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'phone'");
            if (!$colCheck || $colCheck->num_rows === 0) {
                $conn->query("ALTER TABLE users ADD COLUMN phone VARCHAR(20) DEFAULT NULL");
            }

            $verifiedColExists = false;
            $verifiedColCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'email_verified'");
            if ($verifiedColCheck && $verifiedColCheck->num_rows > 0) {
                $verifiedColExists = true;
            }

            $selectFields = "id, name, email, phone"
                . ($verifiedColExists ? ", email_verified, verification_token" : "")
                . ", password";

            $stmt = $conn->prepare("SELECT $selectFields FROM users WHERE email = ?");
            if (!$stmt) {
                $errorMessage = 'Login prepare failed: ' . $conn->error;
            } else {
                $stmt->bind_param("s", $loginValue);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result && $result->num_rows === 1) {
                    $user = $result->fetch_assoc();

                    if (password_verify($password, $user['password'])) {
                        // Block login only if unverified AND has a pending token
                        // (existing users pre-verification have email_verified=0 but no token)
                        $needsVerification = $verifiedColExists
                            && (int)($user['email_verified'] ?? 1) === 0
                            && !empty($user['verification_token']);

                        if ($needsVerification) {
                            $errorHtml = 'Please verify your email before logging in. <a href="/alke/pages/resend_verification.php">Resend verification email</a>';
                        } else {
                            // Auto-fix legacy accounts that have email_verified=0 but no token
                            if ($verifiedColExists && (int)($user['email_verified'] ?? 1) === 0) {
                                $conn->query("UPDATE users SET email_verified = 1 WHERE id = " . (int)$user['id']);
                            }

                            // Prevent session fixation
                            session_regenerate_id(true);
                            alke_rate_limit_reset('login');

                            $_SESSION['user_id'] = (int)$user['id'];
                            $_SESSION['user_name'] = (string)($user['name'] ?? '');
                            $_SESSION['user_email'] = (string)($user['email'] ?? '');
                            $_SESSION['user_phone'] = (string)($user['phone'] ?? '');

                            $redirect = '/alke/index.php';
                            if (!empty($_SESSION['redirect_after_login'])) {
                                $redirect = $_SESSION['redirect_after_login'];
                                unset($_SESSION['redirect_after_login']);
                            }

                            header("Location: $redirect");
                            exit();
                        }
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

      <?php if (!empty($errorHtml)): ?>
        <div class="checkout-alert">
          <p><?php echo $errorHtml; ?></p>
        </div>
      <?php endif; ?>

      <div class="checkout-card">
        <form method="POST" action="/alke/pages/login.php" class="checkout-form">
          <?php echo alke_csrf_field(); ?>
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
