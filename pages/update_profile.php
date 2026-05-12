<?php
session_start();
include '../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'You must be logged in to update your profile.'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method.'
    ]);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
$email = isset($_POST['email']) ? trim((string)$_POST['email']) : '';
$phone = isset($_POST['phone']) ? trim((string)$_POST['phone']) : '';

if ($name === '' || $email === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Name and email are required.'
    ]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Please enter a valid email address.'
    ]);
    exit;
}

$phoneColumnExists = false;
$columnCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'phone'");
if ($columnCheck && $columnCheck->num_rows > 0) {
    $phoneColumnExists = true;
}

if ($phoneColumnExists) {
    $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to prepare profile update.'
        ]);
        exit;
    }
    $stmt->bind_param("sssi", $name, $email, $phone, $userId);
} else {
    $stmt = $conn->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to prepare profile update.'
        ]);
        exit;
    }
    $stmt->bind_param("ssi", $name, $email, $userId);
}

if (!$stmt->execute()) {
    $stmt->close();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to update profile at the moment.'
    ]);
    exit;
}

$stmt->close();

$_SESSION['user_name'] = $name;
$_SESSION['user_email'] = $email;
$_SESSION['user_phone'] = $phone;

echo json_encode([
    'success' => true,
    'message' => 'Profile updated successfully.',
    'profile' => [
        'name' => $name,
        'email' => $email,
        'phone' => $phone
    ]
]);
