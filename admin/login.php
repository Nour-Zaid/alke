<?php
session_start();
require_once __DIR__ . '/config.php';                 // ADMIN_USER / ADMIN_PASS_HASH (fails closed)
require_once __DIR__ . '/../includes/helpers.php';    // CSRF + escaping
include __DIR__ . '/../config/db.php';                // $conn

alke_security_headers(); // clickjacking / sniffing / CSP / HSTS on the login page

// If already logged in, go to the dashboard.
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: /alke/admin/index');
    exit;
}

// Make sure the rate-limit table exists (self-healing on fresh databases).
$conn->query("
    CREATE TABLE IF NOT EXISTS admin_login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ip_time (ip, attempted_at)
    )
");

/** Best-effort client IP (Railway sets X-Forwarded-For). */
function admin_client_ip(): string
{
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        $parts = explode(',', $xff);
        return trim($parts[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

const ADMIN_LOGIN_WINDOW_MIN = 15;   // sliding window
const ADMIN_LOGIN_MAX_FAILS  = 10;   // failures per IP per window

$error = '';
$ip    = substr(admin_client_ip(), 0, 45);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Opportunistic cleanup of old rows.
    $conn->query("DELETE FROM admin_login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)");

    // Count recent failures from THIS ip (per-IP, so it can't lock out a real
    // admin coming from a different address).
    $recent = 0;
    if ($stmt = $conn->prepare("SELECT COUNT(*) FROM admin_login_attempts WHERE ip = ? AND attempted_at > (NOW() - INTERVAL " . ADMIN_LOGIN_WINDOW_MIN . " MINUTE)")) {
        $stmt->bind_param('s', $ip);
        $stmt->execute();
        $recent = (int)$stmt->get_result()->fetch_row()[0];
        $stmt->close();
    }

    // Progressive delay slows automated guessing without a hard lockout.
    usleep(min($recent, 6) * 250000); // up to ~1.5s

    if ($recent >= ADMIN_LOGIN_MAX_FAILS) {
        $error = 'Too many attempts from your network. Please wait a few minutes and try again.';
    } elseif (!alke_csrf_check()) {
        $error = 'Security check failed. Please reload the page and try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        if (hash_equals(ADMIN_USER, $username) && password_verify($password, ADMIN_PASS_HASH)) {
            // Success: clear this IP's attempts, rotate the session id, log in.
            if ($del = $conn->prepare("DELETE FROM admin_login_attempts WHERE ip = ?")) {
                $del->bind_param('s', $ip);
                $del->execute();
                $del->close();
            }
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_user'] = $username;
            header('Location: /alke/admin/index');
            exit;
        }

        // Failure: record the attempt.
        if ($ins = $conn->prepare("INSERT INTO admin_login_attempts (ip) VALUES (?)")) {
            $ins->bind_param('s', $ip);
            $ins->execute();
            $ins->close();
        }
        $error = 'Incorrect username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Login — Alke Clothes</title>
  <link rel="stylesheet" href="/alke/admin/css/admin.css?v=4">
</head>
<body>
<div class="login-page">
  <div class="login-card">
    <h1>Admin Panel</h1>
    <p class="login-sub">Alke Clothes Store Management</p>

    <?php if ($error): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
      <?= alke_csrf_field() ?>
      <div class="login-field">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required
               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
      </div>
      <div class="login-field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>
      </div>
      <button type="submit" class="login-btn">Sign In</button>
    </form>
  </div>
</div>
</body>
</html>
