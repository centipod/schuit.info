# Public, immutable inputs; no pre-existing Hetzner image or private files.
FROM composer:2@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc AS composer
FROM php:8.3.36-apache-trixie@sha256:0d46cb96775228313d3997c155f78227de13bf9972e0c736e653fb23aded79b2 AS build
RUN apt-get update && apt-get install -y --no-install-recommends libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev libzip-dev libicu-dev unzip git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j2 gd intl mysqli pdo_mysql zip bcmath exif
RUN mkdir -p /build && cd /build \
    && curl -fsSLo wordpress.zip https://downloads.wordpress.org/release/wordpress-7.1.3.zip \
    && echo 'eb58a90ed55147f90f7bd55d0d3334960a69b7e8a4cc142c8ddfea8965218227  wordpress.zip' | sha256sum -c - \
    && unzip -q wordpress.zip && rm wordpress.zip \
    && curl -fsSLo webtrees.zip https://github.com/fisharebest/webtrees/releases/download/2.2.6/webtrees-2.2.6.zip \
    && echo 'c6b82f0269733b71de1a160a93eb32029e3467674e9bbfe3956d46a8bb04f925  webtrees.zip' | sha256sum -c - \
    && unzip -q webtrees.zip && rm webtrees.zip \
    && curl -fsSLo /usr/local/bin/wp https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar \
    && echo 'ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c  /usr/local/bin/wp' | sha256sum -c - \
    && chmod 755 /usr/local/bin/wp
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY deployment/auth-dependencies/composer.* /build/auth/
RUN cd /build/auth && composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-plugins --classmap-authoritative
COPY deployment/webtrees-dependencies/composer.* /build/webtrees/
RUN cd /build/webtrees && rm -rf vendor && composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-plugins --classmap-authoritative

FROM php:8.3.36-apache-trixie@sha256:0d46cb96775228313d3997c155f78227de13bf9972e0c736e653fb23aded79b2 AS runtime
RUN apt-get update && apt-get upgrade -y --no-install-recommends \
    && apt-get install -y --no-install-recommends libfreetype6 libjpeg62-turbo libpng16-16t64 libwebp7 libzip5 libicu76 \
    && dpkg-query -W -f='${binary:Package}\n' | grep -E '(-dev(:.*)?$|^linux-libc-dev|^libc-dev-bin$|^gcc|^g\+\+|^cpp|^make$|^autoconf$|^automake$|^binutils|^re2c$)' | grep -vE '^gcc-[0-9]+-base' > /tmp/build-packages \
    && xargs -r apt-get purge -y < /tmp/build-packages \
    && rm -rf /var/lib/apt/lists/* /usr/src/php* /usr/local/include/php /tmp/build-packages
COPY --from=build /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=build /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
COPY --from=build /usr/local/bin/wp /usr/local/bin/wp
COPY --from=build --chown=33:33 /build/wordpress/ /var/www/html/
COPY --from=build --chown=33:33 /build/webtrees/ /var/www/html/tree/
COPY --chown=33:33 wordpress-theme/schuit-portal/ /var/www/html/wp-content/themes/schuit-portal/
COPY --chown=33:33 wordpress-plugins/ /var/www/html/wp-content/plugins/
COPY --chown=33:33 wordpress-mu-plugins/ /var/www/html/wp-content/mu-plugins/
COPY --chown=33:33 modules_v4/ /var/www/html/tree/modules_v4/
COPY logo.png banner.png /var/www/html/
COPY scripts/ /opt/schuit-scripts/
COPY docker/login-throttle.php docker/runtime-guard.php docker/cognito*.php /opt/schuit-security/
COPY --from=build /build/auth/vendor/ /opt/schuit-security/vendor/
COPY docker/schuit-php.ini /usr/local/etc/php/conf.d/schuit.ini
COPY docker/schuit-apache.conf /etc/apache2/conf-enabled/schuit.conf
COPY docker/release-vhost.conf /etc/apache2/sites-enabled/000-default.conf
RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini \
    && a2enmod headers rewrite remoteip \
    && printf 'Listen 8080\n' > /etc/apache2/ports.conf \
    && printf 'ServerName localhost\nRemoteIPHeader X-Forwarded-For\nRemoteIPInternalProxy ${SCHUIT_TRUSTED_PROXY_CIDR}\n' > /etc/apache2/conf-enabled/remoteip.conf \
    && printf 'auto_prepend_file=/opt/schuit-security/runtime-guard.php\n' > /usr/local/etc/php/conf.d/schuit-login.ini \
    && mkdir -p /var/lib/schuit-gedcom /run/schuit-metrics \
    && chown -R 33:33 /var/lib/schuit-gedcom /run/schuit-metrics /var/run/apache2 /var/lock/apache2 \
    && php -r 'foreach (["mysqli","pdo_mysql","gd","intl","mbstring","zip","xml","curl"] as $e) {if (!extension_loaded($e)) {throw new RuntimeException($e);}}'
ARG SOURCE_REVISION=unknown
ARG CI_RUN_ID=local
LABEL org.opencontainers.image.source="https://github.com/centipod/schuit.info" org.opencontainers.image.revision=$SOURCE_REVISION io.centipod.ci-run=$CI_RUN_ID
USER 33:33
EXPOSE 8080
