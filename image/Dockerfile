# Bagisto on the official PHP-FPM Alpine image: the release's source (GitHub
# tag archive, SHA-256 verified) with its Composer lock installed without dev
# packages. The code lives in the image; public/ is copied to a volume for
# Caddy by setup, storage/ is a volume. Theme assets are prebuilt upstream
# (public/themes/*/build): no Node build.
ARG PHP_VERSION=8.4
FROM composer:2 AS composer

FROM php:${PHP_VERSION}-fpm-alpine

ARG BAGISTO_VERSION=2.4.12
# SHA-256 of https://github.com/bagisto/bagisto/archive/refs/tags/v<version>.tar.gz
# (empty: not checked).
ARG BAGISTO_SHA256=f81ce2382c53bd4db5b30bde39001ded29ae9609fa13e674e0b7eabf46d27e2c

# Extensions required by Bagisto's composer.json (calendar intl pdo_mysql)
# and its dependencies (gd: product images; zip: Excel import/export;
# bcmath, exif). icu-data-full: Alpine's ICU only has English locale data
# (intl would format CLP as "CLP 9,990").
RUN set -eux; \
    apk add --no-cache su-exec icu-data-full; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
        freetype-dev icu-dev libjpeg-turbo-dev libpng-dev libwebp-dev libzip-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" bcmath calendar exif gd intl pdo_mysql zip; \
    runDeps="$(scanelf --needed --nobanner --format '%n#p' --recursive /usr/local/lib/php/extensions \
        | tr ',' '\n' | sort -u | awk 'system("[ -e /usr/local/lib/" $1 " ]") == 0 { next } { print "so:" $1 }')"; \
    apk add --no-cache --virtual .bagisto-rundeps $runDeps; \
    apk del --no-network .build-deps; \
    for ext in bcmath calendar exif gd intl pdo_mysql zip; do php -m | grep -qx "$ext"; done

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/bagisto
COPY overlay /usr/src/overlay
RUN set -eux; \
    wget -q -O /tmp/bagisto.tar.gz "https://github.com/bagisto/bagisto/archive/refs/tags/v${BAGISTO_VERSION}.tar.gz"; \
    if [ -n "${BAGISTO_SHA256}" ]; then echo "${BAGISTO_SHA256}  /tmp/bagisto.tar.gz" | sha256sum -c -; fi; \
    tar -xzf /tmp/bagisto.tar.gz --strip-components=1; \
    rm /tmp/bagisto.tar.gz; \
    cp -R /usr/src/overlay/. .; \
    rm -rf /usr/src/overlay; \
    # Development files of the repository.
    rm -rf .env.example docker docker-compose.yml tests phpunit.xml AGENTS.md CLAUDE.md \
        public/llms.txt public/llms-full.txt; \
    # The stack's service provider (image/overlay), registered last.
    sed -i 's/^];$/    App\\Providers\\StackServiceProvider::class,\n];/' bootstrap/providers.php; \
    grep -q 'StackServiceProvider::class' bootstrap/providers.php; \
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --no-progress \
        --prefer-dist --optimize-autoloader --no-scripts; \
    COMPOSER_ALLOW_SUPERUSER=1 composer check-platform-reqs --no-dev; \
    rm -rf /root/.composer; \
    # Laravel's package discovery (the skipped post-autoload-dump script).
    APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= php artisan package:discover; \
    rm -f bootstrap/cache/config.php; \
    # public/storage -> storage/app/public (uploads), like `storage:link`.
    ln -s /var/www/bagisto/storage/app/public public/storage; \
    # storage/ becomes a volume: new volumes get this tree, owned by www-data.
    chown -R www-data:www-data storage bootstrap/cache; \
    # Build id: setup copies public/ to Caddy's volume when it changes.
    echo "${BAGISTO_VERSION} $(date -u +%Y%m%dT%H%M%SZ)" > public/.build

ENV BAGISTO_VERSION=${BAGISTO_VERSION}
