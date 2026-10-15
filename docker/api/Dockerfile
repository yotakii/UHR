# UHR API — بيئة تطوير (PHP 8.3 + Composer)
FROM php:8.3-cli-alpine

RUN apk add --no-cache bash git unzip libpq-dev icu-dev libzip-dev postgresql-client libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
 && docker-php-ext-install pdo_pgsql pgsql intl zip bcmath pcntl opcache gd

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/api/php.ini /usr/local/etc/php/conf.d/uhr.ini
COPY docker/api/entrypoint.sh /usr/local/bin/uhr-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/uhr-entrypoint && chmod +x /usr/local/bin/uhr-entrypoint

WORKDIR /var/www
ENV COMPOSER_ALLOW_SUPERUSER=1 PHP_CLI_SERVER_WORKERS=4
EXPOSE 8000
ENTRYPOINT ["uhr-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
