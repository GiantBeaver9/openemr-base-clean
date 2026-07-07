<?php

/**
 * TraceRecorder — the append-only sink for spans (I12: every path writes spans).
 *
 * Two implementations: DbTraceWriter (mod_copilot_trace via QueryUtils) for runtime and
 * InMemoryTraceRecorder for isolated tests. The interface is intentionally write-only
 * plus a start helper — reads for the dashboard go through a separate query path so this
 * stays a pure sink (the store is the observability source of truth).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

interface TraceRecorder
{
    /**
     * Open a span. `startedAt` is passed in (never read from a clock here) so the read
     * path can stamp it deterministically and tests can assert ordering.
     */
    public function start(
        string $correlationId,
        TraceKind $kind,
        string $startedAt,
        ?string $parentSpanId = null,
        ?int $pid = null,
        ?int $userId = null,
    ): Span;

    /**
     * Persist a closed span. Append-only: implementations must never UPDATE or DELETE.
     */
    public function record(Span $span): void;
}
