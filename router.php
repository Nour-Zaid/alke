<?php
/**
 * Router for the PHP built-in server (used on Railway/Nixpacks).
 *
 * The app is written with absolute "/alke/..." paths so it can live under
 * htdocs/alke locally. In production it is served at the domain root, so this
 * router maps every "/alke/..." request back onto the repo files and redirects
 * the bare domain root into the app. No application code needs to change.
 */

$path = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');

// Bare root -> send visitors into the app.
if ($path === '' || $path === '/') {
    header('Location: /alke/index.php');
    exit;
}

// Everything the app links to is under the /alke/ mount point.
if (strpos($path, '/alke/') !== 0) {
    http_response_code(404);
    exit('Not found');
}

$docroot = __DIR__;
$rel     = substr($path, strlen('/alke')); // keep leading slash, e.g. /css/style.css
$full    = realpath($docroot . $rel);

// Reject anything resolving outside the app directory (path traversal).
if ($full === false || strpos($full, $docroot) !== 0) {
    http_response_code(404);
    exit('Not found');
}

// Directory request -> serve its index.php if present.
if (is_dir($full)) {
    $candidate = rtrim($full, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'index.php';
    if (!is_file($candidate)) {
        http_response_code(404);
        exit('Not found');
    }
    $full = $candidate;
}

if (!is_file($full)) {
    http_response_code(404);
    exit('Not found');
}

// PHP files: execute them (they use __DIR__ for includes, so cwd is safe either way).
if (strtolower(pathinfo($full, PATHINFO_EXTENSION)) === 'php') {
    chdir(dirname($full));
    require $full;
    return true;
}

// Static assets: emit with a sensible content type.
$mimes = [
    'css'  => 'text/css',
    'js'   => 'application/javascript',
    'json' => 'application/json',
    'map'  => 'application/json',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'svg'  => 'image/svg+xml',
    'webp' => 'image/webp',
    'ico'  => 'image/x-icon',
    'woff' => 'font/woff',
    'woff2'=> 'font/woff2',
    'ttf'  => 'font/ttf',
    'txt'  => 'text/plain',
    'html' => 'text/html',
    'pdf'  => 'application/pdf',
];
$ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
if (isset($mimes[$ext])) {
    header('Content-Type: ' . $mimes[$ext]);
}
readfile($full);
return true;
