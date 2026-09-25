<footer class="site-footer">
    <div class="container footer-grid">
      <div>
        <h3>Alke Clothes</h3>
        <p>Modern premium fashion for everyday confidence.</p>
      </div>

      <div>
        <h4>Quick Links</h4>
        <ul>
          <li><a href="/alke/index.php">Home</a></li>
          <li><a href="/alke/pages/products.php">Shop</a></li>
          <li><a href="/alke/pages/products.php?category=1">Men</a></li>
          <li><a href="/alke/pages/products.php?category=2">Women</a></li>
          <li><a href="/alke/pages/contact.php">Contact</a></li>
        </ul>
      </div>

      <div>
        <h4>Contact</h4>
        <p>Email: hello@alkeclothes.com</p>
        <p>Phone: +1 (555) 123-4567</p>
        <p>City: New York, USA</p>
      </div>
    </div>

    <div class="footer-bottom">
      <p>&copy; <?php echo date('Y'); ?> Alke Clothes. All rights reserved.</p>
    </div>
  </footer>

  <script>
    const navToggle = document.getElementById('navToggle');
    const siteNav = document.getElementById('siteNav');

    if (navToggle && siteNav) {
      navToggle.addEventListener('click', function () {
        const expanded = navToggle.getAttribute('aria-expanded') === 'true';
        navToggle.setAttribute('aria-expanded', (!expanded).toString());
        siteNav.classList.toggle('open');
      });
    }

    const cartDrawerToggle = document.getElementById('cartDrawerToggle');
    const cartDrawer = document.getElementById('cartDrawer');
    const cartDrawerClose = document.getElementById('cartDrawerClose');
    const cartDrawerOverlay = document.getElementById('cartDrawerOverlay');

    function openCartDrawer() {
      if (!cartDrawer) return;
      cartDrawer.classList.add('open');
      if (cartDrawerOverlay) cartDrawerOverlay.classList.add('show');
      cartDrawer.setAttribute('aria-hidden', 'false');
    }

    function closeCartDrawer() {
      if (!cartDrawer) return;
      cartDrawer.classList.remove('open');
      if (cartDrawerOverlay) cartDrawerOverlay.classList.remove('show');
      cartDrawer.setAttribute('aria-hidden', 'true');
    }

    if (cartDrawerToggle) {
      cartDrawerToggle.addEventListener('click', function () {
        if (cartDrawer && cartDrawer.classList.contains('open')) {
          closeCartDrawer();
        } else {
          openCartDrawer();
        }
      });
    }

    if (cartDrawerClose) {
      cartDrawerClose.addEventListener('click', closeCartDrawer);
    }

    if (cartDrawerOverlay) {
      cartDrawerOverlay.addEventListener('click', closeCartDrawer);
    }

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        closeCartDrawer();
      }
    });

    const productQtySelect = document.getElementById('productQty');
    const selectedQtyInput = document.getElementById('selectedQty');
    if (productQtySelect && selectedQtyInput) {
      productQtySelect.addEventListener('change', function () {
        selectedQtyInput.value = productQtySelect.value;
      });
    }
  </script>
</body>
</html>
