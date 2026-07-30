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
# ÉTAPE 3 : base PHP-FPM commune
# ============================================================
FROM php:8.3-fpm-bookworm AS php_fpm_base

LABEL org.opencontainers.image.title="Symfony AMQP PHP-FPM"
LABEL org.opencontainers.image.description="PHP 8.3 FPM pour l'application Symfony"

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PATH="/var/www/html/vendor/bin:${PATH}"

WORKDIR /var/www/html

# Outils système utiles au développement et au healthcheck FPM
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash \
        curl \
        git \
        unzip \
        libfcgi-bin \
    && rm -rf /var/lib/apt/lists/*

# Installation du script spécialisé pour les extensions PHP
COPY --from=extension_installer \
    /usr/bin/install-php-extensions \
    /usr/local/bin/install-php-extensions

# Extensions nécessaires à Symfony, PostgreSQL, Redis et RabbitMQ
RUN install-php-extensions \
    intl \
    pdo_pgsql \
    zip \
    opcache \
    redis \
    amqp \
    pcntl \
    sockets

# Copie de Composer depuis son image officielle
COPY --from=composer_binary \
    /usr/bin/composer \
    /usr/local/bin/composer

# Script de démarrage de l'application
COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint

RUN chmod +x /usr/local/bin/docker-entrypoint

ENTRYPOINT ["/usr/local/bin/docker-entrypoint"]

# Configuration PHP générale
RUN printf '%s\n' \
    'date.timezone=UTC' \
    'memory_limit=512M' \
    'upload_max_filesize=20M' \
    'post_max_size=20M' \
    'max_execution_time=300' \
    'expose_php=0' \
    'opcache.enable=1' \
    'opcache.enable_cli=1' \
    'opcache.validate_timestamps=1' \
    > /usr/local/etc/php/conf.d/99-app.ini

# Activation du ping interne de PHP-FPM
RUN printf '%s\n' \
    '[www]' \
    'ping.path=/fpm-ping' \
    'pm.status_path=/fpm-status' \
    > /usr/local/etc/php-fpm.d/zz-healthcheck.conf


# ============================================================
# ÉTAPE 4 : image de développement
# ============================================================
FROM php_fpm_base AS php_fpm_dev

ENV APP_ENV=dev
ENV APP_DEBUG=1

CMD ["php-fpm", "-F"]


# ============================================================
# ÉTAPE 5 : image de production
# Cette étape sera surtout utilisée plus tard par la CI/CD.
# ============================================================
FROM php_fpm_base AS php_fpm_prod

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

RUN printf '%s\n' \
    'opcache.validate_timestamps=0' \
    > /usr/local/etc/php/conf.d/99-production.ini

CMD ["php-fpm", "-F"]