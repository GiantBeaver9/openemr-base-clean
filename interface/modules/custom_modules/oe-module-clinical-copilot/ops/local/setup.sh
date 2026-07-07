#!/usr/bin/env bash
#
# Clinical Co-Pilot -- one-command local bring-up.
#
# Stands up the maintained OpenEMR dev stack (docker/development-easy), waits for
# its first-boot auto-install (OpenEMR + composer + npm, all inside the
# openemr/openemr:flex image -- which carries its own composer GitHub token, so
# it works where a host `composer install` may not), then installs + enables this
# module, seeds synthetic diabetes patients, and runs the dependency-free smoke
# check. Idempotent: safe to re-run.
#
# Usage (from anywhere in the repo):
#   interface/modules/custom_modules/oe-module-clinical-copilot/ops/local/setup.sh
#
# Optional -- exercise the narrated LLM path (synthetic data only; see
# ../../docs/configuration.md): export a key BEFORE running:
#   export CLINICAL_COPILOT_GEMINI_API_KEY=your_ai_studio_key
# With no key set, synthesis renders facts-only and chat is a facts browser --
# still fully usable.
#
# @package OpenEMR\Modules\ClinicalCopilot
# @license GNU General Public License 3

set -euo pipefail

# --- locate the repo + module (works from any CWD) --------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR" && git rev-parse --show-toplevel 2>/dev/null || cd "$SCRIPT_DIR/../../../../../.." && pwd)"
MODULE_REL="interface/modules/custom_modules/oe-module-clinical-copilot"
COMPOSE_MAIN="$REPO_ROOT/docker/development-easy/docker-compose.yml"
COMPOSE_OVERLAY="$REPO_ROOT/$MODULE_REL/ops/local/compose.gemini.yml"
CONTAINER_ROOT="/var/www/localhost/htdocs/openemr"
HTTPS_URL="https://localhost:${WT_HTTPS_PORT:-9300}"

# uid/gid so apache writes bind-mounted files as you (avoids EACCES); see the
# dev compose's HOST_UID comment.
export HOST_UID="${HOST_UID:-$(id -u)}"
export HOST_GID="${HOST_GID:-$(id -g)}"

DC=(docker compose -f "$COMPOSE_MAIN" -f "$COMPOSE_OVERLAY")

log()  { printf '\n\033[1;36m==>\033[0m %s\n' "$*"; }
die()  { printf '\n\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

command -v docker >/dev/null 2>&1 || die "docker not found on PATH."
[ -f "$COMPOSE_MAIN" ] || die "dev compose not found at $COMPOSE_MAIN"

if [ -z "${CLINICAL_COPILOT_GEMINI_API_KEY:-}${CLINICAL_COPILOT_GCP_PROJECT_ID:-}" ]; then
  log "No LLM credentials set -> the module will run in facts-only / facts-browser mode."
  log "  (set CLINICAL_COPILOT_GEMINI_API_KEY to enable narration -- synthetic data only.)"
fi

# --- 1. bring up the stack ---------------------------------------------------
log "Starting the OpenEMR dev stack (mysql + openemr:flex)..."
"${DC[@]}" up -d

# --- 2. wait for OpenEMR's first-boot auto-install ---------------------------
# First boot runs the OpenEMR installer + composer + npm build -- can take
# several minutes. We poll the HTTPS login page until it serves.
log "Waiting for OpenEMR to finish first-boot install (this can take 5-10 min on a cold pull)..."
deadline=$(( $(date +%s) + 1200 ))   # 20 min ceiling
until curl -sk --max-time 5 "$HTTPS_URL/interface/login/login.php?site=default" 2>/dev/null | grep -qi "openemr\|login"; do
  [ "$(date +%s)" -lt "$deadline" ] || die "Timed out waiting for OpenEMR. Check: ${DC[*]} logs openemr"
  printf '.'
  sleep 10
done
printf '\n'
log "OpenEMR is up at $HTTPS_URL  (login: admin / pass)"

exec_in() { "${DC[@]}" exec -T openemr "$@"; }

# --- 3. install + enable the module ------------------------------------------
log "Installing + enabling the Clinical Co-Pilot module..."
exec_in php "$CONTAINER_ROOT/$MODULE_REL/ops/local/install-module.php" \
  || die "Module install failed. See the UI fallback in ops/local/install-module.php header."

# --- 4. seed synthetic diabetes patients -------------------------------------
log "Seeding synthetic diabetes patients (landmine fixtures)..."
exec_in php "$CONTAINER_ROOT/$MODULE_REL/tests/Seed/SeedClinicalCopilot.php" --force \
  || die "Seeding failed. See tests/Seed/SeedClinicalCopilot.php."

# --- 5. dependency-free smoke check (deterministic core + review fixes) -------
log "Running the deterministic-core smoke check..."
exec_in php "$CONTAINER_ROOT/$MODULE_REL/tests/smoke/deterministic-core-smoke.php" \
  || die "Smoke check failed -- deterministic core regression, investigate before proceeding."

# --- 6. done -----------------------------------------------------------------
log "Local bring-up complete."
exec_in php "$CONTAINER_ROOT/$MODULE_REL/ops/local/print-patients.php" || true
cat <<EOF

Next:
  * App:        $HTTPS_URL   (admin / pass)
  * Dashboard:  $HTTPS_URL/$MODULE_REL/public/dashboard.php
  * Health:     $HTTPS_URL/$MODULE_REL/public/health.php   (ready.php for deps)
  * Full tests (inside the container): openemr-cmd unit-test / phpunit-isolated / phpstan
  * Background warm/QA worker ticks while a clinician is logged into the browser;
    for headless warming wire the cron documented in ops/README.md.
  * Tear down:  ${DC[*]} down          (add -v to also drop the DB volume)
EOF
