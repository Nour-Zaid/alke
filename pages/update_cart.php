<?php
session_start();
include '../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method',
        'cart_count' => array_sum($_SESSION['cart'])
    ]);
    exit;
}

$action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
$productId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
$quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;

if ($productId <= 0 || $action === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Missing action or product_id',
        'cart_count' => array_sum($_SESSION['cart'])
    ]);
    exit;
}

switch ($action) {
    case 'add':
        if ($quantity < 1) {
            $quantity = 1;
        }
        if (isset($_SESSION['cart'][$productId])) {
            $_SESSION['cart'][$productId] += $quantity;
        } else {
            $_SESSION['cart'][$productId] = $quantity;
        }
        break;

    case 'update':
        if ($quantity <= 0) {
            unset($_SESSION['cart'][$productId]);
        } else {
            $_SESSION['cart'][$productId] = $quantity;
        }
        break;

    case 'remove':
        unset($_SESSION['cart'][$productId]);
        break;

    default:
        echo json_encode([
            'success' => false,
            'message' => 'Invalid action',
            'cart_count' => array_sum($_SESSION['cart'])
        ]);
        exit;
}

$cartCount = array_sum($_SESSION['cart']);

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

            $dbImage = trim($row['image'] ?? '');
            $imagePath = '/alke/testblackshirt.jpeg';
            if (!empty($dbImage) && file_exists(__DIR__ . '/../assets/' . $dbImage)) {
                $imagePath = '/alke/assets/' . $dbImage;
            }

            $lineTotal = (float)$row['price'] * $qty;
            $miniCartTotal += $lineTotal;
            $miniCartItems[] = [
                'id'         => $pid,
                'name'       => $row['name'],
                'price'      => (float)$row['price'],
                'qty'        => $qty,
                'image'      => $imagePath,
                'line_total' => $lineTotal,
            ];
        }
    }
}

echo json_encode([
    'success'   => true,
    'cart_count' => $cartCount,
    'mini_cart' => [
        'items' => $miniCartItems,
        'total' => $miniCartTotal,
    ],
]);
