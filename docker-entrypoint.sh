#!/bin/sh
# ---------------------------------------------------------------------------
# FamPay Gateway - container entrypoint
# Render provides the listening port through $PORT (usually 10000).
# Apache must be reconfigured before it starts, otherwise the health check
# and the public URL never connect.
# ---------------------------------------------------------------------------
set -e

PORT="${PORT:-80}"

echo "[fampay] configuring Apache to listen on port ${PORT}"
sed -ri "s/^Listen [0-9]+$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s|<VirtualHost \*:[0-9]+>|<VirtualHost *:${PORT}>|" /etc/apache2/sites-available/000-default.conf

# Silence the "could not reliably determine the server's fully qualified domain name" warning
if ! grep -q "^ServerName" /etc/apache2/apache2.conf; then
  echo "ServerName localhost" >> /etc/apache2/apache2.conf
fi

# Composer dependencies may be absent when the image is built without them.
if [ ! -f /var/www/html/vendor/autoload.php ] && [ -f /usr/bin/composer ]; then
  echo "[fampay] vendor/ missing - installing composer dependencies"
  cd /var/www/html && composer install --no-dev --optimize-autoloader --no-interaction || true
fi

exec "$@"
