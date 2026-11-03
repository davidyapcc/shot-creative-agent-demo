#!/bin/sh
set -e

# 1. Ensure composer dependencies exist first before invoking any PHP/artisan commands
if [ ! -f "vendor/autoload.php" ]; then
  echo "Installing composer dependencies..."
  composer install --no-interaction --prefer-dist --optimize-autoloader
fi

# 2. Ensure .env exists and APP_KEY is generated
if [ ! -f ".env" ]; then
  cp .env.example .env
fi

if ! grep -q "^APP_KEY=base64:" .env 2>/dev/null; then
  php artisan key:generate --no-interaction --force
fi

# 3. Primary web container runs database migrations
if [ "$1" = "php" ] && [ "$2" = "artisan" ] && [ "$3" = "serve" ]; then
  php artisan migrate --force --no-interaction
fi

exec "$@"
