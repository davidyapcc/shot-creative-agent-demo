#!/bin/sh
set -e

frontend_sources_are_newer()
{
  if [ ! -f "public/build/manifest.json" ]; then
    return 0
  fi

  newer=$(find resources package.json vite.config.js -type f -newer public/build/manifest.json | head -n 1 || true)
  [ -n "$newer" ]
}

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

# 3. Primary web container runs database migrations and builds frontend assets
if [ "$1" = "php" ] && [ "$2" = "artisan" ] && [ "$3" = "serve" ]; then
  php artisan migrate --force --no-interaction

  # Remove stale hot-reload file if present from host
  if [ -f "public/hot" ]; then
    rm -f public/hot
  fi

  if command -v npm >/dev/null 2>&1; then
    if [ ! -f "public/build/manifest.json" ] || [ "${FORCE_FRONTEND_BUILD:-false}" = "true" ] || frontend_sources_are_newer; then
      echo "Building frontend assets..."
      if [ ! -x node_modules/.bin/vite ]; then
        echo "Installing frontend dependencies..."
        npm install
      fi
      if ! npm run build; then
        echo "Frontend build failed. Reinstalling dependencies and retrying..." >&2
        npm install
        npm run build
      fi
    fi
  fi
fi

exec "$@"
