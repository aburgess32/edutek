# EduPak PHP Application Image
# Base: PHP 8.1 with Apache (matches composer.json requirement: >=8.1)
FROM php:8.1-apache

# Install system dependencies required for PHP extensions
RUN apt-get update && apt-get install -y \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libicu-dev \
    libzip-dev \
    zip \
    unzip \
    ffmpeg \
    && rm -rf /var/lib/apt/lists/*

# Configure and install GD with full format support
RUN docker-php-ext-configure gd \
    --with-freetype \
    --with-jpeg \
    --with-webp

# Install PHP extensions needed by EduPak
RUN docker-php-ext-install -j$(nproc) \
    gd \
    mysqli \
    pdo \
    pdo_mysql \
    intl \
    zip \
    opcache

# Install Composer for PHP dependency management
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Enable Apache mod_rewrite for .htaccess support
RUN a2enmod rewrite

# Enable OpenSSL 3.0 legacy provider (required for BF-CBC cipher used in tiles.php link encryption)
RUN { \
    echo 'openssl_conf = openssl_init'; \
    echo '[openssl_init]'; \
    echo 'providers = provider_sect'; \
    echo '[provider_sect]'; \
    echo 'default = default_sect'; \
    echo 'legacy = legacy_sect'; \
    echo '[default_sect]'; \
    echo 'activate = 1'; \
    echo '[legacy_sect]'; \
    echo 'activate = 1'; \
} > /usr/local/etc/openssl-legacy.cnf
ENV OPENSSL_CONF=/usr/local/etc/openssl-legacy.cnf

# PHP production-oriented settings tuned for low-memory tablet clients
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.memory_consumption=64'; \
    echo 'opcache.interned_strings_buffer=8'; \
    echo 'opcache.max_accelerated_files=4000'; \
    echo 'opcache.revalidate_freq=60'; \
    echo 'opcache.fast_shutdown=1'; \
} > /usr/local/etc/php/conf.d/opcache-recommended.ini

# General PHP settings
RUN { \
    echo 'upload_max_filesize=64M'; \
    echo 'post_max_size=64M'; \
    echo 'memory_limit=128M'; \
    echo 'max_execution_time=60'; \
    echo 'display_errors=Off'; \
    echo 'log_errors=On'; \
    echo 'error_log=/var/log/apache2/php_error.log'; \
    echo 'output_buffering=4096'; \
    echo 'session.auto_start=0'; \
} > /usr/local/etc/php/conf.d/edupak.ini

# Set document root to htdocs (matching XAMPP convention)
ENV APACHE_DOCUMENT_ROOT /var/www/html

# Allow .htaccess overrides — required for EduPak routing
RUN sed -i 's|/var/www/html|${APACHE_DOCUMENT_ROOT}|g' /etc/apache2/sites-available/000-default.conf \
    && sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

WORKDIR /var/www/html

# Install PHP dependencies (composer.json is mounted/copied at runtime)
# This layer is for production builds; dev uses volume mounts
COPY composer.json composer.lock* /var/www/
RUN cd /var/www && composer install --no-interaction --prefer-dist --no-dev 2>/dev/null || true

EXPOSE 80
