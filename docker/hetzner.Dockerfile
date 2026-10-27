# Reuse the verified PHP 8.3.36 security build already running on this server.
FROM centipod-wordpress:php8.3.36-preview-20261021-security1 AS extensions
USER root
RUN apt-get update && apt-get install -y --no-install-recommends $PHPIZE_DEPS \
    && docker-php-ext-install pdo_mysql
FROM centipod-wordpress:php8.3.36-preview-20261021-security1
USER root
COPY --from=extensions /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
RUN docker-php-ext-enable pdo_mysql && a2enmod headers
COPY docker/schuit-apache.conf /etc/apache2/conf-enabled/schuit.conf
COPY docker/schuit-php.ini /usr/local/etc/php/conf.d/schuit.ini
USER 33:33
