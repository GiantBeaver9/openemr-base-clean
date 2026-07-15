# Clinical Co-Pilot — external medical-knowledge store (RAG)

The summarizer grounds its guideline-evidence section with RAG. That evidence
comes from **general medical knowledge** (paraphrased, widely-published guideline
summaries), which is a fundamentally different data class from **PHI** (the
patient chart). This module keeps the two physically separate.

## Two data domains, two databases

| Domain | Store | Holds | Under BAA |
|---|---|---|---|
| **PHI** | OpenEMR's MySQL | Patient chart, extractions, telemetry, chat | Yes |
| **General medical knowledge** | A separate Postgres (e.g. on Railway) | `guideline_chunks` — PHI-free guideline text | Not required |

They are **distinct connections to distinct servers.** A query against the
knowledge Postgres can never reach a chart row, and the retriever that reads the
knowledge store never touches the PHI database. The separation is structural, not
a runtime `WHERE` clause that could be forgotten.

> **The knowledge database must never contain PHI.** Every row is reproducible
> from source control (the in-repo corpus under `src/Rag/corpus/`). If a value
> could identify a patient, it does not belong there.

## How the segregation is enforced (defense in depth)

1. **Separate physical store.** `KnowledgeBaseConnection` is its own PDO to its
   own server, configured by its own env vars. It has no handle on OpenEMR's DB.
2. **Read-only surface.** The request path talks to the store only through
   `KnowledgeQueryRunner::select()` — a single parameterized SELECT. There is no
   write method; the store is populated exclusively by the offline seed script.
3. **PHI-scrubbed queries in transit.** A retrieval query can be a raw chat
   question ("why is Jane's A1c 9.4 on 3/2?"). `KnowledgeQueryScrubber` reduces
   it to non-PHI keywords *before it leaves the process*: it drops names,
   numbers, dates, and emails, and keeps only clinical terms — including analyte
   codes like `a1c`/`b12`/`sglt2` — plus the structured analyte tags (which are
   non-PHI by construction). Nothing patient-identifying reaches the non-BAA store.

## Where it plugs in

Everything rides the existing `RetrieverInterface` seam, so no consumer changed:

```
Supervisor / PatientEvidenceService
  └─ GuidelineRetrieverFactory::createDefault()
       ├─ knowledge Postgres CONFIGURED  → PostgresGuidelineRetriever  (this store)
       └─ NOT configured                 → HybridRetriever  (offline in-repo corpus)
```

The choice is made once, from env. With nothing configured the module behaves
exactly as before — fully offline. Configured-but-unreachable surfaces an honest
"degraded" (empty evidence, visible in the trace and on the dashboard), rather
than silently masking a misconfiguration.

## Setup (one command)

Provision a Postgres, then point the module at it and seed it from the corpus:

```bash
export CLINICAL_COPILOT_KNOWLEDGE_DATABASE_URL="postgresql://user:pass@host:5432/knowledge?sslmode=require"

# Applies schema.sql (idempotent) and upserts every chunk from src/Rag/corpus/*.json.
php ops/knowledge/seed_from_corpus.php
```

`ops/knowledge/schema.sql` creates `guideline_chunks` with a pgvector
`embedding vector(768)` column (HNSW cosine index), a Postgres full-text
`search_vector` (GIN-indexed), and a GIN index over `tags`.

**Retrieval is vector-first.** Each chunk is embedded on write (the Gemini
embeddings API, same key as generation); the query is embedded too (after PHI
scrubbing), and retrieval ranks by pgvector cosine similarity
(`embedding <=> :query`) plus a fixed boost when a chunk's `tags` overlap the
requested analytes. When no embedding key is configured — or a query embed fails,
or no embedded rows match — it falls back to full-text (`ts_rank` over a
`websearch_to_tsquery`, keywords OR-combined for recall). So vector search is an
upgrade layered on top of full-text, never a hard dependency.

Requirements for the live path:
- **pgvector** on the knowledge Postgres (`CREATE EXTENSION vector` — managed
  providers incl. Railway ship it; `schema.sql` runs it).
- **`pdo_pgsql`** on the app container.
- A **Gemini API key** for embeddings (`CLINICAL_COPILOT_GEMINI_API_KEY`); without
  it the store runs on full-text alone.

Without config the module falls back to the offline in-repo corpus.

> Note: `seed_from_corpus.php` loads the in-repo corpus for full-text search but
> does not embed (it runs without the OpenEMR/Gemini runtime). To vector-index the
> corpus, ingest it through the CLI/UI instead, which embeds on write.

## Adding a document (chunk-and-store, no code change)

When a new guideline or reference comes out, push it straight in — no deploy:

**Maintenance → Knowledge Base (RAG)** (top-nav, admin-gated). Upload a
Text / Markdown / HTML / PDF / image, or paste text; set a **source** label
(the citation, and the key that a re-upload replaces), optional title / section /
tags, and the **chunk size** for *this* document (dense tables want small chunks,
prose wants large — it is chosen per upload, not a global constant). Preview the
proposed chunks, then commit.

Or in bulk from the container:

```bash
# one document
openemr-cmd e 'php .../ops/knowledge/ingest_document.php \
    --file=/path/ada-2027.pdf --source="ADA 2027 Addendum" --tags=a1c,lipids --chunk-size=1200'

# a whole folder (source defaults to each file name)
openemr-cmd e 'php .../ops/knowledge/ingest_document.php --dir=/path/guidelines'
```

How it works: **DocumentTextExtractor** gets plain text out (text/markdown/HTML
used directly; PDF/image transcribed via the reused Gemini vision seam — no new
PDF dependency), **DocumentChunker** splits it on heading/paragraph boundaries at
the chosen size with overlap and auto-detects analyte tags, and
**KnowledgeChunkWriter** upserts the chunks in one transaction — replacing the
document's previous chunks by `source` so a corrected version supersedes the old
one. Writes go through a dedicated write path/role; the retrieval path stays
SELECT-only.

## Growing the knowledge base (other paths)

You can also extend the in-repo `src/Rag/corpus/*.json` (keeping it PHI-free and
reproducible) and re-run `seed_from_corpus.php`, or load your own rows into
`guideline_chunks` following the same column shape. Re-running the seed is safe:
rows upsert by `id`.

## Verifying

The observability dashboard shows a **Knowledge store** row:
`connected — N guideline chunks` when the Postgres is reachable, `offline corpus`
when no external DB is configured, or `unreachable` when configured but down.
