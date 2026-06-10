<?php
session_start();
include '../config/db.php';
include '../includes/helpers.php';

header('Content-Type: application/json');

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

function cart_response(bool $success, string $message = ''): void
{
    global $conn;

    $cartCount = array_sum(array_map('intval', $_SESSION['cart']));

    $miniCartItems = [];
    $miniCartTotal = 0.0;

    if (!empty($_SESSION['cart'])) {
        $ids = array_map('intval', array_keys($_SESSION['cart']));
        $idsList = implode(',', $ids);
        $miniResult = $conn->query("SELECT id, name, price, image FROM products WHERE id IN ($idsList)");
        if ($miniResult) {
            while ($row = $miniResult->fetch_assoc()) {
                $pid = (int)$row['id'];
                $qty = isset($_SESSION['cart'][$pid]) ? (int)$_SESSION['cart'][$pid] : 0;
                if ($qty < 1) continue;

                $lineTotal = (float)$row['price'] * $qty;
                $miniCartTotal += $lineTotal;
                $miniCartItems[] = [
                    'id'         => $pid,
                    'name'       => $row['name'],
                    'price'      => (float)$row['price'],
                    'qty'        => $qty,
                    'image'      => alke_product_image($row),
                    'line_total' => $lineTotal,
                ];
            }
        }
    }

    echo json_encode([
        'success'    => $success,
        'message'    => $message,
        'cart_count' => $cartCount,
        'mini_cart'  => [
            'items' => $miniCartItems,
            'total' => $miniCartTotal,
        ],
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cart_response(false, 'Invalid request method');
}

if (!alke_csrf_check()) {
    cart_response(false, 'Your session expired. Please refresh the page.');
}

$action    = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
$productId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
$quantity  = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;

if ($productId <= 0 || $action === '') {
    cart_response(false, 'Missing action or product_id');
}

/* ── Look up the product so we can validate stock ──────────── */
$product = null;
$stmt = $conn->prepare("SELECT id, stock FROM products WHERE id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows === 1) {
        $product = $res->fetch_assoc();
    }
    $stmt->close();
}

if (!$product && $action !== 'remove') {
    cart_response(false, 'Product not found.');
}

$stock = $product ? (int)$product['stock'] : 0;

switch ($action) {
    case 'add':
        if ($stock <= 0) {
            cart_response(false, 'This product is out of stock.');
        }
        $quantity = max(1, $quantity);
        $current  = isset($_SESSION['cart'][$productId]) ? (int)$_SESSION['cart'][$productId] : 0;
        $newQty   = $current + $quantity;

        if ($newQty > $stock) {
            $_SESSION['cart'][$productId] = $stock;
            cart_response(false, 'Only ' . $stock . ' in stock — quantity adjusted.');
        }
        $_SESSION['cart'][$productId] = $newQty;
        break;

    case 'update':
        if ($quantity <= 0) {
            unset($_SESSION['cart'][$productId]);
        } elseif ($quantity > $stock) {
            $_SESSION['cart'][$productId] = max(1, $stock);
            cart_response(false, 'Only ' . $stock . ' in stock — quantity adjusted.');
        } else {
            $_SESSION['cart'][$productId] = $quantity;
        }
        break;

    case 'remove':
        unset($_SESSION['cart'][$productId]);
        break;

    case 'clear':
        $_SESSION['cart'] = [];
        break;

    default:
        cart_response(false, 'Invalid action');
}

cart_response(true);
