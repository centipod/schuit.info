FROM centipod-schuit:php8.3.36-20261027-security3
USER root
# GD supports every image format currently in the archive. PDFs are served as
# documents; generating new PDF thumbnails is intentionally disabled.
RUN rm /usr/local/etc/php/conf.d/docker-php-ext-imagick.ini \
    && rm /usr/local/lib/php/extensions/*/imagick.so \
    && apt-get update && apt-get upgrade -y --no-install-recommends \
    && apt-get purge -y ghostscript imagemagick-7-common \
       libmagickcore-7.q16-10 libmagickwand-7.q16-10 libgs10 libgs10-common libgs-common \
       libheif1 libheif-plugin-aomenc libheif-plugin-dav1d libheif-plugin-libde265 libheif-plugin-x265 \
       libraw23t64 libcups2t64 libfftw3-double3 libjbig2dec0 libtiff6 libjbig0 \
    && rm -rf /var/lib/apt/lists/* \
    && php -r 'foreach (["mysqli","pdo_mysql","gd","intl","mbstring","zip","xml","curl"] as $e) { if (!extension_loaded($e)) { throw new RuntimeException($e); } } if (extension_loaded("imagick")) { throw new RuntimeException("Imagick still loaded"); }'
COPY docker/runtime-guard.php /opt/schuit-security/runtime-guard.php
COPY docker/schuit-php.ini /usr/local/etc/php/conf.d/schuit.ini
COPY docker/schuit-apache.conf /etc/apache2/conf-enabled/schuit.conf
RUN printf 'auto_prepend_file=/opt/schuit-security/runtime-guard.php\n' > /usr/local/etc/php/conf.d/schuit-login.ini
USER 33:33
