#!/bin/sh
# Bind Apache to Railway's PORT (default 8080). Flex image listens on 80 by default.
set -eu

APACHE_PORT="${PORT:-8080}"
export PORT="${APACHE_PORT}"

if [ -f /etc/apache2/httpd.conf ]; then
    sed -i "s/^Listen 80$/Listen ${APACHE_PORT}/" /etc/apache2/httpd.conf
    sed -i "s/^Listen 443$/# Listen 443 disabled for Railway HTTP/" /etc/apache2/httpd.conf
fi

echo "Railway Apache: configured Listen ${APACHE_PORT}"
