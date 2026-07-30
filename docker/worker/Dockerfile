# syntax=docker/dockerfile:1.7

# ============================================================
# ÉTAPE 1 : récupération de Composer
# ============================================================
FROM composer:2 AS composer_binary


# ============================================================
# ÉTAPE 2 : outil d'installation des extensions PHP
# ============================================================
FROM ghcr.io/mlocati/php-extension-installer:2.11.1 AS extension_installer


# ============================================================
# ÉTAPE 3 : base PHP-CLI commune aux workers
# ============================================================
FROM php:8.3-cli-bookworm AS php_cli_base

LABEL org.opencontainers.image.title="Symfony AMQP Worker"
LABEL org.opencontainers.image.description="PHP 8.3 CLI pour les workers Symfony Messenger"

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PATH="/var/www/html/vendor/bin:${PATH}"

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash \
        curl \
        git \
        unzip \
        procps \
    && rm -rf /var/lib/apt/lists/*

COPY --from=extension_installer \
    /usr/bin/install-php-extensions \
    /usr/local/bin/install-php-extensions

RUN install-php-extensions \
    intl \
    pdo_pgsql \
    zip \
    opcache \
    redis \
    amqp \
    pcntl \
    sockets

COPY --from=composer_binary \
    /usr/bin/composer \
    /usr/local/bin/composer

RUN printf '%s\n' \
    'date.timezone=UTC' \
    'memory_limit=512M' \
    'max_execution_time=0' \
    'opcache.enable_cli=1' \
    > /usr/local/etc/php/conf.d/99-worker.ini


# ============================================================
# ÉTAPE 4 : worker de développement
# ============================================================
FROM php_cli_base AS worker_dev

ENV APP_ENV=dev
ENV APP_DEBUG=1

CMD ["php", "-v"]


# ============================================================
# ÉTAPE 5 : worker de production
# ============================================================
FROM php_cli_base AS worker_prod

ENV APP_ENV=prod
ENV APP_DEBUG=0

COPY app/composer.json app/composer.lock ./

RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --classmap-authoritative

COPY app/ ./

RUN mkdir -p var/cache var/log \
    && chown -R www-data:www-data var

CMD ["php", "bin/console", "messenger:consume", "async_normal", "--time-limit=3600", "--memory-limit=512M"]