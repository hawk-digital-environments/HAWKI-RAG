# Laravel App with PHP-FPM and Vite build
FROM php:8.4-fpm AS laravel-app

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    nodejs \
    npm \
    python3-requests \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libicu-dev \
    libmagickwand-dev \
    libzip-dev \
    build-essential \
    bash \
    supervisor \
 && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) gd pdo_mysql opcache bcmath exif pcntl zip intl sockets \
 && rm -rf /tmp/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www

# Copy only composer files for caching
COPY composer.json composer.lock ./

# Copy rest of application
COPY . .

# Install PHP dependencies (no dev)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy only package files for caching
COPY package.json package-lock.json ./

# Install Node dependencies and build Vite assets
RUN npm ci

# Build frontend assets
RUN npm run build \
 && if [ -d resources/js/crawler ]; then cd resources/js/crawler && npm ci; fi

# Fix Laravel permissions
RUN chown -R www-data:www-data \
    /var/www/storage \
    /var/www/bootstrap/cache \
    /var/www/public/build

# Copy supervisor config
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/php-custom.ini /usr/local/etc/php/conf.d/zz-hawki-rag.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000

# Run entrypoint (fixes permissions) then supervisor (starts php-fpm)
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

# Python RAG API (FastAPI bridge / RAG-Anything)
FROM python:3.11-slim AS python-rag

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    DEBIAN_FRONTEND=noninteractive \
    RAG_WORKING_DIR=/app/rag_storage \
    OLLAMA_URL=http://ollama:11434 \
    QDRANT_URL=http://qdrant:6333 \
    NEO4J_URI=bolt://neo4j:7687

WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential \
    ca-certificates \
    curl \
    git \
    imagemagick \
    libreoffice \
    libgomp1 \
    poppler-utils \
    tesseract-ocr \
    tesseract-ocr-eng \
    tesseract-ocr-deu \
    && rm -rf /var/lib/apt/lists/*

COPY python_rag/requirements.txt /app/requirements.txt
RUN python -m pip install --no-cache-dir --upgrade pip \
    && pip install --no-cache-dir -r requirements.txt

COPY python_rag /app

RUN mkdir -p /app/rag_storage

EXPOSE 8003
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8003"]

# Local reranker
FROM python:3.11-slim AS rerank

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    libgomp1 \
    ca-certificates \
    curl && \
    rm -rf /var/lib/apt/lists/*

COPY python_rag/requirements.txt /app/requirements.txt
RUN pip install --no-cache-dir -r requirements.txt

COPY python_rag/rerank/local_reranker/app.py /app/app.py

EXPOSE 8000
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
