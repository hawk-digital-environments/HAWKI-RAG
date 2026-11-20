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
# get www-data user id

current_www_data_uid=$(id -u www-data)
echo "www-data user ID: $current_www_data_uid"

# get current owner and group of the directory

current_owner=$(stat -c "%U" "$CRAWLED_DATA_ROOT")

current_owner_id=$(stat -c "%u" "$CRAWLED_DATA_ROOT")

# show the current owner and group for debugging
echo "Current owner of $CRAWLED_DATA_ROOT: $current_owner"
echo "Current owner ID of $CRAWLED_DATA_ROOT: $current_owner_id"
current_group=$(stat -c "%g" "$CRAWLED_DATA_ROOT")
echo "Current group of $CRAWLED_DATA_ROOT:$current_group"
# If the current owner id is not equal to the uid of www-data, change the owner to www-data and the group to the shared storage group.
if [[ "$current_owner_id" -ne "$current_www_data_uid" ]]; then
    echo "Changing owner of $CRAWLED_DATA_ROOT to www-data and group to $SHARED_STORAGE_GID..."
    chown -R www-data:"$SHARED_STORAGE_GID" "$CRAWLED_DATA_ROOT"
fi
# check if the outer moste directory has the correct permissions
current_permissions=$(stat -c "%a" "$CRAWLED_DATA_ROOT")
echo "Current permissions of $CRAWLED_DATA_ROOT: $current_permissions"
if [[ "$current_permissions" != "2775" ]]; then
    # Otherwise set the correct permissions recursively
    echo "Setting permissions of $CRAWLED_DATA_ROOT to 2775..."
    chmod -R 2775 "$CRAWLED_DATA_ROOT"
fi
echo "Permissions fixed successfully!"

# Run Laravel package discovery (skipped during build)
echo "Running Laravel package discovery..."
php artisan package:discover --ansi || echo "Warning: Package discovery failed, continuing..."

touch "$APP_READY_MARKER"
echo "Container initialization complete!"
