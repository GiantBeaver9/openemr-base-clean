<?php

/**
 * Span — one node in a trace. Nests via parent_span_id (a chat turn parents its tool
 * calls, LLM calls, and verify span, so "what happened, in what order" is one query).
 *
 * A span is mutable during its lifetime (you open it, then close it with a status and
 * duration) but is written to the append-only trace store exactly once, on close.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

final class Span
{
    public SpanStatus $status = SpanStatus::Ok;
    public ?int $durationMs = null;
    public ?string $errorClass = null;
    public ?string $errorDetail = null;   // never PHI, never raw exception text shown to users
    public ?string $model = null;
    public ?int $tokensIn = null;
    public ?int $tokensOut = null;
    public ?float $costUsd = null;
    public ?string $payloadRef = null;

    public function __construct(
        public readonly string $correlationId,
        public readonly string $spanId,
        public readonly ?string $parentSpanId,
        public readonly TraceKind $kind,
        public readonly string $startedAt,   // ISO-8601 with millis
        public readonly ?int $pid = null,
        public readonly ?int $userId = null,
    ) {
    }

    public function close(SpanStatus $status, ?int $durationMs = null): self
    {
        $this->status = $status;
        $this->durationMs = $durationMs;
        return $this;
    }

    public function failWith(\Throwable $e, ?int $durationMs = null): self
    {
        $this->status = SpanStatus::Error;
        $this->errorClass = $e::class;
        $this->errorDetail = 'see trace payload'; // detail stays behind payload_ref; never inline PHI
        $this->durationMs = $durationMs;
        return $this;
    }

    /**
     * @return array<string, mixed> row shape for mod_copilot_trace (bind order defined by TraceWriter)
     */
    public function toRow(): array
    {
        return [
            'correlation_id' => $this->correlationId,
            'span_id' => $this->spanId,
            'parent_span_id' => $this->parentSpanId,
            'kind' => $this->kind->value,
            'started_at' => $this->startedAt,
            'duration_ms' => $this->durationMs,
            'status' => $this->status->value,
            'error_class' => $this->errorClass,
            'error_detail' => $this->errorDetail,
            'model' => $this->model,
            'tokens_in' => $this->tokensIn,
            'tokens_out' => $this->tokensOut,
            'cost_usd' => $this->costUsd,
            'pid' => $this->pid,
            'user_id' => $this->userId,
            'payload_ref' => $this->payloadRef,
        ];
    }
}
