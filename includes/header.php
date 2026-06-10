<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include __DIR__ . '/../config/db.php';
include __DIR__ . '/helpers.php';

alke_security_headers();

$miniCartItems = [];
$miniCartTotal = 0;
$miniCartCount = 0;

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$miniCartCount = array_sum(array_map('intval', $_SESSION['cart']));

if (!empty($_SESSION['cart'])) {
    $ids = array_map('intval', array_keys($_SESSION['cart']));
    if (!empty($ids)) {
        $idsList = implode(',', $ids);
        $miniResult = $conn->query("SELECT * FROM products WHERE id IN ($idsList)");

        if ($miniResult) {
            while ($row = $miniResult->fetch_assoc()) {
                $pid = (int)$row['id'];
                $qty = isset($_SESSION['cart'][$pid]) ? (int)$_SESSION['cart'][$pid] : 1;
                if ($qty < 1) {
                    $qty = 1;
                }

                $imagePath = alke_product_image($row);
                $lineTotal = ((float)$row['price']) * $qty;
                $miniCartTotal += $lineTotal;

                $miniCartItems[] = [
                    'id' => $pid,
                    'name' => $row['name'],
                    'price' => (float)$row['price'],
                    'qty' => $qty,
                    'image' => $imagePath,
                    'line_total' => $lineTotal
                ];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?php echo alke_esc(alke_csrf_token()); ?>">
  <title>Alke Clothes</title>
  <link rel="stylesheet" href="/alke/css/style.css?v=3.2">
</head>
<body>
  <header class="site-header">
    <div class="container nav-wrap">
      <a href="/alke/index.php" class="brand" aria-label="Alke Clothes Home">
        <img src="/alke/the symbol.jpeg" alt="Alke Clothes Logo" class="brand-logo">
        <span class="brand-text">Alke Clothes</span>
      </a>

      <button class="nav-toggle" id="navToggle" aria-label="Toggle navigation" aria-expanded="false">
        ☰
      </button>

      <nav class="site-nav" id="siteNav">
        <a href="/alke/index.php">Home</a>
        <a href="/alke/pages/products.php">Shop</a>
        <a href="/alke/pages/contact.php">Contact</a>

        <?php if (!isset($_SESSION['user_id'])): ?>
          <a href="/alke/pages/login.php">Login</a>
          <a href="/alke/pages/register.php">Register</a>
        <?php else: ?>
          <a href="/alke/pages/logout.php">Logout</a>
        <?php endif; ?>
      </nav>

      <form class="nav-search" action="/alke/pages/products.php" method="GET" role="search">
        <input
          type="search"
          name="q"
          placeholder="Search products…"
          aria-label="Search products"
          value="<?php echo isset($_GET['q']) ? alke_esc($_GET['q']) : ''; ?>"
        >
        <button type="submit" aria-label="Search">🔍</button>
      </form>

      <div class="nav-user-actions">
        <button type="button" id="cartDrawerToggle" class="cart-icon cart-toggle-btn" aria-label="Open cart panel">
          <span class="cart-emoji">🛒</span>
          <?php if ($miniCartCount > 0): ?>
            <span class="cart-count-badge"><?php echo (int)$miniCartCount; ?></span>
          <?php endif; ?>
        </button>

        <?php if (isset($_SESSION['user_id'])): ?>
        <a href="/alke/pages/profile.php" class="profile-link" aria-label="Open profile">
          <span>Profile</span>
        </a>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <aside class="cart-drawer" id="cartDrawer" aria-hidden="true">
    <div class="cart-drawer-header">
      <h3>Your Cart</h3>
      <button type="button" class="cart-drawer-close" id="cartDrawerClose" aria-label="Close cart panel">×</button>
    </div>

    <div class="cart-drawer-body">
      <?php if (!empty($miniCartItems)): ?>
        <?php foreach ($miniCartItems as $item): ?>
          <div class="mini-cart-item">
            <a href="/alke/pages/product.php?id=<?php echo (int)$item['id']; ?>" class="mini-cart-thumb-link" aria-label="View <?php echo htmlspecialchars($item['name']); ?> details">
              <img src="<?php echo htmlspecialchars($item['image']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" class="mini-cart-thumb">
            </a>
            <div class="mini-cart-info">
              <a href="/alke/pages/product.php?id=<?php echo (int)$item['id']; ?>" class="mini-cart-name-link">
                <p class="mini-cart-name"><?php echo htmlspecialchars($item['name']); ?></p>
              </a>
              <p class="mini-cart-meta">Qty: <?php echo (int)$item['qty']; ?> • $<?php echo number_format((float)$item['line_total'], 2); ?></p>
            </div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p class="mini-cart-empty">Your cart is empty.</p>
      <?php endif; ?>
    </div>

    <div class="cart-drawer-footer">
      <p class="mini-cart-total">Total: $<?php echo number_format((float)$miniCartTotal, 2); ?></p>
      <a href="/alke/pages/cart.php" class="btn mini-cart-full-btn">Go to Full Cart</a>
    </div>
  </aside>
  <div class="cart-drawer-overlay" id="cartDrawerOverlay"></div>

<script>
function _esc(s) {
  var d = document.createElement('div');
  d.appendChild(document.createTextNode(String(s)));
  return d.innerHTML;
}

window.refreshMiniCart = function (miniCart) {
  var body  = document.querySelector('.cart-drawer-body');
  var total = document.querySelector('.mini-cart-total');
  if (!body) return;

  if (!miniCart || !miniCart.items || miniCart.items.length === 0) {
    body.innerHTML = '<p class="mini-cart-empty">Your cart is empty.</p>';
    if (total) total.textContent = 'Total: $0.00';
    return;
  }

  var html = '';
  miniCart.items.forEach(function (item) {
    html += '<div class="mini-cart-item">'
      + '<a href="/alke/pages/product.php?id=' + item.id + '" class="mini-cart-thumb-link">'
      + '<img src="' + _esc(item.image) + '" alt="' + _esc(item.name) + '" class="mini-cart-thumb">'
      + '</a>'
      + '<div class="mini-cart-info">'
      + '<a href="/alke/pages/product.php?id=' + item.id + '" class="mini-cart-name-link">'
      + '<p class="mini-cart-name">' + _esc(item.name) + '</p>'
      + '</a>'
      + '<p class="mini-cart-meta">Qty: ' + item.qty + ' &bull; $' + item.line_total.toFixed(2) + '</p>'
      + '</div></div>';
  });

  body.innerHTML = html;
  if (total) total.textContent = 'Total: $' + miniCart.total.toFixed(2);
};
</script>
