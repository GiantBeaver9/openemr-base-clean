-- Clinical Co-Pilot -- external medical-knowledge store (Postgres) schema.
--
-- This database is SEPARATE from OpenEMR's MySQL by design. OpenEMR's DB holds
-- PHI (the patient chart, extractions, telemetry) under the BAA; THIS database
-- holds only general medical knowledge -- paraphrased, widely-published
-- guideline summaries with no patient-identifying data. Keeping them on distinct
-- servers makes the segregation physical: a query here can never reach a chart
-- row, and the RAG retriever that reads here never touches the PHI database.
--
-- >>> DO NOT INSERT PHI INTO THIS DATABASE. <<<
-- Every row is reproducible from source control (the in-repo corpus under
-- src/Rag/corpus/). If a value could identify a patient, it does not belong here.
--
-- Apply once against the Railway Postgres:
--   psql "$CLINICAL_COPILOT_KNOWLEDGE_DATABASE_URL" -f schema.sql
-- Then load the corpus:
--   php ops/knowledge/seed_from_corpus.php

CREATE TABLE IF NOT EXISTS guideline_chunks (
    id       text PRIMARY KEY,
    title    text NOT NULL DEFAULT '',
    source   text NOT NULL,
    section  text NOT NULL DEFAULT '',
    body     text NOT NULL,
    tags     text[] NOT NULL DEFAULT '{}',
    url      text,
    -- Full-text index column, maintained by Postgres from the human-readable
    -- fields. title/section are weighted above body so a topic match ranks first.
    search_vector tsvector GENERATED ALWAYS AS (
        setweight(to_tsvector('english', coalesce(title, '')), 'A')
        || setweight(to_tsvector('english', coalesce(section, '')), 'B')
        || setweight(to_tsvector('english', coalesce(body, '')), 'C')
    ) STORED,
    updated_at timestamptz NOT NULL DEFAULT now()
);

-- GIN over the tsvector powers the `@@ websearch_to_tsquery(...)` retrieval.
CREATE INDEX IF NOT EXISTS guideline_chunks_search_idx
    ON guideline_chunks USING GIN (search_vector);

-- GIN over tags powers the `tags && ARRAY[...]` analyte-overlap boost.
CREATE INDEX IF NOT EXISTS guideline_chunks_tags_idx
    ON guideline_chunks USING GIN (tags);

COMMENT ON TABLE guideline_chunks IS
    'General medical-knowledge corpus for RAG. PHI-FREE by policy -- never insert patient data.';
