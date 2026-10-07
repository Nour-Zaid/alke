<?php
require_once __DIR__ . '/includes/auth.php';
include __DIR__ . '/../config/db.php';

$pageTitle  = 'Products';
$activePage = 'products';

admin_require_csrf(); // reject POST without a valid CSRF token

$message     = '';
$messageType = '';

/* ── Auto-migrate: add sizes + colors columns if missing ─ */
foreach (['sizes', 'colors'] as $col) {
    $chkCol = $conn->query("SHOW COLUMNS FROM products LIKE '$col'");
    if ($chkCol->num_rows === 0) {
        $conn->query("ALTER TABLE products ADD COLUMN $col VARCHAR(255) NOT NULL DEFAULT ''");
    }
}

/* Multi-image gallery: ensure table + backfill legacy single images. */
alke_ensure_product_images($conn);

/** Validate & store one uploaded image (by magic bytes). Returns [filename|null, error]. */
function alke_admin_store_image(array $file): array
{
    $mimeToExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [null, ''];
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) return [null, 'Image upload failed. Please try again.'];
    if ($file['size'] > 5 * 1024 * 1024) return [null, 'Each image must be under 5 MB.'];
    $info = @getimagesize($file['tmp_name']);
    $mime = $info['mime'] ?? '';
    if (!isset($mimeToExt[$mime])) return [null, 'Invalid image. Use a real JPG, PNG, GIF or WEBP file.'];
    $newName = 'product_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $mimeToExt[$mime];
    if (!move_uploaded_file($file['tmp_name'], __DIR__ . '/../assets/' . $newName)) {
        return [null, 'Failed to save image. Check that the assets folder is writable.'];
    }
    return [$newName, ''];
}

/** Set products.image to the product's first gallery image (or '' if none). */
function alke_admin_sync_cover(mysqli $conn, int $pid): void
{
    $cover = '';
    if ($r = $conn->query("SELECT image FROM product_images WHERE product_id = " . (int)$pid . " ORDER BY sort_order, id LIMIT 1")) {
        if ($row = $r->fetch_assoc()) $cover = (string)$row['image'];
    }
    if ($stmt = $conn->prepare("UPDATE products SET image = ? WHERE id = ?")) {
        $stmt->bind_param('si', $cover, $pid);
        $stmt->execute();
        $stmt->close();
    }
}

/** Pull all uploaded images from a product_images[] field into [filename,...]; sets $err on failure. */
function alke_admin_collect_uploads(string $field, string &$err): array
{
    $saved = [];
    if (empty($_FILES[$field]['name']) || !is_array($_FILES[$field]['name'])) return $saved;
    $f = $_FILES[$field];
    $n = count($f['name']);
    for ($i = 0; $i < $n; $i++) {
        [$fn, $e] = alke_admin_store_image([
            'name'     => $f['name'][$i],
            'tmp_name' => $f['tmp_name'][$i],
            'error'    => $f['error'][$i],
            'size'     => $f['size'][$i],
        ]);
        if ($e !== '') { $err = $e; break; }
        if ($fn !== null) $saved[] = $fn;
    }
    return $saved;
}

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
        $sizes       = implode(',', array_filter(array_map('trim', (array)($_POST['sizes']  ?? []))));
        $colors      = implode(',', array_filter(array_map('trim', (array)($_POST['colors'] ?? []))));

        if ($name === '') {
            $message = 'Product name is required.';
            $messageType = 'danger';
        } elseif ($price <= 0) {
            $message = 'Price must be greater than zero.';
            $messageType = 'danger';
        } else {
            // Handle one or more uploaded images (validated by magic bytes).
            $uploadErr   = '';
            $savedImages = alke_admin_collect_uploads('product_images', $uploadErr);
            if ($uploadErr !== '') {
                $message = $uploadErr;
                $messageType = 'danger';
            }

            if (empty($message)) {
                if ($action === 'add_product') {
                    $cover = $savedImages[0] ?? '';
                    $stmt = $conn->prepare(
                        "INSERT INTO products (name, description, price, stock, category_id, image, sizes, colors)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->bind_param('ssdissss', $name, $description, $price, $stock, $category_id, $cover, $sizes, $colors);
                    $ok = $stmt->execute();
                    $newId = $ok ? (int)$conn->insert_id : 0;
                    $stmt->close();

                    if ($ok && $newId > 0 && $savedImages) {
                        $ins = $conn->prepare("INSERT INTO product_images (product_id, image, sort_order) VALUES (?, ?, ?)");
                        foreach ($savedImages as $i => $img) {
                            $ins->bind_param('isi', $newId, $img, $i);
                            $ins->execute();
                        }
                        $ins->close();
                        alke_admin_sync_cover($conn, $newId);
                    }
                    $message     = $ok ? 'Product added successfully.' : 'Failed to add product: ' . $conn->error;
                    $messageType = $ok ? 'success' : 'danger';
                } else {
                    $product_id = (int)($_POST['product_id'] ?? 0);
                    // Update text fields only; the cover image is derived from the gallery.
                    $stmt = $conn->prepare(
                        "UPDATE products SET name=?, description=?, price=?, stock=?, category_id=?, sizes=?, colors=?
                         WHERE id=?"
                    );
                    $stmt->bind_param('ssdisssi', $name, $description, $price, $stock, $category_id, $sizes, $colors, $product_id);
                    $ok = $stmt->execute();
                    $stmt->close();

                    if ($ok && $product_id > 0 && $savedImages) {
                        // Append new images after any existing ones.
                        $next = 0;
                        if ($r = $conn->query("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM product_images WHERE product_id = " . (int)$product_id)) {
                            $next = (int)$r->fetch_row()[0];
                        }
                        $ins = $conn->prepare("INSERT INTO product_images (product_id, image, sort_order) VALUES (?, ?, ?)");
                        foreach ($savedImages as $img) {
                            $ins->bind_param('isi', $product_id, $img, $next);
                            $ins->execute();
                            $next++;
                        }
                        $ins->close();
                    }
                    if ($ok && $product_id > 0) alke_admin_sync_cover($conn, $product_id);
                    $message     = $ok ? 'Product updated successfully.' : 'Failed to update product: ' . $conn->error;
                    $messageType = $ok ? 'success' : 'danger';
                }
            }
        }
    }

    // DELETE a single gallery image
    if ($action === 'delete_image') {
        $imageId = (int)($_POST['image_id'] ?? 0);
        if ($imageId > 0) {
            $q = $conn->prepare("SELECT product_id, image FROM product_images WHERE id = ?");
            $q->bind_param('i', $imageId);
            $q->execute();
            $imgRow = $q->get_result()->fetch_assoc();
            $q->close();

            if ($imgRow) {
                $pid = (int)$imgRow['product_id'];
                $img = (string)$imgRow['image'];
                $d = $conn->prepare("DELETE FROM product_images WHERE id = ?");
                $d->bind_param('i', $imageId);
                $d->execute();
                $d->close();

                // Remove the file only if no other row still references it.
                $esc  = $conn->real_escape_string($img);
                $used = (int)$conn->query("SELECT COUNT(*) FROM product_images WHERE image = '$esc'")->fetch_row()[0];
                if ($used === 0 && $img !== '' && is_file(__DIR__ . '/../assets/' . $img)) {
                    @unlink(__DIR__ . '/../assets/' . $img);
                }
                alke_admin_sync_cover($conn, $pid);
                $message = 'Image removed.'; $messageType = 'success';
            }
        }
    }

    // DELETE
    if ($action === 'delete_product') {
        $product_id = (int)($_POST['product_id'] ?? 0);

        // Block deletion only if the product is in an active (non-closed) order
        $chk = $conn->prepare("
            SELECT COUNT(*) FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            WHERE oi.product_id = ? AND o.status IN ('pending','processing','shipped')
        ");
        $chk->bind_param('i', $product_id);
        $chk->execute();
        $usedIn = (int)$chk->get_result()->fetch_row()[0];
        $chk->close();

        if ($usedIn > 0) {
            $message     = "Cannot delete: this product is in $usedIn active order(s). Wait until those orders are delivered or cancelled.";
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

    // ADD CATEGORY
    if ($action === 'add_category') {
        $catName = trim($_POST['category_name'] ?? '');
        if ($catName === '' || mb_strlen($catName) > 100) {
            $message = 'Category name is required (max 100 characters).';
            $messageType = 'danger';
        } else {
            $dup = $conn->prepare("SELECT COUNT(*) FROM categories WHERE name = ?");
            $dup->bind_param('s', $catName);
            $dup->execute();
            $exists = (int)$dup->get_result()->fetch_row()[0];
            $dup->close();
            if ($exists > 0) {
                $message = "Category \"$catName\" already exists.";
                $messageType = 'danger';
            } else {
                $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
                $stmt->bind_param('s', $catName);
                $ok = $stmt->execute();
                $stmt->close();
                $message     = $ok ? "Category \"$catName\" added." : 'Could not add category.';
                $messageType = $ok ? 'success' : 'danger';
            }
        }
    }

    // DELETE CATEGORY (products in it become uncategorized via ON DELETE SET NULL)
    if ($action === 'delete_category') {
        $catId = (int)($_POST['category_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->bind_param('i', $catId);
        $ok = $stmt->execute();
        $stmt->close();
        $message     = $ok ? 'Category deleted. Any products in it are now uncategorized.' : 'Could not delete category.';
        $messageType = $ok ? 'success' : 'danger';
    }
}

/* ── Form repopulation on validation error ──────────── */
$fAction       = 'add_product';
$fProductId    = 0;
$fName         = '';
$fDescription  = '';
$fPrice        = '';
$fStock        = '';
$fCategoryId   = 0;
$fCurrentImage = '';
$fSizesArr     = [];
$fColorsArr    = [];

if (!empty($message) && $messageType === 'danger' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $fAction       = trim($_POST['action']       ?? 'add_product');
    $fProductId    = (int)($_POST['product_id']  ?? 0);
    $fName         = trim($_POST['name']         ?? '');
    $fDescription  = trim($_POST['description']  ?? '');
    $fPrice        = trim($_POST['price']        ?? '');
    $fStock        = trim($_POST['stock']        ?? '');
    $fCategoryId   = (int)($_POST['category_id'] ?? 0);
    $fCurrentImage = trim($_POST['current_image'] ?? '');
    $fSizesArr     = (array)($_POST['sizes']     ?? []);
    $fColorsArr    = (array)($_POST['colors']    ?? []);
}

/* ── Fetch data ─────────────────────────────────────── */
$categories = $conn->query("SELECT id, name FROM categories ORDER BY name");
$catList    = $conn->query("
    SELECT c.id, c.name, COUNT(p.id) AS cnt
    FROM categories c
    LEFT JOIN products p ON p.category_id = c.id
    GROUP BY c.id, c.name
    ORDER BY c.name
");
$products   = $conn->query("
    SELECT p.*, c.name AS category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    ORDER BY p.id DESC
");

// All gallery images grouped by product (for the edit panel).
$imagesByProduct = [];
if ($rImgs = $conn->query("SELECT id, product_id, image FROM product_images ORDER BY sort_order, id")) {
    while ($ri = $rImgs->fetch_assoc()) {
        $imagesByProduct[(int)$ri['product_id']][] = ['id' => (int)$ri['id'], 'image' => (string)$ri['image']];
    }
}

include __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?>
  <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<!-- Categories -->
<div class="admin-section" style="margin-bottom:24px;">
  <div class="admin-section-header"><h2>Categories</h2></div>
  <div class="admin-section-body">
    <form method="POST" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:14px;">
      <?= alke_csrf_field() ?>
      <input type="hidden" name="action" value="add_category">
      <input type="text" name="category_name" class="form-control" placeholder="New category name" required
             maxlength="100" style="max-width:260px;">
      <button type="submit" class="btn btn-sm">+ Add Category</button>
    </form>

    <?php if ($catList && $catList->num_rows > 0): ?>
      <div style="display:flex; flex-wrap:wrap; gap:8px;">
        <?php while ($cat = $catList->fetch_assoc()): ?>
          <span style="display:inline-flex; align-items:center; gap:8px; background:#f1f3f5; border:1px solid #e2e5e9; border-radius:20px; padding:5px 6px 5px 14px; font-size:0.85rem;">
            <?= htmlspecialchars($cat['name']) ?>
            <span style="color:#999;">(<?= (int)$cat['cnt'] ?>)</span>
            <form method="POST" style="display:inline; margin:0;"
                  onsubmit="return confirm('Delete category &quot;<?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>&quot;? Products in it become uncategorized.');">
              <?= alke_csrf_field() ?>
              <input type="hidden" name="action" value="delete_category">
              <input type="hidden" name="category_id" value="<?= (int)$cat['id'] ?>">
              <button type="submit" title="Delete category"
                      style="border:none; background:#e03131; color:#fff; width:20px; height:20px; border-radius:50%; cursor:pointer; line-height:1; font-size:0.9rem;">×</button>
            </form>
          </span>
        <?php endwhile; ?>
      </div>
    <?php else: ?>
      <p class="empty-state" style="padding:8px 0;">No categories yet.</p>
    <?php endif; ?>
  </div>
</div>

<!-- Add / Edit form (hidden by default) -->
<div class="admin-section" id="productFormPanel" style="display:none; margin-bottom: 24px;">
  <div class="admin-section-header">
    <h2 id="formPanelTitle">Add New Product</h2>
    <button type="button" class="btn btn-sm btn-outline" onclick="closeForm()">✕ Close</button>
  </div>
  <div class="admin-section-body">
    <form method="POST" enctype="multipart/form-data">
      <?= alke_csrf_field() ?>
      <input type="hidden" name="action"         id="fAction"       value="<?= htmlspecialchars($fAction) ?>">
      <input type="hidden" name="product_id"     id="fProductId"    value="<?= $fProductId ?>">
      <input type="hidden" name="current_image"  id="fCurrentImage" value="<?= htmlspecialchars($fCurrentImage) ?>">

      <div class="form-row form-row-2">
        <div class="form-group">
          <label for="fName">Product Name *</label>
          <input type="text" id="fName" name="name" class="form-control" required placeholder="e.g. Classic Black Tee" value="<?= htmlspecialchars($fName) ?>">
        </div>
        <div class="form-group">
          <label for="fCategory">Category</label>
          <select id="fCategory" name="category_id" class="form-control">
            <option value="">— None —</option>
            <?php if ($categories): $categories->data_seek(0); while ($cat = $categories->fetch_assoc()): ?>
              <option value="<?= (int)$cat['id'] ?>" <?= $fCategoryId === (int)$cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
            <?php endwhile; endif; ?>
          </select>
        </div>
      </div>

      <div class="form-row form-row-2">
        <div class="form-group">
          <label for="fPrice">Price (JD) *</label>
          <input type="number" id="fPrice" name="price" class="form-control" required min="0.01" step="0.01" placeholder="29.99" value="<?= htmlspecialchars($fPrice) ?>">
        </div>
        <div class="form-group">
          <label for="fStock">Stock (units)</label>
          <input type="number" id="fStock" name="stock" class="form-control" min="0" placeholder="0" value="<?= htmlspecialchars($fStock) ?>">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="fDescription">Description</label>
          <textarea id="fDescription" name="description" class="form-control" rows="3" placeholder="Optional product description..."><?= htmlspecialchars($fDescription) ?></textarea>
        </div>
      </div>

      <div class="form-row form-row-2">
        <div class="form-group">
          <label>Sizes Available</label>
          <div class="checkbox-group" id="fSizesGroup">
            <?php foreach (['XS','S','M','L','XL','XXL'] as $sz): ?>
              <label class="check-label">
                <input type="checkbox" name="sizes[]" value="<?= $sz ?>" <?= in_array($sz, $fSizesArr) ? 'checked' : '' ?>> <?= $sz ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="form-group">
          <label>Colors Available</label>
          <div class="checkbox-group" id="fColorsGroup">
            <?php foreach (['Black','White','Gray','Navy','Beige','Brown','Red','Blue','Green'] as $cl): ?>
              <label class="check-label">
                <input type="checkbox" name="colors[]" value="<?= $cl ?>" <?= in_array($cl, $fColorsArr) ? 'checked' : '' ?>> <?= $cl ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="fImage">Product Images <span style="color:#aaa;font-weight:400;">(JPG/PNG/WEBP, max 5 MB each — you can pick several)</span></label>

          <!-- Existing gallery images (edit mode) -->
          <div id="fExistingImages" class="existing-images" style="display:none; flex-wrap:wrap; gap:8px; margin-bottom:10px;"></div>

          <input type="file" id="fImage" name="product_images[]" class="form-control" accept="image/*" multiple onchange="previewImgs(this)">
          <p style="font-size:0.78rem; color:#888; margin-top:4px;">The first image is used as the main/cover photo. New uploads are added to the gallery.</p>
          <div id="imgPreview" class="img-preview-row" style="display:flex; flex-wrap:wrap; gap:8px; margin-top:8px;"></div>
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
    <div style="overflow-x:auto;">
    <table class="admin-table">
      <thead>
        <tr>
          <th></th>
          <th>#</th>
          <th>Name</th>
          <th>Category</th>
          <th>Price</th>
          <th>Stock</th>
          <th>Sizes</th>
          <th>Colors</th>
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
            <td data-label="ID"><?= (int)$p['id'] ?></td>
            <td data-label="Name"><?= htmlspecialchars($p['name']) ?></td>
            <td data-label="Category"><?= htmlspecialchars($p['category_name'] ?? '—') ?></td>
            <td data-label="Price">JD <?= number_format((float)$p['price'], 2) ?></td>
            <td data-label="Stock">
              <?php $s = (int)$p['stock']; ?>
              <span style="color: <?= $s === 0 ? '#dc3545' : ($s <= 5 ? '#d97706' : 'inherit') ?>; font-weight: <?= $s <= 5 ? 600 : 400 ?>;">
                <?= $s ?>
              </span>
            </td>
            <td data-label="Sizes" style="font-size:0.78rem; color:#666; white-space:nowrap;"><?= htmlspecialchars($p['sizes']  ?? '') ?: '—' ?></td>
            <td data-label="Colors" style="font-size:0.78rem; color:#666; white-space:nowrap;"><?= htmlspecialchars($p['colors'] ?? '') ?: '—' ?></td>
            <td data-label="" style="white-space:nowrap;">
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
                data-images='<?= htmlspecialchars(json_encode($imagesByProduct[(int)$p['id']] ?? [], JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>'
                data-sizes="<?= htmlspecialchars($p['sizes'] ?? '', ENT_QUOTES) ?>"
                data-colors="<?= htmlspecialchars($p['colors'] ?? '', ENT_QUOTES) ?>"
              >Edit</button>

              <form method="POST" style="display:inline;"
                    onsubmit="return confirm('Delete &quot;<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>&quot;? This cannot be undone.');">
                <?= alke_csrf_field() ?>
                <input type="hidden" name="action"     value="delete_product">
                <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
              </form>
            </td>
          </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
    </div>
  <?php else: ?>
    <p class="empty-state">No products yet. Click "Add New Product" to create one.</p>
  <?php endif; ?>
</div>

<script>
var ADMIN_CSRF = '<?= htmlspecialchars(alke_csrf_token(), ENT_QUOTES) ?>';

// Render the product's existing gallery images (with delete buttons) in edit mode.
function renderExistingImages(json) {
  var box = document.getElementById('fExistingImages');
  box.innerHTML = '';
  var imgs = [];
  try { imgs = JSON.parse(json || '[]'); } catch (e) { imgs = []; }
  if (!imgs.length) { box.style.display = 'none'; return; }
  box.style.display = 'flex';
  imgs.forEach(function (im, idx) {
    var wrap = document.createElement('div');
    wrap.style.cssText = 'position:relative; width:72px;';
    wrap.innerHTML =
      '<img src="/alke/assets/' + im.image + '" alt="" style="width:72px; height:90px; object-fit:cover; border-radius:5px; border:1px solid #e2e5e9;">'
      + (idx === 0 ? '<span style="position:absolute; left:2px; top:2px; background:#0f0f0f; color:#fff; font-size:0.6rem; padding:1px 5px; border-radius:8px;">Cover</span>' : '')
      + '<button type="button" title="Remove image" onclick="deleteProductImage(' + im.id + ')" '
      + 'style="position:absolute; top:-7px; right:-7px; width:20px; height:20px; border:none; border-radius:50%; background:#e03131; color:#fff; cursor:pointer; line-height:1; font-size:0.85rem;">&times;</button>';
    box.appendChild(wrap);
  });
}

function deleteProductImage(id) {
  if (!confirm('Remove this image?')) return;
  var f = document.createElement('form');
  f.method = 'POST';
  f.action = '/alke/admin/products';
  f.innerHTML = '<input type="hidden" name="csrf_token" value="' + ADMIN_CSRF + '">'
    + '<input type="hidden" name="action" value="delete_image">'
    + '<input type="hidden" name="image_id" value="' + id + '">';
  document.body.appendChild(f);
  f.submit();
}

function setCheckboxes(groupId, csv) {
  var vals = csv ? csv.split(',').map(function(v){ return v.trim(); }) : [];
  document.querySelectorAll('#' + groupId + ' input[type="checkbox"]').forEach(function(cb) {
    cb.checked = vals.indexOf(cb.value) !== -1;
  });
}

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
  document.getElementById('imgPreview').innerHTML = '';
  document.getElementById('fImage').value = '';
  renderExistingImages('[]');
  setCheckboxes('fSizesGroup', '');
  setCheckboxes('fColorsGroup', '');

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
    document.getElementById('imgPreview').innerHTML            = '';
    renderExistingImages(btn.dataset.images);
    setCheckboxes('fSizesGroup',  btn.dataset.sizes  || '');
    setCheckboxes('fColorsGroup', btn.dataset.colors || '');

    var panel = document.getElementById('productFormPanel');
    panel.style.display = 'block';
    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
});

function closeForm() {
  document.getElementById('productFormPanel').style.display = 'none';
}

// Live previews for one or more selected files
function previewImgs(input) {
  var row = document.getElementById('imgPreview');
  row.innerHTML = '';
  if (!input.files) return;
  Array.prototype.forEach.call(input.files, function (file) {
    var reader = new FileReader();
    reader.onload = function (e) {
      var img = document.createElement('img');
      img.src = e.target.result;
      img.style.cssText = 'width:72px; height:90px; object-fit:cover; border-radius:5px; border:1px solid #e2e5e9;';
      row.appendChild(img);
    };
    reader.readAsDataURL(file);
  });
}

// If there was a form error, keep the form open with correct state
<?php if (!empty($message) && $messageType === 'danger'): ?>
document.getElementById('productFormPanel').style.display = 'block';
document.getElementById('formPanelTitle').textContent = '<?= $fAction === 'edit_product' ? 'Edit Product' : 'Add New Product' ?>';
document.getElementById('fSubmitBtn').textContent     = '<?= $fAction === 'edit_product' ? 'Save Changes' : 'Add Product' ?>';
<?php endif; ?>
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
