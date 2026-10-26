<?php
session_start();
include '../config/db.php';

include '../includes/header.php';

$cartItems  = alke_cart_lines($conn);
$totalPrice = alke_cart_total($cartItems);
?>

<main class="products-page">
  <section class="section products-section">
    <div class="container">
      <div class="section-title">
        <h2>Your Cart</h2>
        <p>Review your selected items</p>
      </div>

      <?php if (!empty($cartItems)): ?>
        <div class="cart-table-wrap">
          <table class="cart-table">
            <thead>
              <tr>
                <th>Product</th>
                <th>Name</th>
                <th>Price</th>
                <th>Qty</th>
                <th>Subtotal</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody id="cartTableBody">
              <?php foreach ($cartItems as $item): ?>
                <tr class="cart-row" data-key="<?php echo htmlspecialchars($item['key'], ENT_QUOTES); ?>" data-price="<?php echo (float)$item['price']; ?>">
                  <td>
                    <a href="/alke/product?id=<?php echo (int)$item['id']; ?>" class="cart-thumb-link" aria-label="View <?php echo htmlspecialchars($item['name']); ?> details">
                      <img
                        src="<?php echo htmlspecialchars($item['image_path']); ?>"
                        alt="<?php echo htmlspecialchars($item['name']); ?>"
                        class="cart-thumb"
                      >
                    </a>
                  </td>
                  <td class="cart-name-cell">
                    <?php echo htmlspecialchars($item['name']); ?>
                    <?php if ($item['variant'] !== ''): ?>
                      <span class="cart-variant" style="display:block; font-size:0.82rem; color:var(--muted, #777); font-weight:400; margin-top:2px;"><?php echo htmlspecialchars($item['variant']); ?></span>
                    <?php endif; ?>
                  </td>
                  <td class="item-price" data-label="Price">JD <?php echo number_format((float)$item['price'], 2); ?></td>
                  <td data-label="Qty">
                    <div class="qty-control">
                      <button type="button" class="qty-btn js-qty-btn" data-direction="decrease">−</button>
                      <span class="qty-value"><?php echo (int)$item['quantity']; ?></span>
                      <button type="button" class="qty-btn js-qty-btn" data-direction="increase">+</button>
                    </div>
                  </td>
                  <td class="item-subtotal" data-label="Subtotal">JD <?php echo number_format((float)$item['subtotal'], 2); ?></td>
                  <td class="cart-action-cell">
                    <button type="button" class="btn product-btn js-remove-item">Remove</button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="cart-summary">
          <h3 id="cartTotalText">Total: JD <?php echo number_format((float)$totalPrice, 2); ?></h3>

          <div class="cart-actions">
            <button type="button" id="clearCartBtn" class="btn btn-ghost cart-clear">Clear Cart</button>
            <a href="/alke/products" class="btn btn-ghost">Continue Shopping</a>
            <a href="/alke/checkout" class="btn cart-checkout-btn">Proceed to Checkout</a>
          </div>
        </div>
      <?php else: ?>
        <p class="no-products">Your cart is empty. Add products from the shop page.</p>
        <div style="text-align:center; margin-top: 16px;">
          <a href="/alke/products" class="btn">Go to Shop</a>
        </div>
      <?php endif; ?>
    </div>
  </section>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const tableBody = document.getElementById('cartTableBody');
  const totalEl = document.getElementById('cartTotalText');
  const clearCartBtn = document.getElementById('clearCartBtn');
  const qtyTimers = {}; // debounce server sync per cart line

  function postCart(action, key, quantity) {
    const data = new FormData();
    data.append('action', action);
    data.append('key', key || '');
    data.append('csrf_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
    if (typeof quantity !== 'undefined') {
      data.append('quantity', quantity);
    }

    return fetch('/alke/update_cart', {
      method: 'POST',
      body: data
    }).then(function (res) { return res.json(); });
  }

  function updateBadge(count) {
    const badge = document.querySelector('.cart-count-badge');
    const toggleBtn = document.getElementById('cartDrawerToggle');

    if (badge) {
      if (Number(count) > 0) {
        badge.textContent = count;
      } else {
        badge.remove();
      }
      return;
    }

    if (toggleBtn && Number(count) > 0) {
      const span = document.createElement('span');
      span.className = 'cart-count-badge';
      span.textContent = count;
      toggleBtn.appendChild(span);
    }
  }

  function recalcTotal() {
    if (!tableBody || !totalEl) return;
    let total = 0;
    tableBody.querySelectorAll('.cart-row').forEach(function (row) {
      const subtotalText = row.querySelector('.item-subtotal') ? row.querySelector('.item-subtotal').textContent : '0';
      const subtotal = parseFloat(subtotalText.replace(/[^0-9.]/g, '')) || 0;
      total += subtotal;
    });
    totalEl.textContent = 'Total: JD ' + total.toFixed(2);
  }

  if (tableBody) {
    tableBody.addEventListener('click', function (e) {
      const qtyBtn = e.target.closest('.js-qty-btn');
      const removeBtn = e.target.closest('.js-remove-item');
      if (!qtyBtn && !removeBtn) return;

      const row = e.target.closest('.cart-row');
      if (!row) return;

      const lineKey = row.getAttribute('data-key');
      const unitPrice = parseFloat(row.getAttribute('data-price')) || 0;
      const qtyEl = row.querySelector('.qty-value');
      let currentQty = parseInt(qtyEl.textContent, 10) || 1;

      if (qtyBtn) {
        const direction = qtyBtn.getAttribute('data-direction');
        const newQty = direction === 'increase' ? currentQty + 1 : currentQty - 1;

        // Optimistic UI — update instantly so it feels snappy on mobile.
        if (newQty <= 0) {
          row.remove();
        } else {
          qtyEl.textContent = String(newQty);
          const subtotalCell = row.querySelector('.item-subtotal');
          if (subtotalCell) subtotalCell.textContent = 'JD ' + (unitPrice * newQty).toFixed(2);
        }
        recalcTotal();

        // Debounced sync to the server (handles rapid taps, avoids races).
        clearTimeout(qtyTimers[lineKey]);
        qtyTimers[lineKey] = setTimeout(function () {
          const finalQty = row.parentNode ? (parseInt(qtyEl.textContent, 10) || 0) : 0;
          postCart('update', lineKey, finalQty)
            .then(function (data) {
              if (!data || !data.success) { window.location.reload(); return; }
              updateBadge(data.cart_count);
              if (window.refreshMiniCart && data.mini_cart) window.refreshMiniCart(data.mini_cart);
              if (!tableBody.querySelector('.cart-row')) window.location.reload();
            })
            .catch(function () { window.location.reload(); });
        }, 300);
      }

      if (removeBtn) {
        postCart('remove', lineKey)
          .then(function (data) {
            if (!data || !data.success) {
              alert('Could not remove item.');
              return;
            }

            row.remove();
            updateBadge(data.cart_count);
            if (window.refreshMiniCart && data.mini_cart) {
              window.refreshMiniCart(data.mini_cart);
            }

            if (!tableBody.querySelector('.cart-row')) {
              window.location.reload();
              return;
            }

            recalcTotal();
          })
          .catch(function () {
            alert('Request failed. Please try again.');
          });
      }
    });
  }

  if (clearCartBtn) {
    clearCartBtn.addEventListener('click', function () {
      postCart('clear')
        .then(function () {
          window.location.reload();
        })
        .catch(function () {
          alert('Could not clear cart.');
        });
    });
  }
});
</script>

<?php include '../includes/footer.php'; ?>
