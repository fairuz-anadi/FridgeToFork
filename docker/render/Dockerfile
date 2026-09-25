# Single-container image for Render: the React client is built and served
# from Laravel's public/ directory, so the site and the API share one URL.

# ---- Stage 1: build the React client ------------------------------------
FROM node:20-alpine AS client

WORKDIR /client

# Render passes service environment variables in as build args.
ARG VITE_GOOGLE_CLIENT_ID=""
ENV VITE_API_URL=/api
ENV VITE_GOOGLE_CLIENT_ID=${VITE_GOOGLE_CLIENT_ID}

COPY client/package.json client/package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY client/ ./
RUN npm run build

# ---- Stage 2: Laravel API on Apache -------------------------------------
FROM php:8.2-apache-bookworm

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libzip-dev unzip \
    && docker-php-ext-install pdo_pgsql pgsql zip \
    && a2enmod rewrite \
    && echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername \
    && sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && sed -ri -e 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi

# The built client goes into public/ alongside index.php. Apache serves
# index.html for "/" and the static assets directly; every other page URL
# falls through to Laravel, which returns index.html (routes/web.php).
COPY --from=client /client/dist/ /var/www/html/public/

COPY docker/render/start.sh /usr/local/bin/start-render
RUN sed -i 's/\r$//' /usr/local/bin/start-render \
    && chmod +x /usr/local/bin/start-render \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 10000

CMD ["start-render"]
