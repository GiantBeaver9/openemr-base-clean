# Clinical Co-Pilot — CI gates (U9)

Two gates the module ships with, both runnable from the repo root.

## 1. Additivity diff gate (`additivity-diff-gate.sh`)

Enforces module additivity (I9): the module is a self-contained directory and must never modify host
code. The gate fails if any changed file falls outside the module directory, except the four spec
documents the module co-authors at repo root (`ARCHITECTURE.md`, `ARCHITECTURE_COMPLETE.md`,
`USERS.md`, `docs/clinical-copilot-tradeoffs.md`).

```bash
interface/modules/custom_modules/oe-module-clinical-copilot/ci/additivity-diff-gate.sh origin/master
```

The base ref defaults to `origin/master`. It compares `<base-ref>...HEAD` (three-dot: only commits on
this branch). Exit `0` = clean, `1` = a path escaped the module boundary (offenders printed to
stderr), `2` = usage / bad base ref.

## 2. Read-only write gate (`phpstan/`)

This is **layer 1 of three** read-only-enforcement layers (ARCHITECTURE.md §4):

1. **`ForbiddenWriteRule` (this gate).** A module-scoped PHPStan rule (mirroring the host's
   `tests/PHPStan/Rules/*` pattern) that fails on every write API — `QueryUtils::sqlInsert()` and the
   global `sqlInsert()`; `sqlStatement*` / `sqlStatementThrowException()` carrying an
   `INSERT/UPDATE/DELETE/REPLACE` literal; host-service `insert()/update()/delete()` — called from
   any class **except** the whitelisted `mod_copilot_*` persistence classes (`DocStore`,
   `Doc\DbDocGateway`, `Observability\DbTraceWriter`, `Observability\CircuitBreakerStore`,
   `Observability\CadenceConfigStore`, `Chat\DbSessionGateway`, and `tests/Seed/`). The rule
   self-scopes to files under `oe-module-clinical-copilot/`, so it is a no-op on host code and safe to
   fold into a full-codebase run.

   ```bash
   vendor/bin/phpstan analyse \
     -c interface/modules/custom_modules/oe-module-clinical-copilot/ci/phpstan/phpstan-module.neon
   ```

2. **SELECT-only MySQL user (deploy step).** Capability reads run on a dedicated MySQL account granted
   only `SELECT` on core clinical tables (with `INSERT/UPDATE/DELETE` on `mod_copilot_*` only), so even
   a defect that slips past layer 1 cannot write a core table. This is a **deployment requirement**,
   configured at the database, not in module code — provision a role such as:

   ```sql
   -- Reads over the whole schema, writes confined to the module's own tables.
   GRANT SELECT ON `openemr`.* TO 'copilot_ro'@'%';
   GRANT INSERT, UPDATE, DELETE ON `openemr`.`mod_copilot_%` TO 'copilot_ro'@'%';
   ```

   (Adjust database name / host grant to the site.) The module's read path uses this account; the
   append-only ledger writes it does make all target `mod_copilot_*` tables.

3. **LLM egress redaction.** Direct identifiers are tokenized before any Vertex call and re-hydrated
   after verification (`src/Reduce/EgressRedactor.php`) — the third layer, in the module itself.

### Notes

- The PHPStan config runs from the repo root so Composer's autoloader resolves both host and module
  classes. It analyses only `src/`; add `../../tests` locally if you want the rule exercised over the
  seed/test tree too (the seed path is whitelisted).
- `ForbiddenWriteRule` lives under `ci/phpstan/` (not `src/`) so it ships with the module but is not
  loaded at runtime — it is a build-time analysis rule, bootstrapped by the neon.
