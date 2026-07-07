<?php

/**
 * FixtureTraceReader — in-memory TraceReader for isolated tests (no DB).
 *
 * Constructed with a list of span rows (same shape as mod_copilot_trace / Span::toRow),
 * it lets the dashboard aggregation and the alert/breaker windows be tested without the
 * framework. Mirrors TraceQuery's semantics: window filter + descending order, per-
 * correlation rollups, ordered waterfall, single-span lookup.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

final class FixtureTraceReader implements TraceReader
{
    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(private readonly array $rows)
    {
    }

    public function windowSpans(string $sinceIso, int $limit = 5000): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            if ((string) ($row['started_at'] ?? '') >= $sinceIso) {
                $out[] = $row;
            }
        }
        usort($out, static fn(array $a, array $b): int => (string) ($b['started_at'] ?? '') <=> (string) ($a['started_at'] ?? ''));
        return array_slice($out, 0, max(1, $limit));
    }

    public function requestList(string $sinceIso, ?string $kind = null, int $limit = 200): array
    {
        $groups = [];
        foreach ($this->windowSpans($sinceIso, PHP_INT_MAX) as $row) {
            if ($kind !== null && $kind !== '' && (string) ($row['kind'] ?? '') !== $kind) {
                continue;
            }
            $cid = (string) ($row['correlation_id'] ?? '');
            if ($cid === '') {
                continue;
            }
            if (!isset($groups[$cid])) {
                $groups[$cid] = [
                    'correlation_id' => $cid,
                    'started_at' => (string) ($row['started_at'] ?? ''),
                    'span_count' => 0,
                    'errors' => 0,
                    'total_ms' => 0,
                    'cost_usd' => 0.0,
                    'pid' => $row['pid'] ?? null,
                    'user_id' => $row['user_id'] ?? null,
                ];
            }
            $groups[$cid]['span_count']++;
            if ((string) ($row['status'] ?? '') === SpanStatus::Error->value) {
                $groups[$cid]['errors']++;
            }
            $groups[$cid]['total_ms'] += (int) ($row['duration_ms'] ?? 0);
            $groups[$cid]['cost_usd'] += (float) ($row['cost_usd'] ?? 0.0);
            $start = (string) ($row['started_at'] ?? '');
            if ($start !== '' && $start < $groups[$cid]['started_at']) {
                $groups[$cid]['started_at'] = $start;
            }
        }
        $list = array_values($groups);
        usort($list, static fn(array $a, array $b): int => $b['started_at'] <=> $a['started_at']);
        return array_slice($list, 0, max(1, $limit));
    }

    public function waterfall(string $correlationId): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            if ((string) ($row['correlation_id'] ?? '') === $correlationId) {
                $out[] = $row;
            }
        }
        usort($out, static fn(array $a, array $b): int => (string) ($a['started_at'] ?? '') <=> (string) ($b['started_at'] ?? ''));
        return $out;
    }

    public function span(string $spanId): ?array
    {
        foreach ($this->rows as $row) {
            if ((string) ($row['span_id'] ?? '') === $spanId) {
                return $row;
            }
        }
        return null;
    }
}
