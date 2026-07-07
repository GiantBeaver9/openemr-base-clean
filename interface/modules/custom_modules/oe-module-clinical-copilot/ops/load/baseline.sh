#!/usr/bin/env bash
#
# Clinical Co-Pilot — baseline capture (ARCHITECTURE.md §3.6, R8).
#
# Single-user baselines of the deployed stack, BEFORE the load tests:
#   - synthesis, WARM  (doc.php on an already-warmed patient)
#   - synthesis, COLD  (doc.php on a not-yet-warmed patient — read-time gen)
#   - chat turn with 0 / 1 / 3 tool calls (prompts chosen to elicit each)
# For each: request latency (repeated), plus a CPU/mem sample of the app
# container when one is reachable (openemr-cmd / docker).
#
# SYNTHETIC PATIENTS ONLY (OPEN-1): all PIDs must be synthetic. No real PHI.
#
# Usage (typically in-stack, see ops/README.md):
#   BASE_URL=https://localhost:9300 WARM_PID=1 COLD_PID=2 \
#     DOCKER_CONTAINER=development-easy-openemr-1 ./baseline.sh
#
# Env vars (defaults shown):
#   BASE_URL=https://localhost:9300  SITE=default  USERNAME=admin  PASSWORD=pass
#   WARM_PID=1   COLD_PID=2   REPEAT=3   INSECURE=1
#   DOCKER_CONTAINER=<unset>   (if set, sample its CPU/mem via `docker stats`)
#
# Requires: bash, curl, awk, sort, date. `docker` optional (CPU/mem sampling).
# Tool-call counts are NOMINAL (driven by prompt intent); confirm the actual
# count from the turn's trace (status.php progress) when recording results.

set -u

BASE_URL="${BASE_URL:-https://localhost:9300}"
MODULE_BASE="${BASE_URL}/interface/modules/custom_modules/oe-module-clinical-copilot/public"
LOGIN_URL="${BASE_URL}/interface/main/main_screen.php"
SITE="${SITE:-default}"
USERNAME="${USERNAME:-admin}"
PASSWORD="${PASSWORD:-pass}"
WARM_PID="${WARM_PID:-1}"
COLD_PID="${COLD_PID:-2}"
REPEAT="${REPEAT:-3}"
DOCKER_CONTAINER="${DOCKER_CONTAINER:-}"

CURL_TLS=()
if [ "${INSECURE:-1}" = "1" ]; then
  CURL_TLS=(-k)
fi

WORKDIR="$(mktemp -d "${TMPDIR:-/tmp}/copilot-baseline.XXXXXX")"
JAR="${WORKDIR}/jar.txt"
trap 'rm -rf "$WORKDIR"' EXIT

echo "Clinical Co-Pilot baseline capture (R8)"
echo "  base_url = ${BASE_URL}"
echo "  warm_pid = ${WARM_PID}   cold_pid = ${COLD_PID}   (SYNTHETIC ONLY — OPEN-1)"
echo "  repeat   = ${REPEAT}"
if [ -n "$DOCKER_CONTAINER" ]; then
  echo "  cpu/mem  = docker stats ${DOCKER_CONTAINER}"
else
  echo "  cpu/mem  = n/a (set DOCKER_CONTAINER to sample)"
fi
echo

# --- Auth bootstrap ----------------------------------------------------------
curl -s "${CURL_TLS[@]}" -c "$JAR" -b "$JAR" -o /dev/null \
  --data-urlencode "new_login_session_management=1" \
  --data-urlencode "authUser=${USERNAME}" \
  --data-urlencode "clearPass=${PASSWORD}" \
  --data-urlencode "languageChoice=1" \
  --data-urlencode "site=${SITE}" \
  "${LOGIN_URL}?auth=login&site=${SITE}"

CSRF_PAGE="$(curl -s "${CURL_TLS[@]}" -c "$JAR" -b "$JAR" \
  "${MODULE_BASE}/chat.php?pid=${WARM_PID}" 2>/dev/null)"
CSRF="$(printf '%s' "$CSRF_PAGE" \
  | grep -oE 'name="csrf_token_form" value="[^"]+"' \
  | head -n1 | sed -E 's/.*value="([^"]+)".*/\1/')"
if [ -z "$CSRF" ]; then
  echo "WARNING: no CSRF token captured — check credentials/BASE_URL. Chat rows may 400." >&2
fi

# Sample container CPU/mem (or n/a). Prints "<cpu%> <memUsage>".
capture_stat() {
  if [ -n "$DOCKER_CONTAINER" ] && command -v docker >/dev/null 2>&1; then
    docker stats --no-stream --format '{{.CPUPerc}} {{.MemUsage}}' "$DOCKER_CONTAINER" 2>/dev/null \
      || echo "n/a n/a"
  else
    echo "n/a n/a"
  fi
}

# Time one HTTP call; echo "<ms> <http_code>".
time_call() {
  local method="$1"; shift
  if [ "$method" = "GET" ]; then
    curl -s "${CURL_TLS[@]}" -c "$JAR" -b "$JAR" -o /dev/null \
      -w '%{time_total} %{http_code}' "$1" 2>/dev/null \
      | awk '{printf "%.0f %s", $1*1000, $2}'
  else
    local url="$1"; shift
    curl -s "${CURL_TLS[@]}" -c "$JAR" -b "$JAR" -o /dev/null \
      -w '%{time_total} %{http_code}' "$@" "$url" 2>/dev/null \
      | awk '{printf "%.0f %s", $1*1000, $2}'
  fi
}

# Run a scenario REPEAT times; print latency summary + a CPU/mem sample.
# Args: label, method, url, [extra curl args…]
run_scenario() {
  local label="$1" method="$2" url="$3"; shift 3
  local lat_file="${WORKDIR}/lat.txt"; : > "$lat_file"
  local codes="" i ms code
  for i in $(seq 1 "$REPEAT"); do
    read -r ms code < <(time_call "$method" "$url" "$@")
    echo "$ms" >> "$lat_file"
    codes="${codes} ${code}"
  done
  local stat cpu mem
  stat="$(capture_stat)"; cpu="${stat%% *}"; mem="${stat#* }"
  sort -n "$lat_file" | awk -v label="$label" -v codes="$codes" -v cpu="$cpu" -v mem="$mem" '
    { v[NR]=$1; sum+=$1 }
    END {
      n=NR
      med = (n%2)? v[int(n/2)+1] : int((v[n/2]+v[n/2+1])/2)
      printf "  %-26s n=%d  min=%dms  med=%dms  max=%dms  avg=%.0fms\n", label, n, v[1], med, v[n], sum/n
      printf "  %-26s http=[%s ]  cpu=%s  mem=%s\n", "", codes, cpu, mem
    }'
}

echo "Baselines (REPEAT=${REPEAT} each):"
echo
echo "[synthesis]"
run_scenario "synthesis warm (doc)" GET "${MODULE_BASE}/doc.php?pid=${WARM_PID}"
run_scenario "synthesis cold (doc)" GET "${MODULE_BASE}/doc.php?pid=${COLD_PID}"
echo
echo "[chat — tool-call counts are nominal; confirm via status.php trace]"
run_scenario "chat 0 tool calls" POST "${MODULE_BASE}/chat.php" \
  -H 'Accept: application/json' \
  --data-urlencode "message=Hello — can you introduce what you can help with?" \
  --data-urlencode "pid=${WARM_PID}" --data-urlencode "session_id=0" \
  --data-urlencode "csrf_token_form=${CSRF}" --data-urlencode "transport=json"
run_scenario "chat 1 tool call" POST "${MODULE_BASE}/chat.php" \
  -H 'Accept: application/json' \
  --data-urlencode "message=What is the most recent A1c result on file?" \
  --data-urlencode "pid=${WARM_PID}" --data-urlencode "session_id=0" \
  --data-urlencode "csrf_token_form=${CSRF}" --data-urlencode "transport=json"
run_scenario "chat 3 tool calls" POST "${MODULE_BASE}/chat.php" \
  -H 'Accept: application/json' \
  --data-urlencode "message=Summarize the recent labs, vital-sign trend, and active medications." \
  --data-urlencode "pid=${WARM_PID}" --data-urlencode "session_id=0" \
  --data-urlencode "csrf_token_form=${CSRF}" --data-urlencode "transport=json"
echo
echo "Record these in ops/RESULTS.md. CPU/mem shows n/a unless DOCKER_CONTAINER"
echo "is set to the app container (in-stack: openemr-cmd resolves it for you)."
