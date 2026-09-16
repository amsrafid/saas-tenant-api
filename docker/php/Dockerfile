FROM php:8.4-fpm

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pgsql redis opcache intl bcmath pcntl zip @composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-app.conf

WORKDIR /var/www

# php-fpm refuses a root pool unless the master is started with -R.
CMD ["php-fpm", "-R"]
