# Clinical Co-Pilot — Build Notes (shared context for all build units)

This file is the **single source of shared engineering context** for building the
`oe-module-clinical-copilot` module. Every build unit reads this first, then its
own unit brief. Keep it accurate; if a host API differs from what's written here,
fix this file.

## What we are building

An **additive** OpenEMR custom module: a pre-visit clinical synthesis co-pilot for
one outpatient endocrinologist. Two surfaces over one deterministic fact layer:
(1) a pre-warmed, cited pre-visit synthesis per scheduled patient; (2) a multi-turn
chat agent pinned to a patient. **The LLM narrates and navigates — it never extracts.**

**Authoritative specs (read the relevant sections for your unit):**
- `USERS.md` (repo root) — target user, UC1–UC6 (source of truth).
- `ARCHITECTURE.md` (repo root) — agent layer: chat §1, verification §2, observability §3, auth/PHI §4, failure model §6.
- `ARCHITECTURE_COMPLETE.md` (repo root) — fact object schema, capabilities, lab contract C1–C4, invariants I1–I13, module tables, **build units U1–U13** (the table of owned files + acceptance criteria).
- `docs/clinical-copilot-tradeoffs.md` (repo root) — decision record T1–T21.

## Non-negotiable invariants (from ARCHITECTURE_COMPLETE.md — do not violate)

- **I2** facts never cached; only narratives cached. **I3/E7** doc & trace stores append-only (no UPDATE/DELETE in code).
- **I5** no silent exclusion — every filtered row becomes a visible `exclusion` fact with citations + reason.
- **I6/I11** degradation absolute — LLM unavailable ⇒ facts + "narrative unavailable"; no unverified prose ever rendered.
- **I9 additivity** — ZERO diff outside `oe-module-clinical-copilot/` (spec docs are the only exception). No writes to any core OpenEMR table, ever. Module-owned `mod_copilot_*` tables only.
- **I10** patient pinning structural — no tool accepts a patient id; executor injects session pid; every fact's pid asserted on return AND re-verified on output (V3).
- **I12** every invocation leaves a trace (correlation id on every span/row/log).
- **I13** the LLM is I/O-less — it emits structured *requests*; module code validates, pins, executes, returns facts.

## LLM platform (decided — T18) — pin these exactly

- Provider: **Google Gemini on Vertex AI** (HIPAA-eligible under GCP BAA; NOT AI-Studio keys).
- Models: synthesis reduce + chat turns = **Gemini 2.5 Pro** (`gemini-2.5-pro`); advisory second-pass reviewer / LLM-judge = **Gemini 2.5 Flash** (`gemini-2.5-flash`). Version strings are **pinned** and folded into `prompt_version` (a digest input).
- Structured output: Vertex `responseMimeType: application/json` + `responseSchema`.
- Auth: GCP service account via `google/auth` ADC — no API keys in code/config.
- Transport: HTTPS via Guzzle to Vertex REST; certificate verification ON.
- **Synthetic patients only this phase** (OPEN-1). Egress redaction: identifiers → per-session pseudonym tokens before any Vertex call, re-hydrated after verification.
- The module must **degrade cleanly with NO credentials configured** (dev/test default): the LLM client detects missing ADC and reports "unavailable" so synthesis renders facts-only and chat becomes a facts browser. All deterministic logic + tests run with no cloud access.

## Namespace, placement, headers

- Root: `interface/modules/custom_modules/oe-module-clinical-copilot/`
- PSR-4: `OpenEMR\Modules\ClinicalCopilot\` → `src/`
- Every PHP file: `declare(strict_types=1);` after the docblock. PER-CS, 4-space indent.
- File header docblock (use verbatim, adjust @author line only if a file has a prior author):

```php
/**
 * <Brief description>
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */
```

## Host APIs — exact signatures (verified against source; use these, do not guess)

**DB — `OpenEMR\Common\Database\QueryUtils`** (static):
- `fetchRecords(string $sql, array $binds = [], bool $noLog = false): array` — SELECT → list of assoc rows.
- `querySingleRow(string $sql, array $params = [], bool $log = true)` — single row assoc.
- `fetchSingleValue(string $sql, string $column, array $binds = [])` — scalar.
- `sqlInsert(string $sql, array $binds = []): int` — INSERT, returns last id.
- `sqlStatementThrowException(string $sql, array $binds = [], bool $noLog = false)` — general stmt, throws on error.
- `startTransaction()/commitTransaction()/rollbackTransaction()`; `inTransaction(callable): mixed`.
- Parameterize with `?`. Never interpolate. Module writes go ONLY to `mod_copilot_*` tables.

**Logging — `OpenEMR\Common\Logging\SystemLogger`** (PSR-3): `new SystemLogger()` then `->error($msg, array $context)`, `->info(...)`, `->debug(...)`. **Never** interpolate variables into the message — use the context array. Always include `correlation_id` in context.

**Audit — `OpenEMR\Common\Logging\EventAuditLogger`**: `EventAuditLogger::instance()->newEvent($event, $user, $groupname, $success, $comments = '', $patient_id = null, $log_from = 'open-emr', $menu_item = 'dashboard')`. For chart-data access use event `patient-record`, action carried in comments with correlation id.

**ACL — `OpenEMR\Common\Acl\AclMain`**: `AclMain::aclCheckCore(string $section, string $value): bool`. Gate every copilot surface with `AclMain::aclCheckCore('patients','med')`. Also register the module's own ACL section (`clinical_copilot`) so a site can grant/deny independently.

**CSRF — `OpenEMR\Common\Csrf\CsrfUtils`**: `CsrfUtils::collectCsrfToken($session, $subject='default'): string` (emit hidden input / header), `CsrfUtils::checkCsrfInput($token, $session, $subject='default')` at top of every POST handler.

**Globals — `OpenEMR\Core\OEGlobalsBag::getInstance()`**: `->getString($k)`, `->getInt($k)`, `->getBoolean($k)`, `->getKernel()`, `->getWebRoot()`, `->getProjectDir()`. Not `$GLOBALS`.

**Session — `SessionWrapperFactory` / `SessionUtil`** for the authed user (`authUserID`). Not raw `$_SESSION`.

**Host clinical services (READ-ONLY use):**
- `OpenEMR\Services\PrescriptionService` — meds; its FHIR MedicationRequest layer already UNIONs `prescriptions` + `lists(type=medication)`. Reuse for MedResponse (T4).
- `OpenEMR\Services\ProcedureService` — procedure orders/reports/results (`activity=1`).
- `OpenEMR\Services\VitalsService` — `form_vitals` (pid-indexed).

**Page bootstrap contract (public/*.php):** set flags (`$ignoreAuth=false`, never set `$sessionAllowWrite` on chat) BEFORE `require_once dirname(__FILE__).'/../../../../globals.php';` (adjust depth), then CSRF → ACL → session identity in that order. Twig autoescape is globally OFF: escape explicitly with `text()`, `attr()`, `xlt()`, `xla()`, `js_escape()` in PHP and `|text |attr |xlt` filters in Twig. PHI only in POST bodies, never URLs/logs/exception messages.

**Background services:** `background_services` row (columns: name, title, active, running, next_run, execute_interval [minutes], function, require_once, sort_order, lock_expires_at). The framework calls `function()` from `require_once` file on logged-in AJAX ticks / cron. A cron entry hitting `library/ajax/execute_background_services.php` every 5 min is a HARD deployment requirement (document it, don't add it to core). Module registers/removes its row in ModuleManagerListener enable/disable/reset — NOT by editing core `sql/database.sql`.

## Module-owned tables (ship in `table.sql`; append-only where noted)

`mod_copilot_doc` (append-only), `mod_copilot_cadence` (config, versioned — module-owned so UPDATE allowed for config only), `mod_copilot_chat_session`, `mod_copilot_chat_turn` (append-only), `mod_copilot_trace` (append-only). Full column lists in ARCHITECTURE_COMPLETE.md "Module-owned tables". Use `#IfNotTable name` / `#EndIf` guards like other modules' install SQL. All must `DROP` cleanly on uninstall (export-before-drop confirmation is the operator's; the SQL just drops `mod_copilot_*`).

## Fact object — the one canonical shape

Every capability output, tool result, digest input, prompt fact, verifier check operates on the single `Fact` shape in ARCHITECTURE_COMPLETE.md ("Fact object"). It ships as **a JSON Schema file** (`src/Fact/schema/fact.schema.json`) beside typed PHP fact objects — the schema is the contract. `fact_id = hash(capability, kind, citations, canonical value)` (value included so a preloaded fact and a corrected re-fetch never collide). `derived_*` facts are computed deterministically by capabilities and cite the raw facts they derive from — this is what lets V4 stay strict while prose says "rose 0.6".

## Testing

- **Isolated (no DB)** — pure logic: fact model, canonical serializer, digest determinism, C3 parsing/censoring, C4 unit conversion, verifier V1–V6 with stub facts, redaction round-trip, prompt-assembly bytes. Put under `tests/` in the module; these are the primary green gate here (host runs them via `composer phpunit-isolated`). Namespace test classes `OpenEMR\Modules\ClinicalCopilot\Tests\`.
- **DB-backed** — LabSlice contract evals, capability known-answer fixtures, digest evals E1–E7, docstore append-only, read-path, chat evals, adversarial evals. Write them even though the full docker stack may not run here; they are part of the deliverable and must be correct.
- **Data providers** carry: `@codeCoverageIgnore Data providers run before coverage instrumentation starts.`
- Every eval documents the failure mode it guards (naming discipline like E1–E7).
- `php -l` must pass on every file. Follow PHPStan level-10 expectations (native types everywhere, no `mixed` unless narrowed, no new baseline entries).

## U12 additions — accuracy agent + telemetry (user decision of record)

The user explicitly wants (a) the advisory accuracy-gauging agent and (b) richer
telemetry to prove the prompts are working. Build these into U12. **Guardrail: the
accuracy agent is ADVISORY, never a blocking gate** — the deterministic verifier
V1–V6 stays the only gate (T15). The agent scores and watches; it never approves.

- **Accuracy agent** = the §2.5 second-pass reviewer: a separate **Gemini Flash**
  instance that re-reads each rendered answer (synthesis + chat turn) against the
  session fact set from scratch and returns a structured verdict
  `{concurs: bool, flags: [{claim_ref, class: emphasis|paraphrase|omission|other, note}]}`.
  Stored on the doc/turn row and in a `verify`-adjacent trace span; feeds the
  dashboard and eval suite. When ADC/LLM is unavailable it degrades silently
  (verdict `unavailable`) — it must never block rendering.
- **Dashboard metrics to add (all from the trace table + the Flash verdict):**
  `reviewer_concurrence_rate`; `salience_score` (reviewer flags a "Salience Failure"
  when a high-priority out-of-range/critical fact is not near the top);
  `narrative_density_ratio` (unique cited clinical entities ÷ narrative length);
  `fact_utilization_rate` / null-data density (% of extracted facts left uncited —
  fluff + capability-tuning signal); `chat_drilldown_rate` (turns needing a tool
  call beyond the preloaded envelope — what the pre-pull couldn't answer). These sit
  beside the already-specced per-check `verification_pass_rate` (V1–V6), cache hit
  rate, p50/p95, cost, and the over-reliance indicators (citation click-through,
  facts-panel opens).
- Deterministic wherever possible: density/utilization/drilldown are pure trace math
  (no LLM). Only concurrence/salience use the Flash verdict, and both are advisory.

## Commits

Conventional Commits, scope `copilot`. One commit per build unit (or per U-pair), e.g.
`feat(copilot): U3 fact model, canonical serializer, and content-address digest`.
Add trailer `Assisted-by: Claude Code`. Do NOT reference model identifiers. Keep all
changes inside the module directory (additivity I9).
