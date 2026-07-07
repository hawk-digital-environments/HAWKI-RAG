FROM neunerlei/php-nginx:8.4 AS laravel-app

ARG DOCKER_PROJECT_HOST
ARG DOCKER_PROJECT_PATH
ARG DOCKER_PROJECT_PROTOCOL
ENV DOCKER_PROJECT_HOST=${DOCKER_PROJECT_HOST:-ixdlab.hawk.de} \
    DOCKER_PROJECT_PATH=${DOCKER_PROJECT_PATH:-/hawki-rag/} \
    DOCKER_PROJECT_PROTOCOL=${DOCKER_PROJECT_PROTOCOL:-https} \
    ASSET_URL="${DOCKER_PROJECT_PROTOCOL}://${DOCKER_PROJECT_HOST}${DOCKER_PROJECT_PATH}" \
    APP_URL="${DOCKER_PROJECT_PROTOCOL}://${DOCKER_PROJECT_HOST}${DOCKER_PROJECT_PATH}"

# Install runtime/build dependencies. The base image already includes the PHP
# extensions used by Laravel here: gd, pdo_mysql, pdo_pgsql, opcache, bcmath,
# exif, pcntl, zip, and intl. Temporal client operations run in Python.
RUN apt-get update && apt-get install -y \
    python3-requests \
    unzip \
 && rm -rf /var/lib/apt/lists/*

# Copy only composer files for caching
COPY composer.json composer.lock ./

# Install PHP dependencies (no dev and no scripts)
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Copy rest of application
COPY . .

# Fix Laravel permissions
RUN chown -R www-data:www-data \
    /var/www/html/storage \
    /var/www/html/bootstrap/cache

# Copy container config
COPY ./docker/rag_app /container/custom/

# Dump composer autoload
RUN composer dump-autoload --optimize
