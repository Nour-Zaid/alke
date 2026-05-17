<?php
require_once __DIR__ . '/includes/auth.php';
include __DIR__ . '/../config/db.php';

$pageTitle  = 'Products';
$activePage = 'products';

$message     = '';
$messageType = '';

/* ── Handle form submissions ────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    // ADD or EDIT
    if ($action === 'add_product' || $action === 'edit_product') {
        $name        = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price       = (float)($_POST['price'] ?? 0);
        $stock       = max(0, (int)($_POST['stock'] ?? 0));
        $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $imageName   = trim($_POST['current_image'] ?? '');

        if ($name === '') {
            $message = 'Product name is required.';
            $messageType = 'danger';
        } elseif ($price <= 0) {
            $message = 'Price must be greater than zero.';
            $messageType = 'danger';
        } else {
            // Handle image upload
            if (!empty($_FILES['product_image']['name'])) {
                $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                $ext = strtolower(pathinfo($_FILES['product_image']['name'], PATHINFO_EXTENSION));

                if (!in_array($ext, $allowed)) {
                    $message = 'Invalid image type. Use JPG, PNG, GIF or WEBP.';
                    $messageType = 'danger';
                } elseif ($_FILES['product_image']['size'] > 5 * 1024 * 1024) {
                    $message = 'Image must be under 5 MB.';
                    $messageType = 'danger';
                } else {
                    $newName = 'product_' . time() . '_' . rand(100, 999) . '.' . $ext;
                    $dest    = __DIR__ . '/../assets/' . $newName;
                    if (move_uploaded_file($_FILES['product_image']['tmp_name'], $dest)) {
                        $imageName = $newName;
                    } else {
                        $message = 'Failed to save image. Check that the assets folder is writable.';
                        $messageType = 'danger';
                    }
                }
            }

            if (empty($message)) {
                if ($action === 'add_product') {
                    $stmt = $conn->prepare(
                        "INSERT INTO products (name, description, price, stock, category_id, image)
                         VALUES (?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->bind_param('ssdiss', $name, $description, $price, $stock, $category_id, $imageName);
                    $ok = $stmt->execute();
                    $stmt->close();
                    $message     = $ok ? 'Product added successfully.' : 'Failed to add product: ' . $conn->error;
                    $messageType = $ok ? 'success' : 'danger';
                } else {
                    $product_id = (int)($_POST['product_id'] ?? 0);
                    $stmt = $conn->prepare(
                        "UPDATE products SET name=?, description=?, price=?, stock=?, category_id=?, image=?
                         WHERE id=?"
                    );
                    $stmt->bind_param('ssdissi', $name, $description, $price, $stock, $category_id, $imageName, $product_id);
                    $ok = $stmt->execute();
                    $stmt->close();
                    $message     = $ok ? 'Product updated successfully.' : 'Failed to update product: ' . $conn->error;
                    $messageType = $ok ? 'success' : 'danger';
                }
            }
        }
    }

    // DELETE
    if ($action === 'delete_product') {
        $product_id = (int)($_POST['product_id'] ?? 0);

        // Check for existing order references
        $chk = $conn->prepare("SELECT COUNT(*) FROM order_items WHERE product_id = ?");
        $chk->bind_param('i', $product_id);
        $chk->execute();
        $usedIn = (int)$chk->get_result()->fetch_row()[0];
        $chk->close();

        if ($usedIn > 0) {
            $message     = "Cannot delete: this product appears in $usedIn order(s). Update the product instead.";
            $messageType = 'danger';
        } else {
            $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
            $stmt->bind_param('i', $product_id);
            $ok = $stmt->execute();
            $stmt->close();
            $message     = $ok ? 'Product deleted.' : 'Failed to delete product.';
            $messageType = $ok ? 'success' : 'danger';
        }
    }
}

/* ── Fetch data ─────────────────────────────────────── */
$categories = $conn->query("SELECT id, name FROM categories ORDER BY name");
$products   = $conn->query("
    SELECT p.*, c.name AS category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    ORDER BY p.id DESC
");

include __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?>
  <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<!-- Add / Edit form (hidden by default) -->
<div class="admin-section" id="productFormPanel" style="display:none; margin-bottom: 24px;">
  <div class="admin-section-header">
    <h2 id="formPanelTitle">Add New Product</h2>
    <button type="button" class="btn btn-sm btn-outline" onclick="closeForm()">✕ Close</button>
  </div>
  <div class="admin-section-body">
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action"         id="fAction"       value="add_product">
      <input type="hidden" name="product_id"     id="fProductId"    value="">
      <input type="hidden" name="current_image"  id="fCurrentImage" value="">

      <div class="form-row form-row-2">
        <div class="form-group">
          <label for="fName">Product Name *</label>
          <input type="text" id="fName" name="name" class="form-control" required placeholder="e.g. Classic Black Tee">
        </div>
        <div class="form-group">
          <label for="fCategory">Category</label>
          <select id="fCategory" name="category_id" class="form-control">
            <option value="">— None —</option>
            <?php if ($categories): $categories->data_seek(0); while ($cat = $categories->fetch_assoc()): ?>
              <option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
            <?php endwhile; endif; ?>
          </select>
        </div>
      </div>

      <div class="form-row form-row-2">
        <div class="form-group">
          <label for="fPrice">Price ($) *</label>
          <input type="number" id="fPrice" name="price" class="form-control" required min="0.01" step="0.01" placeholder="29.99">
        </div>
        <div class="form-group">
          <label for="fStock">Stock (units)</label>
          <input type="number" id="fStock" name="stock" class="form-control" min="0" placeholder="0">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="fDescription">Description</label>
          <textarea id="fDescription" name="description" class="form-control" rows="3" placeholder="Optional product description..."></textarea>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="fImage">Product Image <span style="color:#aaa;font-weight:400;">(JPG/PNG/WEBP, max 5 MB)</span></label>
          <input type="file" id="fImage" name="product_image" class="form-control" accept="image/*" onchange="previewImg(this)">
          <img id="imgPreview" class="img-preview" src="" alt="" style="display:none;">
        </div>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn" id="fSubmitBtn">Add Product</button>
        <button type="button" class="btn btn-outline" onclick="closeForm()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Products list -->
<div class="admin-section">
  <div class="admin-section-header">
    <h2>All Products (<?= $products ? $products->num_rows : 0 ?>)</h2>
    <button type="button" class="btn btn-sm" onclick="openAddForm()">+ Add New Product</button>
  </div>

  <?php if ($products && $products->num_rows > 0): ?>
    <table class="admin-table">
      <thead>
        <tr>
          <th></th>
          <th>#</th>
          <th>Name</th>
          <th>Category</th>
          <th>Price</th>
          <th>Stock</th>
          <th style="width:130px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php while ($p = $products->fetch_assoc()):
          $dbImg  = trim($p['image'] ?? '');
          $imgSrc = '/alke/testblackshirt.jpeg';
          if (!empty($dbImg) && file_exists(__DIR__ . '/../assets/' . $dbImg)) {
              $imgSrc = '/alke/assets/' . $dbImg;
          }
        ?>
          <tr>
            <td><img src="<?= htmlspecialchars($imgSrc) ?>" class="product-thumb" alt=""></td>
            <td><?= (int)$p['id'] ?></td>
            <td><?= htmlspecialchars($p['name']) ?></td>
            <td><?= htmlspecialchars($p['category_name'] ?? '—') ?></td>
            <td>$<?= number_format((float)$p['price'], 2) ?></td>
            <td>
              <?php $s = (int)$p['stock']; ?>
              <span style="color: <?= $s === 0 ? '#dc3545' : ($s <= 5 ? '#d97706' : 'inherit') ?>; font-weight: <?= $s <= 5 ? 600 : 400 ?>;">
                <?= $s ?>
              </span>
            </td>
            <td style="white-space:nowrap;">
              <button
                type="button"
                class="btn btn-sm btn-warning edit-btn"
                data-id="<?= (int)$p['id'] ?>"
                data-name="<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>"
                data-desc="<?= htmlspecialchars($p['description'] ?? '', ENT_QUOTES) ?>"
                data-price="<?= (float)$p['price'] ?>"
                data-stock="<?= (int)$p['stock'] ?>"
                data-category="<?= (int)($p['category_id'] ?? 0) ?>"
                data-image="<?= htmlspecialchars($dbImg, ENT_QUOTES) ?>"
              >Edit</button>

              <form method="POST" style="display:inline;"
                    onsubmit="return confirm('Delete &quot;<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>&quot;? This cannot be undone.');">
                <input type="hidden" name="action"     value="delete_product">
                <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
              </form>
            </td>
          </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="empty-state">No products yet. Click "Add New Product" to create one.</p>
  <?php endif; ?>
</div>

<script>
// Open form for adding a new product
function openAddForm() {
  document.getElementById('formPanelTitle').textContent = 'Add New Product';
  document.getElementById('fAction').value      = 'add_product';
  document.getElementById('fProductId').value   = '';
  document.getElementById('fCurrentImage').value = '';
  document.getElementById('fName').value        = '';
  document.getElementById('fDescription').value = '';
  document.getElementById('fPrice').value       = '';
  document.getElementById('fStock').value       = '';
  document.getElementById('fCategory').value    = '';
  document.getElementById('fSubmitBtn').textContent = 'Add Product';
  document.getElementById('imgPreview').style.display = 'none';
  document.getElementById('fImage').value = '';

  var panel = document.getElementById('productFormPanel');
  panel.style.display = 'block';
  panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// Open form for editing an existing product (using data-* attributes)
document.querySelectorAll('.edit-btn').forEach(function (btn) {
  btn.addEventListener('click', function () {
    document.getElementById('formPanelTitle').textContent      = 'Edit Product';
    document.getElementById('fAction').value                   = 'edit_product';
    document.getElementById('fProductId').value                = btn.dataset.id;
    document.getElementById('fCurrentImage').value             = btn.dataset.image;
    document.getElementById('fName').value                     = btn.dataset.name;
    document.getElementById('fDescription').value              = btn.dataset.desc;
    document.getElementById('fPrice').value                    = btn.dataset.price;
    document.getElementById('fStock').value                    = btn.dataset.stock;
    document.getElementById('fCategory').value                 = btn.dataset.category;
    document.getElementById('fSubmitBtn').textContent          = 'Save Changes';
    document.getElementById('fImage').value                    = '';

    var preview = document.getElementById('imgPreview');
    if (btn.dataset.image) {
      preview.src = '/alke/assets/' + btn.dataset.image;
      preview.style.display = 'block';
    } else {
      preview.style.display = 'none';
    }

    var panel = document.getElementById('productFormPanel');
    panel.style.display = 'block';
    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
});

function closeForm() {
  document.getElementById('productFormPanel').style.display = 'none';
}

// Live image preview on file select
function previewImg(input) {
  var preview = document.getElementById('imgPreview');
  if (input.files && input.files[0]) {
    var reader = new FileReader();
    reader.onload = function (e) {
      preview.src = e.target.result;
      preview.style.display = 'block';
    };
    reader.readAsDataURL(input.files[0]);
  }
}

// If there was a form error, keep the form open
<?php if (!empty($message) && $messageType === 'danger'): ?>
document.getElementById('productFormPanel').style.display = 'block';
<?php endif; ?>
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
