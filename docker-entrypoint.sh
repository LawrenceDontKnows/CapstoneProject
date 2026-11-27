
#!/bin/sh
set -e

PORT="${PORT:-80}"

# Configure Apache to use Railway's assigned port
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Ensure upload directories exist
mkdir -p /var/www/html/uploads/grade_imports

# Set permissions for the Railway persistent volume
chown -R www-data:www-data /var/www/html/uploads/grade_imports
chmod -R 775 /var/www/html/uploads/grade_imports

# Start Apache
exec "$@"
