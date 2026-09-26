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

      <div class="contact-info">
        <a class="contact-info-item" href="mailto:alkeclothingco@gmail.com">
          <svg class="contact-info-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="2.5" y="4.5" width="19" height="15" rx="2.5"/><path d="m3 6 9 7 9-7"/>
          </svg>
          <span><strong>Email</strong><br>alkeclothingco@gmail.com</span>
        </a>
        <a class="contact-info-item" href="tel:+962777261388">
          <svg class="contact-info-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M6.5 3h3l1.6 4-2 1.4a12 12 0 0 0 5 5l1.4-2 4 1.6v3a2 2 0 0 1-2.1 2A16 16 0 0 1 4.5 5.1 2 2 0 0 1 6.5 3Z"/>
          </svg>
          <span><strong>Phone</strong><br>+962 7 7726 1388</span>
        </a>
        <a class="contact-info-item" href="https://instagram.com/alke.jo" target="_blank" rel="noopener">
          <svg class="contact-info-icon" viewBox="0 0 24 24" aria-hidden="true">
            <defs>
              <radialGradient id="alkeIg" cx="30%" cy="107%" r="130%">
                <stop offset="0%" stop-color="#fdf497"/><stop offset="5%" stop-color="#fdf497"/>
                <stop offset="45%" stop-color="#fd5949"/><stop offset="60%" stop-color="#d6249f"/>
                <stop offset="90%" stop-color="#285AEB"/>
              </radialGradient>
            </defs>
            <path fill="url(#alkeIg)" d="M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.22.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.05.41 2.22.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.22-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.16-1.05.36-2.22.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.22-.41-.56-.22-.96-.48-1.38-.9-.42-.42-.68-.82-.9-1.38-.16-.42-.36-1.05-.41-2.22C2.17 15.58 2.16 15.2 2.16 12s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.22.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.05-.36 2.22-.41C8.42 2.17 8.8 2.16 12 2.16Zm0 1.62c-3.14 0-3.51.01-4.75.07-.9.04-1.39.19-1.71.32-.43.17-.74.37-1.06.69-.32.32-.52.63-.69 1.06-.13.32-.28.81-.32 1.71-.06 1.24-.07 1.61-.07 4.75s.01 3.51.07 4.75c.04.9.19 1.39.32 1.71.17.43.37.74.69 1.06.32.32.63.52 1.06.69.32.13.81.28 1.71.32 1.24.06 1.61.07 4.75.07s3.51-.01 4.75-.07c.9-.04 1.39-.19 1.71-.32.43-.17.74-.37 1.06-.69.32-.32.52-.63.69-1.06.13-.32.28-.81.32-1.71.06-1.24.07-1.61.07-4.75s-.01-3.51-.07-4.75c-.04-.9-.19-1.39-.32-1.71a2.86 2.86 0 0 0-.69-1.06 2.86 2.86 0 0 0-1.06-.69c-.32-.13-.81-.28-1.71-.32-1.24-.06-1.61-.07-4.75-.07Zm0 2.76a5.3 5.3 0 1 1 0 10.6 5.3 5.3 0 0 1 0-10.6Zm0 1.62a3.68 3.68 0 1 0 0 7.36 3.68 3.68 0 0 0 0-7.36Zm5.5-.13a1.24 1.24 0 1 1-2.48 0 1.24 1.24 0 0 1 2.48 0Z"/>
          </svg>
          <span><strong>Instagram</strong><br>@alke.jo</span>
        </a>
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
