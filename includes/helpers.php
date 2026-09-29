<?php
/**
 * Alke Clothes — shared helper functions
 * Include this file after session_start() / db.php.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ── Output escaping shorthand ─────────────────────────────── */
if (!function_exists('alke_esc')) {
    function alke_esc($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

/* ── CSRF protection ───────────────────────────────────────── */
if (!function_exists('alke_csrf_token')) {
    function alke_csrf_token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('alke_csrf_field')) {
    function alke_csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . alke_esc(alke_csrf_token()) . '">';
    }
}

if (!function_exists('alke_csrf_check')) {
    function alke_csrf_check(?string $token = null): bool
    {
        $token = $token ?? ($_POST['csrf_token'] ?? '');
        return !empty($_SESSION['csrf_token'])
            && is_string($token)
            && hash_equals($_SESSION['csrf_token'], $token);
    }
}

/* ── Product image resolver (single source of truth) ──────── */
if (!function_exists('alke_product_image')) {
    function alke_product_image($row): string
    {
        $dbImage = isset($row['image']) ? trim((string)$row['image']) : '';
        if ($dbImage !== '' && file_exists(__DIR__ . '/../assets/' . $dbImage)) {
            return '/alke/assets/' . $dbImage;
        }
        return '/alke/testblackshirt.jpeg';
    }
}

/* ── Simple per-session rate limiter ───────────────────────── */
if (!function_exists('alke_rate_limit')) {
    /**
     * Returns true if the action is ALLOWED, false if rate-limited.
     * Example: alke_rate_limit('login', 5, 300) → max 5 tries / 5 minutes.
     */
    function alke_rate_limit(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        $bucketKey = 'rl_' . $key;
        $now = time();

        if (!isset($_SESSION[$bucketKey]) || !is_array($_SESSION[$bucketKey])) {
            $_SESSION[$bucketKey] = [];
        }

        // Drop attempts outside the window
        $_SESSION[$bucketKey] = array_values(array_filter(
            $_SESSION[$bucketKey],
            fn($t) => ($now - (int)$t) < $windowSeconds
        ));

        if (count($_SESSION[$bucketKey]) >= $maxAttempts) {
            return false;
        }

        $_SESSION[$bucketKey][] = $now;
        return true;
    }
}

if (!function_exists('alke_rate_limit_reset')) {
    function alke_rate_limit_reset(string $key): void
    {
        unset($_SESSION['rl_' . $key]);
    }
}

/* ── Security headers ──────────────────────────────────────── */
if (!function_exists('alke_security_headers')) {
    function alke_security_headers(): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');

        // Content-Security-Policy. The app uses inline <style>/<script> and inline
        // style="" attributes, plus Google Fonts — the policy below permits exactly
        // those while blocking external/injected scripts, objects and framing.
        header(
            "Content-Security-Policy: " .
            "default-src 'self'; " .
            "base-uri 'self'; " .
            "object-src 'none'; " .
            "frame-ancestors 'self'; " .
            "form-action 'self'; " .
            "img-src 'self' data:; " .
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
            "font-src 'self' https://fonts.gstatic.com; " .
            "script-src 'self' 'unsafe-inline'; " .
            "connect-src 'self'"
        );

        // HSTS only when the request actually arrived over HTTPS (Railway proxy
        // sets X-Forwarded-Proto). Never send it on plain-HTTP local dev.
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off');
        if ($proto === 'https' || $https) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}
