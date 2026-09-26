<?php
/**
 * Database connection.
 * Reads credentials from environment variables when available
 * (useful for Railway/Render/Docker), falling back to local XAMPP defaults.
 */

// Prefer a single connection URL if provided (e.g. Railway's MYSQL_URL /
// MYSQL_PRIVATE_URL). Format: mysql://user:pass@host:port/dbname
$dbUrl = getenv('MYSQL_URL') ?: getenv('MYSQL_PRIVATE_URL') ?: getenv('DATABASE_URL') ?: getenv('DB_URL') ?: '';

if ($dbUrl !== '') {
    $p        = parse_url($dbUrl);
    $host     = $p['host'] ?? 'localhost';
    $port     = (int)($p['port'] ?? 3306);
    $user     = isset($p['user']) ? urldecode($p['user']) : 'root';
    $password = isset($p['pass']) ? urldecode($p['pass']) : '';
    $database = isset($p['path']) ? ltrim($p['path'], '/') : 'railway';
} else {
    $host     = getenv('DB_HOST')     ?: 'localhost';
    $user     = getenv('DB_USER')     ?: 'root';
    $password = getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '';
    $database = getenv('DB_NAME')     ?: 'alke_store';
    $port     = (int)(getenv('DB_PORT') ?: 3306);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/*
 * Retry the connection a few times. On hosts like Railway the private network
 * (mysql.railway.internal) can take a moment to become reachable right after a
 * cold start, which previously caused an intermittent "technical difficulties"
 * message on the first page load. Retrying makes that first load succeed.
 */
$conn        = null;
$maxAttempts = 5;
for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    try {
        $conn = mysqli_init();
        $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
        $conn->real_connect($host, $user, $password, $database, $port);
        $conn->set_charset('utf8mb4');
        break; // connected
    } catch (mysqli_sql_exception $e) {
        $conn = null;
        if ($attempt >= $maxAttempts) {
            error_log('DB connection failed after ' . $attempt . ' attempts: ' . $e->getMessage());
            http_response_code(503);
            header('Retry-After: 2');
            die('We are having technical difficulties. Please try again shortly.');
        }
        usleep(400000); // wait 0.4s, then retry
    }
}

// Keep legacy (non-exception) behaviour for the rest of the codebase,
// which checks return values manually.
mysqli_report(MYSQLI_REPORT_OFF);
