#!/bin/bash
set -e

echo "Fixing Laravel storage and cache permissions..."

# Ensure directories exist and fix permissions for Laravel
chown -R www-data:www-data \
    /var/www/storage \
    /var/www/bootstrap/cache

chmod -R 775 /var/www/storage
chmod -R 775 /var/www/bootstrap/cache

# Ensure specific subdirectories exist
mkdir -p /var/www/storage/logs
mkdir -p /var/www/storage/framework/cache
mkdir -p /var/www/storage/framework/sessions
mkdir -p /var/www/storage/framework/views
mkdir -p /var/www/storage/app/public

# Set permissions on subdirectories
chown -R www-data:www-data /var/www/storage/*
chmod -R 775 /var/www/storage/*

echo "Permissions fixed successfully!"

# Run Laravel package discovery (skipped during build)
echo "Running Laravel package discovery..."
php artisan package:discover --ansi || echo "Warning: Package discovery failed, continuing..."

echo "Container initialization complete!"

# Execute the main command (supervisord)
exec "$@"
