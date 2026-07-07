# Clinical Co-Pilot — Build Status

Agentic build loop over the U1–U13 build units (ARCHITECTURE_COMPLETE.md).
Environment note: this build ran without the Docker dev stack (no `openemr-cmd`,
no DB, no live Vertex). Validation therefore = `php -l` on every file + an
executable pure-PHP isolated test runner (`php tests/run-isolated.php`) for the
deterministic core. DB-backed PHPUnit and live-LLM paths are written to spec and
must be run in-stack (`openemr-cmd clean-sweep-tests`) before deployment.

| Unit | Scope | Status | Verified by |
|---|---|---|---|
| U1 | Module skeleton (composer, bootstrap, table.sql, cleanup, ACL/menu) | ✅ built | php -l |
| U3 | Fact model + canonical serializer + digest | ✅ built + tested | run-isolated.php (FactModelTest) |
| U2 | Seed + fixtures (synthetic diabetes patients w/ landmines) | ✅ built + tested | FixturesTest 71/71 |
| U4 | LabSlice reader (C1–C4 contract, exclusion accounting) | ⏳ | |
| U5 | Capabilities (ControlProxy, MedResponse, VitalsTrend, OverdueTests, PendingResults) | ⏳ | |
| U6 | DocStore (append-only) | ✅ built + tested | DocStoreTest 26/26 |
| U7 | Reduce (Vertex client, prompt assembly, egress redaction, degradation) | ⏳ | |
| U8 | Read path + doc page (facts-first Twig) | ⏳ | |
| U9 | Worker + additivity CI (PHPStan forbidden-write rule) | ⏳ | |
| U10 | Verifier (V1–V6, fail-closed) | ⏳ | |
| U11 | Chat agent (pid-pinned session, tool executor, SSE) | ⏳ | |
| U12 | Observability (traces, dashboard, health/ready, alerts, breakers) | ✅ built + tested | Metrics/Breaker/RateLimiter 58/58 |
| U13 | Ops artifacts (Bruno collection, baseline/load harness) | ⏳ | |

Full isolated suite: **199/199** assertions green · 70 PHP files php -l clean.

Legend: ✅ built & validated · 🟡 built, not fully validated · ⏳ pending

## Known limitations (honest)
- No DB/stack here → DB-backed reads (U4/U5/U6/U8) are written against host
  service/QueryUtils APIs but exercised only via fixture-backed pure-logic tests.
- Live Vertex calls (U7/U11) are stubbed behind an interface; the transport is
  written to the pinned REST contract (T18) but not called against real Vertex.
- Synthetic patients only (OPEN-1). No real PHI.
