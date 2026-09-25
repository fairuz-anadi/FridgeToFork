#!/bin/sh
set -e

echo "Starting FridgeToFork on Render..."

# Render routes traffic to $PORT.
PORT="${PORT:-10000}"
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Recipe image URLs are built from APP_URL; default to the service's own URL.
if [ -z "${APP_URL}" ] && [ -n "${RENDER_EXTERNAL_URL}" ]; then
  export APP_URL="${RENDER_EXTERNAL_URL}"
fi

# Derive a stable Laravel key from the secret seed Render generates, so the
# key survives restarts without being committed to the repository.
if [ -z "${APP_KEY}" ]; then
  if [ -n "${APP_KEY_SEED}" ]; then
    APP_KEY=$(php -r "echo 'base64:' . base64_encode(hash('sha256', getenv('APP_KEY_SEED'), true));")
  else
    APP_KEY=$(php artisan key:generate --show --no-interaction)
  fi
  export APP_KEY
fi

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

php artisan config:clear || true
php artisan storage:link --force || true

echo "Waiting for the database..."
tries=0
until php artisan migrate:status >/dev/null 2>&1; do
  tries=$((tries + 1))
  if [ "$tries" -ge 30 ]; then
    echo "Database not reachable; showing the last error:"
    php artisan migrate:status || true
    exit 1
  fi
  sleep 2
done

echo "Running migrations..."
php artisan migrate --force

# The seeders use updateOrCreate, so re-running them is safe. It also
# regenerates the recipe artwork, which lives on the container's disk and is
# lost whenever a free Render instance restarts.
echo "Seeding demo data..."
php artisan db:seed --force

echo "Starting Apache on port ${PORT}..."
exec apache2-foreground
