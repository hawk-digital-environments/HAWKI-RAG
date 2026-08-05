#!/bin/bash
set -e

APP_READY_MARKER=/tmp/hawki-rag-app-ready
rm -f "$APP_READY_MARKER"

# Create symlink for built assets
echo "Creating symlink for built assets..."
rm -rf /var/www/html/public/build
ln -s /var/www/built_resources /var/www/html/public/build

echo "Fixing Laravel storage and cache permissions..."

# Ensure specific subdirectories exist
mkdir -p /var/www/html/storage/logs
mkdir -p /var/www/html/storage/framework/cache
mkdir -p /var/www/html/storage/framework/sessions
mkdir -p /var/www/html/storage/framework/views
mkdir -p /var/www/html/storage/app/public

# Set permissions after the persistent storage mount has been initialized.
chown -R www-data:www-data \
    /var/www/html/storage \
    /var/www/html/bootstrap/cache
chmod -R 775 \
    /var/www/html/storage \
    /var/www/html/bootstrap/cache

CRAWLED_DATA_ROOT="${HAWKI_RAG_CRAWLED_DATA_ROOT:-${DEFAULT_CRAWLED_ROOT:-/app/shared}}"
SHARED_STORAGE_UID="${PIPELINE_SHARED_STORAGE_UID:?PIPELINE_SHARED_STORAGE_UID is required}"
SHARED_STORAGE_GID="${PIPELINE_SHARED_STORAGE_GID:?PIPELINE_SHARED_STORAGE_GID is required}"

echo "Ensuring crawled-data root exists at $CRAWLED_DATA_ROOT..."
mkdir -p \
    "$CRAWLED_DATA_ROOT/sources" \
    "$CRAWLED_DATA_ROOT/logs" \
    "$CRAWLED_DATA_ROOT/public" \
    "$CRAWLED_DATA_ROOT/storage/logs"
chown -R www-data:www-data "$CRAWLED_DATA_ROOT"
chgrp -R "$SHARED_STORAGE_GID" "$CRAWLED_DATA_ROOT"
chmod -R 775 "$CRAWLED_DATA_ROOT"
setfacl -R -P -m "u:$SHARED_STORAGE_UID:rwX,m::rwX" "$CRAWLED_DATA_ROOT"
find "$CRAWLED_DATA_ROOT" -type d -exec setfacl -m \
    "d:u:$SHARED_STORAGE_UID:rwx,d:g::rwx,d:m::rwx" {} +
find "$CRAWLED_DATA_ROOT" -type d -exec chmod g+s {} +

echo "Permissions fixed successfully!"

# Run Laravel package discovery (skipped during build)
echo "Running Laravel package discovery..."
php artisan package:discover --ansi || echo "Warning: Package discovery failed, continuing..."

touch "$APP_READY_MARKER"
echo "Container initialization complete!"
