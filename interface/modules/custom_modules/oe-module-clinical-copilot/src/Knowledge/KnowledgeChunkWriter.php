<?php

/**
 * Writes chunked knowledge to Postgres — the ONLY writer on the request side.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Knowledge;

use OpenEMR\Modules\ClinicalCopilot\Rag\GuidelineChunk;

/**
 * The write half of knowledge ingestion. It upserts a document's chunks into the
 * same `guideline_chunks` table the seed script and the retriever use, in one
 * transaction, and — by default — first deletes the document's previous chunks by
 * `source`, so re-uploading a corrected guideline cleanly SUPERSEDES the old
 * version rather than leaving stale chunks behind (the chunk count for a shrunk
 * document would otherwise never go down).
 *
 * It is confined to the ingestion flow; the retrieval path has no reference to
 * it. Table identifiers are validated (never bound) exactly as in the retriever.
 */
final class KnowledgeChunkWriter
{
    public function __construct(
        private readonly KnowledgeWriteRunner $runner,
        private readonly string $table = 'guideline_chunks',
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $this->table) !== 1) {
            throw new \DomainException('Invalid knowledge base table name');
        }
    }

    public function isAvailable(): bool
    {
        return $this->runner->isAvailable();
    }

    /**
     * Upsert a document's chunks. When $replaceExisting is true (the default) the
     * document's prior chunks (same `source`) are removed first, all in one
     * transaction. Returns the number of chunks written.
     *
     * @param list<GuidelineChunk> $chunks all sharing one document `source`
     */
    public function write(array $chunks, bool $replaceExisting = true): int
    {
        if ($chunks === []) {
            return 0;
        }
        if (!$this->runner->isAvailable()) {
            throw new \RuntimeException('Knowledge store is not configured or its driver is unavailable');
        }

        $upsert = sprintf(
            'INSERT INTO %s (id, title, source, section, body, tags, url) '
            . 'VALUES (:id, :title, :source, :section, :body, :tags::text[], :url) '
            . 'ON CONFLICT (id) DO UPDATE SET '
            . 'title = EXCLUDED.title, source = EXCLUDED.source, section = EXCLUDED.section, '
            . 'body = EXCLUDED.body, tags = EXCLUDED.tags, url = EXCLUDED.url, updated_at = now()',
            $this->table,
        );

        $this->runner->begin();
        try {
            if ($replaceExisting) {
                $this->runner->execute(
                    sprintf('DELETE FROM %s WHERE source = :source', $this->table),
                    ['source' => $chunks[0]->source],
                );
            }

            $written = 0;
            foreach ($chunks as $chunk) {
                $this->runner->execute($upsert, [
                    'id' => $chunk->id,
                    'title' => $chunk->title,
                    'source' => $chunk->source,
                    'section' => $chunk->section,
                    'body' => $chunk->text,
                    'tags' => $this->pgTextArray($chunk->tags),
                    'url' => $chunk->url,
                ]);
                $written++;
            }

            $this->runner->commit();

            return $written;
        } catch (\Throwable $e) {
            $this->runner->rollback();

            throw $e;
        }
    }

    /**
     * @param list<string> $values
     */
    private function pgTextArray(array $values): string
    {
        if ($values === []) {
            return '{}';
        }

        $quoted = array_map(
            static fn (string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"',
            $values,
        );

        return '{' . implode(',', $quoted) . '}';
    }
}
