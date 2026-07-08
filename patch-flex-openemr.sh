#!/bin/sh
# Patch upstream flex openemr.sh for Railway fork deploys.
set -eu

OPENEMR_SH=/var/www/localhost/htdocs/openemr.sh

if [ ! -f "${OPENEMR_SH}" ]; then
    echo "patch-flex-openemr: ${OPENEMR_SH} not found" >&2
    exit 1
fi

# Skip git fetch when the tree is already in htdocs (container restart).
# Clone into "openemr" — flex rsync hardcodes that name, not the repo name.
awk '
/if \[\[ -f \/var\/www\/localhost\/htdocs\/auto_configure\.php \]\] && \[\[ "\${EMPTY}" != "yes" \]\] &&/ {
    print "if [[ -f /var/www/localhost/htdocs/openemr/composer.json ]]; then"
    print "    echo \"OpenEMR source already present, skipping flex git fetch\""
    print "elif [[ -f /var/www/localhost/htdocs/auto_configure.php ]] && [[ \"${EMPTY}\" != \"yes\" ]] &&"
    next
}
/git clone "\${FLEX_REPOSITORY}" --branch "\${FLEX_REPOSITORY_BRANCH}" --depth 1$/ {
    print "        rm -rf /openemr /openemr-base-clean"
    print "        git clone \"${FLEX_REPOSITORY}\" --branch \"${FLEX_REPOSITORY_BRANCH}\" --depth 1 openemr"
    next
}
{ print }
' "${OPENEMR_SH}" > "${OPENEMR_SH}.patched"

mv "${OPENEMR_SH}.patched" "${OPENEMR_SH}"
echo "patch-flex-openemr: applied Railway flex clone fixes"
