FROM neunerlei/node-nginx:25 AS node-build

ARG DOCKER_PROJECT_HOST=ixdlab.hawk.de
ARG DOCKER_PROJECT_PATH=/hawki-rag/
ARG DOCKER_PROJECT_PROTOCOL=https
ARG DOCKER_SERVICE_PATH
ENV DOCKER_PROJECT_HOST=${DOCKER_PROJECT_HOST} \
    DOCKER_PROJECT_PATH=${DOCKER_PROJECT_PATH} \
    DOCKER_PROJECT_PROTOCOL=${DOCKER_PROJECT_PROTOCOL}

# Copy only package files for caching
COPY package.json package-lock.json ./

# Install Node dependencies with more tolerant network settings (CI/build hosts can be slow)
RUN npm config set fetch-retries 5 \
 && npm config set fetch-retry-mintimeout 20000 \
 && npm config set fetch-retry-maxtimeout 120000 \
 && npm config set fetch-timeout 300000 \
 && npm ci --with-dev

# Copy rest of application
COPY --chown=www-data:www-data . .

# Prepare container for building
RUN rm -rf /var/www/html/public/build \
    && gosu www-data mkdir -p /var/www/html/public/build \
    && source /container/entrypoint/entrypoint.sh \
    && npm run build

# =================================================================

FROM neunerlei/php-nginx:8.4 AS laravel-app

ARG DOCKER_PROJECT_HOST=ixdlab.hawk.de
ARG DOCKER_PROJECT_PATH=/hawki-rag/
ARG DOCKER_PROJECT_PROTOCOL=https
ENV DOCKER_PROJECT_HOST=${DOCKER_PROJECT_HOST} \
    DOCKER_PROJECT_PATH=${DOCKER_PROJECT_PATH} \
    DOCKER_PROJECT_PROTOCOL=${DOCKER_PROJECT_PROTOCOL}
ENV ASSET_URL="${DOCKER_PROJECT_PROTOCOL}://${DOCKER_PROJECT_HOST}${DOCKER_PROJECT_PATH}" \
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

# Copy built assets from node build stage
COPY --chown=www-data:www-data --from=node-build /var/www/html/public/build /var/www/built_resources

# Recreate the runtime skeleton because local storage data is excluded from the
# build context and mounted separately at runtime.
RUN mkdir -p \
    /var/www/html/storage/app/public \
    /var/www/html/storage/framework/cache/data \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/views \
    /var/www/html/storage/logs \
    /var/www/html/bootstrap/cache \
 && chown -R www-data:www-data \
    /var/www/html/storage \
    /var/www/html/bootstrap/cache \
    /var/www/built_resources

# Copy container config
COPY ./docker/rag_app /container/custom/

# Dump composer autoload
RUN composer dump-autoload --optimize
