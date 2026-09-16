# Build the standard Caddy distribution from a reviewed Go module lock.
FROM golang:1.27.1-bookworm@sha256:648f440f42a0958804efb24df176f806f9d353b41f1c0627f666428e40310f6b AS caddy_builder
ENV GOTOOLCHAIN=local CGO_ENABLED=0 GOMAXPROCS=2
WORKDIR /src/caddy
COPY build/caddy/ ./
RUN --mount=type=cache,id=localnh-caddy-modules,target=/go/pkg/mod \
    --mount=type=cache,id=localnh-caddy-build,target=/root/.cache/go-build \
    go mod download && go mod verify \
    && go build -p=2 -mod=readonly -trimpath -ldflags="-s -w" -o /out/caddy .

FROM php:8.3-fpm-trixie@sha256:5ad27201cf5cdf1704e147218e64fa0db2155ecda26edbe2b3eeb7b90cbf9328 AS app_builder

# Install system dependencies, Python 3, and build libraries
RUN apt-get update && apt-get install -y --no-install-recommends \
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

# Refresh security fixes in packages inherited from the pinned base/build layer.
# Keep this layer deliberately reviewable; update/rebuild when advisories change.
RUN apt-get update \
    && apt-get install -y --no-install-recommends --only-upgrade libpcre2-8-0 libssh2-1t64 linux-libc-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# Copy requirements and initialize Python virtual environment
COPY requirements.txt requirements.lock requirements-tools.lock ./
RUN python3 -m venv /var/www/html/venv \
    && /var/www/html/venv/bin/pip install --no-cache-dir --require-hashes --only-binary=:all: -r requirements-tools.lock \
    && /var/www/html/venv/bin/pip install --no-cache-dir --require-hashes --only-binary=:all: -r requirements.lock \
    && /var/www/html/venv/bin/pip check \
    # Installation tooling is not used by the running application.
    && /var/www/html/venv/bin/pip uninstall -y setuptools pip \
    && /var/www/html/venv/bin/python -c "import curl_cffi, PIL, cffi, certifi, pycparser"

# Start again from the same PHP/OS ABI; copy compiled artifacts, not compilers.
FROM php:8.3-fpm-trixie@sha256:5ad27201cf5cdf1704e147218e64fa0db2155ecda26edbe2b3eeb7b90cbf9328 AS runtime_base
RUN apt-get update && apt-get install -y --no-install-recommends \
    python3 sqlite3 libvips-tools curl ca-certificates \
    libwebp7 libjpeg62-turbo libpng16-16t64 libfreetype6 libzip5 \
    libargon2-1 libcurl4t64 libonig5 libreadline8t64 libsodium23 libsqlite3-0 libssl3t64 libxml2 zlib1g \
    libpcre2-8-0 libssh2-1t64 \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS libc6-dev linux-libc-dev \
    && rm -rf /var/lib/apt/lists/* /usr/local/include/php /usr/src/php* \
    && rm -f /usr/local/bin/phpize /usr/local/bin/php-config /usr/local/bin/docker-php-ext-*
# Apply available security updates to inherited runtime packages as well.
RUN apt-get update \
    && apt-get install -y --no-install-recommends --only-upgrade gzip libc6 libc-bin perl-base \
    && rm -rf /var/lib/apt/lists/*
FROM runtime_base AS runtime
COPY --from=app_builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=app_builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
COPY --from=app_builder /var/www/html/venv/ /var/www/html/venv/
COPY --from=caddy_builder /out/caddy /usr/bin/caddy
WORKDIR /var/www/html

# Copy application files
COPY . .
# Build manifests are source material, not application runtime assets.
RUN rm -rf build

# Prepare directories and file permissions
RUN mkdir -p /var/www/html/cache/images \
             /var/www/html/snapshot \
             /var/www/html/config \
    && chown -R www-data:www-data /var/www/html \
    && chmod +x /var/www/html/localnh \
    && chmod +x /var/www/html/docker-entrypoint.sh

# Persist session storage separately from collection data and exports.
ENV LOCALNH_CONTAINER=1 LOCALNH_DATA_DIR="" LOCALNH_SESSION_DIR=/var/lib/localnh/sessions LOCALNH_TRUSTED_PROXIES="" VIPS_BLOCK_UNTRUSTED=1
RUN mkdir -p /var/lib/localnh/sessions \
    && chown www-data:www-data /var/lib/localnh/sessions \
    && chmod 700 /var/lib/localnh/sessions \
    && printf '%s\n' '[www]' 'env[LOCALNH_DATA_DIR] = $LOCALNH_DATA_DIR' 'env[LOCALNH_SESSION_DIR] = $LOCALNH_SESSION_DIR' 'env[LOCALNH_TRUSTED_PROXIES] = $LOCALNH_TRUSTED_PROXIES' > /usr/local/etc/php-fpm.d/zz-localnh-env.conf

EXPOSE 8080

ENTRYPOINT ["/var/www/html/docker-entrypoint.sh"]
