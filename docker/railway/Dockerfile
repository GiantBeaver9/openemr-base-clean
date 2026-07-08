# Railway deploy — thin wrapper around openemr/openemr:flex.
#
# Avoids copying/building the full OpenEMR tree on Railway (COPY . was timing
# out on the Metal builder). The flex entrypoint clones FLEX_REPOSITORY on
# first boot and runs composer/npm inside the container.
#
# Wire MySQL on the OpenEMR service (replace MySQL with your DB service name):
#   MYSQLHOST=${{MySQL.MYSQLHOST}}
#   MYSQLPORT=${{MySQL.MYSQLPORT}}
#   MYSQLUSER=${{MySQL.MYSQLUSER}}
#   MYSQLPASSWORD=${{MySQL.MYSQLPASSWORD}}
#   MYSQLDATABASE=${{MySQL.MYSQLDATABASE}}
#   OE_USER=admin
#   OE_PASS=<choose-a-password>
#   CLINICAL_COPILOT_GEMINI_API_KEY=<optional, synthetic demo only>
#
# After first deploy, SSH in and run:
#   php interface/modules/custom_modules/oe-module-clinical-copilot/ops/local/install-module.php
#   php interface/modules/custom_modules/oe-module-clinical-copilot/tests/Seed/SeedClinicalCopilot.php --force

FROM openemr/openemr:flex

ENV FLEX_REPOSITORY=https://github.com/GiantBeaver9/openemr-base-clean.git \
    FLEX_REPOSITORY_BRANCH=main

COPY docker/railway/railway-entrypoint.sh /usr/local/bin/railway-entrypoint.sh
RUN chmod 755 /usr/local/bin/railway-entrypoint.sh

EXPOSE 80 443

ENTRYPOINT ["/usr/local/bin/railway-entrypoint.sh"]
