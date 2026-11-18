# Development/test image: Debian PHP CLI with the extensions the app needs
# (memcached for the cache store, intl for Unicode NFC normalization).
FROM debian:trixie-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl git unzip \
        php-cli php-curl php-intl php-mbstring php-xml php-zip php-sqlite3 php-memcached \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
