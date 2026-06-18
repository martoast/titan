# syntax=docker/dockerfile:1.7
#
# Production image for the Titan Laravel app (web + queue + scheduler all run this image,
# differing only by command). Local development still uses Laravel Sail (compose.yaml);
# this Dockerfile is for SELF-HOSTING via docker-compose.prod.yml.
#
# Multi-stage: composer deps → Vite assets → a hardened php-fpm + nginx runtime.

# ─── 1) PHP dependencies (no dev) ────────────────────────────────────────────
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
      --no-dev --prefer-dist --no-interaction --no-progress \
      --no-scripts --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev --no-scripts

# ─── 2) Frontend assets (Vite/Tailwind) ──────────────────────────────────────
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm ci --no-audit --no-fund
COPY . .
RUN npm run build

# ─── 3) Runtime: serversideup php-fpm + nginx (non-root, prod-tuned) ──────────
FROM serversideup/php:8.4-fpm-nginx AS app

# TLS is terminated by Caddy in front of this container, so serve plain HTTP on :8080.
# Laravel optimizations run at boot; ONLY the web service migrates (set in compose).
ENV PHP_OPCACHE_ENABLE=1 \
    SSL_MODE=off \
    AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_MIGRATION=false \
    AUTORUN_LARAVEL_CONFIG_CACHE=true \
    AUTORUN_LARAVEL_VIEW_CACHE=true \
    AUTORUN_LARAVEL_ROUTE_CACHE=false \
    AUTORUN_LARAVEL_STORAGE_LINK=true

USER root
RUN install-php-extensions bcmath gd intl pcntl exif redis
USER www-data

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=vendor /app/vendor ./vendor
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build
