FROM neunerlei/node-nginx:25 AS node-build

ARG DOCKER_PROJECT_HOST
ARG DOCKER_PROJECT_PATH
ARG DOCKER_PROJECT_PROTOCOL
ARG DOCKER_SERVICE_PATH
ENV DOCKER_PROJECT_HOST=${DOCKER_PROJECT_HOST:-ixdlab.hawk.de} \
    DOCKER_PROJECT_PATH=${DOCKER_PROJECT_PATH:-/hawki-rag/} \
    DOCKER_PROJECT_PROTOCOL=${DOCKER_PROJECT_PROTOCOL:-https}

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
    && npm run build \
    && if [ -d resources/js/crawler ]; then cd resources/js/crawler && npm ci --fetch-timeout=300000; fi

# =================================================================

FROM neunerlei/php-nginx:8.4 AS laravel-app

ARG DOCKER_PROJECT_HOST
ARG DOCKER_PROJECT_PATH
ARG DOCKER_PROJECT_PROTOCOL
ENV DOCKER_PROJECT_HOST=${DOCKER_PROJECT_HOST:-ixdlab.hawk.de} \
    DOCKER_PROJECT_PATH=${DOCKER_PROJECT_PATH:-/hawki-rag/} \
    DOCKER_PROJECT_PROTOCOL=${DOCKER_PROJECT_PROTOCOL:-https} \
    ASSET_URL="${DOCKER_PROJECT_PROTOCOL}://${DOCKER_PROJECT_HOST}${DOCKER_PROJECT_PATH}" \
    APP_URL="${DOCKER_PROJECT_PROTOCOL}://${DOCKER_PROJECT_HOST}${DOCKER_PROJECT_PATH}"

# Install system dependencies
RUN apt-get update && apt-get install -y \
    python3-requests \
    libonig-dev \
    libxml2-dev \
    unzip \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libicu-dev \
    libmagickwand-dev \
    libzip-dev \
    build-essential \
 && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) gd pdo_mysql opcache bcmath exif pcntl zip intl sockets \
 && rm -rf /tmp/*

# Copy only composer files for caching
COPY composer.json composer.lock ./

# Install PHP dependencies (no dev and no scripts)
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Copy rest of application
COPY . .

# Copy built assets from node build stage
COPY --chown=www-data:www-data --from=node-build /var/www/html/public/build /var/www/built_resources

# Fix Laravel permissions
RUN chown -R www-data:www-data \
    /var/www/html/storage \
    /var/www/html/bootstrap/cache \
    /var/www/built_resources

# Copy container config
COPY ./docker/rag_app /container/custom/

# Dump composer autoload
RUN composer dump-autoload --optimize
