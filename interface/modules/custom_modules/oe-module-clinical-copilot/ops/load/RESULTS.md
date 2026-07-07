# Clinical Co-Pilot — Baseline + Load Results (R8, R9)

Template only — **no numbers have been captured yet** (this build unit ships
the harness; a run against a real deployed dev/staging stack still needs to
happen before shipping, per ARCHITECTURE.md §3.6: "Before the agent ships:
capture baseline ... load tests ... Results are committed with the eval
dataset; future changes are measured against them"). Fill in every `TBD`
below after running `../baseline/capture-baseline.sh` and the two `k6`
scripts in `../k6/`, then commit this file — it is the tracked baseline
future changes get diffed against.

## Scaling stance (T20 — vertical-first, read this before the numbers)

OpenEMR is a session-holding monolith and this module is in-process by
design (T2): every open chat turn holds a PHP-FPM worker for the turn's
whole duration (up to the 30s deadline), so concurrency is bought with
**worker count**, which is bought with **cores and RAM on a bigger machine
type** — not with horizontal app replicas (which would immediately hit
shared-session, worker-singleton, and sticky-connection problems this
monolith isn't built for). The numbers below exist to answer one question:
**how many workers/how much RAM does this deployment need for N concurrent
users**, not "how do we add more app servers." When a deployment outgrows
the biggest sensible single machine (multi-clinic scale), the honest next
moves are read replicas for fact extraction and pulling the chat loop out
of process — a deliberate re-opening of T2 (recorded as T20), not something
this harness is trying to defer or hide.

## Environment captured against

| Field | Value |
|---|---|
| Date | TBD |
| Git commit | TBD |
| Host / machine type | TBD (e.g. GCE `n2-standard-4`, or dev laptop — state it plainly; these numbers are only meaningful relative to their hardware) |
| PHP-FPM `pm.max_children` | TBD |
| MySQL max_connections | TBD |
| LLM credentials configured? | TBD (yes = real Vertex latency; no = degraded/facts-only path — state which was measured, they are NOT comparable) |
| Seed state | TBD (U2 seed run? how many patients/facts?) |

## Baseline (single-request, unloaded — `capture-baseline.sh`)

| Scenario | time_connect (s) | TTFB (s) | total (s) | HTTP | Notes |
|---|---|---|---|---|---|
| Synthesis read, WARM | TBD | TBD | TBD | TBD | doc row already exists for current digest; no LLM call expected |
| Synthesis read, COLD (Regenerate) | TBD | TBD | TBD | TBD | forces reduce+verify; expect this ≈ one LLM round trip + verify |
| Chat turn, 0 tool calls | TBD | TBD | TBD | TBD | answerable from preloaded seed facts alone |
| Chat turn, 1 tool call | TBD | TBD | TBD | TBD | one drill-down beyond the preload window |
| Chat turn, 3 tool calls | TBD | TBD | TBD | TBD | chaining question across labs+meds+vitals |

CPU/mem during baseline capture (idle stack, single in-flight request):

| Container | CPU % | Mem (used / limit) |
|---|---|---|
| openemr (apache+php) | TBD | TBD |
| mysql | TBD | TBD |

## Load: 10 concurrent users (`doc-read-load.js`, `chat-turn-load.js`)

Command run: `k6 run --vus 10 --duration 3m ...` (doc), `--duration 5m ...` (chat)

### Synthesis read (`doc_read_duration`)

| Metric | Value |
|---|---|
| p50 | TBD |
| p95 | TBD |
| p99 | TBD |
| error rate | TBD |
| requests/s | TBD |

### Chat turn (`chat_turn_duration`)

| Metric | Value |
|---|---|
| p50 | TBD |
| p95 | TBD |
| p99 | TBD |
| error rate | TBD |
| `chat_turns_degraded` count | TBD |
| `chat_turns_frozen` count | TBD (should be 0 under normal load — a nonzero count here means something in the run is tripping V3, worth investigating before reading the rest of the numbers) |

### Saturation @ 10 VUs

| Signal | Value |
|---|---|
| PHP-FPM active workers (peak) | TBD |
| PHP-FPM `pm.max_children` headroom | TBD (peak / max_children) |
| MySQL `Threads_connected` (peak) | TBD |
| openemr container CPU % (peak) | TBD |
| openemr container mem (peak) | TBD |

## Load: 50 concurrent users

Command run: `k6 run --vus 50 --duration 3m ...` (doc), `--duration 5m ...` (chat)

### Synthesis read (`doc_read_duration`)

| Metric | Value |
|---|---|
| p50 | TBD |
| p95 | TBD |
| p99 | TBD |
| error rate | TBD |
| requests/s | TBD |

### Chat turn (`chat_turn_duration`)

| Metric | Value |
|---|---|
| p50 | TBD |
| p95 | TBD |
| p99 | TBD |
| error rate | TBD |
| `chat_turns_degraded` count | TBD |
| `chat_turns_frozen` count | TBD |

### Saturation @ 50 VUs

| Signal | Value |
|---|---|
| PHP-FPM active workers (peak) | TBD |
| PHP-FPM `pm.max_children` headroom | TBD |
| MySQL `Threads_connected` (peak) | TBD |
| openemr container CPU % (peak) | TBD |
| openemr container mem (peak) | TBD |
| Did the run hit the circuit breaker (§3.7)? | TBD |

## Reading these numbers (fill in once captured)

- Does chat p95 at 50 VUs blow the 30s hard turn deadline (§1.3)? If so,
  that is the sizing signal T20 describes — the fix is more PHP-FPM
  workers / a bigger machine type, not application changes.
- Compare 10 VU vs 50 VU PHP-FPM worker saturation: is it linear (healthy —
  purely a worker-count ceiling) or superlinear (a lock contention / DB
  connection-pool problem worth its own investigation before it's assumed
  to be "just" a sizing question)?
- Does `chat_turns_degraded` rise disproportionately at 50 VUs vs 10? That
  would point at LLM-side rate limiting/timeouts under concurrency, not a
  local resource ceiling — a different remediation (raise Vertex quota /
  widen the per-tick budget in §3.7) than adding local workers.
