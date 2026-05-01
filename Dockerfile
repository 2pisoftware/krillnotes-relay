FROM composer:2 AS composer

FROM php:8.3-cli AS base

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsodium-dev libsqlite3-dev unzip \
    && docker-php-ext-install pdo_sqlite sodium \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist

COPY . .

RUN mkdir -p storage/database storage/bundles storage/invites \
    && chmod +x docker-entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["./docker-entrypoint.sh"]
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public/", "public/index.php"]

FROM base AS test

RUN composer install --no-scripts --no-interaction --prefer-dist
