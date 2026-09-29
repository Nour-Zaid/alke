<?php
/**
 * Authorized payment-proof server.
 *
 * Payment screenshots are NOT publicly reachable (the router/.htaccess block
 * /assets/uploads/ directly). They are served only here, and only to:
 *   - a logged-in admin, or
 *   - the guest who placed this exact order in the current session.
 */
session_start();
include __DIR__ . '/../config/db.php';

$orderId = isset($_GET['order']) ? (int)$_GET['order'] : 0;
if ($orderId <= 0) {
    http_response_code(404);
    exit('Not found');
}

$isAdmin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$isOwner = isset($_SESSION['last_order_id']) && (int)$_SESSION['last_order_id'] === $orderId;

// A logged-in customer (not used in guest-only mode, but future-proof) may view
// a proof for an order they own.
if (!$isAdmin && !$isOwner && isset($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    if ($stmt = $conn->prepare("SELECT 1 FROM orders WHERE id = ? AND user_id = ? LIMIT 1")) {
        $stmt->bind_param('ii', $orderId, $uid);
        $stmt->execute();
        $isOwner = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
    }
}

if (!$isAdmin && !$isOwner) {
    http_response_code(403);
    exit('Forbidden');
}

$proof = '';
if ($stmt = $conn->prepare("SELECT payment_proof FROM orders WHERE id = ? LIMIT 1")) {
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $proof = $row['payment_proof'] ?? '';
}
if ($proof === '' || $proof === null) {
    http_response_code(404);
    exit('Not found');
}

// Resolve strictly within the proofs directory (defend against traversal).
$fname   = basename((string)$proof);
$baseDir = realpath(__DIR__ . '/../assets/uploads/proofs');
$real    = realpath(__DIR__ . '/../assets/uploads/proofs/' . $fname);
if ($baseDir === false || $real === false || strpos($real, $baseDir) !== 0 || !is_file($real)) {
    http_response_code(404);
    exit('Not found');
}

$mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
$ext   = strtolower(pathinfo($real, PATHINFO_EXTENSION));

header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="proof-' . $orderId . '.' . $ext . '"');
header('Cache-Control: private, no-store');
readfile($real);
