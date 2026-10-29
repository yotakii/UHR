# UHR API — صورة الإنتاج: PHP-FPM 8.3 + الكود + الحزم (بدون حزم التطوير)
FROM composer:2 AS vendor
WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader --ignore-platform-reqs

# pg_dump بنفس إصدار قاعدة البيانات (18) للنسخ الاحتياطي، وpdftotext لاستخراج نص السياسات PDF
FROM php:8.3-fpm-alpine
RUN (apk add --no-cache postgresql18-client || apk add --no-cache postgresql-client) \
 && apk add --no-cache poppler-utils bash su-exec libpq-dev icu-dev libzip-dev libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
 && docker-php-ext-install pdo_pgsql pgsql intl zip bcmath pcntl opcache gd
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/api/php.ini /usr/local/etc/php/conf.d/uhr.ini
COPY deploy/php-prod.ini /usr/local/etc/php/conf.d/uhr-prod.ini
COPY deploy/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-uhr.conf

WORKDIR /var/www
COPY --from=vendor /app/vendor ./vendor
COPY backend/ ./
RUN rm -f .env && mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --optimize --no-dev --no-interaction \
 && chown -R www-data:www-data storage bootstrap/cache
COPY deploy/api-entrypoint.sh /usr/local/bin/uhr-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/uhr-entrypoint && chmod +x /usr/local/bin/uhr-entrypoint
ENV TZ=Asia/Damascus
EXPOSE 9000
ENTRYPOINT ["uhr-entrypoint"]
CMD ["php-fpm"]
