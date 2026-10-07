# Alke store — production image
# Uses the official PHP image so the mysqli extension is guaranteed present
# (Railway's nixpacks php build does not include it by default).
FROM php:8.2-cli

# mysqli for MySQL connectivity + GD for server-side image downscaling on upload
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libjpeg62-turbo-dev libpng-dev libwebp-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" mysqli gd \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Production PHP hardening (errors off, secure session cookies)
COPY php-prod.ini /usr/local/etc/php/conf.d/zz-alke.ini

COPY . /app

# Railway injects $PORT; default to 8080 locally. router.php maps the app's
# /alke/ absolute paths onto the domain root.
EXPOSE 8080
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} router.php"]
