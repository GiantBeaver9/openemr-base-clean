<?php

/**
 * The intake-extractor worker: extracts structured facts from a document.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Agent;

use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionClient;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionOutcome;
use OpenEMR\Modules\ClinicalCopilot\Ingest\SchemaValidationException;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmUnavailableException;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceRecorderInterface;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceSpan;

/**
 * The second Week 2 worker. It wraps the vision {@see ExtractionClient} and
 * records a `worker` span parented to the supervisor span (the inspectable
 * handoff). It degrades exactly like the ingestion path: no model or
 * schema-rejected output returns null (the caller falls back), and the span
 * status reflects which happened — never an exception bubbling through the
 * orchestration. It does NOT write to the chart; the write-back lock flow owns
 * that. Here it only surfaces facts for an answer.
 */
final class IntakeExtractorWorker
{
    public function __construct(
        private readonly ExtractionClient $extractionClient,
        private readonly TraceRecorderInterface $tracer,
    ) {
    }

    public function name(): WorkerName
    {
        return WorkerName::IntakeExtractor;
    }

    public function run(AgentRequest $request, string $parentSpanId): ?ExtractionOutcome
    {
        if (!$request->hasDocument() || $request->docType === null) {
            return null;
        }

        $start = new \DateTimeImmutable();
        $t0 = microtime(true);
        $status = 'ok';
        $outcome = null;

        try {
            $outcome = $this->extractionClient->extract(
                $request->docType,
                (string)$request->documentBytes,
                (string)$request->mimeType,
                'agent',
            );
        } catch (LlmUnavailableException) {
            $status = 'degraded';
        } catch (SchemaValidationException) {
            $status = 'error';
        }

        $this->tracer->record(new TraceSpan(
            $request->correlationId,
            TraceSpan::newSpanId(),
            $parentSpanId,
            'worker',
            $start,
            (int)round((microtime(true) - $t0) * 1000),
            $status,
            $request->pid,
            null,
            null,
            null,
            $outcome?->modelVersion,
            $outcome?->tokensIn,
            $outcome?->tokensOut,
        ));

        return $outcome;
    }
}
