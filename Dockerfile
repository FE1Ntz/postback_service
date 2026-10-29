FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libcurl4-openssl-dev libonig-dev libxml2-dev libzip-dev libsqlite3-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_sqlite mbstring dom xml xmlwriter curl zip pcntl \
    && pecl install redis-6.2.0 \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts --no-autoloader
COPY . .
RUN touch .env && composer dump-autoload --optimize --no-interaction \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh
USER www-data
EXPOSE 8000
ENTRYPOINT ["sh", "/var/www/html/docker/entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]
