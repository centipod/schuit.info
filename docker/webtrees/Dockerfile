FROM php:8.2-apache

ARG WEBTREES_VERSION=2.2.6
ARG WEBTREES_DOWNLOAD_URL=https://github.com/fisharebest/webtrees/releases/download/${WEBTREES_VERSION}/webtrees-${WEBTREES_VERSION}.zip

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
      curl \
      default-mysql-client \
      unzip \
      libicu-dev \
      libjpeg62-turbo-dev \
      libpng-dev \
      libonig-dev \
      libzip-dev \
      libxml2-dev \
      zlib1g-dev \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd intl mbstring mysqli pdo_mysql zip opcache xml \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    curl -fsSL "${WEBTREES_DOWNLOAD_URL}" -o /tmp/webtrees.zip; \
    mkdir -p /var/www/html; \
    unzip -q /tmp/webtrees.zip -d /var/www/html; \
    if [ -d /var/www/html/webtrees ]; then \
      find /var/www/html/webtrees -mindepth 1 -maxdepth 1 -exec mv {} /var/www/html/ \;; \
      rmdir /var/www/html/webtrees; \
    fi; \
    rm /tmp/webtrees.zip; \
    chown -R www-data:www-data /var/www/html

COPY php.ini /usr/local/etc/php/conf.d/webtrees.ini
COPY entrypoint.sh /usr/local/bin/webtrees-entrypoint
RUN chmod +x /usr/local/bin/webtrees-entrypoint

ENTRYPOINT ["webtrees-entrypoint"]
CMD ["apache2-foreground"]
