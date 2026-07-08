#!/bin/sh
# Bind Apache to Railway's PORT. Flex image uses Listen 0.0.0.0:80 and VirtualHost *:80.
set -eu

APACHE_PORT="${PORT:-80}"
export PORT="${APACHE_PORT}"

HTTPD_CONF=/etc/apache2/httpd.conf
OPENEMR_CONF=/etc/apache2/conf.d/openemr.conf

if [ -f "${HTTPD_CONF}" ]; then
    sed -i "s/^Listen 0\.0\.0\.0:80$/Listen 0.0.0.0:${APACHE_PORT}/" "${HTTPD_CONF}"
    sed -i "s/^Listen 80$/Listen 0.0.0.0:${APACHE_PORT}/" "${HTTPD_CONF}"
    sed -i "s/^Listen 443$/# Listen 443 disabled for Railway HTTP/" "${HTTPD_CONF}"
fi

if [ -f "${OPENEMR_CONF}" ]; then
    sed -i "s/<VirtualHost \\*:80>/<VirtualHost *:${APACHE_PORT}>/" "${OPENEMR_CONF}"
    sed -i "s/^<VirtualHost _default_:443>/# <VirtualHost _default_:443> disabled for Railway/" "${OPENEMR_CONF}"
fi

if [ -f "${HTTPD_CONF}" ] && ! grep -qE "^Listen (0\.0\.0\.0:)?${APACHE_PORT}$" "${HTTPD_CONF}"; then
    echo "Listen 0.0.0.0:${APACHE_PORT}" >> "${HTTPD_CONF}"
fi

httpd -t
echo "Railway Apache: listening on 0.0.0.0:${APACHE_PORT}"
