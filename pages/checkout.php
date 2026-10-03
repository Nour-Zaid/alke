<?php
session_start();
include '../config/db.php';
include '../includes/helpers.php';
include '../includes/mailer.php';

/* Auto-migrate: add shipping + payment + discount columns to orders if missing */
foreach (['ship_name' => 'VARCHAR(150)', 'ship_email' => 'VARCHAR(150)', 'ship_phone' => 'VARCHAR(40)',
          'ship_address' => 'VARCHAR(255)', 'ship_city' => 'VARCHAR(100)', 'ship_country' => 'VARCHAR(100)',
          'ship_postal_code' => 'VARCHAR(30)', 'payment_method' => 'VARCHAR(30)',
          'payment_proof' => 'VARCHAR(255)', 'coupon_code' => 'VARCHAR(50)',
          'discount_amount' => 'DECIMAL(10,2)', 'shipping_fee' => 'DECIMAL(10,2)',
          'delivery_area' => 'VARCHAR(20)'] as $col => $type) {
    $chk = $conn->query("SHOW COLUMNS FROM orders LIKE '$col'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE orders ADD COLUMN $col $type DEFAULT NULL");
    }
}

/* Ensure the coupons table exists (so coupon validation works everywhere). */
$conn->query("
    CREATE TABLE IF NOT EXISTS coupons (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(50) NOT NULL UNIQUE,
        type ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
        value DECIMAL(10,2) NOT NULL DEFAULT 0,
        min_order DECIMAL(10,2) NOT NULL DEFAULT 0,
        max_uses INT NOT NULL DEFAULT 0,
        used_count INT NOT NULL DEFAULT 0,
        expires_at DATE DEFAULT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

/* Supported payment methods (value => label) */
$paymentMethods = [
    'cod'  => 'Cash on Delivery',
    'cliq' => 'Pay with CLIQ',
];

/* Delivery areas and their flat shipping fee (JD). */
$shippingRates  = ['amman' => 2.00, 'outside' => 3.00];
$shippingLabels = ['amman' => 'Inside Amman', 'outside' => 'Outside Amman'];

/* CLIQ payee alias + the registered business name that appears to the sender. */
$cliqAlias        = 'Alke';
$cliqBusinessName = 'Sharikat Rowad Al-Aqmisha for design &amp; manufacturing of embroidered clothing';

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$cartItems = [];
$totalPrice = 0;
$orderPlaced = false;
$errorMessage = '';

if (!empty($_SESSION['cart'])) {
    $productIds = array_map('intval', array_keys($_SESSION['cart']));
    if (!empty($productIds)) {
        $idsList = implode(',', $productIds);
        $result = $conn->query("SELECT id, name, price, image FROM products WHERE id IN ($idsList)");

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $productId = (int)$row['id'];
                $quantity = isset($_SESSION['cart'][$productId]) ? (int)$_SESSION['cart'][$productId] : 1;
                $quantity = max(1, $quantity);

                $imagePath = alke_product_image($row);

                $subtotal = ((float)$row['price']) * $quantity;
                $totalPrice += $subtotal;

                $cartItems[] = [
                    'id' => $productId,
                    'name' => $row['name'],
                    'price' => (float)$row['price'],
                    'quantity' => $quantity,
                    'image_path' => $imagePath,
                    'subtotal' => $subtotal
                ];
            }
        }
    }
}

/*
  Simple payment hook placeholder for future gateway integration.
  You can replace this with gateway preparation/intent creation later.
*/
function preparePaymentPayload($orderId, $amount, $customerName, $customerEmail)
{
    return [
        'order_id' => $orderId,
        'amount' => $amount,
        'customer_name' => $customerName,
        'customer_email' => $customerEmail,
        'currency' => 'USD',
        'gateway_status' => 'not_configured'
    ];
}

// Step 1: Validate form fields and store in session for review
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['place_order'])) {
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $address = isset($_POST['address']) ? trim($_POST['address']) : '';
    $city = isset($_POST['city']) ? trim($_POST['city']) : '';
    $country = isset($_POST['country']) ? trim($_POST['country']) : '';
    $postalCode = isset($_POST['postal_code']) ? trim($_POST['postal_code']) : '';
    $paymentMethod = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : '';
    $deliveryArea = isset($_POST['delivery_area']) ? trim($_POST['delivery_area']) : '';
    $couponCode = isset($_POST['coupon_code']) ? trim($_POST['coupon_code']) : '';

    $_SESSION['user_phone'] = $phone;

    $couponCheck = alke_validate_coupon($conn, $couponCode, (float)$totalPrice);

    // CLIQ payment screenshot — uploaded with THIS form, before the order is placed.
    // Persist it in the session so it survives the review step and validation retries
    // (browsers don't re-populate file inputs when the user edits and resubmits).
    $proofError = '';
    $proofPath  = $_SESSION['pending_proof'] ?? null;
    if ($paymentMethod === 'cliq') {
        $f = $_FILES['payment_proof'] ?? null;
        $hasNewFile = $f && isset($f['error']) && $f['error'] !== UPLOAD_ERR_NO_FILE;
        if ($hasNewFile) {
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                $proofError = 'Screenshot upload failed. Please try again.';
            } elseif ($f['size'] > 5 * 1024 * 1024) {
                $proofError = 'Screenshot must be under 5 MB.';
            } else {
                $info = @getimagesize($f['tmp_name']);
                $mime = $info['mime'] ?? '';
                if (!isset($allowed[$mime])) {
                    $proofError = 'Please upload a JPG, PNG or WebP image.';
                } else {
                    $dir = __DIR__ . '/../assets/uploads/proofs';
                    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
                    $fname = 'pending_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
                    if (move_uploaded_file($f['tmp_name'], $dir . '/' . $fname)) {
                        // Drop a previously uploaded pending screenshot to avoid orphans.
                        if ($proofPath && strpos($proofPath, 'uploads/proofs/pending_') === 0
                            && is_file(__DIR__ . '/../assets/' . $proofPath)) {
                            @unlink(__DIR__ . '/../assets/' . $proofPath);
                        }
                        $proofPath = 'uploads/proofs/' . $fname;
                        $_SESSION['pending_proof'] = $proofPath;
                    } else {
                        $proofError = 'Could not save the screenshot. Please try again.';
                    }
                }
            }
        }
        if ($proofError === '' && empty($proofPath)) {
            $proofError = 'Please upload a screenshot of your CLIQ payment.';
        }
    } else {
        // Cash on delivery (or any non-CLIQ method) needs no screenshot.
        $proofPath = null;
    }

    if (!alke_csrf_check()) {
        $errorMessage = 'Your session expired. Please try again.';
    } elseif (empty($cartItems)) {
        $errorMessage = 'Your cart is empty.';
    } elseif ($name === '' || $email === '' || $phone === '' || $address === '' || $city === '' || $country === '' || $postalCode === '') {
        $errorMessage = 'Please fill all checkout details.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'Please enter a valid email address.';
    } elseif (!isset($paymentMethods[$paymentMethod])) {
        $errorMessage = 'Please select a payment method.';
    } elseif (!isset($shippingRates[$deliveryArea])) {
        $errorMessage = 'Please select your delivery area.';
    } elseif ($proofError !== '') {
        $errorMessage = $proofError;
    } elseif (!$couponCheck['ok']) {
        $errorMessage = $couponCheck['message'];
    } else {
        $couponCode = $couponCheck['code']; // normalized (may be '')
        $_SESSION['pending_order'] = compact('name', 'email', 'phone', 'address', 'city', 'country', 'postalCode', 'paymentMethod', 'deliveryArea', 'couponCode');
        $_SESSION['pending_order']['proof'] = $proofPath;
        $orderPlaced = true;
    }
}

// Step 2: User confirmed — insert order in a transaction with stock checks
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['confirm_order'])) {
    if (!alke_csrf_check()) {
        $errorMessage = 'Your session expired. Please try again.';
    } elseif (empty($cartItems) || empty($_SESSION['pending_order'])) {
        $errorMessage = 'Session expired. Please fill checkout details again.';
    } else {
        $pending = $_SESSION['pending_order'];
        $status  = 'pending';
        // Guest checkout: orders are placed without an account.
        $user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        // Fall back to COD if an unknown method somehow slipped through.
        $paymentMethod = isset($paymentMethods[$pending['paymentMethod'] ?? '']) ? $pending['paymentMethod'] : 'cod';

        $conn->begin_transaction();

        try {
            /* 1. Lock product rows and verify stock */
            foreach ($cartItems as $item) {
                $pid = (int)$item['id'];
                $qty = (int)$item['quantity'];

                $stmtLock = $conn->prepare("SELECT name, stock FROM products WHERE id = ? FOR UPDATE");
                $stmtLock->bind_param('i', $pid);
                $stmtLock->execute();
                $lockRes = $stmtLock->get_result();
                $lockRow = $lockRes ? $lockRes->fetch_assoc() : null;
                $stmtLock->close();

                if (!$lockRow) {
                    throw new Exception('A product in your cart is no longer available.');
                }
                if ((int)$lockRow['stock'] < $qty) {
                    throw new Exception('"' . $lockRow['name'] . '" only has ' . (int)$lockRow['stock'] . ' in stock. Please update your cart.');
                }
            }

            /* 2. Coupon: re-validate and atomically claim a use (single-use safe).
                  The discount is always recomputed from the DB, never trusted. */
            $discount    = 0.0;
            $couponFinal = null;
            $couponWanted = trim((string)($pending['couponCode'] ?? ''));
            if ($couponWanted !== '') {
                $cv = alke_validate_coupon($conn, $couponWanted, (float)$totalPrice);
                if ($cv['ok'] && $cv['discount'] > 0) {
                    $claim = $conn->prepare(
                        "UPDATE coupons SET used_count = used_count + 1
                         WHERE code = ? AND active = 1
                           AND (max_uses = 0 OR used_count < max_uses)
                           AND (expires_at IS NULL OR expires_at >= CURDATE())"
                    );
                    $claim->bind_param('s', $cv['code']);
                    $claim->execute();
                    if ($claim->affected_rows === 1) {
                        $discount    = $cv['discount'];
                        $couponFinal = $cv['code'];
                    }
                    $claim->close();
                }
            }
            // Shipping: recomputed server-side from the chosen area (never trusted from the client).
            $deliveryArea = isset($shippingRates[$pending['deliveryArea'] ?? '']) ? $pending['deliveryArea'] : 'outside';
            $shippingFee  = $shippingRates[$deliveryArea];

            $finalTotal = round(max(0, (float)$totalPrice - $discount) + $shippingFee, 2);

            /* 3. Insert order with shipping + payment + discount details.
                  For CLIQ, the screenshot was already uploaded on the checkout form. */
            $proofForOrder = ($paymentMethod === 'cliq') ? ($pending['proof'] ?? null) : null;
            $stmtOrder = $conn->prepare("
                INSERT INTO orders
                    (user_id, total_price, status, payment_method, payment_proof, coupon_code, discount_amount,
                     shipping_fee, delivery_area,
                     ship_name, ship_email, ship_phone, ship_address, ship_city, ship_country, ship_postal_code)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtOrder->bind_param(
                "idssssddssssssss",
                $user_id,
                $finalTotal,
                $status,
                $paymentMethod,
                $proofForOrder,
                $couponFinal,
                $discount,
                $shippingFee,
                $deliveryArea,
                $pending['name'],
                $pending['email'],
                $pending['phone'],
                $pending['address'],
                $pending['city'],
                $pending['country'],
                $pending['postalCode']
            );
            if (!$stmtOrder->execute()) {
                throw new Exception('Could not save your order. Please try again.');
            }
            $order_id = (int)$conn->insert_id;
            $stmtOrder->close();

            /* 3. Insert items and decrement stock */
            $stmtItem  = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity) VALUES (?, ?, ?)");
            $stmtStock = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");

            foreach ($cartItems as $item) {
                $pid = (int)$item['id'];
                $qty = (int)$item['quantity'];

                $stmtItem->bind_param("iii", $order_id, $pid, $qty);
                if (!$stmtItem->execute()) {
                    throw new Exception('Could not save your order items. Please try again.');
                }

                $stmtStock->bind_param("ii", $qty, $pid);
                if (!$stmtStock->execute()) {
                    throw new Exception('Could not update product stock. Please try again.');
                }
            }

            $stmtItem->close();
            $stmtStock->close();

            $conn->commit();

            /* Send emails (non-blocking: failures are logged, never shown). */
            $payLabel = $paymentMethods[$paymentMethod] ?? 'Cash on Delivery';

            // Shared line-item rows (used in both the customer and store emails).
            $rows = '';
            foreach ($cartItems as $it) {
                $rows .= '<tr>'
                       . '<td style="padding:10px 0;border-bottom:1px solid #f0f0f0;color:#2a2a2a;">'
                       . htmlspecialchars($it['name'])
                       . ' <span style="color:#8a8a8a;">× ' . (int)$it['quantity'] . '</span></td>'
                       . '<td align="right" style="padding:10px 0;border-bottom:1px solid #f0f0f0;color:#2a2a2a;white-space:nowrap;">JD ' . number_format((float)$it['subtotal'], 2) . '</td>'
                       . '</tr>';
            }
            if ($discount > 0) {
                $rows .= '<tr>'
                       . '<td style="padding:10px 0;border-bottom:1px solid #f0f0f0;color:#1e8f4e;">Discount'
                       . ($couponFinal ? ' (' . htmlspecialchars($couponFinal) . ')' : '') . '</td>'
                       . '<td align="right" style="padding:10px 0;border-bottom:1px solid #f0f0f0;color:#1e8f4e;white-space:nowrap;">− JD ' . number_format($discount, 2) . '</td>'
                       . '</tr>';
            }
            $rows .= '<tr>'
                   . '<td style="padding:10px 0;border-bottom:1px solid #f0f0f0;color:#2a2a2a;">Delivery <span style="color:#8a8a8a;">(' . htmlspecialchars($shippingLabels[$deliveryArea] ?? '') . ')</span></td>'
                   . '<td align="right" style="padding:10px 0;border-bottom:1px solid #f0f0f0;color:#2a2a2a;white-space:nowrap;">JD ' . number_format($shippingFee, 2) . '</td>'
                   . '</tr>';
            $rows .= '<tr>'
                   . '<td style="padding:12px 0 0;font-weight:700;color:#0f0f0f;">Total</td>'
                   . '<td align="right" style="padding:12px 0 0;font-weight:700;color:#0f0f0f;white-space:nowrap;">JD ' . number_format($finalTotal, 2) . '</td>'
                   . '</tr>';
            $itemsTable = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
                        . 'style="border-collapse:collapse;font-size:14px;margin:4px 0 20px;">' . $rows . '</table>';

            // ── Customer receipt ──────────────────────────────────────────
            $payNote = $paymentMethod === 'cliq'
                ? '<p style="margin:0 0 14px;background:#f6efe6;border:1px solid #e5d0b5;border-radius:8px;padding:12px 14px;">We received your <strong>CLIQ payment screenshot</strong> and will confirm it shortly.</p>'
                : '<p style="margin:0 0 14px;background:#f6efe6;border:1px solid #e5d0b5;border-radius:8px;padding:12px 14px;">You chose <strong>Cash on Delivery</strong> — please have <strong>JD ' . number_format($finalTotal, 2) . '</strong> ready when your order arrives.</p>';

            $custBody = '<p style="margin:0 0 14px;">Hi ' . htmlspecialchars($pending['name']) . ', thanks for shopping with Alke! '
                      . 'We\'ve received your order <strong>#' . $order_id . '</strong> and are getting it ready.</p>'
                      . '<p style="margin:0 0 6px;font-weight:700;color:#0f0f0f;">Order summary</p>'
                      . $itemsTable
                      . '<p style="margin:0 0 14px;">Payment method: <strong>' . htmlspecialchars($payLabel) . '</strong></p>'
                      . $payNote
                      . '<p style="margin:0 0 4px;font-weight:700;color:#0f0f0f;">Shipping to</p>'
                      . '<p style="margin:0;color:#555;">' . htmlspecialchars($pending['name']) . '<br>'
                      . htmlspecialchars($pending['address']) . '<br>'
                      . htmlspecialchars($pending['city'] . ', ' . $pending['country'] . ' ' . $pending['postalCode']) . '<br>'
                      . htmlspecialchars($pending['phone']) . '</p>';

            $custHtml = alke_email_template(
                'Thank you for your order!',
                $custBody,
                'Order #' . $order_id . ' confirmed — JD ' . number_format($finalTotal, 2)
            );
            $replyTo = getenv('MAIL_REPLY_TO') ?: 'alkeclothingco@gmail.com';
            @alke_send_email($pending['email'], 'Your Alke order #' . $order_id, $custHtml, null, $replyTo);

            // ── Store notification (goes to the team inbox) ───────────────
            $storeTo   = getenv('STORE_EMAIL') ?: 'alkeclothingco@gmail.com';
            $storeBody = '<p style="margin:0 0 14px;"><strong>' . htmlspecialchars($payLabel) . '</strong> · '
                       . htmlspecialchars($pending['name']) . ' · '
                       . '<a href="mailto:' . htmlspecialchars($pending['email']) . '" style="color:#b8916a;">' . htmlspecialchars($pending['email']) . '</a> · '
                       . htmlspecialchars($pending['phone']) . '</p>'
                       . '<p style="margin:0 0 14px;color:#555;">' . htmlspecialchars($pending['address'] . ', ' . $pending['city'] . ', '
                               . $pending['country'] . ' ' . $pending['postalCode']) . '</p>'
                       . $itemsTable;
            $storeHtml = alke_email_template(
                'New order #' . $order_id,
                $storeBody,
                'New order — JD ' . number_format($finalTotal, 2)
            );
            @alke_send_email($storeTo, 'New order #' . $order_id . ' — Alke', $storeHtml);

            $_SESSION['cart'] = [];
            unset($_SESSION['pending_order'], $_SESSION['pending_proof']);
            // Let the guest view the confirmation for the order they just placed.
            $_SESSION['last_order_id'] = $order_id;
            header("Location: order_success.php?id=" . $order_id);
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $errorMessage = $e->getMessage();
        }
    }
}

include '../includes/header.php';
?>

<main class="products-page">
  <section class="section products-section">
    <div class="container">
      <div class="section-title">
        <h2>Checkout</h2>
        <p>Complete your order</p>
      </div>

      <?php if ($orderPlaced): ?>
        <div class="checkout-card" style="max-width: 800px; margin: 0 auto;">
          <h3 class="checkout-card-title">Review Your Order</h3>
          <p class="checkout-card-subtitle">Please confirm your order details before placing it.</p>

          <div class="checkout-summary-list" style="margin-top: 20px;">
            <?php foreach ($cartItems as $item): ?>
              <div class="checkout-summary-item">
                <div class="checkout-item-left">
                  <img src="<?php echo htmlspecialchars($item['image_path']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" class="checkout-item-thumb">
                  <div>
                    <p class="checkout-item-name"><?php echo htmlspecialchars($item['name']); ?></p>
                    <p class="checkout-item-meta">Qty: <?php echo (int)$item['quantity']; ?> × JD <?php echo number_format((float)$item['price'], 2); ?></p>
                  </div>
                </div>
                <p class="checkout-item-subtotal">JD <?php echo number_format((float)$item['subtotal'], 2); ?></p>
              </div>
            <?php endforeach; ?>
          </div>

          <?php
            $reviewCoupon   = alke_validate_coupon($conn, (string)($_SESSION['pending_order']['couponCode'] ?? ''), (float)$totalPrice);
            $reviewDiscount = $reviewCoupon['ok'] ? $reviewCoupon['discount'] : 0.0;
            $reviewArea     = isset($shippingRates[$_SESSION['pending_order']['deliveryArea'] ?? '']) ? $_SESSION['pending_order']['deliveryArea'] : 'outside';
            $reviewShip     = $shippingRates[$reviewArea];
            $reviewFinal    = max(0, round((float)$totalPrice - $reviewDiscount, 2)) + $reviewShip;
          ?>
          <p class="checkout-review-payment" style="border-top:none; padding-top:0;">
            <span>Subtotal</span><strong>JD <?php echo number_format((float)$totalPrice, 2); ?></strong>
          </p>
          <?php if ($reviewDiscount > 0): ?>
            <p class="checkout-review-payment" style="border-top:none; color:#1e8f4e;">
              <span>Discount (<?php echo htmlspecialchars($reviewCoupon['code']); ?>)</span>
              <strong>− JD <?php echo number_format($reviewDiscount, 2); ?></strong>
            </p>
          <?php endif; ?>
          <p class="checkout-review-payment" style="border-top:none;">
            <span>Delivery (<?php echo htmlspecialchars($shippingLabels[$reviewArea]); ?>)</span>
            <strong>JD <?php echo number_format($reviewShip, 2); ?></strong>
          </p>
          <div class="checkout-total">
            <span>Total</span>
            <strong>JD <?php echo number_format($reviewFinal, 2); ?></strong>
          </div>

          <?php $reviewPayment = $_SESSION['pending_order']['paymentMethod'] ?? 'cod'; ?>
          <p class="checkout-review-payment">
            <span>Payment Method</span>
            <strong><?php echo htmlspecialchars($paymentMethods[$reviewPayment] ?? 'Cash on Delivery'); ?></strong>
          </p>
          <?php if ($reviewPayment === 'cliq' && !empty($_SESSION['pending_order']['proof'])): ?>
            <p class="checkout-review-payment">
              <span>Payment Screenshot</span>
              <strong style="color:#1e8f4e;">✅ Attached</strong>
            </p>
          <?php endif; ?>

          <form method="POST" action="/alke/checkout" class="checkout-actions" style="margin-top: 20px;">
            <input type="hidden" name="confirm_order" value="1">
            <?php echo alke_csrf_field(); ?>
            <button type="submit" class="btn">Confirm Order</button>
            <a href="/alke/checkout" class="btn checkout-secondary-btn">Edit Order</a>
          </form>
        </div>
      <?php else: ?>
        <?php if (!empty($errorMessage)): ?>
          <div class="checkout-alert">
            <p><?php echo htmlspecialchars($errorMessage); ?></p>
          </div>
        <?php endif; ?>

        <?php if (!empty($cartItems)): ?>
          <div class="checkout-layout">
            <div class="checkout-card">
              <h3 class="checkout-card-title">Customer Details</h3>
              <p class="checkout-card-subtitle">Enter your information to place the order. Fields marked <span class="req">*</span> are required.</p>

              <?php
                // Preserve whatever the customer already typed if validation failed.
                $pend = $_SESSION['pending_order'] ?? [];
                $fv = [
                  'name'    => $_POST['name']        ?? $pend['name']       ?? $_SESSION['user_name']  ?? '',
                  'email'   => $_POST['email']       ?? $pend['email']      ?? $_SESSION['user_email'] ?? '',
                  'phone'   => $_POST['phone']       ?? $pend['phone']      ?? $_SESSION['user_phone'] ?? '',
                  'address' => $_POST['address']     ?? $pend['address']    ?? '',
                  'city'    => $_POST['city']        ?? $pend['city']       ?? '',
                  'country' => $_POST['country']     ?? $pend['country']    ?? '',
                  'postal'  => $_POST['postal_code'] ?? $pend['postalCode'] ?? '',
                  'coupon'  => $_POST['coupon_code'] ?? $pend['couponCode'] ?? '',
                ];
              ?>
              <form method="POST" action="/alke/checkout" class="checkout-form" enctype="multipart/form-data" novalidate>
                <?php echo alke_csrf_field(); ?>
                <div class="checkout-field">
                  <label for="checkoutName">Name <span class="req">*</span></label>
                  <input type="text" id="checkoutName" name="name" value="<?php echo alke_esc($fv['name']); ?>" required>
                </div>

                <div class="checkout-field">
                  <label for="checkoutEmail">Email <span class="req">*</span></label>
                  <input type="email" id="checkoutEmail" name="email" value="<?php echo alke_esc($fv['email']); ?>" required>
                </div>

                <div class="checkout-field">
                  <label for="checkoutPhone">Phone Number <span class="req">*</span></label>
                  <input type="text" id="checkoutPhone" name="phone" value="<?php echo alke_esc($fv['phone']); ?>" required>
                </div>

                <div class="checkout-field">
                  <label for="checkoutAddress">Address <span class="req">*</span></label>
                  <input type="text" id="checkoutAddress" name="address" value="<?php echo alke_esc($fv['address']); ?>" required>
                </div>

                <div class="checkout-field">
                  <label for="checkoutCity">City <span class="req">*</span></label>
                  <input type="text" id="checkoutCity" name="city" value="<?php echo alke_esc($fv['city']); ?>" required>
                </div>

                <div class="checkout-field">
                  <label for="checkoutCountry">Country <span class="req">*</span></label>
                  <input type="text" id="checkoutCountry" name="country" value="<?php echo alke_esc($fv['country']); ?>" required>
                </div>

                <div class="checkout-field">
                  <label for="checkoutPostal">Postal Code <span class="req">*</span></label>
                  <input type="text" id="checkoutPostal" name="postal_code" value="<?php echo alke_esc($fv['postal']); ?>" required>
                </div>

                <?php $chosenPayment = $_POST['payment_method'] ?? $_SESSION['pending_order']['paymentMethod'] ?? ''; ?>
                <div class="checkout-field">
                  <label>Payment Method <span class="req">*</span></label>
                  <div class="payment-options">
                    <label class="payment-option">
                      <input type="radio" name="payment_method" value="cod" <?php echo $chosenPayment === 'cod' ? 'checked' : ''; ?>>
                      <span class="payment-option-body">
                        <span class="payment-option-title">💵 Cash on Delivery</span>
                        <span class="payment-option-desc">Pay with cash when your order arrives.</span>
                      </span>
                    </label>
                    <label class="payment-option">
                      <input type="radio" name="payment_method" value="cliq" <?php echo $chosenPayment === 'cliq' ? 'checked' : ''; ?>>
                      <span class="payment-option-body">
                        <span class="payment-option-title">📱 Pay with CLIQ</span>
                        <span class="payment-option-desc">Pay now via CLIQ using the details below.</span>
                      </span>
                    </label>
                  </div>

                  <div class="cliq-details" id="cliqDetails" style="<?php echo $chosenPayment === 'cliq' ? '' : 'display:none;'; ?>">
                    <h4>Pay with CLIQ</h4>
                    <p>Send <strong id="cliqAmount">JD <?php echo number_format((float)$totalPrice, 2); ?></strong> via CLIQ to:</p>
                    <p class="cliq-alias"><?php echo htmlspecialchars($cliqAlias); ?></p>
                    <p class="cliq-note">ℹ️ The name shown will be <strong><?php echo $cliqBusinessName; ?></strong> — this is Alke's registered business name, so you're sending to the right place.</p>

                    <?php $hasProof = !empty($_SESSION['pending_proof']); ?>
                    <div class="checkout-field" style="margin-top:12px;">
                      <label for="paymentProof">Payment screenshot <span class="req">*</span></label>
                      <input type="file" id="paymentProof" name="payment_proof" accept="image/png,image/jpeg,image/webp">
                      <p class="cliq-note" style="margin-top:6px;">
                        <?php if ($hasProof): ?>
                          ✅ Screenshot attached. Choose a new file only if you want to replace it.
                        <?php else: ?>
                          Upload a screenshot of your completed CLIQ payment (JPG, PNG or WebP, max 5&nbsp;MB).
                        <?php endif; ?>
                      </p>
                    </div>
                  </div>
                </div>

                <?php $chosenArea = $_POST['delivery_area'] ?? $_SESSION['pending_order']['deliveryArea'] ?? ''; ?>
                <div class="checkout-field">
                  <label>Delivery Area <span class="req">*</span></label>
                  <div class="payment-options">
                    <label class="payment-option">
                      <input type="radio" name="delivery_area" value="amman" data-fee="2" <?php echo $chosenArea === 'amman' ? 'checked' : ''; ?>>
                      <span class="payment-option-body">
                        <span class="payment-option-title">🏙️ Inside Amman</span>
                        <span class="payment-option-desc">JD 2.00 delivery</span>
                      </span>
                    </label>
                    <label class="payment-option">
                      <input type="radio" name="delivery_area" value="outside" data-fee="3" <?php echo $chosenArea === 'outside' ? 'checked' : ''; ?>>
                      <span class="payment-option-body">
                        <span class="payment-option-title">🚚 Outside Amman</span>
                        <span class="payment-option-desc">JD 3.00 delivery</span>
                      </span>
                    </label>
                  </div>
                </div>

                <div class="checkout-field">
                  <label for="checkoutCoupon">Coupon code <span style="color:var(--muted); font-weight:400;">(optional)</span></label>
                  <div class="coupon-row">
                    <input type="text" id="checkoutCoupon" name="coupon_code" value="<?php echo alke_esc($fv['coupon']); ?>" placeholder="e.g. WELCOME10" autocapitalize="characters">
                    <button type="button" id="applyCouponBtn" class="btn coupon-apply-btn">Apply</button>
                  </div>
                  <p class="coupon-msg" id="couponMsg" style="display:none;"></p>
                </div>

                <div class="checkout-actions">
                  <button type="submit" name="place_order" class="btn">Place Order</button>
                  <a href="/alke/cart" class="btn checkout-secondary-btn">Back to Cart</a>
                </div>
              </form>
            </div>

            <div class="checkout-card">
              <h3 class="checkout-card-title">Order Summary</h3>
              <div class="checkout-summary-list">
                <?php foreach ($cartItems as $item): ?>
                  <div class="checkout-summary-item">
                    <div class="checkout-item-left">
                      <img src="<?php echo htmlspecialchars($item['image_path']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" class="checkout-item-thumb">
                      <div>
                        <p class="checkout-item-name"><?php echo htmlspecialchars($item['name']); ?></p>
                        <p class="checkout-item-meta">Qty: <?php echo (int)$item['quantity']; ?> × JD <?php echo number_format((float)$item['price'], 2); ?></p>
                      </div>
                    </div>
                    <p class="checkout-item-subtotal">JD <?php echo number_format((float)$item['subtotal'], 2); ?></p>
                  </div>
                <?php endforeach; ?>
              </div>

              <div class="checkout-total checkout-subtotal-row" id="summarySubtotalRow" style="display:none;">
                <span>Subtotal</span>
                <strong id="summarySubtotal">JD <?php echo number_format((float)$totalPrice, 2); ?></strong>
              </div>
              <div class="checkout-total coupon-discount-row" id="summaryDiscountRow" style="display:none; color:#1e8f4e;">
                <span id="summaryDiscountLabel">Discount</span>
                <strong id="summaryDiscount">− JD 0.00</strong>
              </div>
              <div class="checkout-total" id="summaryShipRow" style="display:none;">
                <span>Delivery</span>
                <strong id="summaryShip">JD 0.00</strong>
              </div>
              <div class="checkout-total">
                <span>Total</span>
                <strong id="summaryTotal" data-subtotal="<?php echo (float)$totalPrice; ?>">JD <?php echo number_format((float)$totalPrice, 2); ?></strong>
              </div>
            </div>
          </div>
        <?php else: ?>
          <p class="no-products">Your cart is empty. Add products before checkout.</p>
          <div class="checkout-empty-action">
            <a href="/alke/products" class="btn">Go to Shop</a>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </section>
</main>

<script>
(function () {
  var cliqBox = document.getElementById('cliqDetails');
  if (cliqBox) {
    document.querySelectorAll('input[name="payment_method"]').forEach(function (radio) {
      radio.addEventListener('change', function () {
        cliqBox.style.display = (this.value === 'cliq' && this.checked) ? '' : 'none';
      });
    });
  }

  // ── Live order summary: subtotal − discount + delivery ─────────
  var btn   = document.getElementById('applyCouponBtn');
  var input = document.getElementById('checkoutCoupon');
  var msg   = document.getElementById('couponMsg');
  var totalEl = document.getElementById('summaryTotal');
  if (totalEl) {
    var subtotal = parseFloat(totalEl.dataset.subtotal) || 0;
    var subRow  = document.getElementById('summarySubtotalRow');
    var discRow = document.getElementById('summaryDiscountRow');
    var shipRow = document.getElementById('summaryShipRow');
    var shipEl  = document.getElementById('summaryShip');
    var cliqAmt = document.getElementById('cliqAmount');
    var fmt = function (n) { return 'JD ' + Number(n).toFixed(2); };

    // Shared state, updated by the coupon box and the delivery-area radios.
    var state = { discount: 0, code: '' };

    function shipFee() {
      var sel = document.querySelector('input[name="delivery_area"]:checked');
      return sel ? (parseFloat(sel.dataset.fee) || 0) : 0;
    }

    function render() {
      var ship    = shipFee();
      var hasShip = document.querySelector('input[name="delivery_area"]:checked') !== null;
      var hasDisc = state.discount > 0;

      if (subRow)  subRow.style.display  = (hasDisc || hasShip) ? '' : 'none';
      var subEl = document.getElementById('summarySubtotal');
      if (subEl) subEl.textContent = fmt(subtotal);

      if (discRow) discRow.style.display = hasDisc ? '' : 'none';
      if (hasDisc) {
        document.getElementById('summaryDiscountLabel').textContent = 'Discount (' + state.code + ')';
        document.getElementById('summaryDiscount').textContent = '− ' + fmt(state.discount);
      }

      if (shipRow) shipRow.style.display = hasShip ? '' : 'none';
      if (shipEl)  shipEl.textContent = fmt(ship);

      var total = Math.max(0, subtotal - state.discount) + ship;
      totalEl.textContent = fmt(total);
      if (cliqAmt) cliqAmt.textContent = fmt(total);
    }

    document.querySelectorAll('input[name="delivery_area"]').forEach(function (r) {
      r.addEventListener('change', render);
    });

    function apply() {
      if (!btn || !input) return;
      var code = input.value.trim();
      var body = new URLSearchParams();
      body.append('coupon_code', code);
      body.append('csrf_token', (document.querySelector('meta[name="csrf-token"]') || {}).content || '');
      var orig = btn.textContent;
      btn.disabled = true; btn.textContent = '…';
      fetch('/alke/apply_coupon', { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          btn.disabled = false; btn.textContent = orig;
          if (res.ok && res.discount > 0) {
            msg.style.display = 'block';
            msg.className = 'coupon-msg is-ok';
            msg.textContent = '✓ ' + res.code + ' applied — you save ' + fmt(res.discount);
            state.discount = res.discount; state.code = res.code;
          } else if (code === '') {
            msg.style.display = 'none';
            state.discount = 0; state.code = '';
          } else {
            msg.style.display = 'block';
            msg.className = 'coupon-msg is-error';
            msg.textContent = res.message || 'Invalid coupon.';
            state.discount = 0; state.code = '';
          }
          render();
        })
        .catch(function () {
          btn.disabled = false; btn.textContent = orig;
          msg.style.display = 'block';
          msg.className = 'coupon-msg is-error';
          msg.textContent = 'Could not apply coupon. Please try again.';
        });
    }

    if (btn && input) {
      btn.addEventListener('click', apply);
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); apply(); }
      });
    }

    render(); // reflect any pre-selected area / returning state on load
    if (input && input.value.trim() !== '') { apply(); } // auto-apply a prefilled code
  }
})();
</script>

<?php include '../includes/footer.php'; ?>
