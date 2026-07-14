#!/bin/bash
set -e

# Create symlink for built assets
echo "Creating symlink for built assets..."
rm -rf /var/www/html/public/build
ln -s /var/www/built_resources /var/www/html/public/build

echo "Fixing Laravel storage and cache permissions..."

# Ensure directories exist and fix permissions for Laravel
chown -R www-data:www-data \
    /var/www/storage \
    /var/www/bootstrap/cache

chmod -R 775 /var/www/storage
chmod -R 775 /var/www/bootstrap/cache

# Ensure specific subdirectories exist
mkdir -p /var/www/html/storage/logs
mkdir -p /var/www/html/storage/framework/cache
mkdir -p /var/www/html/storage/framework/sessions
mkdir -p /var/www/html/storage/framework/views
mkdir -p /var/www/html/storage/app/public

# Set permissions on subdirectories
chown -R www-data:www-data /var/www/html/storage/*
chmod -R 775 /var/www/html/storage/*

CRAWLED_DATA_ROOT="${HAWKI_RAG_CRAWLED_DATA_ROOT:-${DEFAULT_CRAWLED_ROOT:-/app/shared}}"

echo "Ensuring crawled-data root exists at $CRAWLED_DATA_ROOT..."
mkdir -p "$CRAWLED_DATA_ROOT"
chown -R www-data:www-data "$CRAWLED_DATA_ROOT"
chmod -R 775 "$CRAWLED_DATA_ROOT"
find "$CRAWLED_DATA_ROOT" -type d -exec chmod g+s {} +

GRAPH_SNAPSHOT=/var/www/html/public/neo4j_graph_visualization.json

if [ ! -f "$GRAPH_SNAPSHOT" ]; then
    echo "Creating empty Neo4j graph visualization snapshot..."
    cat > "$GRAPH_SNAPSHOT" <<'JSON'
{
    "ok": true,
    "generated_at": null,
    "limit": 250,
    "node_count": 0,
    "relationship_count": 0,
    "recent_doc_id": null,
    "recent_relationship_count": 0,
    "document_count": 0,
    "nodes": [],
    "links": []
}
JSON
fi

chown www-data:www-data "$GRAPH_SNAPSHOT"
chmod 666 "$GRAPH_SNAPSHOT"

echo "Permissions fixed successfully!"

# Run Laravel package discovery (skipped during build)
echo "Running Laravel package discovery..."
php artisan package:discover --ansi || echo "Warning: Package discovery failed, continuing..."

echo "Container initialization complete!"
