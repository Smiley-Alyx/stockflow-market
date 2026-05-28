FROM php:8.4-cli-alpine

ARG UID=1000
ARG GID=1000

WORKDIR /var/www/html

RUN apk add --no-cache \
        bash \
        curl \
        git \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        postgresql-dev \
        sqlite-dev \
        unzip \
    && docker-php-ext-install \
        bcmath \
        intl \
        pcntl \
        pdo_pgsql \
        pdo_sqlite \
        zip \
    && addgroup -g "${GID}" stockflow \
    && adduser -D -u "${UID}" -G stockflow stockflow

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/stockflow.ini

USER stockflow

ENV COMPOSER_CACHE_DIR=/tmp/composer-cache
