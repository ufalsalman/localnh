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
    libvips-tools \
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

# Persist session storage separately from collection data and exports.
ENV LOCALNH_SESSION_DIR=/var/lib/localnh/sessions LOCALNH_TRUSTED_PROXIES=""
RUN mkdir -p /var/lib/localnh/sessions \
    && chown www-data:www-data /var/lib/localnh/sessions \
    && chmod 700 /var/lib/localnh/sessions \
    && printf '%s\n' '[www]' 'env[LOCALNH_SESSION_DIR] = $LOCALNH_SESSION_DIR' 'env[LOCALNH_TRUSTED_PROXIES] = $LOCALNH_TRUSTED_PROXIES' > /usr/local/etc/php-fpm.d/zz-localnh-env.conf

EXPOSE 8080

ENTRYPOINT ["/var/www/html/docker-entrypoint.sh"]
