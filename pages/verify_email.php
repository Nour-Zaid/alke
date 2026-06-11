<?php
session_start();
include '../config/db.php';

$message = '';
$isSuccess = false;

$colCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'email_verified'");
$colExists = $colCheck && $colCheck->num_rows > 0;

if (!$colExists) {
    $message = 'Email verification is not configured.';
} else {
    $token = isset($_GET['token']) ? trim($_GET['token']) : '';

    if ($token === '') {
        $message = 'Invalid verification link.';
    } else {
        $stmt = $conn->prepare("SELECT id FROM users WHERE verification_token = ? AND email_verified = 0");
        if ($stmt) {
            $stmt->bind_param("s", $token);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $result->num_rows === 1) {
                $row = $result->fetch_assoc();
                $userId = (int)$row['id'];
                $stmt->close();

                $update = $conn->prepare("UPDATE users SET email_verified = 1, verification_token = NULL WHERE id = ?");
                if ($update) {
                    $update->bind_param("i", $userId);
                    if ($update->execute()) {
                        $isSuccess = true;
                        $message = 'Your email has been verified successfully! You can now log in.';
                    } else {
                        $message = 'Verification failed. Please try again.';
                    }
                    $update->close();
                } else {
                    $message = 'Verification failed. Please try again.';
                }
            } else {
                $stmt->close();
                $message = 'This verification link is invalid or has already been used.';
            }
        } else {
            $message = 'Verification failed. Please try again.';
        }
    }
}

include '../includes/header.php';
?>

<main class="products-page">
  <section class="section products-section">
    <div class="container" style="max-width: 520px;">
      <div class="section-title">
        <h2>Email Verification</h2>
      </div>

      <div class="checkout-card" style="text-align: center;">
        <p style="margin-bottom: 1.5rem;"><?php echo htmlspecialchars($message); ?></p>
        <?php if ($isSuccess): ?>
          <a href="/alke/pages/login.php" class="btn">Go to Login</a>
        <?php else: ?>
          <a href="/alke/pages/resend_verification.php" class="btn checkout-secondary-btn">Resend Verification Email</a>
        <?php endif; ?>
      </div>
    </div>
  </section>
</main>

<?php include '../includes/footer.php'; ?>
