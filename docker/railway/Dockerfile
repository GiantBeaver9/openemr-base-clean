# Railway deploy — thin wrapper around openemr/openemr:flex.
#
# Avoids copying/building the full OpenEMR tree on Railway (COPY . was timing
# out on the Metal builder). The flex entrypoint clones FLEX_REPOSITORY on
# first boot and runs composer/npm inside the container.
#
# Set in Railway (service variables):
#   MYSQL_HOST, MYSQL_ROOT_PASS, MYSQL_USER, MYSQL_PASS, MYSQL_DATABASE
#   OE_USER, OE_PASS
#   CLINICAL_COPILOT_GEMINI_API_KEY (optional; synthetic demo only)
#   CLINICAL_COPILOT_GEMINI_API_MODEL (optional)
#
# After first deploy, SSH in and run:
#   php interface/modules/custom_modules/oe-module-clinical-copilot/ops/local/install-module.php
#   php interface/modules/custom_modules/oe-module-clinical-copilot/tests/Seed/SeedClinicalCopilot.php --force

FROM openemr/openemr:flex

ENV FLEX_REPOSITORY=https://github.com/GiantBeaver9/openemr-base-clean.git \
    FLEX_REPOSITORY_BRANCH=main

EXPOSE 80 443
