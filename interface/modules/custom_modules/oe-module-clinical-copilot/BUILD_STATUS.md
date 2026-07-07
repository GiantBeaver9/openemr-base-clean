# Clinical Co-Pilot — Build Status

Agentic build loop over the U1–U13 build units (ARCHITECTURE_COMPLETE.md), built
in dependency waves. **All 13 units complete.**

Environment note: this build ran **without the Docker dev stack** (no `openemr-cmd`,
no MySQL, no live Vertex). Validation therefore = `php -l` on every file + an
executable **pure-PHP isolated test runner** (`php tests/run-isolated.php`) for the
deterministic and pure-logic cores, plus a cross-unit integration smoke test. The
DB-backed PHPUnit suites and live-LLM/E2E paths are written to spec and must be run
in-stack (`openemr-cmd clean-sweep-tests`) before deployment.

## Headline signal
- **183 PHP files, all `php -l` clean.**
- **570/570 isolated assertions passing** (15 pure-logic + integration suites).
- **Additivity (I9) proven:** `ci/additivity-diff-gate.sh origin/main` → OK, all changed
  paths within the module boundary + the co-pilot design docs.

| Unit | Scope | Status | Verified by |
|---|---|---|---|
| U1 | Module skeleton (composer, bootstrap, table.sql, cleanup, ACL/menu) | ✅ | php -l |
| U3 | Fact model + canonical serializer + digest | ✅ | FactModelTest |
| U12 | Observability (trace spine + Metrics/Breaker/RateLimiter/Alerts/Dashboard/health/ready) | ✅ | Observability/Metrics/CircuitBreaker/RateLimiter/AlertEvaluator/TraceReader |
| U6 | DocStore (append-only) | ✅ | DocStoreTest 26/26 |
| U2 | Seed + fixtures (synthetic diabetes patients w/ 12 landmines) | ✅ | FixturesTest 71/71 |
| U7 | Reduce (Vertex client, prompt assembly, egress redaction, degradation) | ✅ | ReduceTest 38/38 |
| U4 | LabSlice reader (C1–C4 contract, exclusion accounting) | ✅ | LabSliceTest 94/94 |
| U10 | Verifier (V1–V6, fail-closed) | ✅ | VerifierTest 57/57 |
| U5 | Capabilities (ControlProxy, MedResponse, VitalsTrend, OverdueTests, PendingResults) | ✅ | CapabilitiesTest 63/63 |
| — | CapabilityFactory + SynthesisVersions (shared wiring) | ✅ | CapabilityFactoryTest 7/7 |
| U8 | Read path + doc page (facts-first Twig) | ✅ | ReadPathTest 40/40 |
| U11 | Chat agent (pid-pinned session, tool executor, SSE) | ✅ | ChatAgentTest 47/47 |
| U9 | Worker + additivity CI (PHPStan forbidden-write rule) | ✅ | WorkerTest 25/25 + diff-gate |
| U13 | Ops artifacts (Bruno collection, baseline/load harness) | ✅ | JSON/bash/node validated |

## What is proven here vs. what needs the stack
**Proven now (pure PHP, no DB):** the entire deterministic spine — fact model, digest
(content-addressed freshness, E5/E6), the full lab contract C1–C4 (censoring,
supersession both variants, no-unit-no-math, mmol/mol conversion, visible exclusions),
all five capabilities incl. derived facts, verifier V1–V6 (wrong-number/wrong-patient/
uncited/causation all blocked), reduce degradation + egress redaction round-trip,
append-only DocStore, read-path digest cache + capability-crash rule, chat pinning +
tool-executor pid injection + adversarial refusals, observability metrics/breaker/limits,
worker warm-only-on-miss + budget. End-to-end via CapabilityFactory smoke test.

**Needs the dev stack before deploy (written, `php -l` clean, not runnable here):**
- DB-backed readers (`Db*` classes), QueryUtils joins, the seed runner.
- Controllers/pages that bootstrap `interface/globals.php` (CSRF/ACL/audit/Twig render).
- Live Vertex calls (VertexClient — needs GCP ADC creds); everything is exercised here
  through `StubLlmClient`.
- The PHPStan ForbiddenWriteRule (needs the vendor tree) and the Bruno/load runs.

## Deploy checklist (before any real use — still synthetic-only, OPEN-1)
1. `composer install` (module autoload), enable via Module Manager (runs `table.sql`).
2. Seed synthetic patients: `tests/Seed/SeedRunner.php` in-stack.
3. Configure Vertex: set `clinical_copilot_vertex_project` + service-account ADC.
4. Add the cron for `library/ajax/execute_background_services.php` (worker + alerts).
5. Run `openemr-cmd clean-sweep-tests` + `openemr-cmd code-quality` (PHPStan level 10).
6. `ops/bruno` collection green; capture `ops/RESULTS.md` baselines + 10/50-user load.
7. Deploy on a SELECT-only MySQL user for capability reads (read-only enforcement layer 2).

## Honest limitations
- Synthetic patients only (OPEN-1). No real PHI. Egress redaction is minimization, not
  de-identification (quasi-identifiers remain).
- Verifier catches uncited/ungrounded/wrong-patient/banned-lexical claims; it does NOT
  catch misleading emphasis, paraphrased banned claims, or omission beyond the closed
  conflict set (§2.4) — bounded by facts-first rendering + the advisory second-pass reviewer.
