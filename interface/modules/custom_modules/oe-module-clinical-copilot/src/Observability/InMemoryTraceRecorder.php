<?php

/**
 * InMemoryTraceRecorder — append-only span sink for isolated tests (no DB).
 *
 * Lets pure-logic units record spans and lets tests assert "every path left a trace"
 * (I12) and reconstruct order — without the framework.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

final class InMemoryTraceRecorder implements TraceRecorder
{
    /** @var list<Span> */
    private array $spans = [];

    public function start(
        string $correlationId,
        TraceKind $kind,
        string $startedAt,
        ?string $parentSpanId = null,
        ?int $pid = null,
        ?int $userId = null,
    ): Span {
        return new Span($correlationId, CorrelationId::spanId(), $parentSpanId, $kind, $startedAt, $pid, $userId);
    }

    public function record(Span $span): void
    {
        $this->spans[] = $span;
    }

    /**
     * @return list<Span>
     */
    public function spans(): array
    {
        return $this->spans;
    }

    /**
     * @return list<Span>
     */
    public function byCorrelation(string $correlationId): array
    {
        return array_values(array_filter(
            $this->spans,
            static fn(Span $s): bool => $s->correlationId === $correlationId,
        ));
    }
}
