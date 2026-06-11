<?php
session_start();
include '../config/db.php';

$message = '';
$isSuccess = false;
$devVerifyLink = '';
$prefillEmail = isset($_SESSION['user_email']) ? $_SESSION['user_email'] : '';

$colCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'email_verified'");
$colExists = $colCheck && $colCheck->num_rows > 0;

if (!$colExists) {
    $message = 'Email verification is not configured.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';

    if ($email === '') {
        $message = 'Please enter your email address.';
    } else {
        $stmt = $conn->prepare("SELECT id, name, email_verified FROM users WHERE email = ?");
        if ($stmt) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $result->num_rows === 1) {
                $user = $result->fetch_assoc();
                $stmt->close();

                if ((int)$user['email_verified'] === 1) {
                    $message = 'This email is already verified. You can log in.';
                } else {
                    $userId = (int)$user['id'];
                    $token = bin2hex(random_bytes(32));

                    $stmtUpdate = $conn->prepare("UPDATE users SET verification_token = ? WHERE id = ?");
                    if ($stmtUpdate) {
                        $stmtUpdate->bind_param("si", $token, $userId);
                        $stmtUpdate->execute();
                        $stmtUpdate->close();
                    }

                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $verifyLink = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/alke/pages/verify_email.php?token=' . urlencode($token);
                    $subject = 'Verify your email - Alke';
                    $body = "Hello " . $user['name'] . ",\n\nClick the link below to verify your email address:\n\n" . $verifyLink . "\n\nIf you did not request this, ignore this email.";
                    $headers = 'From: noreply@' . $_SERVER['HTTP_HOST'];
                    $mailSent = @mail($email, $subject, $body, $headers);

                    if (!$mailSent || in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'])) {
                        $devVerifyLink = $verifyLink;
                    }

                    $isSuccess = true;
                    $message = 'Verification email sent! Please check your inbox and click the link.';
                }
            } else {
                $stmt->close();
                $message = 'No account found with that email address.';
            }
        } else {
            $message = 'An error occurred. Please try again.';
        }
    }
}

include '../includes/header.php';
?>

<main class="products-page">
  <section class="section products-section">
    <div class="container" style="max-width: 520px;">
      <div class="section-title">
        <h2>Resend Verification Email</h2>
        <p>Enter your email to receive a new verification link</p>
      </div>

      <?php if (!empty($message)): ?>
        <div class="<?php echo $isSuccess ? 'checkout-success' : 'checkout-alert'; ?>">
          <p><?php echo htmlspecialchars($message); ?></p>
          <?php if ($isSuccess): ?>
            <?php if (!empty($devVerifyLink)): ?>
              <p style="margin-top: 0.75rem; font-size: 0.85rem; color: #555;">
                (Local dev — no email server detected)<br>
                <a href="<?php echo htmlspecialchars($devVerifyLink); ?>">Click here to verify your email</a>
              </p>
            <?php endif; ?>
            <a href="/alke/pages/login.php" class="btn" style="margin-top: 1rem;">Go to Login</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if (!$isSuccess && $colExists): ?>
        <div class="checkout-card">
          <form method="POST" action="/alke/pages/resend_verification.php" class="checkout-form">
            <div class="checkout-field">
              <label for="resendEmail">Email Address</label>
              <input type="email" id="resendEmail" name="email" value="<?php echo htmlspecialchars($prefillEmail); ?>" required>
            </div>

            <div class="checkout-actions">
              <button type="submit" class="btn">Send Verification Email</button>
              <a href="/alke/pages/login.php" class="btn checkout-secondary-btn">Back to Login</a>
            </div>
          </form>
        </div>
      <?php endif; ?>
    </div>
  </section>
</main>

<?php include '../includes/footer.php'; ?>
