#!/usr/bin/env bash
#
# Clinical Co-Pilot — portable bash+curl load driver (ARCHITECTURE.md §3.6, R9).
#
# A k6-free fallback for environments without k6. Same shape as load.js: each
# virtual user logs in independently (its own cookie jar / session), fetches a
# CSRF token, then loops hitting the WARM doc + one chat turn for DURATION
# seconds. Reports p50/p95/p99 latency and error rate per path.
#
# SYNTHETIC PATIENTS ONLY (OPEN-1): point PID at a synthetic patient. Never run
# against a database containing real PHI.
#
# Usage:
#   VUS=10 DURATION=60 BASE_URL=https://localhost:9300 PID=1 ./load.sh
#   VUS=50 DURATION=60 BASE_URL=https://localhost:9300 PID=1 ./load.sh
#
# Env vars (all optional; defaults shown):
#   BASE_URL=https://localhost:9300  PID=1  SITE=default
#   USERNAME=admin  PASSWORD=pass  VUS=10  DURATION=60
#   INSECURE=1   (pass -k to curl; the dev stack uses a self-signed cert)
#
# Requires: bash, curl, awk, sort, date. No k6, no jq.

set -u

BASE_URL="${BASE_URL:-https://localhost:9300}"
MODULE_BASE="${BASE_URL}/interface/modules/custom_modules/oe-module-clinical-copilot/public"
LOGIN_URL="${BASE_URL}/interface/main/main_screen.php"
SITE="${SITE:-default}"
USERNAME="${USERNAME:-admin}"
PASSWORD="${PASSWORD:-pass}"
PID="${PID:-1}"
VUS="${VUS:-10}"
DURATION="${DURATION:-60}"
CHAT_MESSAGE="${CHAT_MESSAGE:-What were the most recent lab results on file for this patient?}"

CURL_OPTS=(-s -o /dev/null)
CURL_TLS=()
if [ "${INSECURE:-1}" = "1" ]; then
  CURL_TLS=(-k)
fi

WORKDIR="$(mktemp -d "${TMPDIR:-/tmp}/copilot-load.XXXXXX")"
trap 'rm -rf "$WORKDIR"' EXIT

echo "Clinical Co-Pilot load driver (bash+curl)"
echo "  base_url = ${BASE_URL}"
echo "  pid      = ${PID}  (SYNTHETIC ONLY — OPEN-1)"
echo "  vus      = ${VUS}"
echo "  duration = ${DURATION}s"
echo

# One virtual user: independent session; loop until the shared deadline.
run_vu() {
  local id="$1" deadline="$2"
  local jar="${WORKDIR}/jar_${id}.txt"
  local out="${WORKDIR}/vu_${id}.csv"   # lines: path,ms,ok(1/0)
  : > "$out"

  # --- Login (new-login path needs no CSRF token) ---------------------------
  curl "${CURL_OPTS[@]}" "${CURL_TLS[@]}" -c "$jar" -b "$jar" \
    --data-urlencode "new_login_session_management=1" \
    --data-urlencode "authUser=${USERNAME}" \
    --data-urlencode "clearPass=${PASSWORD}" \
    --data-urlencode "languageChoice=1" \
    --data-urlencode "site=${SITE}" \
    "${LOGIN_URL}?auth=login&site=${SITE}" >/dev/null 2>&1

  # --- Fetch CSRF token from the chat page ----------------------------------
  local page csrf
  page="$(curl -s "${CURL_TLS[@]}" -c "$jar" -b "$jar" \
    "${MODULE_BASE}/chat.php?pid=${PID}" 2>/dev/null)"
  csrf="$(printf '%s' "$page" \
    | grep -oE 'name="csrf_token_form" value="[^"]+"' \
    | head -n1 | sed -E 's/.*value="([^"]+)".*/\1/')"
  if [ -z "$csrf" ]; then
    csrf="$(printf '%s' "$page" \
      | grep -oE 'data-csrf="[^"]+"' | head -n1 | sed -E 's/.*data-csrf="([^"]+)".*/\1/')"
  fi

  # --- Loop: warm doc + one chat turn ---------------------------------------
  local now start ms code ok
  while :; do
    now="$(date +%s)"
    [ "$now" -ge "$deadline" ] && break

    # 1. Warm doc (GET). curl reports total time + HTTP code.
    read -r ms code < <(curl -s "${CURL_TLS[@]}" -c "$jar" -b "$jar" -o /dev/null \
      -w '%{time_total} %{http_code}' \
      "${MODULE_BASE}/doc.php?pid=${PID}" 2>/dev/null)
    ms="$(awk -v t="${ms:-0}" 'BEGIN{printf "%.0f", t*1000}')"
    ok=0; [ "${code:-0}" = "200" ] && ok=1
    echo "doc,${ms},${ok}" >> "$out"

    # 2. Chat turn (POST). 4xx = clean refusal/guard (healthy); only 5xx fails.
    read -r ms code < <(curl -s "${CURL_TLS[@]}" -c "$jar" -b "$jar" -o /dev/null \
      -w '%{time_total} %{http_code}' \
      -H 'Accept: application/json' \
      --data-urlencode "message=${CHAT_MESSAGE}" \
      --data-urlencode "pid=${PID}" \
      --data-urlencode "session_id=0" \
      --data-urlencode "csrf_token_form=${csrf}" \
      --data-urlencode "transport=json" \
      "${MODULE_BASE}/chat.php" 2>/dev/null)
    ms="$(awk -v t="${ms:-0}" 'BEGIN{printf "%.0f", t*1000}')"
    ok=0; [ -n "${code:-}" ] && [ "${code}" -lt 500 ] 2>/dev/null && ok=1
    echo "chat,${ms},${ok}" >> "$out"
  done
}

DEADLINE=$(( $(date +%s) + DURATION ))
echo "Spawning ${VUS} virtual users…"
for i in $(seq 1 "$VUS"); do
  run_vu "$i" "$DEADLINE" &
done
wait
echo "Run complete. Aggregating…"
echo

# Percentiles + error rate per path, computed with awk over the sorted latencies.
summarize() {
  local path="$1" label="$2"
  local lat_file="${WORKDIR}/lat_${path}.txt"
  # shellcheck disable=SC2129
  cat "${WORKDIR}"/vu_*.csv 2>/dev/null | awk -F, -v p="$path" '$1==p{print $2}' \
    | sort -n > "$lat_file"

  local total errors
  total="$(cat "${WORKDIR}"/vu_*.csv 2>/dev/null | awk -F, -v p="$path" '$1==p{c++} END{print c+0}')"
  errors="$(cat "${WORKDIR}"/vu_*.csv 2>/dev/null | awk -F, -v p="$path" '$1==p && $3==0{c++} END{print c+0}')"

  if [ "${total:-0}" -eq 0 ]; then
    printf '  %-16s no samples\n' "$label"
    return
  fi

  awk -v label="$label" -v total="$total" -v errors="$errors" '
    { v[NR]=$1 }
    END {
      n=NR
      function pct(p,   idx){ idx=int((p/100.0)*(n-1))+1; if(idx<1)idx=1; if(idx>n)idx=n; return v[idx] }
      erate = (total>0)? (errors*100.0/total) : 0
      printf "  %-16s n=%-6d err=%-5.1f%%  p50=%-7dms p95=%-7dms p99=%-7dms max=%-7dms\n", \
        label, total, erate, pct(50), pct(95), pct(99), v[n]
    }' "$lat_file"
}

echo "Results (VUS=${VUS}, DURATION=${DURATION}s):"
summarize "doc"  "doc (warm)"
summarize "chat" "chat turn"
echo
echo "Record these in ops/RESULTS.md. Capture CPU/mem alongside via baseline.sh"
echo "or a host monitor; this driver measures latency + error rate only."
