FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    nginx \
    supervisor \
    postgresql-dev \
    libzip-dev \
    zip \
    unzip \
    nodejs \
    npm \
    docker-cli \
    docker-cli-compose \
    && docker-php-ext-install pdo pdo_pgsql zip opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install PHP deps
COPY symfony/composer.json symfony/composer.lock ./
RUN composer install --no-scripts --no-autoloader --prefer-dist

COPY symfony/ .

# Build frontend
COPY frontend/ /frontend/
RUN cd /frontend && npm ci && npm run build -- --outDir /app/public/app --emptyOutDir

RUN composer dump-autoload --optimize \
    && mkdir -p var/traces var/log var/cache \
    && chmod -R 777 var \
    && addgroup -g 984 docker-host 2>/dev/null || true \
    && adduser www-data docker-host 2>/dev/null || true

# Nginx config
COPY docker/nginx.conf /etc/nginx/nginx.conf

# PHP-FPM: pass env vars
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zzz-env.conf

# PHP limits
COPY docker/php.ini /usr/local/etc/php/conf.d/zzz-app.ini

# Supervisord config
COPY docker/supervisord.conf /etc/supervisord.conf

EXPOSE 80

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
