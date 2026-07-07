#!/usr/bin/env bash
#
# additivity-diff-gate.sh — enforce module additivity (I9): the Clinical Co-Pilot module ships as a
# self-contained directory under interface/modules/custom_modules/ and must NEVER modify host code.
# This gate fails the build if any changed file falls outside the module directory, with the single
# exception of the four spec documents the module's design deliberately co-authors at repo root.
#
# Usage:
#   ci/additivity-diff-gate.sh [<base-ref>]
#
# <base-ref> defaults to "origin/master". The diff compared is <base-ref>...HEAD (the merge-base
# three-dot form) so only commits on this branch are considered, not upstream churn.
#
# Exit status:
#   0  every changed path is inside the module dir or an allowed spec doc
#   1  one or more changed paths escape the additivity boundary (printed to stderr)
#   2  usage / git error (e.g. base ref not found)
#
# @package   OpenEMR\Modules\ClinicalCopilot
# @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3

set -euo pipefail

BASE_REF="${1:-origin/main}"

# Paths that may change outside the module directory: the co-pilot design corpus the
# module co-owns (the case-study deliverables). AUDIT.md is the pre-build audit and a
# named companion to ARCHITECTURE.md; it belongs to the same family as the spec docs.
# No host/core source is ever in this list — that is what the gate exists to protect.
ALLOWED_OUTSIDE=(
  "ARCHITECTURE.md"
  "ARCHITECTURE_COMPLETE.md"
  "AUDIT.md"
  "USERS.md"
  "docs/clinical-copilot-tradeoffs.md"
)

MODULE_DIR="interface/modules/custom_modules/oe-module-clinical-copilot"

if ! git rev-parse --verify --quiet "${BASE_REF}^{commit}" >/dev/null; then
  echo "additivity-diff-gate: base ref '${BASE_REF}' not found" >&2
  exit 2
fi

# Three-dot diff: changes on HEAD since it diverged from the base ref.
mapfile -t CHANGED < <(git diff --name-only "${BASE_REF}...HEAD")

if [ "${#CHANGED[@]}" -eq 0 ]; then
  echo "additivity-diff-gate: no changed files vs ${BASE_REF} — nothing to check."
  exit 0
fi

is_allowed() {
  local path="$1"

  # Inside the module directory: always allowed.
  case "$path" in
    "${MODULE_DIR}/"*) return 0 ;;
  esac

  # One of the explicitly allowed spec documents.
  local allowed
  for allowed in "${ALLOWED_OUTSIDE[@]}"; do
    if [ "$path" = "$allowed" ]; then
      return 0
    fi
  done

  return 1
}

OFFENDERS=()
for path in "${CHANGED[@]}"; do
  if ! is_allowed "$path"; then
    OFFENDERS+=("$path")
  fi
done

if [ "${#OFFENDERS[@]}" -gt 0 ]; then
  echo "additivity-diff-gate: FAIL — ${#OFFENDERS[@]} path(s) escape the module additivity boundary (I9):" >&2
  for path in "${OFFENDERS[@]}"; do
    echo "  - ${path}" >&2
  done
  echo "" >&2
  echo "Only files under ${MODULE_DIR}/ (plus the co-pilot design docs) may change in this module's PRs." >&2
  exit 1
fi

echo "additivity-diff-gate: OK — all ${#CHANGED[@]} changed path(s) are within the module boundary."
exit 0
