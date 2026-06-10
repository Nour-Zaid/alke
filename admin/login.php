<?php
session_start();
require_once __DIR__ . '/config.php';

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: /alke/admin/index.php');
    exit;
}

$error = '';

function admin_rate_limit_ok(): bool
{
    $now = time();
    if (!isset($_SESSION['admin_login_attempts']) || !is_array($_SESSION['admin_login_attempts'])) {
        $_SESSION['admin_login_attempts'] = [];
    }
    $_SESSION['admin_login_attempts'] = array_values(array_filter(
        $_SESSION['admin_login_attempts'],
        fn($t) => ($now - (int)$t) < 300
    ));
    if (count($_SESSION['admin_login_attempts']) >= 5) {
        return false;
    }
    $_SESSION['admin_login_attempts'][] = $now;
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if (!admin_rate_limit_ok()) {
        $error = 'Too many attempts. Please wait a few minutes.';
    } elseif (hash_equals(ADMIN_USER, $username) && password_verify($password, ADMIN_PASS_HASH)) {
        session_regenerate_id(true);
        unset($_SESSION['admin_login_attempts']);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_user'] = $username;
        header('Location: /alke/admin/index.php');
        exit;
    } else {
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
  <link rel="stylesheet" href="/alke/admin/css/admin.css">
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

    <p class="login-hint">Set ADMIN_USER / ADMIN_PASS_HASH env vars (or edit admin/config.php) before going live.</p>
  </div>
</div>
</body>
</html>
