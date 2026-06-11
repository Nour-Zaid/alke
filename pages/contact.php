<?php
session_start();
include '../config/db.php';
include '../includes/helpers.php';

/* Auto-migrate: contact_messages table */
$conn->query("
    CREATE TABLE IF NOT EXISTS contact_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(150) NOT NULL,
        subject VARCHAR(200) DEFAULT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

$errorMessage = '';
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (!alke_csrf_check()) {
        $errorMessage = 'Your session expired. Please try again.';
    } elseif (!alke_rate_limit('contact', 3, 600)) {
        $errorMessage = 'You have sent too many messages. Please try again later.';
    } elseif ($name === '' || $email === '' || $message === '') {
        $errorMessage = 'Please fill in your name, email, and message.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'Please enter a valid email address.';
    } elseif (mb_strlen($message) > 5000) {
        $errorMessage = 'Your message is too long (max 5000 characters).';
    } else {
        $stmt = $conn->prepare("INSERT INTO contact_messages (name, email, subject, message) VALUES (?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param('ssss', $name, $email, $subject, $message);
            if ($stmt->execute()) {
                $successMessage = 'Thanks for reaching out! We will get back to you soon.';
            } else {
                $errorMessage = 'Something went wrong. Please try again.';
            }
            $stmt->close();
        } else {
            $errorMessage = 'Something went wrong. Please try again.';
        }
    }
}

include '../includes/header.php';
?>

<main class="products-page">
  <section class="section products-section">
    <div class="container" style="max-width: 720px;">
      <div class="section-title">
        <h2>Contact Us</h2>
        <p>Questions about an order or our products? We'd love to hear from you.</p>
      </div>

      <?php if ($successMessage !== ''): ?>
        <div class="checkout-card" style="text-align:center;">
          <p style="margin-bottom: 1rem;"><?php echo alke_esc($successMessage); ?></p>
          <a href="/alke/pages/products.php" class="btn">Continue Shopping</a>
        </div>
      <?php else: ?>
        <?php if ($errorMessage !== ''): ?>
          <div class="checkout-alert">
            <p><?php echo alke_esc($errorMessage); ?></p>
          </div>
        <?php endif; ?>

        <div class="checkout-card">
          <form method="POST" action="/alke/pages/contact.php" class="checkout-form">
            <?php echo alke_csrf_field(); ?>

            <div class="checkout-field">
              <label for="contactName">Name</label>
              <input type="text" id="contactName" name="name"
                     value="<?php echo isset($_SESSION['user_name']) ? alke_esc($_SESSION['user_name']) : ''; ?>" required>
            </div>

            <div class="checkout-field">
              <label for="contactEmail">Email</label>
              <input type="email" id="contactEmail" name="email"
                     value="<?php echo isset($_SESSION['user_email']) ? alke_esc($_SESSION['user_email']) : ''; ?>" required>
            </div>

            <div class="checkout-field">
              <label for="contactSubject">Subject (optional)</label>
              <input type="text" id="contactSubject" name="subject">
            </div>

            <div class="checkout-field">
              <label for="contactMessage">Message</label>
              <textarea id="contactMessage" name="message" rows="6" required></textarea>
            </div>

            <div class="checkout-actions">
              <button type="submit" class="btn">Send Message</button>
            </div>
          </form>
        </div>
      <?php endif; ?>
    </div>
  </section>
</main>

<?php include '../includes/footer.php'; ?>
