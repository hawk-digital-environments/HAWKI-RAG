# ------------------------------------------------------------------------------
# Base image: PHP-FPM with required extensions
# ------------------------------------------------------------------------------
FROM php:8.4-fpm

# ------------------------------------------------------------------------------
# System dependencies
# ------------------------------------------------------------------------------
RUN apt-get update && apt-get install -y \
    git \
    curl \
    zip \
    unzip \
    supervisor \
    libonig-dev \
    libxml2-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libicu-dev \
    libzip-dev \
    build-essential \
    pkg-config \
 && rm -rf /var/lib/apt/lists/*

# ------------------------------------------------------------------------------
# Install PHP extensions
# ------------------------------------------------------------------------------
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) \
        pdo_mysql gd bcmath exif pcntl intl zip sockets \
 && pecl install redis \
 && docker-php-ext-enable redis

# ------------------------------------------------------------------------------
# Install Node.js (official, correct version for Vite)
# ------------------------------------------------------------------------------
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
 && apt-get install -y nodejs \
 && node -v && npm -v

# ------------------------------------------------------------------------------
# Install Composer
# ------------------------------------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# ------------------------------------------------------------------------------
# Set working directory
# ------------------------------------------------------------------------------
WORKDIR /var/www

# ------------------------------------------------------------------------------
# Copy the full application
# ------------------------------------------------------------------------------
COPY . .

# Install PHP dependencies (skip scripts to avoid artisan errors during build)
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Install Node dependencies and build frontend assets
RUN npm ci

# Build frontend assets
RUN npm run build

# ------------------------------------------------------------------------------
# Fix Laravel file/directory permissions
# ------------------------------------------------------------------------------
RUN chown -R www-data:www-data /var/www \
 && find /var/www -type d -exec chmod 755 {} \; \
 && find /var/www -type f -exec chmod 644 {} \;

# ------------------------------------------------------------------------------
# Copy Supervisor config
# ------------------------------------------------------------------------------
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# ------------------------------------------------------------------------------
# Copy entrypoint script
# ------------------------------------------------------------------------------
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
