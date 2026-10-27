FROM centipod-schuit:php8.3.36-20261027-security2
USER root
RUN apt-get update && apt-get upgrade -y --no-install-recommends \
    && dpkg-query -W -f='${binary:Package}\n' | grep -E '(-dev(:.*)?$|^linux-libc-dev|^libc-dev-bin$|^gcc|^g\+\+|^cpp|^make$|^autoconf$|^automake$|^binutils|^re2c$)' | grep -vE '^gcc-[0-9]+-base' > /tmp/build-packages \
    && xargs -r apt-get purge -y < /tmp/build-packages \
    && rm -rf /usr/src/php /usr/src/php.tar.xz /usr/local/include/php /var/lib/apt/lists/* /tmp/build-packages \
    && php -r 'foreach (["mysqli","pdo_mysql","gd","intl","mbstring","zip","xml","curl","imagick"] as $e) { if (!extension_loaded($e)) { throw new RuntimeException($e); } }' \
    && apache2ctl -t
# Do not autoremove: runtime libraries used by compiled PHP are not all tracked
# as Debian package dependencies. No website files/data enter this image.
COPY docker/login-throttle.php /opt/schuit-security/login-throttle.php
RUN printf 'auto_prepend_file=/opt/schuit-security/login-throttle.php\n' > /usr/local/etc/php/conf.d/schuit-login.ini \
    && printf 'RemoteIPHeader X-Forwarded-For\nRemoteIPInternalProxy ${SCHUIT_TRUSTED_PROXY_CIDR}\n' > /etc/apache2/conf-enabled/remoteip.conf
USER 33:33
