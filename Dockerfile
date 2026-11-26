FROM php:8.2-apache

# Install system deps + PHP extensions required by the app
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg-dev libfreetype6-dev libzip-dev \
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

# Copy app source
COPY . /var/www/html/

# Ensure uploads dir exists and is writable
RUN mkdir -p /var/www/html/uploads/avatars /var/www/html/uploads/grade_imports \
    /var/www/html/uploads/register_bg /var/www/html/uploads/slideshow \
    && chown -R www-data:www-data /var/www/html/uploads

# Runtime entrypoint: bind Apache to $PORT provided by Render
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]