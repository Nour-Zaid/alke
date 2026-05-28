<?php
session_start();
include '../config/db.php';

if (isset($_SESSION['user_id'])) {
    header("Location: /alke/index.php");
    exit();
}

$errorMessage = '';
$successMessage = '';
$devVerifyLink = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if ($name === '' || $email === '' || $phone === '' || $password === '') {
        $errorMessage = 'Please fill in all fields.';
    } else {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $colCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'phone'");
        if (!$colCheck || $colCheck->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN phone VARCHAR(20) DEFAULT NULL");
        }

        $verificationReady = false;
        $verColCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'verification_token'");
        if ($verColCheck && $verColCheck->num_rows > 0) {
            $verificationReady = true;
        }

        $stmt = $conn->prepare("INSERT INTO users (name, email, password, phone) VALUES (?, ?, ?, ?)");
        if (!$stmt) {
            $errorMessage = 'Sign Up prepare failed: ' . $conn->error;
        } else {
            $stmt->bind_param("ssss", $name, $email, $hashedPassword, $phone);

            if ($stmt->execute()) {
                $newUserId = (int)$conn->insert_id;

                if ($verificationReady) {
                    $token = bin2hex(random_bytes(32));
                    $stmtToken = $conn->prepare("UPDATE users SET verification_token = ? WHERE id = ?");
                    if ($stmtToken) {
                        $stmtToken->bind_param("si", $token, $newUserId);
                        $stmtToken->execute();
                        $stmtToken->close();
                    }

                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $verifyLink = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/alke/pages/verify_email.php?token=' . urlencode($token);
                    $subject = 'Verify your email - Alke';
                    $body = "Hello " . $name . ",\n\nPlease click the link below to verify your email address:\n\n" . $verifyLink . "\n\nIf you did not create this account, you can ignore this email.";
                    $headers = 'From: noreply@' . $_SERVER['HTTP_HOST'];
                    $mailSent = @mail($email, $subject, $body, $headers);

                    if (!$mailSent || in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'])) {
                        $devVerifyLink = $verifyLink;
                    }

                    $successMessage = 'Account created! Please check your email and click the verification link before logging in.';
                } else {
                    $_SESSION['user_id'] = $newUserId;
                    $_SESSION['user_name'] = $name;
                    $_SESSION['user_email'] = $email;
                    $_SESSION['user_phone'] = $phone;

                    header("Location: /alke/index.php");
                    exit();
                }
            } else {
                $errorMessage = 'Sign Up failed: ' . $stmt->error;
            }

            $stmt->close();
        }
    }
}

include '../includes/header.php';
?>

<main class="products-page">
  <section class="section products-section">
    <div class="container" style="max-width: 520px;">
      <div class="section-title">
        <h2>Sign Up</h2>
        <p>Sign Up to checkout your orders</p>
      </div>

      <?php if (!empty($successMessage)): ?>
        <div class="checkout-card" style="text-align: center;">
          <p style="margin-bottom: 1rem;"><?php echo htmlspecialchars($successMessage); ?></p>
          <?php if (!empty($devVerifyLink)): ?>
            <p style="margin-bottom: 1.5rem; font-size: 0.85rem; color: #555;">
              (Local dev — no email server detected)<br>
              <a href="<?php echo htmlspecialchars($devVerifyLink); ?>">Click here to verify your email</a>
            </p>
          <?php endif; ?>
          <a href="/alke/pages/login.php" class="btn">Go to Login</a>
        </div>
      <?php else: ?>
        <?php if (!empty($errorMessage)): ?>
          <div class="checkout-alert">
            <p><?php echo htmlspecialchars($errorMessage); ?></p>
          </div>
        <?php endif; ?>

        <div class="checkout-card">
          <form method="POST" action="/alke/pages/register.php" class="checkout-form">
            <div class="checkout-field">
              <label for="regName">Name</label>
              <input type="text" id="regName" name="name" required>
            </div>

            <div class="checkout-field">
              <label for="regEmail">Email</label>
              <input type="email" id="regEmail" name="email" required>
            </div>

            <div class="checkout-field">
              <label for="regPhone">Phone</label>
              <input type="text" id="regPhone" name="phone" required>
            </div>

            <div class="checkout-field">
              <label for="regPassword">Password</label>
              <input type="password" id="regPassword" name="password" required>
            </div>

            <div class="checkout-actions">
              <button type="submit" class="btn">Sign Up</button>
              <a href="/alke/pages/login.php" class="btn checkout-secondary-btn">Already have an account? Login</a>
            </div>
          </form>
        </div>
      <?php endif; ?>
    </div>
  </section>
</main>

<?php include '../includes/footer.php'; ?>
