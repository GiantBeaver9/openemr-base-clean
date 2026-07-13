<?php

/**
 * Orchestrates one upload: store the source, extract, persist a draft, (intake) create the patient.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ingest;

use OpenEMR\Modules\ClinicalCopilot\ReadPath\NullTraceRecorder;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceRecorderInterface;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\TraceSpan;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmUnavailableException;

/**
 * The Week 2 `attach_and_extract(patient_id, file, doc_type)` tool. It never
 * commits derived facts to the chart itself — that is the lock step
 * ({@see ExtractionReview}) via {@see ChartWriter}. The one exception is intake:
 * a patient must exist before we can navigate to their page, so intake creates
 * the patient at upload from the model's best-guess demographics; the physician
 * then verifies/corrects on that patient's page before locking.
 *
 * Everything degrades: no model configured ({@see LlmUnavailableException}) or
 * model output that fails the strict schema ({@see SchemaValidationException})
 * both fall back to a blank draft the physician hand-fills — the flow never
 * dead-ends on a missing or misbehaving model. The correlation id threads
 * through the ingest span, the vision_extract child span, and (later) the
 * chart_commit span, so the whole ingestion is one reconstructable trace.
 */
final class AttachAndExtract
{
    public function __construct(
        private readonly ExtractionClient $extractionClient,
        private readonly ExtractionStore $store,
        private readonly ChartWriter $chartWriter,
        private readonly int $documentCategoryId,
        private readonly TraceRecorderInterface $tracer = new NullTraceRecorder(),
    ) {
    }

    /**
     * Intake: create the patient from the extracted demographics, store the
     * source, and persist the draft. Returns the new pid + extraction id.
     */
    public function ingestIntake(
        string $bytes,
        string $filename,
        string $mimeType,
        string $correlationId,
        int $userId,
    ): IngestResult {
        $span = $this->openSpan($correlationId, null, 'ingest', 0);
        [$outcome, $visionUsed, $schemaRejected] = $this->tryExtract(
            DocType::IntakeForm,
            $bytes,
            $mimeType,
            $correlationId,
            $span,
            0,
        );

        $demographics = $this->demographicsFrom($outcome);
        $pid = $this->chartWriter->createPatientFromIntake($demographics);

        $extractionId = $this->persistDraft($pid, DocType::IntakeForm, $outcome, $visionUsed, $correlationId, $userId);
        $this->storeSource($pid, $filename, $mimeType, $bytes, $extractionId);

        $this->closeSpan($span, $pid, $visionUsed, $outcome);

        return new IngestResult($pid, $extractionId, DocType::IntakeForm, $visionUsed, $schemaRejected);
    }

    /**
     * Labs: existing patient. Store the source, extract, persist the draft. No
     * chart write here — results reach `procedure_result` only on lock.
     */
    public function ingestLab(
        int $pid,
        string $bytes,
        string $filename,
        string $mimeType,
        string $correlationId,
        int $userId,
    ): IngestResult {
        $span = $this->openSpan($correlationId, null, 'ingest', $pid);
        [$outcome, $visionUsed, $schemaRejected] = $this->tryExtract(
            DocType::LabPdf,
            $bytes,
            $mimeType,
            $correlationId,
            $span,
            $pid,
        );

        $extractionId = $this->persistDraft($pid, DocType::LabPdf, $outcome, $visionUsed, $correlationId, $userId);
        $this->storeSource($pid, $filename, $mimeType, $bytes, $extractionId);

        $this->closeSpan($span, $pid, $visionUsed, $outcome);

        return new IngestResult($pid, $extractionId, DocType::LabPdf, $visionUsed, $schemaRejected);
    }

    /**
     * Manual-entry start: an empty draft for a lab tab with no PDF. The
     * physician adds result rows in the review UI, then locks.
     */
    public function startManualLab(int $pid, string $correlationId, int $userId): IngestResult
    {
        $blank = ExtractionSchema::blankExtraction(DocType::LabPdf);
        $extractionId = $this->persistDraft($pid, DocType::LabPdf, $this->outcome($blank, null), false, $correlationId, $userId);

        return new IngestResult($pid, $extractionId, DocType::LabPdf, false, false);
    }

    /**
     * @return array{0: ExtractionOutcome, 1: bool, 2: bool} [outcome, visionUsed, schemaRejected]
     */
    private function tryExtract(
        DocType $docType,
        string $bytes,
        string $mimeType,
        string $correlationId,
        TraceSpan $parent,
        int $pid,
    ): array {
        $childStart = new \DateTimeImmutable();
        $t0 = microtime(true);
        try {
            $outcome = $this->extractionClient->extract($docType, $bytes, $mimeType, 'upload');
            $this->recordChild($parent, $correlationId, 'vision_extract', $childStart, $t0, 'ok', $pid, $outcome);

            return [$outcome, true, false];
        } catch (LlmUnavailableException $e) {
            $this->recordChild($parent, $correlationId, 'vision_extract', $childStart, $t0, 'degraded', $pid, null);

            return [$this->outcome(ExtractionSchema::blankExtraction($docType), null), false, false];
        } catch (SchemaValidationException $e) {
            $this->recordChild($parent, $correlationId, 'vision_extract', $childStart, $t0, 'error', $pid, null);

            return [$this->outcome(ExtractionSchema::blankExtraction($docType), null), false, true];
        }
    }

    private function persistDraft(
        int $pid,
        DocType $docType,
        ExtractionOutcome $outcome,
        bool $visionUsed,
        string $correlationId,
        int $userId,
    ): int {
        $extractionId = $this->store->insertHeader(
            pid: $pid,
            docType: $docType,
            sourceDocumentId: null,
            correlationId: $correlationId,
            model: $visionUsed ? $outcome->modelVersion : null,
            promptVersion: $visionUsed ? $outcome->promptVersion : null,
            latencyMs: $visionUsed ? $outcome->latencyMs : null,
            tokensIn: $visionUsed ? $outcome->tokensIn : null,
            tokensOut: $visionUsed ? $outcome->tokensOut : null,
            costUsd: null,
            createdBy: $userId,
        );

        foreach ($outcome->extraction->fields as $field) {
            $this->store->insertField($extractionId, $field);
        }

        return $extractionId;
    }

    private function storeSource(int $pid, string $filename, string $mimeType, string $bytes, int $extractionId): void
    {
        if ($bytes === '') {
            return;
        }

        $documentId = $this->chartWriter->storeSourceDocument(
            $pid,
            $this->documentCategoryId,
            $filename,
            $mimeType,
            $bytes,
            $extractionId,
        );

        if ($documentId !== null) {
            $this->store->setSourceDocument($extractionId, $documentId);
        }
    }

    /**
     * @param array<string, string|null> $unused
     *
     * @return array<string, string|null>
     */
    private function demographicsFrom(ExtractionOutcome $outcome): array
    {
        $out = [];
        foreach ($outcome->extraction->fields as $field) {
            $out[$field->fieldKey] = $field->value;
        }

        return $out;
    }

    private function outcome(ParsedExtraction $extraction, ?string $model): ExtractionOutcome
    {
        return new ExtractionOutcome($extraction, $model ?? '', '', 0, 0, 0);
    }

    private function openSpan(string $correlationId, ?string $parentSpanId, string $kind, int $pid): TraceSpan
    {
        return new TraceSpan(
            $correlationId,
            TraceSpan::newSpanId(),
            $parentSpanId,
            $kind,
            new \DateTimeImmutable(),
            null,
            'ok',
            $pid,
        );
    }

    private function closeSpan(TraceSpan $span, int $pid, bool $visionUsed, ExtractionOutcome $outcome): void
    {
        $this->tracer->record($span);
    }

    private function recordChild(
        TraceSpan $parent,
        string $correlationId,
        string $kind,
        \DateTimeImmutable $start,
        float $t0,
        string $status,
        int $pid,
        ?ExtractionOutcome $outcome,
    ): void {
        $durationMs = (int)round((microtime(true) - $t0) * 1000);
        $this->tracer->record(new TraceSpan(
            $correlationId,
            TraceSpan::newSpanId(),
            $parent->spanId,
            $kind,
            $start,
            $durationMs,
            $status,
            $pid,
            null,
            null,
            null,
            $outcome?->modelVersion,
            $outcome?->tokensIn,
            $outcome?->tokensOut,
        ));
    }
}
