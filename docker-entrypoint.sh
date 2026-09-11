#!/bin/sh
set -e

# External storage must be initialized explicitly. Never silently create a
# second empty collection when an environment variable or mount is wrong.
if [ -n "${LOCALNH_DATA_DIR:-}" ]; then
    python3 /var/www/html/script/py/storage.py paths >/dev/null
    chown -R www-data:www-data "$LOCALNH_DATA_DIR"
else
    if [ -d /var/www/html/config ] && [ -z "$(ls -A /var/www/html/config)" ]; then
        cp -r /var/www/html/config.example/. /var/www/html/config/
    fi
    chown -R www-data:www-data /var/www/html/cache /var/www/html/snapshot /var/www/html/config 2>/dev/null || true
    if [ -f /var/www/html/cache.sqlite ]; then chown www-data:www-data /var/www/html/cache.sqlite; fi
fi
session_dir=$(python3 -c 'import sys; sys.path.insert(0,"/var/www/html/script/py"); from storage import session_dir; print(session_dir())')
mkdir -p "$session_dir"
chown www-data:www-data "$session_dir"
chmod 700 "$session_dir"
# Verify selected storage as the actual writer, not only as the root launcher.
runuser -u www-data -- python3 /var/www/html/script/py/storage.py paths >/dev/null

# Start PHP-FPM daemon
php-fpm -D

# Start Caddy web server in foreground. An optional deployment can mount a
# custom Caddyfile here and persist Caddy CA data outside the source tree.
# Cloudflare or any other host proxy is not started by this script.
# See DEPLOYMENT.md for HTTPS origin setup and certificate verification.
exec caddy run --config /var/www/html/Caddyfile --adapter caddyfile
