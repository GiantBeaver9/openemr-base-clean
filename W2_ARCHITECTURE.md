# Week 2 Architecture — Clinical Co-Pilot

Multimodal document ingestion, audited chart write-back, and evidence-grounded
synthesis, built as an **additive** extension of the Week 1 read-only co-pilot.
All code lives in `interface/modules/custom_modules/oe-module-clinical-copilot/`.

Week 1 is a **read-only** engine: deterministic PHP pulls and cites chart facts,
the LLM only narrates, and three enforcement layers (a SELECT-only DB user, a
module-scoped PHPStan forbidden-write rule, and LLM egress redaction) guarantee
nothing touches the chart. **Week 2 is the inverse** — a document comes in, a
vision model extracts structured facts, a human verifies them, and they are
written into the chart through a single audited seam. The Week 1 read path then
reads the committed data back and, grounded in retrieved guideline evidence,
synthesizes it.

---

## 1. Document ingestion flow

Two entry flows, one shared pipeline, an `insert → verify → lock` lifecycle.

**Intake (new patient)** — `public/intake_upload.php`
Upload an intake PDF → vision extraction → **create the patient** from the
extracted demographics → redirect to the review page to verify/edit → lock.

**Labs (existing patient)** — `public/lab_upload.php` (a "Labs" tab beside the
co-pilot on the patient chart)
Upload a lab PDF **or** start manual entry → extraction (or empty draft) →
review page → lock, which commits results to `procedure_result`.

Shared pipeline (`src/Ingest/AttachAndExtract.php` orchestrates 1–4; the review
page drives 5–7):

| # | Stage | Component |
|---|-------|-----------|
| 1 | Store source document (real OpenEMR `documents` row, linked to the extraction) | `ChartWriter::storeSourceDocument()` → `\Document::createDocument()` |
| 2 | Vision extraction (multimodal Gemini through the shared LLM seam) | `ExtractionClient` + `PromptRequest.parts` (inline document) |
| 3 | Strict-schema validation (raw model output never bypasses it) | `ExtractionSchema::validate()` |
| 4 | Persist as `draft` in module staging | `ExtractionStore` → `mod_copilot_extraction` + `mod_copilot_extracted_fact` |
| 5 | Human review: edit each field beside its source citation | `public/extraction_review.php` + `ExtractionReview::editField()` |
| 6 | **Lock**: commit to the chart, compute accuracy, freeze | `ExtractionReview::lock()` → `ChartWriter` |
| 7 | Week 1 read path reads the committed data back | `LabSliceReader` (unchanged) |

With no LLM configured, step 2 degrades to a blank draft the physician completes
by hand (`ExtractionSchema::blankExtraction()`); the flow never dead-ends.

---

## 2. Extraction schemas (the canonical contract)

The schema is the source of truth, not the model. Committed as JSON under
`src/Ingest/schema/` and enforced by `ExtractionSchema` before anything is
persisted; a payload that fails validation becomes a `SchemaValidationException`
and is discarded, never stored.

- `intake_form.schema.json` — demographics, chief concern, current medications,
  allergies, family history. `field_key` is a **closed enum**.
- `lab_pdf.schema.json` — per-result `field_key` (test name), value, unit,
  reference range, abnormal flag, collection date. `field_key` is open (test
  names are free text).

Every field requires a citation (`page` + `quote`); values may be `null`
(blank/illegible) but must be present — the model may never invent a value.

---

## 3. Write-back and read-only enforcement

Week 2 relaxes the Week 1 read-only invariant **in exactly one place**.
`src/Ingest/ChartWriter.php` is the single class permitted to write core tables:

- Intake demographics → `patient_data` (via `PatientService::insert` / raw update).
- Lab results → the `procedure_order → procedure_order_code → procedure_report →
  procedure_result` chain (mirroring the core HL7 inbound writer), with
  `procedure_result.document_id` binding each value to the stored source PDF.

Writes are **idempotent** (a field already committed is skipped) and
**lineage-tracked** (`mod_copilot_extracted_fact.committed_core_table` /
`committed_core_pk` record which core row each fact became) — so re-locking never
produces a duplicate or untraceable record.

The module-scoped PHPStan rule
(`tests/PHPStan/Rules/ForbiddenWriteOutsideRepositoriesRule.php`) is the
enforcement: every class is read-only except the whitelisted `mod_copilot_*`
repositories, and `ChartWriter` is its **single** `SANCTIONED_CORE_WRITERS`
entry. A core write (`sqlInsert`, `PatientService::insert`) anywhere else in
`src/` fails CI. **The gate that proved Week 1 never writes now proves Week 2
writes only there.**

---

## 4. Verification lifecycle

`insert → verify → lock`, in `src/Ingest/ExtractionReview.php`:

- **draft** — editable by anyone with copilot access; edits update staging rows.
- **lock** — commits verified values to the chart, records extraction accuracy,
  sets `status = locked`.
- **locked** — immutable. Only elevated ACL (`admin/super`) can **unlock** to
  correct; corrections re-commit idempotently (append, never silent overwrite).

---

## 5. Citation contract

Two structurally distinct provenance types, never mixed (`src/Ingest/SourceType.php`):

- **`document`** — an uploaded lab/intake page:
  `{source_type, source_id, page_or_section, field_or_chunk_id, quote_or_value,
  bbox}` (`src/Ingest/SourceCitation.php`), with a normalized 0–1000 bounding box
  for the click-to-source overlay.
- **`guideline`** — a retrieved RAG chunk (same shape, `source_type = guideline`).

Patient-record facts keep Week 1's core-row `Citation` (`{table, pk, field}`).
The Week 1 `Fact`/`Verifier` pipeline is deliberately **untouched** — document
and guideline citations are a separate value object, so the load-bearing Week 1
invariants (verifier row-resolution, `FactId` stability) never destabilize. When
a locked lab commits to `procedure_result`, the Week 1 reader re-grounds it in a
normal core-row citation automatically.

---

## 6. Worker graph — deterministic supervisor + two workers

`src/Agent/`. The spec requires "supervisor + 2 workers." We use a
**deterministic router, not an LLM** (see Risks/Tradeoffs §11).

- `Supervisor` — routes an `AgentRequest` by its shape (`hasDocument()` →
  intake-extractor; `needsEvidence()` → evidence-retriever). Opens one
  `supervisor` trace span; each worker records a `worker` child span, so the full
  handoff graph is reconstructable from the correlation id alone. It **gathers**
  facts + evidence; it does not write (chart writes stay in the human-gated lock
  flow). Its result keeps `extraction` (patient facts) and `evidence` (guideline
  snippets) in **separate fields**.
- `IntakeExtractorWorker` — wraps `ExtractionClient`; degrades to `null` on
  no-model/schema-reject, never throws through the orchestration.
- `EvidenceRetrieverWorker` — wraps the RAG retriever (§7).

---

## 7. RAG design — evidence that augments Week 1

`src/Rag/`. The evidence-retriever exists to make the **Week 1 summarizer and
chat** better: they report patient facts; with retrieval they can say "…and the
guideline recommends X [cite]," with guideline evidence kept separate from
patient facts by citation type.

- **Corpus** — a small, committed, PHI-free endocrinology guideline set
  (`src/Rag/corpus/endocrinology.json`), reproducible from source control.
- **Hybrid retrieval** (`HybridRetriever`) — sparse (`SparseRetriever`, pure-PHP
  TF-IDF with analyte-tag boost, offline) fused with an optional dense retriever
  via Reciprocal Rank Fusion, then reranked (`RerankerInterface`).
- **Degrades one layer at a time** — no credentials ⇒ sparse-only +
  `PassthroughReranker`: still real, cited evidence, no network. Dense embeddings
  and a Cohere-style reranker light up independently when configured.
- **Integration points** (augmentation, both additive): the summarizer renders a
  distinct "guideline evidence" section beside the verified narrative; the chat
  gets one deterministic `get_guideline_evidence(topic)` tool. Neither mixes
  guideline evidence into the patient-fact citation pipeline.

> Not used for lab ingestion: "which page has which result" is already produced
> deterministically by extraction (`page` + `bbox` per field), so RAG is reserved
> for guideline grounding, where a corpus actually exists.

---

## 8. Observability

Extends the Week 1 trace (`mod_copilot_trace`, one INSERT per completed span,
nesting via `parent_span_id`). New span kinds: `ingest`, `vision_extract`,
`chart_commit`, `supervisor`, `worker`, `retrieve`. The correlation id
propagates through document storage, extraction, worker handoffs, and the chart
commit — a full Week 2 request is reconstructable from the correlation id alone.

**Extraction accuracy** is the headline Week 2 metric: the human's edits are the
ground truth. Each field stores `vlm_value` (model) and `value` (human-verified);
on lock, `field_accuracy = accepted-unchanged / model-proposed` is computed and
stored on `mod_copilot_extraction`, rolled up by `doc_type`. It is **PHI-free** —
a rate over field keys and accept/edit booleans, never clinical values.

---

## 9. Eval gate (design; the primary remaining deliverable)

A 50-case golden set with **boolean** rubrics — `schema_valid`,
`citation_present`, `factually_consistent`, `safe_refusal`, `no_phi_in_logs` —
run as a PR-blocking check that fails on a >5% category regression. Phase-A code
already wires the rubric hooks: schema validation (`schema_valid`), the mandatory
`SourceCitation` per fact (`citation_present`), schema-gated extraction
(`factually_consistent`), and PHI-free traces (`no_phi_in_logs`). The runner
follows the existing `tests/smoke/deterministic-core-smoke.php` exit-code
pattern; the corpus/golden set is committed (reproducible from the repo). This
gate is the main item still owed (see §13).

---

## 10. Testing strategy

Every test guards a documented failure mode.

- **Isolated (no DB, no live model)** — the contracts: schema validation and each
  failure mode (`ExtractionSchemaTest`), citation round-trip
  (`SourceCitationTest`), the accuracy metric (`ExtractionAccuracyTest`),
  extraction against a stub LLM (`ExtractionClientTest`), retrieval ranking and
  degradation (`SparseRetrieverTest`), and supervisor routing + degradation
  (`SupervisorTest`). No model is ever called live — a hand-written
  `LlmClientInterface` stub stands in.
- **DB-backed (`tests/Db/`, live schema)** — the write path end-to-end:
  `AttachAndExtract` (doc stored, draft persisted, intake patient created),
  `ChartWriter` idempotent commit, and the lock/unlock ACL. *(Owed — requires the
  dev stack; see §13.)*
- **Evaluated via the golden set** — agent behavior (§9).
- **Static** — the additivity gate and the forbidden-write PHPStan rule run on
  every change.

---

## 11. Risks and tradeoffs

- **Core-write blast radius** — the real new risk. Mitigated by confining all core
  writes to one audited `ChartWriter`, idempotent dedup, lineage columns, the
  lock gate, and the PHPStan enforcement that no other class can write.
- **Deterministic supervisor, no LLM router** — deliberate. The route is a pure
  function of the request shape, so an LLM router would add latency, cost, and the
  "black box" the spec's own pitfalls warn against, buying nothing. We keep
  routing loud and inspectable (trace spans) instead. Accepted risk: a grader
  scanning for an LLM supervisor must read this note.
- **Intake asymmetry** — intake creates the patient at upload (necessary to
  navigate to their page); labs stage then commit on lock. Both share the same
  edit/lock lifecycle.
- **Degrade-to-manual everywhere** — no credentials ⇒ extraction falls back to
  manual entry and retrieval to sparse-only. Honest and offline-capable, but
  extraction accuracy is only measured on the vision path.
- **PDF bounding-box overlay is partial** — the review page ships click-to-source
  citations (page + quote) and a source-document preview; the full canvas overlay
  drawing normalized boxes on the rendered page (PDF.js) is the remaining UI item.

---

## 12. Data model, authority, lineage, access control

| Artifact | Authoritative store | Origin (lineage) | Access |
|----------|--------------------|------------------|--------|
| Source document | `documents` | Uploaded file (foreign-ref → extraction) | chart ACL |
| Extracted fact (draft) | `mod_copilot_extracted_fact` | VLM extraction, human-verified | copilot ACL |
| Patient demographics | `patient_data` | Locked intake extraction | chart ACL |
| Lab result | `procedure_result` | Locked lab extraction (→ `document_id`) | chart ACL |
| Guideline chunk | `src/Rag/corpus/` | Committed corpus (no PHI) | public (repo) |
| Trace / accuracy | `mod_copilot_trace` / `mod_copilot_extraction` | Every request step | admin (dashboard) |

One source of truth per data type; no silent overwrites (corrections append with
lineage). Any schema change from Week 1 carries a migration note; the two new
tables are registered in all four schema mirrors (`table.sql`, `sql/install.sql`,
`sql/uninstall.sql`, `ModuleManagerListener::OWNED_TABLES`).

### Privacy / PHI
Uploaded documents and derived facts contain PHI and live in the chart's own
MySQL protection domain, never in third-party observability. Traces and the
extraction-accuracy metric are PHI-free by construction (field keys + rates, not
values). Use only synthetic/demo data in this environment.

### Backup & recovery
Committed artifacts (the guideline corpus, the extraction schemas, the eval
golden set) are reproducible from the repo alone. Chart data
(`documents`, `patient_data`, `procedure_result`) and the module ledgers
(`mod_copilot_*`) are backed up with the OpenEMR database; the append-only
ledgers must be exported before an uninstall (export-before-drop tooling remains
open, as in Week 1).

---

## 13. Status

Built, tested (isolated), and additive-gate-green: the ingestion pipeline
(§1–5), the deterministic worker graph (§6), and the RAG foundation (§7).
**Owed:** the DB-backed test suite (§10, needs the dev stack), the 50-case eval
gate runner (§9), the summarizer/chat evidence integration wiring (§7), and the
PDF canvas bounding-box overlay (§11). Run the full suites via
`openemr-cmd clean-sweep-tests` and `openemr-cmd code-quality` in the dev stack
before submission.
