<?php
// -------------------------------------------------------
// Admin credentials — read ONLY from environment variables.
//
// Required env vars (set these on the host, e.g. Railway):
//   ADMIN_USER       = your admin username
//   ADMIN_PASS_HASH  = password_hash('your-strong-password', PASSWORD_DEFAULT)
//
// There is intentionally NO hardcoded fallback: if the credentials are not
// configured the admin panel fails closed (it will not accept any login).
//
// For local development only, create admin/config.local.php (git-ignored) that
// defines ADMIN_USER_LOCAL and ADMIN_PASS_HASH_LOCAL.
// -------------------------------------------------------

$adminUser = getenv('ADMIN_USER');
$adminHash = getenv('ADMIN_PASS_HASH');
$adminUser = is_string($adminUser) ? trim($adminUser) : '';
$adminHash = is_string($adminHash) ? trim($adminHash) : '';

// Local-dev only fallback via a git-ignored file (never committed / shipped).
if (($adminUser === '' || $adminHash === '') && is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
    if ($adminUser === '' && defined('ADMIN_USER_LOCAL'))      { $adminUser = ADMIN_USER_LOCAL; }
    if ($adminHash === '' && defined('ADMIN_PASS_HASH_LOCAL')) { $adminHash = ADMIN_PASS_HASH_LOCAL; }
}

if ($adminUser === '' || $adminHash === '') {
    http_response_code(503);
    error_log('Admin credentials are not configured: set ADMIN_USER and ADMIN_PASS_HASH.');
    die('Admin panel is not configured.');
}

define('ADMIN_USER', $adminUser);
define('ADMIN_PASS_HASH', $adminHash);
