<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Shared helpers (CSRF tokens, output escaping, security headers).
require_once __DIR__ . '/../../includes/helpers.php';

// Security headers on every admin page (clickjacking, sniffing, CSP, HSTS...).
alke_security_headers();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: /alke/admin/login');
    exit;
}

/**
 * Enforce a valid CSRF token on admin state-changing (POST) requests.
 * Call at the top of any admin page that processes POST actions.
 */
if (!function_exists('admin_require_csrf')) {
    function admin_require_csrf(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !alke_csrf_check()) {
            http_response_code(400);
            die('Security check failed. Please reload the page and try again.');
        }
    }
}
