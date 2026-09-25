<?php
/**
 * Database connection.
 * Reads credentials from environment variables when available
 * (useful for Railway/Render/Docker), falling back to local XAMPP defaults.
 */

$host     = getenv('DB_HOST')     ?: 'localhost';
$user     = getenv('DB_USER')     ?: 'root';
$password = getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '';
$database = getenv('DB_NAME')     ?: 'alke_store';
$port     = (int)(getenv('DB_PORT') ?: 3306);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($host, $user, $password, $database, $port);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    // Never leak credentials or internal errors to visitors.
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(503);
    die('We are having technical difficulties. Please try again shortly.');
}

// Keep legacy (non-exception) behaviour for the rest of the codebase,
// which checks return values manually.
mysqli_report(MYSQLI_REPORT_OFF);
