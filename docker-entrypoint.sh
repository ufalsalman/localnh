#!/bin/sh
set -e

# If config directory is empty, seed it with template files from config.example
if [ -d "/var/www/html/config.example" ] && [ -d "/var/www/html/config" ]; then
    if [ ! "$(ls -A /var/www/html/config)" ]; then
        echo "[*] Initializing empty config directory from config.example..."
        cp -r /var/www/html/config.example/* /var/www/html/config/
    fi
fi

# Ensure storage directories have proper ownership for the web server
chown -R www-data:www-data /var/www/html/cache /var/www/html/snapshot /var/www/html/config 2>/dev/null || true
if [ -f "/var/www/html/cache.sqlite" ]; then
    chown www-data:www-data /var/www/html/cache.sqlite 2>/dev/null || true
fi

# Prepare only the dedicated session directory, including persistent mounts.
# Keep this separate from collection backups. PHP and CLI must use the same
# LOCALNH_SESSION_DIR; a persistent mount preserves logins across recreation.
mkdir -p "$LOCALNH_SESSION_DIR"
chown www-data:www-data "$LOCALNH_SESSION_DIR"
chmod 700 "$LOCALNH_SESSION_DIR"

# Start PHP-FPM daemon
php-fpm -D

# Start Caddy web server in foreground. An optional deployment can mount a
# custom Caddyfile here and persist Caddy CA data outside the source tree.
# Cloudflare or any other host proxy is not started by this script.
# See DEPLOYMENT.md for HTTPS origin setup and certificate verification.
exec caddy run --config /var/www/html/Caddyfile --adapter caddyfile
