#!/bin/sh
set -e

PORT="${PORT:-8080}"

echo "========== APACHE MPM DIAGNOSTICS =========="

echo "Enabled MPM files:"
ls -la /etc/apache2/mods-enabled/mpm_* 2>&1 || true

echo "All MPM LoadModule directives:"
grep -RniE '^[[:space:]]*LoadModule[[:space:]]+mpm_' /etc/apache2 2>/dev/null || true

echo "Apache module configuration:"
apache2ctl -M 2>&1 || true

echo "========== END DIAGNOSTICS =========="

# Configure Apache port
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Prepare grade import directory
mkdir -p /var/www/html/uploads/grade_imports
chown -R www-data:www-data /var/www/html/uploads/grade_imports
chmod -R 775 /var/www/html/uploads/grade_imports

exec "$@"