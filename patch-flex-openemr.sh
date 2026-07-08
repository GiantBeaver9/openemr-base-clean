#!/bin/sh
# Patch upstream flex openemr.sh for Railway fork deploys.
set -eu

OPENEMR_SH=/var/www/localhost/htdocs/openemr.sh
OUT="${OPENEMR_SH}.patched"

if [ ! -f "${OPENEMR_SH}" ]; then
    echo "patch-flex-openemr: ${OPENEMR_SH} not found" >&2
    exit 1
fi

: > "${OUT}"
skip_inserted=0

while IFS= read -r line || [ -n "${line}" ]; do
    case "${line}" in
        *'git clone "${FLEX_REPOSITORY}" --branch "${FLEX_REPOSITORY_BRANCH}" --depth 1'*)
            printf '%s\n' \
                '        rm -rf /openemr /openemr-base-clean' \
                '        git clone "${FLEX_REPOSITORY}" --branch "${FLEX_REPOSITORY_BRANCH}" --depth 1 openemr' >> "${OUT}"
            ;;
        *'if [[ -f /var/www/localhost/htdocs/auto_configure.php ]] && [[ "${EMPTY}" != "yes" ]] &&'*)
            if [ "${skip_inserted}" -eq 0 ]; then
                printf '%s\n' \
                    'if [[ -f /var/www/localhost/htdocs/openemr/composer.json ]]; then' \
                    '    echo "OpenEMR source already present, skipping flex git fetch"' \
                    "$(printf '%s' "${line}" | sed 's/^if /elif /')" >> "${OUT}"
                skip_inserted=1
            else
                printf '%s\n' "${line}" >> "${OUT}"
            fi
            ;;
        *)
            printf '%s\n' "${line}" >> "${OUT}"
            ;;
    esac
done < "${OPENEMR_SH}"

mv "${OUT}" "${OPENEMR_SH}"
echo "patch-flex-openemr: applied Railway flex clone fixes"
