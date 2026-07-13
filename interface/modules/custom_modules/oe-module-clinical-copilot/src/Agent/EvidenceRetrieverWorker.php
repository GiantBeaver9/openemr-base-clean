<?php

/**
 * The evidence-retriever worker: retrieves cited guideline evidence for a request.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Agent;

use OpenEMR\Modules\ClinicalCopilot\Rag\EvidenceSnippet;
use OpenEMR\Modules\ClinicalCopilot\Rag\RetrieverInterface;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceRecorderInterface;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceSpan;

/**
 * One of the two Week 2 workers. It wraps the RAG retriever and records its
 * invocation as a `worker` span whose parent is the supervisor span — that is
 * the inspectable handoff. Its output (guideline {@see EvidenceSnippet}s) is
 * kept structurally separate from patient-record facts by its `SourceType::Guideline`
 * citations, so the assembled answer never conflates the two evidence classes.
 */
final class EvidenceRetrieverWorker
{
    public function __construct(
        private readonly RetrieverInterface $retriever,
        private readonly TraceRecorderInterface $tracer,
    ) {
    }

    public function name(): WorkerName
    {
        return WorkerName::EvidenceRetriever;
    }

    /**
     * @return list<EvidenceSnippet>
     */
    public function run(AgentRequest $request, string $parentSpanId, int $topK = 4): array
    {
        $start = new \DateTimeImmutable();
        $t0 = microtime(true);

        $snippets = $this->retriever->retrieve($request->evidenceQuery(), $request->tags, $topK);

        $this->tracer->record(new TraceSpan(
            $request->correlationId,
            TraceSpan::newSpanId(),
            $parentSpanId,
            'worker',
            $start,
            (int)round((microtime(true) - $t0) * 1000),
            $snippets === [] ? 'degraded' : 'ok',
            $request->pid,
        ));

        return $snippets;
    }
}
