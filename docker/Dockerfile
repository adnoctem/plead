# syntax=docker/dockerfile:1.4

# Build arguments
ARG PHP_VERSION=8.4

# pin versions
FROM php:${PHP_VERSION}-cli-alpine AS base

# container settings
ARG PUID=1001
ARG PGID=1001

WORKDIR /app

# persistent / runtime deps
# hadolint ignore=DL3018
RUN apk add --no-cache \
        git \
    && rm -rf /var/cache/apk/*

# install `install-php-extensions` to install extensions (and composer) with all dependencies included
# ref: https://github.com/mlocati/docker-php-extension-installer
# pdo_sqlite (SQLite database) and pcntl (signal handling in the watch commands)
# are the only extensions the official image does not already compile in.
RUN curl -sSLf \
        -o /usr/local/bin/install-php-extensions \
        https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions && \
    chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions \
    @composer \
    pdo_sqlite \
    pcntl

# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    PHP_INI_SCAN_DIR=":$PHP_INI_DIR/app.conf.d" \
    HOME=/app \
    PLEAD_DATA_DIR=/data

COPY --link --chown=${PUID}:${PGID} docker/conf/php/10-app.ini $PHP_INI_DIR/app.conf.d/

# plead keeps its SQLite database and log file under PLEAD_DATA_DIR
VOLUME [ "/data" ]

# set default environment
ENV APP_ENV=dev \
    XDEBUG_MODE=off

ENTRYPOINT ["/app/bin/plead"]

# -------------------------------------
# DEVELOPMENT Image
# -------------------------------------
FROM base AS dev

ARG PUID
ARG PGID

ENV APP_ENV=dev \
    XDEBUG_MODE=off

# add xdebug in DEV mode
RUN install-php-extensions xdebug

# copy development configs
COPY --link --chown=${PUID}:${PGID} docker/conf/php/20-app.dev.ini $PHP_INI_DIR/app.conf.d/

# -------------------------------------
# PRODUCTION Image
# -------------------------------------
FROM base AS prod

ARG PUID
ARG PGID

ENV APP_ENV=prod

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# copy production configs
COPY --link --chown=${PUID}:${PGID} docker/conf/php/30-app.prod.ini $PHP_INI_DIR/app.conf.d/

# non-root user + writable data directory before dropping privileges
RUN addgroup -S -g ${PGID} plead \
    && adduser -S -D -H -u ${PUID} -G plead plead \
    && mkdir -p /data \
    && chown ${PUID}:${PGID} /data

# prevent the reinstallation of vendors at every changes in the source code
COPY --link composer.json composer.lock ./
RUN set -eux; \
	composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

# copy sources
COPY --link --chown=${PUID}:${PGID} .. ./
RUN rm -Rf docker/

# prepare sources
RUN set -eux; \
	composer dump-autoload --classmap-authoritative --no-dev

# own files
RUN find /app -type f -exec chmod 644 {} + && \
    find /app -type d -exec chmod 755 {} + && \
    chmod +x /app/bin/plead && \
    chown -R ${PUID}:${PGID} /app

USER plead:plead

ENTRYPOINT ["/app/bin/plead"]
