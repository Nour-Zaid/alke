<?php
session_start();
include '../config/db.php';
include '../includes/helpers.php';

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

include '../includes/header.php';

/* ── Read filters from query string ─────────────────────────── */
$search     = isset($_GET['q'])        ? trim((string)$_GET['q'])      : '';
$categoryId = isset($_GET['category']) ? (int)$_GET['category']        : 0;
$sort       = isset($_GET['sort'])     ? (string)$_GET['sort']         : 'newest';
$page       = isset($_GET['page'])     ? max(1, (int)$_GET['page'])    : 1;
$perPage    = 12;

$sortOptions = [
    'newest'     => ['label' => 'Newest',            'sql' => 'p.id DESC'],
    'price_asc'  => ['label' => 'Price: Low → High', 'sql' => 'p.price ASC'],
    'price_desc' => ['label' => 'Price: High → Low', 'sql' => 'p.price DESC'],
    'name_asc'   => ['label' => 'Name: A → Z',       'sql' => 'p.name ASC'],
];
if (!isset($sortOptions[$sort])) {
    $sort = 'newest';
}

/* ── Load categories for the filter bar ─────────────────────── */
$categories = [];
$catResult = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
if ($catResult) {
    while ($c = $catResult->fetch_assoc()) {
        $categories[] = $c;
    }
}

/* ── Build WHERE clause with prepared statements ────────────── */
$where  = [];
$params = [];
$types  = '';

if ($search !== '') {
    $where[] = '(p.name LIKE ? OR p.description LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}
if ($categoryId > 0) {
    $where[] = 'p.category_id = ?';
    $params[] = $categoryId;
    $types .= 'i';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* ── Count total for pagination ─────────────────────────────── */
$totalProducts = 0;
$countSql = "SELECT COUNT(*) AS cnt FROM products p $whereSql";
$stmtCount = $conn->prepare($countSql);
if ($stmtCount) {
    if ($params) {
        $stmtCount->bind_param($types, ...$params);
    }
    $stmtCount->execute();
    $cntRes = $stmtCount->get_result();
    if ($cntRes && ($cntRow = $cntRes->fetch_assoc())) {
        $totalProducts = (int)$cntRow['cnt'];
    }
    $stmtCount->close();
}

$totalPages = max(1, (int)ceil($totalProducts / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

/* ── Fetch products ─────────────────────────────────────────── */
$products = [];
$listSql = "SELECT p.* FROM products p $whereSql ORDER BY {$sortOptions[$sort]['sql']} LIMIT ? OFFSET ?";
$stmtList = $conn->prepare($listSql);
if ($stmtList) {
    $listTypes  = $types . 'ii';
    $listParams = array_merge($params, [$perPage, $offset]);
    $stmtList->bind_param($listTypes, ...$listParams);
    $stmtList->execute();
    $listRes = $stmtList->get_result();
    if ($listRes) {
        while ($row = $listRes->fetch_assoc()) {
            $products[] = $row;
        }
    }
    $stmtList->close();
}

/* ── Helper to build pagination links that keep filters ─────── */
function alke_shop_url(array $overrides = []): string
{
    $qs = array_merge([
        'q'        => $_GET['q']        ?? '',
        'category' => $_GET['category'] ?? '',
        'sort'     => $_GET['sort']     ?? '',
        'page'     => $_GET['page']     ?? '',
    ], $overrides);
    $qs = array_filter($qs, fn($v) => $v !== '' && $v !== null && $v !== 0);
    return '/alke/pages/products.php' . ($qs ? ('?' . http_build_query($qs)) : '');
}
?>

<main class="products-page">
  <section class="section products-section">
    <div class="container">
      <div class="section-title">
        <h2><?php echo $search !== '' ? 'Search: “' . alke_esc($search) . '”' : 'Shop All Products'; ?></h2>
        <p>
          <?php echo (int)$totalProducts; ?> product<?php echo $totalProducts === 1 ? '' : 's'; ?> found
        </p>
      </div>

      <div class="shop-toolbar">
        <div class="shop-filter-chips">
          <a href="<?php echo alke_esc(alke_shop_url(['category' => '', 'page' => ''])); ?>"
             class="filter-chip <?php echo $categoryId === 0 ? 'active' : ''; ?>">All</a>
          <?php foreach ($categories as $cat): ?>
            <a href="<?php echo alke_esc(alke_shop_url(['category' => (int)$cat['id'], 'page' => ''])); ?>"
               class="filter-chip <?php echo $categoryId === (int)$cat['id'] ? 'active' : ''; ?>">
              <?php echo alke_esc($cat['name']); ?>
            </a>
          <?php endforeach; ?>
        </div>

        <form class="shop-sort" method="GET" action="/alke/pages/products.php">
          <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?php echo alke_esc($search); ?>"><?php endif; ?>
          <?php if ($categoryId > 0): ?><input type="hidden" name="category" value="<?php echo (int)$categoryId; ?>"><?php endif; ?>
          <label for="sortSelect">Sort</label>
          <select id="sortSelect" name="sort" onchange="this.form.submit()">
            <?php foreach ($sortOptions as $key => $opt): ?>
              <option value="<?php echo alke_esc($key); ?>" <?php echo $sort === $key ? 'selected' : ''; ?>>
                <?php echo alke_esc($opt['label']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>

      <?php if (!empty($products)): ?>
        <div class="products-grid shop-grid">
          <?php foreach ($products as $row): ?>
            <?php
              $imagePath  = alke_product_image($row);
              $inStock    = (int)$row['stock'] > 0;
            ?>
            <article class="product-card">
              <a href="product.php?id=<?php echo (int)$row['id']; ?>" class="product-card-link">
                <div class="product-image-wrap">
                  <img
                    src="<?php echo alke_esc($imagePath); ?>"
                    alt="<?php echo alke_esc($row['name']); ?>"
                    class="product-image"
                    loading="lazy"
                  >
                  <?php if (!$inStock): ?>
                    <span class="stock-badge out">Out of Stock</span>
                  <?php elseif ((int)$row['stock'] <= 5): ?>
                    <span class="stock-badge low">Only <?php echo (int)$row['stock']; ?> left</span>
                  <?php endif; ?>
                </div>
                <div class="product-body">
                  <h3 class="product-name"><?php echo alke_esc($row['name']); ?></h3>
                  <p class="product-price">$<?php echo number_format((float)$row['price'], 2); ?></p>
                </div>
              </a>

              <div class="product-card-actions">
                <?php if ($inStock): ?>
                  <form method="POST" action="/alke/pages/products.php" class="add-to-cart-form" data-product-id="<?php echo (int)$row['id']; ?>">
                    <input type="hidden" name="product_id" value="<?php echo (int)$row['id']; ?>">
                    <input type="hidden" name="quantity" value="1">
                    <button type="submit" name="add_to_cart" class="btn product-btn">Add to Cart</button>
                  </form>
                <?php else: ?>
                  <button type="button" class="btn product-btn" disabled>Out of Stock</button>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
          <nav class="pagination" aria-label="Product pages">
            <?php if ($page > 1): ?>
              <a class="page-link" href="<?php echo alke_esc(alke_shop_url(['page' => $page - 1])); ?>">‹ Prev</a>
            <?php endif; ?>

            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
              <?php if ($p === $page): ?>
                <span class="page-link current"><?php echo $p; ?></span>
              <?php elseif ($p <= 2 || $p > $totalPages - 2 || abs($p - $page) <= 1): ?>
                <a class="page-link" href="<?php echo alke_esc(alke_shop_url(['page' => $p])); ?>"><?php echo $p; ?></a>
              <?php elseif ($p === 3 && $page > 4): ?>
                <span class="page-ellipsis">…</span>
              <?php elseif ($p === $totalPages - 2 && $page < $totalPages - 3): ?>
                <span class="page-ellipsis">…</span>
              <?php endif; ?>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
              <a class="page-link" href="<?php echo alke_esc(alke_shop_url(['page' => $page + 1])); ?>">Next ›</a>
            <?php endif; ?>
          </nav>
        <?php endif; ?>
      <?php else: ?>
        <div class="no-products">
          <p>No products matched your search.</p>
          <div style="text-align:center; margin-top:16px;">
            <a href="/alke/pages/products.php" class="btn">View All Products</a>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </section>
</main>

<div id="toast-notification" class="toast-notification" role="status" aria-live="polite" aria-atomic="true">
  <p id="toast-message">Product added to cart!</p>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const forms = document.querySelectorAll('.add-to-cart-form');
  const toast = document.getElementById('toast-notification');
  const toastMessage = document.getElementById('toast-message');
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
  let toastTimer = null;

  function updateCartBadge(count) {
    const badge = document.querySelector('.cart-count-badge');
    const toggleBtn = document.getElementById('cartDrawerToggle');
    if (badge) {
      if (Number(count) > 0) {
        badge.textContent = count;
      } else {
        badge.remove();
      }
    } else if (toggleBtn && Number(count) > 0) {
      const span = document.createElement('span');
      span.className = 'cart-count-badge';
      span.textContent = count;
      toggleBtn.appendChild(span);
    }
  }

  function showToast(message, isError) {
    if (!toast || !toastMessage) return;

    toastMessage.textContent = message;
    toast.classList.remove('toast-show', 'toast-hide', 'toast-success', 'toast-error');
    toast.classList.add(isError ? 'toast-error' : 'toast-success');
    toast.classList.add('toast-show');

    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(function () {
      toast.classList.remove('toast-show');
      toast.classList.add('toast-hide');
    }, 3000);
  }

  forms.forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();

      const productId = form.getAttribute('data-product-id');
      const formData = new FormData();
      formData.append('action', 'add');
      formData.append('product_id', productId);
      formData.append('quantity', '1');
      formData.append('csrf_token', csrfToken);

      fetch('/alke/pages/update_cart.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
          if (data && data.success) {
            updateCartBadge(data.cart_count);
            if (window.refreshMiniCart && data.mini_cart) {
              window.refreshMiniCart(data.mini_cart);
            }
            showToast('✓ Product added to cart!', false);
          } else {
            showToast((data && data.message) || 'Could not add item to cart.', true);
          }
        })
        .catch(() => showToast('Request failed. Please try again.', true));
    });
  });
});
</script>

<?php include '../includes/footer.php'; ?>
