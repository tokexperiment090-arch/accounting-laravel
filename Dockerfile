# ============================================================
# RIYO+ - Liberu Accounting Dockerfile
# PHP Version: 8.2 (Stable)
# ============================================================

# Supported PHP versions: 8.2
ARG PHP_VERSION=8.2

###########################################
# Composer dependencies stage
###########################################
FROM php:${PHP_VERSION}-cli-alpine AS composer-deps

WORKDIR /app

# Install required extensions for composer install
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions intl sockets zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy composer files
COPY composer.json composer.lock ./

# Install composer dependencies (no autoloader yet, will optimize in final stage)
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-autoloader \
    --no-ansi \
    --no-scripts \
    --prefer-dist \
    --ignore-platform-req=ext-pcntl

###########################################
# Main application stage
###########################################
FROM php:${PHP_VERSION}-cli-alpine

LABEL maintainer="RIYO+ <support@riyo-plus.com>"
LABEL org.opencontainers.image.title="RIYO+ - Liberu Accounting"
LABEL org.opencontainers.image.description="Production-ready Dockerfile for RIYO+ Accounting"
LABEL org.opencontainers.image.licenses=MIT

ARG WWWUSER=1000
ARG WWWGROUP=1000
ARG TZ=UTC

ENV TERM=xterm-color \
    WITH_HORIZON=false \
    WITH_SCHEDULER=false \
    WITH_REVERB=false \
    OCTANE_SERVER=roadrunner \
    USER=octane \
    ROOT=/var/www/html \
    COMPOSER_FUND=0 \
    COMPOSER_MAX_PARALLEL_HTTP=24

WORKDIR ${ROOT}

SHELL ["/bin/sh", "-lc"]

# ============================================================
# System Setup
# ============================================================
RUN ln -snf /usr/share/zoneinfo/${TZ} /etc/localtime \
  && echo ${TZ} > /etc/timezone

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

# Install system dependencies and PHP extensions
RUN apk update && \
    apk upgrade && \
    apk add --no-cache \
    curl \
    wget \
    nano \
    ncdu \
    procps \
    ca-certificates \
    supervisor \
    libsodium-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev && \
    install-php-extensions \
    bz2 \
    pcntl \
    mbstring \
    bcmath \
    sockets \
    pgsql \
    pdo_pgsql \
    opcache \
    exif \
    pdo_mysql \
    zip \
    intl \
    gd \
    redis \
    pcntl \
    igbinary && \
    docker-php-source delete && \
    rm -rf /var/cache/apk/* /tmp/* /var/tmp/*

# ============================================================
# Supercronic (Cron Scheduler)
# ============================================================
RUN arch="$(apk --print-arch)" \
    && case "$arch" in \
    armhf) _cronic_fname='supercronic-linux-arm' ;; \
    aarch64) _cronic_fname='supercronic-linux-arm64' ;; \
    x86_64) _cronic_fname='supercronic-linux-amd64' ;; \
    x86) _cronic_fname='supercronic-linux-386' ;; \
    *) echo >&2 "error: unsupported architecture: $arch"; exit 1 ;; \
    esac \
    && wget -q "https://github.com/aptible/supercronic/releases/download/v0.2.29/${_cronic_fname}" \
    -O /usr/bin/supercronic \
    && chmod +x /usr/bin/supercronic \
    && mkdir -p /etc/supercronic \
    && echo "*/1 * * * * php ${ROOT}/artisan schedule:run --no-interaction" > /etc/supercronic/laravel

# ============================================================
# Create User
# ============================================================
RUN addgroup -g ${WWWGROUP} ${USER} \
    && adduser -D -h ${ROOT} -G ${USER} -u ${WWWUSER} -s /bin/sh ${USER}

# ============================================================
# Create Directories
# ============================================================
RUN mkdir -p /var/log/supervisor /var/run/supervisor \
    && mkdir -p /tmp/opcache \
    && mkdir -p storage/framework/sessions \
    && mkdir -p storage/framework/views \
    && mkdir -p storage/framework/cache \
    && mkdir -p storage/framework/testing \
    && mkdir -p storage/logs \
    && mkdir -p bootstrap/cache \
    && chown -R ${USER}:${USER} ${ROOT} /var/log /var/run /tmp/opcache \
    && chmod -R 777 /tmp/opcache \
    && chmod -R a+rw ${ROOT}/storage \
    && chmod -R a+rw ${ROOT}/bootstrap/cache

# ============================================================
# PHP Configuration (OPcache Fix)
# ============================================================
RUN cp ${PHP_INI_DIR}/php.ini-production ${PHP_INI_DIR}/php.ini

# Create OPcache configuration file
RUN echo "; OPcache Configuration" > ${PHP_INI_DIR}/conf.d/99-opcache.ini && \
    echo "opcache.enable=1" >> ${PHP_INI_DIR}/conf.d/99-opcache.ini && \
    echo "opcache.memory_consumption=128" >> ${PHP_INI_DIR}/conf.d/99-opcache.ini && \
    echo "opcache.interned_strings_buffer=8" >> ${PHP_INI_DIR}/conf.d/99-opcache.ini && \
    echo "opcache.max_accelerated_files=4000" >> ${PHP_INI_DIR}/conf.d/99-opcache.ini && \
    echo "opcache.revalidate_freq=60" >> ${PHP_INI_DIR}/conf.d/99-opcache.ini && \
    echo "opcache.fast_shutdown=1" >> ${PHP_INI_DIR}/conf.d/99-opcache.ini && \
    echo "opcache.enable_cli=1" >> ${PHP_INI_DIR}/conf.d/99-opcache.ini && \
    echo "; CRITICAL FIX: opcache.file_cache directory" >> ${PHP_INI_DIR}/conf.d/99-opcache.ini && \
    echo "opcache.file_cache=/tmp/opcache" >> ${PHP_INI_DIR}/conf.d/99-opcache.ini && \
    echo "opcache.file_cache_only=0" >> ${PHP_INI_DIR}/conf.d/99-opcache.ini && \
    echo "opcache.file_cache_consistency_checks=1" >> ${PHP_INI_DIR}/conf.d/99-opcache.ini

# Verify OPcache configuration
RUN php -i | grep opcache.file_cache && echo "✅ OPcache file_cache configured successfully"

USER ${USER}

# ============================================================
# Install Composer
# ============================================================
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# ============================================================
# Copy Application Code
# ============================================================
# Copy vendor from composer-deps stage for better caching
COPY --chown=${USER}:${USER} --from=composer-deps /app/vendor ./vendor

# Copy composer files
COPY --chown=${USER}:${USER} composer.json composer.lock ./

# Copy application code
COPY --chown=${USER}:${USER} . .

# ============================================================
# Generate Autoloader
# ============================================================
RUN composer dump-autoload --classmap-authoritative --no-dev && \
    composer clear-cache

# ============================================================
# Copy Configuration Files
# ============================================================
COPY --chown=${USER}:${USER} .docker/supervisord.conf /etc/supervisor/
COPY --chown=${USER}:${USER} .docker/octane/RoadRunner/supervisord.roadrunner.conf /etc/supervisor/conf.d/
COPY --chown=${USER}:${USER} .docker/supervisord.horizon.conf /etc/supervisor/conf.d/
COPY --chown=${USER}:${USER} .docker/supervisord.reverb.conf /etc/supervisor/conf.d/
COPY --chown=${USER}:${USER} .docker/supervisord.scheduler.conf /etc/supervisor/conf.d/
COPY --chown=${USER}:${USER} .docker/supervisord.worker.conf /etc/supervisor/conf.d/
COPY --chown=${USER}:${USER} .docker/start-container /usr/local/bin/start-container

# ============================================================
# Copy Environment File
# ============================================================
COPY --chown=${USER}:${USER} .env ./.env

# ============================================================
# Final Setup
# ============================================================
RUN chmod +x /usr/local/bin/start-container && \
    cat .docker/utilities.sh >> ~/.bashrc

# Ensure opcache directory exists at runtime
RUN mkdir -p /tmp/opcache && chmod 777 /tmp/opcache

# ============================================================
# Health Check
# ============================================================
HEALTHCHECK --start-period=5s --interval=2s --timeout=5s --retries=8 \
    CMD php artisan octane:status || exit 1

# ============================================================
# Expose Ports
# ============================================================
EXPOSE 8000
EXPOSE 8080

# ============================================================
# Entrypoint
# ============================================================
ENTRYPOINT ["start-container"]
