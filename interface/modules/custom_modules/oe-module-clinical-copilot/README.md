# Clinical Co-Pilot (`oe-module-clinical-copilot`)

An AI clinical co-pilot embedded in this OpenEMR fork for one deliberately narrow
user: an outpatient endocrinologist reviewing type-2-diabetes follow-ups before clinic.
Two surfaces over one machinery: a **pre-warmed, cited pre-visit synthesis** per
scheduled patient, and a **multi-turn chat agent pinned to that patient**.

> **Synthetic patients only** (OPEN-1). No real PHI. See `../../../ARCHITECTURE.md` §4.

## Design corpus (repo root)
- [`USERS.md`](../../../USERS.md) — target user + use cases UC1–UC6 (source of truth)
- [`AUDIT.md`](../../../AUDIT.md) — pre-build code-level audit (the hard gate)
- [`ARCHITECTURE.md`](../../../ARCHITECTURE.md) — one-page + agent layer (chat, verification, observability)
- [`ARCHITECTURE_COMPLETE.md`](../../../ARCHITECTURE_COMPLETE.md) — full build spec, fact layer, build units U1–U13
- [`docs/clinical-copilot-tradeoffs.md`](../../../docs/clinical-copilot-tradeoffs.md) — decision record T1–T21
- [`CONTRACTS.md`](CONTRACTS.md) — the build contract every unit conforms to
- [`BUILD_STATUS.md`](BUILD_STATUS.md) — what's built, tested, and what needs the stack

## The four load-bearing decisions
1. **The LLM narrates and navigates; it never extracts.** Five deterministic
   capabilities (`src/Capability/`) read the chart through typed facts + citations
   (`src/Fact/`). The model writes prose over facts it is handed and *requests* which
   capability to run next — it parses no rows and adjudicates no conflicts.
2. **Verification is a deterministic gate** (`src/Verify/`, V1–V6), not prompt
   discipline: every claim must cite a fact that exists, every number must be grounded,
   every cited fact's pid must equal the session's pinned patient, banned claim classes
   are rejected. Fail-closed → facts-only.
3. **Patient pinning is structural** (`src/Chat/`): the session binds one pid
   server-side; no tool accepts a patient argument; every returned fact's pid is
   asserted on ingest and re-verified on output.
4. **Freshness by content-addressing** (`src/Fact/Digest.php`): facts are recomputed
   every read and never cached; only narratives are cached, keyed by a digest of the
   facts. Serving prose over stale facts is structurally unreachable.

## Layout
```
src/Fact/         fact model, canonical serializer, digest (the spine)
src/Lab/          LabSlice — the C1–C4 lab-quality contract
src/Capability/   the five capabilities + CapabilityFactory wiring
src/Reduce/       Vertex LLM client, prompt assembly, egress redaction, degradation
src/Verify/       the V1–V6 verification gate
src/DocStore.php  append-only content-addressed doc ledger
src/Read/         synthesis read path
src/Chat/         pid-pinned chat session, tool executor, agent loop
src/Observability/ correlation ids, trace spans, metrics, dashboard, alerts, breakers
src/Worker.php    background warm-sweep + on-tick alert evaluation
public/           doc.php · chat.php · status.php · health.php · ready.php · dashboard.php
templates/        Twig (autoescape OFF — explicit |text/|attr/xlt everywhere)
schema/           fact.schema.json, claim.schema.json (contracts)
tests/            isolated runner + Unit tests + synthetic Fixtures + Seed
ops/              Bruno API collection + load/baseline harness
ci/               additivity diff-gate + module-scoped PHPStan forbidden-write rule
```

## Testing
```bash
# pure-logic isolated suite (no DB, bare php) — 570/570
php tests/run-isolated.php
php tests/run-isolated.php <SuiteNameSubstring>   # filter to one suite

# in the dev stack (DB-backed + code quality):
openemr-cmd clean-sweep-tests
openemr-cmd code-quality          # incl. PHPStan level 10 + the module ForbiddenWriteRule
```

## Install
Enable via the Module Manager (runs `table.sql` → the five `mod_copilot_*` tables +
the `background_services` warm row). Uninstall runs `cleanup.sql` and drops only
module-owned state (I9); it confirms + offers export-before-drop because the ledgers
are the provenance record (T7 / OPEN-2). Configure Vertex + the worker cron per
`BUILD_STATUS.md`'s deploy checklist.
