# Clinical Co-Pilot — Build Contracts (authoritative)

Every build unit conforms to this. It is the single source of truth for names,
signatures, and conventions so independently-built units stay coherent. Specs:
`ARCHITECTURE.md`, `ARCHITECTURE_COMPLETE.md`, `USERS.md`, `docs/clinical-copilot-tradeoffs.md`
(all at repo root).

## Names
- Namespace root: `OpenEMR\Modules\ClinicalCopilot\` → `src/`
- Module dir: `interface/modules/custom_modules/oe-module-clinical-copilot/`
- Module class: `OpenEMR\Modules\ClinicalCopilot\Bootstrap` (const `MODULE_NAME`, `ACL_SECTION='patients'`, `ACL_SUBSECTION='med'`)

## Coding conventions (house rules, non-negotiable)
- `declare(strict_types=1)` at top of every PHP file; PSR-4; 4-space indent; file-header docblock (GPL-3).
- Native types on every property/param/return. Enums for closed sets. `readonly` for value objects.
- DB reads via host `OpenEMR\Common\Database\QueryUtils` (parameterized `?` binds). NEVER build SQL by concatenation.
- Module is **read-only** to all core tables (I10/T6). Writes go ONLY to `mod_copilot_*` tables through module repositories.
- Twig autoescape is OFF globally: escape every sink with `|text` / `|attr` / `xlt`-family. Never `|raw` on model/user data.
- CSRF on every POST (`CsrfUtils::checkCsrfInput`); ACL at page top (`AclMain::aclCheckCore('patients','med')`).
- PSR-3 logging via context arrays, never string interpolation. Catch `\Throwable`, never `\Exception`. Never expose `$e->getMessage()` to users.
- Every new PHP file must pass `php -l`. Run it before reporting done.

## Already built — DO NOT redefine (import and use)

### Fact model (`src/Fact/`) — U3, complete
- `Capability` (enum: control_proxy, med_response, vitals_trend, overdue_tests, pending_results)
- `FactKind` (enum; `->hasValue()`, `->isDerived()`)
- `FactStatus` (enum; `->supersessionRank():int`, `->resetsOverdueClock():bool`)
- `Comparator` (enum; `::fromToken(string)`, `->isCensored()`)
- `DateSource` (enum: collected, fallback)
- `ExclusionReason` (enum: no_unit, unparseable_value, unperformed_status, unrecognized_status, non_numeric_type, soft_deleted, superseded)
- `Flag` (const CONFLICT/CENSORED/OUT_OF_RANGE_BY_VALUE/OUT_OF_RANGE_BY_LAB_FLAG; `::superseded(int)`, `::excludedReason(ExclusionReason)`) → flags are `list<string>` tokens on a Fact
- `Citation(string $table, int $pk, ?string $field, DateSource $dateSource)` → `->toCanonical()`
- `FactValue(string $raw, ?float $parsed, Comparator $comparator, string $unitOriginal, ?string $unitCanonical, ?string $conversionVersion)` → `->isQuantitative()`, `->toCanonical()`
- `Fact(Capability, string $capabilityVersion, FactKind, int $pid, ?string $clinicalDate, DateSource, ?FactValue, FactStatus, list<string> $flags, list<Citation> $citations)`
  - computes `->factId` (readonly), throws if zero citations. `->toCanonical()`, `->hasFlag()`, `->isConflict()`, `->isExclusion()`.
- `FactSet(int $pid, list<Fact> $facts)` — asserts all facts share pid (I10). `->withFacts(list)`, `->findById(string)`, `->conflicts()`, `->count()`, `->isEmpty()`.
- `CanonicalSerializer` → `->canonicalize(list<Fact>): list<array>`, `->serialize(list<Fact>): string` (pure, deterministic).
- `VersionBundle(array $capabilityVersions, string $cadenceVersion, string $codeSetVersion, string $docType, string $promptVersion)` → `->toCanonical()`
- `Digest(?CanonicalSerializer)` → `->compute(list<Fact>, VersionBundle): string`, `->computeForSet(FactSet, VersionBundle): string` (sha3-256, no timestamps).

### Module skeleton — U1, complete
- `composer.json`, `info.txt`, `version.php`, `openemr.bootstrap.php`, `moduleConfig.php` (TODO if referenced), `ModuleManagerListener.php`, `table.sql`, `cleanup.sql`, `src/Bootstrap.php`, `src/GlobalConfig.php`, `schema/fact.schema.json`.

## Module tables (see `table.sql`)
`mod_copilot_doc`, `mod_copilot_cadence`, `mod_copilot_chat_session`, `mod_copilot_chat_turn`, `mod_copilot_trace`.
Column lists are authoritative in `table.sql` — match them exactly.

## Host classes you MAY use (verified to exist in this fork)
- `OpenEMR\Common\Database\QueryUtils` — `::fetchRecords($sql, $binds)`, `::fetchTableColumn(...)`, etc.
- `OpenEMR\Services\PrescriptionService` (meds — exposes `getAll($search)`; the meds union T4), `OpenEMR\Services\ProcedureService` (use `search()` not `getAll()` — P2 N+1), `OpenEMR\Services\VitalsService` (`search()`). These are DB-backed; wrap them behind a thin module reader so pure-logic can be isolated-tested with fixture rows.
- `OpenEMR\Common\Acl\AclMain::aclCheckCore('patients','med')`
- `OpenEMR\Common\Csrf\CsrfUtils::collectCsrfToken()` / `::checkCsrfInput()`
- `OpenEMR\Common\Logging\SystemLogger` (PSR-3), `OpenEMR\Common\Logging\EventAuditLogger`
- `OpenEMR\Common\Session\SessionUtil`, `SessionWrapperFactory`; `authUserID` for identity
- `OpenEMR\Common\Twig\TwigContainer`
- Correlation/span ids: U12 owns `OpenEMR\Modules\ClinicalCopilot\Observability\CorrelationId::mint(): string` (UUIDv7 from `random_bytes`). Everything else calls that — do NOT invent per-unit id schemes. (UuidRegistry has no simple v4 generator; don't use it for this.)

## Test gates
- Pure-logic units: add a `tests/Unit/<Name>Test.php` exposing `function clinical_copilot_test_<Name>Test(): void` using the `Assert` helper; run `php tests/run-isolated.php`.
- DB-backed / framework units: write PHPUnit tests under `tests/` (run in-stack via `openemr-cmd`), but ALSO keep pure-logic cores isolated-testable where possible.
- Every PHP file must `php -l` clean.

## Per-unit file ownership (disjoint)
- U2 `tests/Seed/`, `tests/Fixtures/`
- U4 `src/Lab/`
- U5 `src/Capability/`
- U6 `src/DocStore.php`
- U7 `src/Reduce/`
- U8 `src/Controller/DocController.php`, `templates/doc*`, `public/doc.php`
- U9 `src/Worker.php`, `src/worker_entry.php`, `ci/`
- U10 `src/Verify/`
- U11 `src/Chat/`, `src/Controller/ChatController.php`, `templates/chat*`, `public/chat.php`, `public/status.php`
- U12 `src/Observability/`, `templates/dashboard*`, `public/health.php`, `public/ready.php`, `public/dashboard.php`
- U13 `ops/`
