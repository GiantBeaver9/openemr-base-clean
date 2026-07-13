#!/usr/bin/env bash
#
# Clinical Co-Pilot Week 2 eval gate -- CI wrapper (spec §6 "HARD GATE").
#
# Runs the 50-case golden set through the module's real deterministic code
# paths and exits non-zero if any boolean-rubric pass-rate falls below its
# baseline (>5% regression) or an absolute floor. No live model or database is
# required -- every case supplies the model output verbatim, so this runs in CI
# without API access (spec: "these tests must pass in CI without live API").
#
# Wire this into whatever CI workflow builds a Clinical Co-Pilot branch,
# alongside the additivity and forbidden-write gates. It cannot add a
# .github/workflows file itself without violating the additivity invariant it
# lives beside, so the invocation is documented here rather than shipped as a
# core-workflow diff. Example GitHub Actions step:
#
#   - name: Clinical Co-Pilot Week 2 eval gate
#     run: interface/modules/custom_modules/oe-module-clinical-copilot/ops/ci/run-eval-gate.sh
#
# To re-baseline after an intentional behaviour change (review the diff first):
#   php interface/modules/custom_modules/oe-module-clinical-copilot/ops/eval/run-evals.php --update-baseline
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUNNER="${SCRIPT_DIR}/../eval/run-evals.php"

if ! command -v php >/dev/null 2>&1; then
  echo "run-eval-gate: php not found on PATH" >&2
  exit 2
fi

php "${RUNNER}"
