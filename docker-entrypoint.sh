
#!/bin/sh
set -e

# Railway application port
PORT="${PORT:-8080}"

echo "========== FIXING APACHE MPM =========="

# Remove ALL enabled MPM modules
rm -f /etc/apache2/mods-enabled/mpm_*.load
rm -f /etc/apache2/mods-enabled/mpm_*.conf

# Enable only prefork
a2enmod mpm_prefork
a2enmod rewrite

# Verify Apache has exactly one MPM
APACHE_MODULES="$(apache2ctl -M 2>&1)" || {
    echo "ERROR: Apache configuration validation failed:"
    echo "$APACHE_MODULES"
    exit 1
}

echo "$APACHE_MODULES"

MPM_COUNT="$(printf '%s\n' "$APACHE_MODULES" | grep -Ec 'mpm_(event|worker|prefork)_module' || true)"

if [ "$MPM_COUNT" -ne 1 ]; then
    echo "ERROR: Expected exactly one MPM. Found: $MPM_COUNT"
    exit 1
fi

echo "Apache MPM successfully configured."

echo "========== CONFIGURING RAILWAY PORT =========="

# Configure Apache to listen on Railway's port
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Ensure grade import directory exists
mkdir -p /var/www/html/uploads/grade_imports

# Set permissions for Railway persistent volume
chown -R www-data:www-data /var/www/html/uploads/grade_imports
chmod -R 775 /var/www/html/uploads/grade_imports

echo "Starting Apache on port ${PORT}"

# Start Apache
exec "$@"
6ab731fe94a9a97d25655839ecef759c7dd69bb0c30a2f87