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
    header('Location: /alke/');
    exit;
}

// Everything the app links to is under the /alke/ mount point.
if (strpos($path, '/alke/') !== 0) {
    http_response_code(404);
    exit('Not found');
}

$docroot = __DIR__;
$rel     = substr($path, strlen('/alke')); // keep leading slash, e.g. /css/style.css
$base    = $docroot . $rel;

// --- Security: block sensitive paths before touching the filesystem ---------

// 1) Deny any dot-segment: .git, .htaccess, .env, .dockerignore, "..", etc.
foreach (explode('/', $rel) as $seg) {
    if ($seg !== '' && $seg[0] === '.') {
        http_response_code(404);
        exit('Not found');
    }
}

// 2) Payment proofs are private — only the authenticated endpoint may serve them.
if (strpos($rel, '/assets/uploads/') === 0) {
    http_response_code(404);
    exit('Not found');
}

// 3) Internal include directories are never entry points.
if (strpos($rel, '/includes/') !== false || strpos($rel, '/config/') !== false) {
    http_response_code(404);
    exit('Not found');
}

// Resolve the request to a real file, staying inside the app directory.
$full = null;
$real = realpath($base);
if ($real !== false && strpos($real, $docroot) === 0) {
    if (is_dir($real)) {
        // Directory -> its index.php
        $idx = rtrim($real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'index.php';
        if (is_file($idx)) {
            $full = $idx;
        }
    } elseif (is_file($real)) {
        $full = $real;
    }
}

// Clean URLs: an extensionless request like /pages/products maps to products.php
if ($full === null) {
    $phpReal = realpath($base . '.php');
    if ($phpReal !== false && strpos($phpReal, $docroot) === 0 && is_file($phpReal)) {
        $full = $phpReal;
    }
}

// Shorthand: /alke/<name> maps to pages/<name>.php (so links can drop the /pages/
// part, e.g. /alke/checkout -> pages/checkout.php). Direct paths above win first,
// so /admin/..., /assets/... and the homepage are unaffected.
if ($full === null) {
    $pagesReal = realpath($docroot . '/pages' . $rel . '.php');
    if ($pagesReal !== false && strpos($pagesReal, $docroot) === 0 && is_file($pagesReal)) {
        $full = $pagesReal;
    }
}

if ($full === null) {
    http_response_code(404);
    exit('Not found');
}

// Never expose/execute the router itself as a route.
if (basename($full) === 'router.php') {
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
// Allowlist: only known-safe static types are ever served. This blocks
// source/config files (.sql, .toml, Dockerfile, .md, .ini, .lock, ...).
if (!isset($mimes[$ext])) {
    http_response_code(404);
    exit('Not found');
}
header('Content-Type: ' . $mimes[$ext]);
// Let browsers cache static assets so repeat visits/navigations are instant.
$cacheable = ['css','js','png','jpg','jpeg','gif','svg','webp','ico','woff','woff2','ttf'];
if (in_array($ext, $cacheable, true)) {
    header('Cache-Control: public, max-age=2592000'); // 30 days
}
readfile($full);
return true;
