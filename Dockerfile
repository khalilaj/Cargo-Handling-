FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libicu-dev libzip-dev libpq-dev \
    && docker-php-ext-install intl zip pdo_pgsql pdo_mysql bcmath opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    COMPOSER_ALLOW_SUPERUSER=1
RUN sed -ri 's#/var/www/html#${APACHE_DOCUMENT_ROOT}#g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf \
    && sed -ri 's#<Directory /var/www/>#<Directory ${APACHE_DOCUMENT_ROOT}/>#' /etc/apache2/apache2.conf \
    && sed -ri 's#AllowOverride None#AllowOverride All#' /etc/apache2/apache2.conf

WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && php artisan filament:assets \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
CMD ["apache2-foreground"]
