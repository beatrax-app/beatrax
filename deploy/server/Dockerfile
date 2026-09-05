# syntax=docker/dockerfile:1
#
# Production image for a self-hosted SERVER deployment of Beatrax (the desktop
# build ships via NativePHP and does not use this). Uses FrankenPHP — a single
# binary that serves the app over HTTP/HTTPS with no separate nginx/php-fpm to
# wire together — so the application code is baked into the image and there is
# no shared-code volume to keep in sync. Multi-stage: build the front-end
# assets with Node, then assemble the FrankenPHP (PHP 8.5) runtime. SQLite is
# the only database this schema supports, in every deployment shape, so
# pdo_sqlite is the only driver installed (ADR-0022).

# --- stage 1: build front-end assets (Vite / Tailwind) ---
FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm ci
# Copy the full source so Tailwind can scan every Blade template (incl. Modules/*).
COPY . .
RUN npm run build

# --- stage 2: FrankenPHP runtime ---
FROM dunglas/frankenphp:php8.5 AS runtime
# install-php-extensions ships in the FrankenPHP image.
RUN install-php-extensions \
        intl pcntl posix pdo_sqlite zip bcmath @composer
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip poppler-utils curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . .
COPY --from=assets /app/public/build ./public/build
# --no-dev: production only. post-autoload-dump runs `package:discover` (safe);
# native:install only runs on `composer update`, never here.
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist \
    && chown -R www-data:www-data storage bootstrap/cache

# FrankenPHP serves $WORKDIR/public automatically. SERVER_NAME=:80 = plain HTTP
# (terminate TLS at a reverse proxy / VPN). Override to a hostname for built-in
# HTTPS. The queue/scheduler services override this command with artisan.
ENV SERVER_NAME=:80
EXPOSE 80
