# Clinical Co-Pilot — Baseline & Load Results (R8, R9)

> **Status: NOT YET RUN — placeholders only.**
> Every cell below is a placeholder (`—`). No numbers here are real. These
> tables are committed empty on purpose (ARCHITECTURE.md §3.6): the harness
> ships with the module, and the numbers are **captured in-stack via
> `openemr-cmd`** against the deployed environment, then filled in and
> committed alongside the eval dataset. Do not fabricate values — run the
> scripts in `ops/load/` and paste the measured output.
>
> **Synthetic patients only (OPEN-1).** All runs use synthetic patients; no
> real PHI is involved in any measurement here.

## Environment (fill in when captured)

| Field | Value |
|-------|-------|
| Date captured | — |
| Git commit | — |
| Host machine type (GCE) | — |
| vCPU / RAM | — |
| PHP-FPM workers configured | — |
| MySQL max_connections | — |
| App URL / stack | — |
| Warm PID / Cold PID (synthetic) | — / — |
| Captured via | `openemr-cmd` (in-stack) |

## Baselines — single user (R8)

Captured with `ops/load/baseline.sh`. Latency is per-request; CPU/mem is an
app-container sample taken during the scenario. Tool-call counts are nominal
(prompt intent) — confirm the actual count from the turn's trace.

| Scenario | med latency | max latency | CPU % (app) | Mem (app) | HTTP codes | Notes |
|----------|------------:|------------:|------------:|----------:|------------|-------|
| Synthesis — warm (`doc.php`) | — | — | — | — | — | already-warmed patient |
| Synthesis — cold (`doc.php`) | — | — | — | — | — | read-time generation (I7) |
| Chat — 0 tool calls | — | — | — | — | — | greeting/refusal path |
| Chat — 1 tool call | — | — | — | — | — | single-capability lookup |
| Chat — 3 tool calls | — | — | — | — | — | labs + vitals + meds |

## Load — 10 concurrent users (R9)

Captured with `ops/load/load.js` (k6, `VUS=10`) or `ops/load/load.sh`
(`VUS=10`). Report per path.

| Path | requests | error rate | p50 | p95 | p99 | max |
|------|---------:|-----------:|----:|----:|----:|----:|
| Doc (warm) | — | — | — | — | — | — |
| Chat turn | — | — | — | — | — | — |

Saturation at 10 VUs:

| Metric | Value |
|--------|-------|
| Peak app CPU % | — |
| Peak app RAM | — |
| PHP-FPM busy workers (peak) | — |
| MySQL active connections (peak) | — |

## Load — 50 concurrent users (R9)

Captured with `ops/load/load.js` (k6, `VUS=50`) or `ops/load/load.sh`
(`VUS=50`). Report per path.

| Path | requests | error rate | p50 | p95 | p99 | max |
|------|---------:|-----------:|----:|----:|----:|----:|
| Doc (warm) | — | — | — | — | — | — |
| Chat turn | — | — | — | — | — | — |

Saturation at 50 VUs:

| Metric | Value |
|--------|-------|
| Peak app CPU % | — |
| Peak app RAM | — |
| PHP-FPM busy workers (peak) | — |
| MySQL active connections (peak) | — |

## Sizing note (vertical-first, T20 / §3.6)

Once captured, the 10→50 saturation deltas give the worker/RAM sizing per N
users. The app scales **vertically** (a bigger GCE machine type), not with app
replicas — each open chat connection holds a PHP worker for the turn's
duration, so concurrency is bought with worker count, which is bought with
cores and RAM. Record the observed workers-per-N-users here so future changes
are measured against it.

| Observation | Value |
|-------------|-------|
| Workers held per concurrent chat turn | — |
| Est. max concurrent chat turns on this machine type | — |
| First resize trigger (metric + threshold) | — |
