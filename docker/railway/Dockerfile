# Railway-compatible OpenEMR image.
#
# Derived from docker/release/Dockerfile but avoids directives Railway's Metal
# builder rejects: BuildKit cache mounts and VOLUME. Builds from the deployed
# git tree so custom modules (Clinical Co-Pilot) ship with the image.

ARG ALPINE_VERSION=3.23
FROM alpine:${ALPINE_VERSION}

ARG PHP_VERSION=8.5
ENV PHP_VERSION=${PHP_VERSION}
ARG PHP_VERSION_ABBR=${PHP_VERSION//./}
ENV PHP_VERSION_ABBR=${PHP_VERSION_ABBR}

RUN apk --no-cache upgrade

RUN apk add --no-cache \
    apache2 \
    apache2-proxy \
    apache2-ssl \
    apache2-utils \
    bash \
    certbot \
    curl \
    dcron \
    git \
    imagemagick \
    mariadb-client \
    mariadb-connector-c \
    ncurses \
    nodejs \
    npm \
    openssl \
    openssl-dev \
    perl \
    rsync \
    shadow \
    su-exec \
    tar

RUN apk add --no-cache \
    php${PHP_VERSION_ABBR} \
    php${PHP_VERSION_ABBR}-apache2 \
    php${PHP_VERSION_ABBR}-bcmath \
    php${PHP_VERSION_ABBR}-calendar \
    php${PHP_VERSION_ABBR}-ctype \
    php${PHP_VERSION_ABBR}-curl \
    php${PHP_VERSION_ABBR}-dom \
    php${PHP_VERSION_ABBR}-fileinfo \
    php${PHP_VERSION_ABBR}-fpm \
    php${PHP_VERSION_ABBR}-gd \
    php${PHP_VERSION_ABBR}-iconv \
    php${PHP_VERSION_ABBR}-intl \
    php${PHP_VERSION_ABBR}-ldap \
    php${PHP_VERSION_ABBR}-mbstring \
    php${PHP_VERSION_ABBR}-mysqli \
    php${PHP_VERSION_ABBR}-openssl \
    php${PHP_VERSION_ABBR}-pdo \
    php${PHP_VERSION_ABBR}-pdo_mysql \
    php${PHP_VERSION_ABBR}-pecl-apcu \
    php${PHP_VERSION_ABBR}-pecl-imagick \
    php${PHP_VERSION_ABBR}-phar \
    php${PHP_VERSION_ABBR}-pecl-redis \
    php${PHP_VERSION_ABBR}-session \
    php${PHP_VERSION_ABBR}-simplexml \
    php${PHP_VERSION_ABBR}-soap \
    php${PHP_VERSION_ABBR}-sockets \
    php${PHP_VERSION_ABBR}-sodium \
    php${PHP_VERSION_ABBR}-tokenizer \
    php${PHP_VERSION_ABBR}-xml \
    php${PHP_VERSION_ABBR}-xmlreader \
    php${PHP_VERSION_ABBR}-xmlwriter \
    php${PHP_VERSION_ABBR}-xsl \
    php${PHP_VERSION_ABBR}-zip \
    php${PHP_VERSION_ABBR}-zlib

RUN sed -i 's/^Listen 80$/Listen 0.0.0.0:80/' /etc/apache2/httpd.conf \
    && usermod -u 1000 apache \
    && ln -sf /usr/bin/php${PHP_VERSION_ABBR} /usr/bin/php \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer

COPY . /tmp/openemr
WORKDIR /tmp/openemr

RUN composer install --no-dev --optimize-autoloader \
    && composer dump-autoload --optimize --apcu

RUN apk add --no-cache build-base \
    && npm install --unsafe-perm \
    && npm run build \
    && cd ccdaservice \
    && npm install --unsafe-perm \
    && cd .. \
    && rm -fr node_modules \
    && apk del --no-cache build-base

RUN find . -type d -exec chmod 500 {} + \
    && find . -type f -exec chmod 400 {} + \
    && chmod 666 sites/default/sqlconf.php \
    && chmod 700 sites/default \
    && find sites/default/documents -type d -exec chmod 700 {} + \
    && find sites/default/documents -type f -exec chmod 600 {} + \
    && chown -R apache:apache . \
    && mkdir -p /var/www/localhost/htdocs \
    && mv /tmp/openemr /var/www/localhost/htdocs/openemr \
    && mkdir -p /etc/ssl/certs /etc/ssl/private /etc/letsencrypt \
    && sed -i 's/^ *CustomLog/#CustomLog/' /etc/apache2/httpd.conf \
    && sed -i 's/^ *ErrorLog/#ErrorLog/' /etc/apache2/httpd.conf \
    && sed -i 's/^ *CustomLog/#CustomLog/' /etc/apache2/conf.d/ssl.conf \
    && sed -i 's/^ *TransferLog/#TransferLog/' /etc/apache2/conf.d/ssl.conf

WORKDIR /var/www/localhost/htdocs/openemr

COPY docker/release/php.ini /etc/php${PHP_VERSION_ABBR}/php.ini
COPY docker/release/openemr.conf /etc/apache2/conf.d/
COPY docker/release/openemr.sh docker/release/ssl.sh docker/release/xdebug.sh docker/release/auto_configure.php ./
COPY docker/release/utilities/unlock_admin.php docker/release/utilities/unlock_admin.sh /root/
COPY docker/release/upgrade/docker-version \
     docker/release/upgrade/fsupgrade-1.sh \
     docker/release/upgrade/fsupgrade-2.sh \
     docker/release/upgrade/fsupgrade-3.sh \
     docker/release/upgrade/fsupgrade-4.sh \
     docker/release/upgrade/fsupgrade-5.sh \
     docker/release/upgrade/fsupgrade-6.sh \
     docker/release/upgrade/fsupgrade-7.sh \
     docker/release/upgrade/fsupgrade-8.sh \
     docker/release/upgrade/fsupgrade-9.sh \
     docker/release/upgrade/fsupgrade-10.sh \
     docker/release/upgrade/fsupgrade-11.sh \
     /root/

RUN chmod 500 openemr.sh ssl.sh xdebug.sh /root/unlock_admin.sh \
    && chmod 000 auto_configure.php /root/unlock_admin.php \
    && chmod 500 \
        /root/fsupgrade-1.sh \
        /root/fsupgrade-2.sh \
        /root/fsupgrade-3.sh \
        /root/fsupgrade-4.sh \
        /root/fsupgrade-5.sh \
        /root/fsupgrade-6.sh \
        /root/fsupgrade-7.sh \
        /root/fsupgrade-8.sh \
        /root/fsupgrade-9.sh \
        /root/fsupgrade-10.sh \
        /root/fsupgrade-11.sh \
    && mkdir -p /run/apache2

COPY docker/release/utilities/devtoolsLibrary.source /root/

ENV APACHE_LOG_DIR=/var/log/apache2

CMD ["./openemr.sh"]
EXPOSE 80
