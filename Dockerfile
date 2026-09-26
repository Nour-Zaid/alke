# Alke store — production image
# Uses the official PHP image so the mysqli extension is guaranteed present
# (Railway's nixpacks php build does not include it by default).
FROM php:8.2-cli

# mysqli for MySQL connectivity
RUN docker-php-ext-install mysqli

WORKDIR /app
COPY . /app

# Railway injects $PORT; default to 8080 locally. router.php maps the app's
# /alke/ absolute paths onto the domain root.
EXPOSE 8080
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} router.php"]
