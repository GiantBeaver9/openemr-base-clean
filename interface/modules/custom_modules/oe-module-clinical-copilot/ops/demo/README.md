# End-to-end demo (`ops/demo/`)

`run-demo.php` drives the whole Week 2 flow as one narrated run — **no dev
stack, no DB, no network, no OpenEMR core** — and writes the console output to
`transcript.txt` so the run is reviewable without re-executing it.

It exists to answer the specific gap that the Week 2 demo *did not* show
verification, observability metrics, or eval results. This run shows all three,
plus ingestion→extraction→citation, guideline retrieval, and measured cost.

```bash
php ops/demo/run-demo.php
```

## What it demonstrates, in order

1. **Ingest + extract** — a scanned lab PDF (stub VLM) becomes strict-schema,
   per-field-cited typed facts (FR-1/2/3/4); a malformed document is correctly
   **refused** at the schema gate (safe_refusal, no partial write).
2. **Verify** — the read-path discipline: a grounded claim set passes V1–V6; a
   wrong-patient claim trips **V3 (sev-1, chat frozen)**; an uncited lab value
   is caught by **V2**. This is the "verification" the demo was missing.
3. **Retrieve** — hybrid RAG returns cited guideline evidence, kept separate
   from patient-fact citations (FR-8).
4. **Observability** — the metric bag + full alert evaluation for a healthy and
   an incident trace population (via `../load/bench/dashboard-demo.php`), with
   four alerts firing under the incident. Rendered dashboards land in
   `../load/bench/results/dashboard-{healthy,incident}.html`.
5. **Eval gate** — the 50-case boolean-rubric HARD GATE (`../eval/run-evals.php`).
6. **Cost** — measured per-call cost tied to the real prompt sizes
   (`../load/bench/measure-tokens.php`).

The full-stack variant (driving the real UI through Selenium against the dev
stack) is described in the top-level `README.md` / `CLAUDE.md`; this script is
the stack-free version that runs anywhere and produces a durable transcript.
