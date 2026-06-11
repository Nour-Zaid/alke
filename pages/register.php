<?php
session_start();
include '../config/db.php';

if (isset($_SESSION['user_id'])) {
    header("Location: /alke/index.php");
    exit();
}

$errorMessage = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if ($name === '' || $email === '' || $phone === '' || $password === '') {
        $errorMessage = 'Please fill in all fields.';
    } else {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $phoneColumnExists = false;
        $columnCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'phone'");
        if ($columnCheck && $columnCheck->num_rows > 0) {
            $phoneColumnExists = true;
        }

        if ($phoneColumnExists) {
            $stmt = $conn->prepare("INSERT INTO users (name, email, password, phone) VALUES (?, ?, ?, ?)");
            if (!$stmt) {
                $errorMessage = 'Sign Up prepare failed: ' . $conn->error;
            } else {
                $stmt->bind_param("ssss", $name, $email, $hashedPassword, $phone);

                if ($stmt->execute()) {
                    $_SESSION['user_id'] = (int)$conn->insert_id;
                    $_SESSION['user_name'] = $name;
                    $_SESSION['user_email'] = $email;
                    $_SESSION['user_phone'] = $phone;

                    header("Location: /alke/index.php");
                    exit();
                } else {
                    $errorMessage = 'Sign Up failed: ' . $stmt->error;
                }

                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
            if (!$stmt) {
                $errorMessage = 'Sign Up prepare failed: ' . $conn->error;
            } else {
                $stmt->bind_param("sss", $name, $email, $hashedPassword);

                if ($stmt->execute()) {
                    $_SESSION['user_id'] = (int)$conn->insert_id;
                    $_SESSION['user_name'] = $name;
                    $_SESSION['user_email'] = $email;
                    $_SESSION['user_phone'] = $phone;

                    header("Location: /alke/index.php");
                    exit();
                } else {
                    $errorMessage = 'Sign Up failed: ' . $stmt->error;
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
        <h2>Sign Up</h2>
        <p>Sign Up to checkout your orders</p>
      </div>

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
    </div>
  </section>
</main>

<?php include '../includes/footer.php'; ?>
