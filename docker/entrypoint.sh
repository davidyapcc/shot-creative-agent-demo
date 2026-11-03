#!/bin/sh
set -e

# Ensure .env exists
if [ ! -f ".env" ]; then
  cp .env.example .env
  php artisan key:generate --no-interaction --force
fi

# Ensure vendor exists with lock to avoid race conditions across containers
if [ ! -f "vendor/autoload.php" ]; then
  mkdir -p /tmp/setup-lock
  if mkdir /tmp/setup-lock/install.lock 2>/dev/null; then
    echo "Installing composer dependencies..."
    composer install --no-interaction --prefer-dist --optimize-autoloader
    rmdir /tmp/setup-lock/install.lock
  else
    while [ ! -f "vendor/autoload.php" ]; do
      echo "Waiting for composer install to finish in primary container..."
      sleep 2
    done
  fi
fi

# Primary web container runs database migrations
if [ "$1" = "php" ] && [ "$2" = "artisan" ] && [ "$3" = "serve" ]; then
  php artisan migrate --force --no-interaction
fi

exec "$@"
