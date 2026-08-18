FROM php:8.3-fpm-bookworm

# Install system dependencies, Caddy, Python 3, and build libraries
RUN apt-get update && apt-get install -y --no-install-recommends \
    caddy \
    python3 \
    python3-venv \
    python3-pip \
    sqlite3 \
    libsqlite3-dev \
    libwebp-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libfreetype6-dev \
    libzip-dev \
    curl \
    ca-certificates \
    && docker-php-ext-configure gd --with-webp --with-jpeg --with-freetype \
    && docker-php-ext-install -j$(nproc) gd pdo_sqlite zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# Copy requirements and initialize Python virtual environment
COPY requirements.txt ./
RUN python3 -m venv /var/www/html/venv \
    && /var/www/html/venv/bin/pip install --no-cache-dir -r requirements.txt

# Copy application files
COPY . .

# Prepare directories and file permissions
RUN mkdir -p /var/www/html/cache/images \
             /var/www/html/snapshot \
             /var/www/html/config \
    && chown -R www-data:www-data /var/www/html \
    && chmod +x /var/www/html/localnh \
    && chmod +x /var/www/html/docker-entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/var/www/html/docker-entrypoint.sh"]
