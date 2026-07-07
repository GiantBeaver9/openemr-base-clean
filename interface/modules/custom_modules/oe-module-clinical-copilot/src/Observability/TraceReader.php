<?php

/**
 * TraceReader — the read side of the trace store, behind an interface (I12, §3.3).
 *
 * The append-only TraceRecorder writes spans; this reads them back for the dashboard.
 * Two impls: TraceQuery (mod_copilot_trace via QueryUtils) and FixtureTraceReader
 * (in-memory rows) so the dashboard's aggregation is isolated-testable without a DB.
 * Every method returns plain span rows / rollups — Metrics does the maths.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

interface TraceReader
{
    /**
     * All spans started at/after $sinceIso (a DATETIME(3) string), newest first, capped.
     * Feeds Metrics::summary for the dashboard tiles.
     *
     * @return list<array<string, mixed>>
     */
    public function windowSpans(string $sinceIso, int $limit = 5000): array;

    /**
     * One rollup row per correlation_id in the window (the tile → request-list step).
     * Optionally restricted to correlations touching a given kind.
     *
     * @return list<array<string, mixed>> each: correlation_id, started_at, span_count,
     *         errors, total_ms, cost_usd, pid, user_id
     */
    public function requestList(string $sinceIso, ?string $kind = null, int $limit = 200): array;

    /**
     * Every span for one correlation id, ordered by started_at then id — the waterfall.
     *
     * @return list<array<string, mixed>>
     */
    public function waterfall(string $correlationId): array;

    /**
     * A single span row by span id (the payload drill-down), or null if absent.
     *
     * @return array<string, mixed>|null
     */
    public function span(string $spanId): ?array;
}
