<?php
session_start();
include '../config/db.php';
include '../includes/helpers.php';

header('Content-Type: application/json');

alke_cart_init();

function cart_response(bool $success, string $message = ''): void
{
    global $conn;

    $miniCartItems = [];
    $miniCartTotal = 0.0;
    foreach (alke_cart_lines($conn) as $line) {
        $miniCartTotal += $line['subtotal'];
        $miniCartItems[] = [
            'id'         => (int)$line['id'],
            'key'        => $line['key'],
            'name'       => $line['name'],
            'variant'    => $line['variant'],
            'price'      => (float)$line['price'],
            'qty'        => $line['quantity'],
            'image'      => $line['image_path'],
            'line_total' => $line['subtotal'],
        ];
    }

    echo json_encode([
        'success'    => $success,
        'message'    => $message,
        'cart_count' => alke_cart_count(),
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

$action   = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
$key      = isset($_POST['key']) ? (string)$_POST['key'] : '';
$quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;

switch ($action) {
    case 'add':
        $productId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
        if ($productId <= 0) {
            cart_response(false, 'Missing product.');
        }
        [$ok, $msg] = alke_cart_add(
            $conn,
            $productId,
            $quantity,
            (string)($_POST['size'] ?? ''),
            (string)($_POST['color'] ?? '')
        );
        cart_response($ok, $msg);

    case 'update':
        [$ok, $msg] = alke_cart_set_qty($conn, $key, $quantity);
        cart_response($ok, $msg);

    case 'remove':
        unset($_SESSION['cart'][$key]);
        cart_response(true);

    case 'clear':
        $_SESSION['cart'] = [];
        cart_response(true);

    default:
        cart_response(false, 'Invalid action');
}
