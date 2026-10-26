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

/* ── Coupon validation (server-side, single source of truth) ─ */
if (!function_exists('alke_validate_coupon')) {
    /**
     * Validate a coupon against a subtotal. Returns:
     *   ['ok'=>bool, 'code'=>string, 'discount'=>float, 'message'=>string, 'coupon'=>?array]
     * An empty code is "ok" with zero discount (coupons are optional).
     * The discount is always recomputed here from the DB — never trusted from input.
     */
    function alke_validate_coupon(mysqli $conn, string $code, float $subtotal): array
    {
        $code = strtoupper(trim($code));
        $fail = function (string $msg) use ($code) {
            return ['ok' => false, 'code' => $code, 'discount' => 0.0, 'message' => $msg, 'coupon' => null];
        };
        if ($code === '') {
            return ['ok' => true, 'code' => '', 'discount' => 0.0, 'message' => '', 'coupon' => null];
        }

        $stmt = $conn->prepare("SELECT * FROM coupons WHERE code = ? LIMIT 1");
        if (!$stmt) {
            return $fail('Could not validate coupon. Please try again.');
        }
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $c = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$c)                              { return $fail('Invalid coupon code.'); }
        if ((int)$c['active'] !== 1)          { return $fail('This coupon is not active.'); }
        if (!empty($c['expires_at']) && strtotime($c['expires_at'] . ' 23:59:59') < time()) {
            return $fail('This coupon has expired.');
        }
        if ((int)$c['max_uses'] > 0 && (int)$c['used_count'] >= (int)$c['max_uses']) {
            return $fail('This coupon has reached its usage limit.');
        }
        if ((float)$c['min_order'] > 0 && $subtotal < (float)$c['min_order']) {
            return $fail('This coupon requires a minimum order of JD ' . number_format((float)$c['min_order'], 2) . '.');
        }

        $discount = ($c['type'] === 'percent')
            ? $subtotal * ((float)$c['value'] / 100.0)
            : (float)$c['value'];
        $discount = round(min($discount, $subtotal), 2); // never exceed the subtotal

        return ['ok' => true, 'code' => $code, 'discount' => $discount, 'message' => '', 'coupon' => $c];
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

/* ── Multi-image support ──────────────────────────────────── */
if (!function_exists('alke_ensure_product_images')) {
    /** Create the product_images table and backfill legacy single images once. */
    function alke_ensure_product_images(mysqli $conn): void
    {
        $conn->query("
            CREATE TABLE IF NOT EXISTS product_images (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                image VARCHAR(255) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                INDEX idx_pi_product (product_id),
                CONSTRAINT fk_pi_product FOREIGN KEY (product_id)
                    REFERENCES products(id) ON DELETE CASCADE ON UPDATE CASCADE
            )
        ");
        // Any product that has a cover image but no gallery rows gets one (idempotent).
        $conn->query("
            INSERT INTO product_images (product_id, image, sort_order)
            SELECT p.id, p.image, 0
            FROM products p
            LEFT JOIN product_images pi ON pi.product_id = p.id
            WHERE p.image IS NOT NULL AND p.image <> '' AND pi.id IS NULL
        ");
    }
}

if (!function_exists('alke_product_images')) {
    /** Web paths of every image for a product (cover first). Always ≥ 1 (falls back). */
    function alke_product_images(mysqli $conn, int $productId, ?string $fallback = null): array
    {
        $out = [];
        if ($productId > 0 && ($stmt = @$conn->prepare(
                "SELECT image FROM product_images WHERE product_id = ? ORDER BY sort_order, id"))) {
            $stmt->bind_param('i', $productId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $img = trim((string)$row['image']);
                if ($img !== '' && file_exists(__DIR__ . '/../assets/' . $img)) {
                    $out[] = '/alke/assets/' . $img;
                }
            }
            $stmt->close();
        }
        if (empty($out)) {
            $fb = $fallback !== null ? trim($fallback) : '';
            $out[] = ($fb !== '' && file_exists(__DIR__ . '/../assets/' . $fb))
                ? '/alke/assets/' . $fb
                : '/alke/testblackshirt.jpeg';
        }
        return $out;
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

/* ── Variant-aware cart ───────────────────────────────────────
 * $_SESSION['cart'] = [ "pid|size|color" => ['id'=>int,'qty'=>int,'size'=>str,'color'=>str] ]
 * Each size/color combination is its own line. Stock is tracked per product,
 * so the quantities of all its variants together may not exceed it.
 */
if (!function_exists('alke_cart_key')) {
    function alke_cart_key(int $pid, string $size, string $color): string
    {
        return $pid . '|' . $size . '|' . $color;
    }

    /** Ensure the cart exists and upgrade legacy [productId => qty] entries. */
    function alke_cart_init(): void
    {
        if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
            return;
        }
        foreach ($_SESSION['cart'] as $key => $line) {
            if (is_array($line)) continue;
            unset($_SESSION['cart'][$key]);
            $pid = (int)$key;
            $qty = (int)$line;
            if ($pid > 0 && $qty > 0) {
                $_SESSION['cart'][alke_cart_key($pid, '', '')] = ['id' => $pid, 'qty' => $qty, 'size' => '', 'color' => ''];
            }
        }
    }

    function alke_cart_count(): int
    {
        alke_cart_init();
        $n = 0;
        foreach ($_SESSION['cart'] as $line) $n += (int)$line['qty'];
        return $n;
    }

    /** Units of a product already in the cart, optionally ignoring one line. */
    function alke_cart_product_qty(int $pid, string $exceptKey = ''): int
    {
        $n = 0;
        foreach ($_SESSION['cart'] as $key => $line) {
            if ((int)$line['id'] === $pid && $key !== $exceptKey) $n += (int)$line['qty'];
        }
        return $n;
    }

    /** The submitted option if the product offers it, else its first option, else ''. */
    function alke_cart_option(?string $csv, string $wanted): string
    {
        $opts = array_values(array_filter(array_map('trim', explode(',', (string)$csv)), 'strlen'));
        if (!$opts) return '';
        return in_array($wanted, $opts, true) ? $wanted : $opts[0];
    }

    /** "Black / M" style label for a line (empty if the product has no options). */
    function alke_variant_label(?string $size, ?string $color): string
    {
        return implode(' / ', array_filter([trim((string)$color), trim((string)$size)], 'strlen'));
    }

    /** Add units of a size/color. Returns [ok, message]. */
    function alke_cart_add(mysqli $conn, int $pid, int $qty, string $size, string $color): array
    {
        alke_cart_init();
        $stmt = $conn->prepare("SELECT stock, sizes, colors FROM products WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $pid);
        $stmt->execute();
        $p = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$p) return [false, 'Product not found.'];

        $stock = (int)$p['stock'];
        if ($stock <= 0) return [false, 'This product is out of stock.'];

        $size  = alke_cart_option($p['sizes'] ?? '', trim($size));
        $color = alke_cart_option($p['colors'] ?? '', trim($color));
        $key   = alke_cart_key($pid, $size, $color);
        $room  = $stock - alke_cart_product_qty($pid, $key);
        $want  = (int)($_SESSION['cart'][$key]['qty'] ?? 0) + max(1, $qty);

        if ($room <= 0) return [false, 'Only ' . $stock . ' in stock — already in your cart.'];
        $_SESSION['cart'][$key] = ['id' => $pid, 'qty' => min($want, $room), 'size' => $size, 'color' => $color];
        return $want > $room ? [false, 'Only ' . $stock . ' in stock — quantity adjusted.'] : [true, ''];
    }

    /** Set a line's quantity (0 removes it). Returns [ok, message]. */
    function alke_cart_set_qty(mysqli $conn, string $key, int $qty): array
    {
        alke_cart_init();
        if (!isset($_SESSION['cart'][$key])) return [false, 'Item not found in your cart.'];
        if ($qty <= 0) {
            unset($_SESSION['cart'][$key]);
            return [true, ''];
        }
        $pid  = (int)$_SESSION['cart'][$key]['id'];
        $stmt = $conn->prepare("SELECT stock FROM products WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $pid);
        $stmt->execute();
        $p = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $room = ($p ? (int)$p['stock'] : 0) - alke_cart_product_qty($pid, $key);
        if ($qty > $room) {
            $_SESSION['cart'][$key]['qty'] = max(1, $room);
            return [false, 'Only ' . max(0, (int)($p['stock'] ?? 0)) . ' in stock — quantity adjusted.'];
        }
        $_SESSION['cart'][$key]['qty'] = $qty;
        return [true, ''];
    }

    /** Cart lines joined with product data; lines for deleted products are dropped. */
    function alke_cart_lines(mysqli $conn): array
    {
        alke_cart_init();
        if (!$_SESSION['cart']) return [];

        $ids = array_unique(array_map(fn($l) => (int)$l['id'], $_SESSION['cart']));
        $products = [];
        if ($res = $conn->query("SELECT * FROM products WHERE id IN (" . implode(',', $ids) . ")")) {
            while ($row = $res->fetch_assoc()) $products[(int)$row['id']] = $row;
        }

        $lines = [];
        foreach ($_SESSION['cart'] as $key => $line) {
            $pid = (int)$line['id'];
            if (!isset($products[$pid])) {
                unset($_SESSION['cart'][$key]);
                continue;
            }
            $qty = max(1, (int)$line['qty']);
            $row = $products[$pid];
            $lines[] = $row + [
                'key'        => $key,
                'quantity'   => $qty,
                'size'       => (string)$line['size'],
                'color'      => (string)$line['color'],
                'variant'    => alke_variant_label($line['size'], $line['color']),
                'image_path' => alke_product_image($row),
                'subtotal'   => (float)$row['price'] * $qty,
            ];
        }
        return $lines;
    }

    function alke_cart_total(array $lines): float
    {
        return (float)array_sum(array_column($lines, 'subtotal'));
    }
}

/* ── order_items size/color columns (added on first use) ──── */
if (!function_exists('alke_ensure_order_item_variants')) {
    function alke_ensure_order_item_variants(mysqli $conn): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        foreach (['size' => 'VARCHAR(30)', 'color' => 'VARCHAR(40)'] as $col => $type) {
            $chk = $conn->query("SHOW COLUMNS FROM order_items LIKE '$col'");
            if ($chk && $chk->num_rows === 0) {
                $conn->query("ALTER TABLE order_items ADD COLUMN $col $type DEFAULT NULL");
            }
        }
    }
}
