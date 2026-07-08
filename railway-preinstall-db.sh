#!/bin/sh
# Railway MySQL plugin pre-creates the database; OpenEMR installer uses CREATE DATABASE
# without IF NOT EXISTS. Prepare DB + app user so quick_install skips creation.
set -eu

: "${MYSQL_HOST:?MYSQL_HOST required}"
: "${MYSQL_ROOT_PASS:?MYSQL_ROOT_PASS required}"
: "${MYSQL_DATABASE:?MYSQL_DATABASE required}"

MYSQL_ROOT_USER="${MYSQL_ROOT_USER:-root}"
MYSQL_PORT="${MYSQL_PORT:-3306}"
MYSQL_USER="${MYSQL_USER:-openemr}"
MYSQL_PASS="${MYSQL_PASS:-pass}"

echo "Railway DB prep: ensuring database and application user exist"

mariadb --skip-ssl \
    -h "${MYSQL_HOST}" -P "${MYSQL_PORT}" \
    -u "${MYSQL_ROOT_USER}" -p"${MYSQL_ROOT_PASS}" \
    -e "CREATE DATABASE IF NOT EXISTS \`${MYSQL_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"

mariadb --skip-ssl \
    -h "${MYSQL_HOST}" -P "${MYSQL_PORT}" \
    -u "${MYSQL_ROOT_USER}" -p"${MYSQL_ROOT_PASS}" \
    -e "CREATE USER IF NOT EXISTS '${MYSQL_USER}'@'%' IDENTIFIED BY '${MYSQL_PASS}'; \
GRANT ALL PRIVILEGES ON \`${MYSQL_DATABASE}\`.* TO '${MYSQL_USER}'@'%'; \
FLUSH PRIVILEGES;"

echo "Railway DB prep: done"
