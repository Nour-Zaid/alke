<?php
/**
 * AJAX coupon preview. Validates a code against the CURRENT cart subtotal
 * (computed server-side from session + DB prices) and returns the discount.
 * This is preview only — the authoritative discount is recomputed and claimed
 * atomically at order confirmation in checkout.php.
 */
session_start();
include __DIR__ . '/../config/db.php';
include __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !alke_csrf_check()) {
    echo json_encode(['ok' => false, 'message' => 'Invalid request.', 'discount' => 0, 'subtotal' => 0, 'total' => 0]);
    exit;
}

// Server-side subtotal from the session cart (never trust client amounts).
$subtotal = 0.0;
if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    $ids = array_map('intval', array_keys($_SESSION['cart']));
    $ids = array_filter($ids, fn($v) => $v > 0);
    if ($ids) {
        $idsList = implode(',', $ids);
        if ($res = $conn->query("SELECT id, price FROM products WHERE id IN ($idsList)")) {
            while ($r = $res->fetch_assoc()) {
                $pid = (int)$r['id'];
                $qty = max(1, (int)($_SESSION['cart'][$pid] ?? 1));
                $subtotal += (float)$r['price'] * $qty;
            }
        }
    }
}
$subtotal = round($subtotal, 2);

$code = isset($_POST['coupon_code']) ? trim($_POST['coupon_code']) : '';
$v    = alke_validate_coupon($conn, $code, $subtotal);
$discount = $v['ok'] ? $v['discount'] : 0.0;
$total    = max(0, round($subtotal - $discount, 2));

echo json_encode([
    'ok'       => $v['ok'],
    'code'     => $v['code'],
    'message'  => $v['message'],
    'discount' => round($discount, 2),
    'subtotal' => $subtotal,
    'total'    => $total,
]);
