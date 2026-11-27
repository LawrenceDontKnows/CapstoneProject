
FROM php:8.2-apache

# Install system dependencies + PHP extensions required by the app
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_mysql mbstring fileinfo gd zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Production PHP settings
RUN { \
    echo 'display_errors=Off'; \
    echo 'log_errors=On'; \
    echo 'error_log=/tmp/php_errors.log'; \
    echo 'memory_limit=256M'; \
    echo 'upload_max_filesize=20M'; \
    echo 'post_max_size=25M'; \
} > /usr/local/etc/php/conf.d/production.ini

# Add Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy application source
COPY . /var/www/html/

# Install PHP dependencies, including PHPMailer
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --optimize-autoloader \
    --working-dir=/var/www/html

# Ensure uploads directories exist and are writable
RUN mkdir -p /var/www/html/uploads/avatars \
    /var/www/html/uploads/grade_imports \
    /var/www/html/uploads/register_bg \
    /var/www/html/uploads/slideshow \
    && chown -R www-data:www-data /var/www/html/uploads

# Runtime entrypoint: bind Apache to Railway's PORT
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
