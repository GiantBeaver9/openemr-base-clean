<?php

/**
 * The deterministic supervisor: routes a request to workers with logged handoffs.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Agent;

use OpenEMR\Modules\ClinicalCopilot\Config\LlmRuntimeConfig;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionClient;
use OpenEMR\Modules\ClinicalCopilot\Observability\TraceRecorder;
use OpenEMR\Modules\ClinicalCopilot\Rag\HybridRetriever;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\LlmClientFactory;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceRecorderInterface;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceSpan;

/**
 * Satisfies the Week 2 "supervisor + 2 workers" requirement with a DETERMINISTIC
 * router rather than an LLM — a deliberate design choice recorded in
 * W2_ARCHITECTURE.md. The route is a pure function of the request's shape
 * ({@see AgentRequest::hasDocument()} / {@see AgentRequest::needsEvidence()}),
 * so an LLM router would add latency, cost, and the "black box" the spec's own
 * pitfalls warn against, buying nothing. Instead the routing is loud and
 * inspectable: the supervisor opens one `supervisor` span, and every worker it
 * invokes records a `worker` child span, so the full handoff graph is
 * reconstructable from the correlation id alone.
 *
 * The supervisor gathers — it does not write. Extracted facts here are for
 * answering; committing them to the chart is the separate, human-gated
 * ingestion lock flow ({@see \OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionReview}).
 */
final class Supervisor
{
    public function __construct(
        private readonly IntakeExtractorWorker $intakeExtractor,
        private readonly EvidenceRetrieverWorker $evidenceRetriever,
        private readonly TraceRecorderInterface $tracer,
    ) {
    }

    public static function createDefault(): self
    {
        $tracer = new TraceRecorder();
        $extractionClient = new ExtractionClient(LlmClientFactory::create(), LlmRuntimeConfig::synthesisModel());

        return new self(
            new IntakeExtractorWorker($extractionClient, $tracer),
            new EvidenceRetrieverWorker(HybridRetriever::createDefault(), $tracer),
            $tracer,
        );
    }

    public function handle(AgentRequest $request): SupervisorResult
    {
        $spanId = TraceSpan::newSpanId();
        $start = new \DateTimeImmutable();
        $t0 = microtime(true);

        $routed = [];
        $extraction = null;
        $evidence = [];

        // Deterministic routing decision — knowable from the request alone.
        if ($request->hasDocument()) {
            $routed[] = WorkerName::IntakeExtractor;
            $outcome = $this->intakeExtractor->run($request, $spanId);
            $extraction = $outcome?->extraction;
        }

        if ($request->needsEvidence()) {
            $routed[] = WorkerName::EvidenceRetriever;
            $evidence = $this->evidenceRetriever->run($request, $spanId);
        }

        $this->tracer->record(new TraceSpan(
            $request->correlationId,
            $spanId,
            null,
            'supervisor',
            $start,
            (int)round((microtime(true) - $t0) * 1000),
            'ok',
            $request->pid,
        ));

        return new SupervisorResult($routed, $extraction, $evidence);
    }
}
