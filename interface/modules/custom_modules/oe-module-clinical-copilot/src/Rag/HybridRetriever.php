<?php

/**
 * Hybrid retrieval: fuse sparse + (optional) dense candidates, then rerank.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Rag;

/**
 * The Week 2 "keyword + dense retrieval, rerank the candidates" requirement,
 * built to degrade one layer at a time rather than all-or-nothing:
 *
 *  - Always: sparse retrieval ({@see SparseRetriever}, offline).
 *  - When a dense retriever is configured (embeddings behind credentials): fuse
 *    sparse + dense with Reciprocal Rank Fusion — order-based, so it needs no
 *    score calibration between two very different scoring schemes.
 *  - Then rerank ({@see RerankerInterface}); {@see PassthroughReranker} is the
 *    no-credentials default.
 *
 * So with nothing configured this is sparse-only with a passthrough rerank —
 * real, cited evidence, no network — and each capability (dense, then rerank)
 * lights up independently as credentials are added. That is what keeps the
 * summarizer/chat augmentation working in every environment.
 */
final class HybridRetriever implements RetrieverInterface
{
    /** Reciprocal Rank Fusion constant; 60 is the standard default. */
    private const RRF_K = 60;

    public function __construct(
        private readonly RetrieverInterface $sparse,
        private readonly RerankerInterface $reranker,
        private readonly ?RetrieverInterface $dense = null,
    ) {
    }

    public static function createDefault(): self
    {
        return new self(
            new SparseRetriever(GuidelineCorpus::createDefault()),
            new PassthroughReranker(),
            null,
        );
    }

    /**
     * @param list<string> $tags
     *
     * @return list<EvidenceSnippet>
     */
    public function retrieve(string $query, array $tags = [], int $topK = 4): array
    {
        // Over-fetch candidates so the reranker has something to work with.
        $candidateK = max($topK * 3, 8);
        $sparseHits = $this->sparse->retrieve($query, $tags, $candidateK);

        if ($this->dense === null) {
            return $this->reranker->rerank($query, $sparseHits, $topK);
        }

        $denseHits = $this->dense->retrieve($query, $tags, $candidateK);
        $fused = $this->reciprocalRankFusion($sparseHits, $denseHits);

        return $this->reranker->rerank($query, array_slice($fused, 0, $candidateK), $topK);
    }

    /**
     * Merge two ranked lists by chunk id; each list contributes 1/(k + rank).
     *
     * @param list<EvidenceSnippet> $a
     * @param list<EvidenceSnippet> $b
     *
     * @return list<EvidenceSnippet>
     */
    private function reciprocalRankFusion(array $a, array $b): array
    {
        /** @var array<string, array{snippet: EvidenceSnippet, score: float, order: int}> $byId */
        $byId = [];
        $order = 0;

        foreach ([$a, $b] as $list) {
            foreach (array_values($list) as $rank => $snippet) {
                $id = $snippet->chunk->id;
                $contribution = 1.0 / (self::RRF_K + $rank + 1);
                if (isset($byId[$id])) {
                    $byId[$id]['score'] += $contribution;
                } else {
                    $byId[$id] = ['snippet' => $snippet, 'score' => $contribution, 'order' => $order++];
                }
            }
        }

        $rows = array_values($byId);
        usort($rows, static function (array $x, array $y): int {
            return $y['score'] <=> $x['score'] ?: $x['order'] <=> $y['order'];
        });

        return array_map(
            static fn (array $row): EvidenceSnippet => $row['snippet']->withScore($row['score']),
            $rows,
        );
    }
}
