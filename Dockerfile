
FROM php:8.2-apache

# Install system dependencies + PHP extensions required by the app
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libonig-dev libcurl4-openssl-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_mysql mbstring fileinfo gd zip curl \
    && rm -rf /var/lib/apt/lists/*

# Configure Apache MPM
# Force Apache to use only prefork MPM
RUN set -eux; \
    rm -f /etc/apache2/mods-enabled/mpm_*.load; \
    rm -f /etc/apache2/mods-enabled/mpm_*.conf; \
    a2enmod mpm_prefork; \
    a2enmod rewrite; \
    apache2ctl -M 2>&1 | grep mpm_prefork_module

# Production PHP settings
RUN { \
    echo 'display_errors=Off'; \
    echo 'log_errors=On'; \
    echo 'error_log=/dev/stderr'; \
    echo 'memory_limit=512M'; \
    echo 'max_execution_time=120'; \
    echo 'max_input_time=120'; \
    echo 'post_max_size=25M'; \
    echo 'upload_max_filesize=20M'; \
    echo 'session.save_path=/tmp'; \
} > /usr/local/etc/php/conf.d/production.ini

# Add Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy application source
COPY . /var/www/html/

# Install PHP dependencies, including PHPMailer
RUN cp /var/www/html/composer/composer.json /var/www/html/composer.json \
    && cp /var/www/html/composer/composer.lock /var/www/html/composer.lock \
    && composer install \
        --no-dev \
        --prefer-dist \
        --no-interaction \
        --optimize-autoloader \
        --working-dir=/var/www/html

# Ensure upload directories exist and are writable
RUN mkdir -p /var/www/html/uploads/avatars \
    /var/www/html/uploads/grade_imports \
    /var/www/html/uploads/register_bg \
    /var/www/html/uploads/slideshow \
    && chown -R www-data:www-data /var/www/html/uploads /var/www/html/image

# Runtime entrypoint: bind Apache to Railway's PORT
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8080
ENTRYPOINT ["/bin/sh", "/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]