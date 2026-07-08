#!/usr/bin/env sh
# Sync Railway service variables before deploy (run from GitLab CI with RAILWAY_TOKEN).
#
# Required GitLab CI variables:
#   RAILWAY_TOKEN, RAILWAY_SERVICE_ID
#
# Optional GitLab CI variables:
#   RAILWAY_MYSQL_SERVICE_NAME — MySQL service name in Railway (default: MySQL)
#   RAILWAY_ENVIRONMENT        — Railway environment name when not default
#   RAILWAY_OE_PASS            — OpenEMR admin password (OE_PASS)
#   CLINICAL_COPILOT_GEMINI_API_KEY — Gemini API key (synthetic demo only)
set -eu

SVC_ID="${SVC_ID:-${RAILWAY_SERVICE_ID:-}}"
MYSQL_SERVICE="${RAILWAY_MYSQL_SERVICE_NAME:-MySQL}"

if [ -z "${RAILWAY_TOKEN:-}" ]; then
    echo "RAILWAY_TOKEN is not set." >&2
    exit 1
fi

if [ -z "${SVC_ID}" ]; then
    echo "RAILWAY_SERVICE_ID is not set." >&2
    exit 1
fi

ENV_FLAG=""
if [ -n "${RAILWAY_ENVIRONMENT:-}" ]; then
    ENV_FLAG="--environment ${RAILWAY_ENVIRONMENT}"
fi

echo "Syncing Railway variables for service ${SVC_ID} (MySQL service: ${MYSQL_SERVICE})..."

OE_PASS_VALUE="${RAILWAY_OE_PASS:-pass}"

# Reference variables wire OpenEMR to the managed MySQL plugin.
# shellcheck disable=SC2086
railway variable set \
    "MYSQLHOST=\${{${MYSQL_SERVICE}.MYSQLHOST}}" \
    "MYSQLPORT=\${{${MYSQL_SERVICE}.MYSQLPORT}}" \
    "MYSQLUSER=\${{${MYSQL_SERVICE}.MYSQLUSER}}" \
    "MYSQLPASSWORD=\${{${MYSQL_SERVICE}.MYSQLPASSWORD}}" \
    "MYSQLDATABASE=\${{${MYSQL_SERVICE}.MYSQLDATABASE}}" \
    "OE_USER=admin" \
    "OE_PASS=${OE_PASS_VALUE}" \
    "CLINICAL_COPILOT_GEMINI_API_MODEL=gemini-2.5-flash" \
    --service "${SVC_ID}" \
    ${ENV_FLAG} \
    --skip-deploys

if [ -n "${CLINICAL_COPILOT_GEMINI_API_KEY:-}" ]; then
    # shellcheck disable=SC2086
    railway variable set \
        "CLINICAL_COPILOT_GEMINI_API_KEY=${CLINICAL_COPILOT_GEMINI_API_KEY}" \
        --service "${SVC_ID}" \
        ${ENV_FLAG} \
        --skip-deploys
else
    echo "CLINICAL_COPILOT_GEMINI_API_KEY not set in GitLab — leaving Gemini key unchanged on Railway."
fi

echo "Railway variables synced."
