#!/bin/sh
set -e

# 1. Resolve dynamic port (Render injects $PORT, defaults to 10000)
PORT="${PORT:-10000}"
export PORT

echo "==> Configuring Nginx port to $PORT..."
envsubst '${PORT}' < /etc/nginx/conf.d/default.conf.template > /etc/nginx/conf.d/default.conf

# 2. Ensure public storage symlink exists
if [ ! -L /var/www/html/public/storage ]; then
    echo "==> Creating storage symlink..."
    php artisan storage:link || true
fi

# 3. Ensure permissions for storage and bootstrap/cache
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# 4. Cache configurations for production performance (if APP_KEY exists)
if [ -n "$APP_KEY" ]; then
    echo "==> Caching Laravel configuration and routes..."
    php artisan config:cache || true
    php artisan route:cache || true
    php artisan view:cache || true
else
    echo "==> WARNING: APP_KEY is not set. Skipping config cache."
fi

# 5. Optional automated migrations (only if explicitly enabled)
if [ "$RUN_MIGRATIONS" = "true" ] || [ "$RUN_MIGRATIONS" = "1" ]; then
    echo "==> Running database migrations (--force)..."
    php artisan migrate --force
else
    echo "==> Skipping automated migrations (set RUN_MIGRATIONS=true in Render to run)."
fi

echo "==> Starting web services (Nginx + PHP-FPM)..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
