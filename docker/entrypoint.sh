#!/bin/sh
set -e

if [ ! -f "vendor/autoload.php" ]; then
    echo "vendor/autoload.php not found. Installing composer dependencies..."
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

exec "$@"
