# syntax=docker/dockerfile:1

# --- Dépendances PHP de production ---
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --ignore-platform-reqs

# --- Compilation des assets (Vite + Tailwind + Alpine) ---
FROM node:24-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
COPY --from=vendor /app/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
RUN npm run build

# --- Base commune : PHP 8.4 FPM + Nginx, utilisateur www-data (non-root) ---
FROM serversideup/php:8.4-fpm-nginx AS base
WORKDIR /var/www/html
COPY --chown=www-data:www-data --from=vendor /app/vendor ./vendor
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build

# --- Tests : dépendances de dev + PHPUnit (docker compose run --rm backend-test) ---
FROM base AS test
ENV APP_ENV=testing
# Configuration fournie par l'environnement ; `artisan test` attend tout de même un fichier .env
RUN composer install --no-interaction --prefer-dist --optimize-autoloader \
    && touch .env
CMD ["php", "artisan", "test"]

# --- Image de production (cible par défaut) ---
FROM base AS production
ENV PHP_OPCACHE_ENABLE=1
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev
