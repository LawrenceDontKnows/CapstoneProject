
#!/bin/sh
set -e

# Railway application port
PORT="${PORT:-8080}"

# Configure Apache to listen on Railway's port
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Ensure grade import directory exists
mkdir -p /var/www/html/uploads/grade_imports

# Set permissions for Railway persistent volume
chown -R www-data:www-data /var/www/html/uploads/grade_imports
chmod -R 775 /var/www/html/uploads/grade_imports

# Start Apache
exec "$@"
