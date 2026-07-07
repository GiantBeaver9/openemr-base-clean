<?php

/**
 * TraceQuery — DB-backed TraceReader over mod_copilot_trace (§3.3, R4).
 *
 * Read-only: every query is a parameterized SELECT through the host QueryUtils; this
 * class never writes (writes go through the append-only DbTraceWriter). It powers the
 * dashboard's click-through: window rollups for the tiles, per-correlation rollups for
 * the request list, the ordered span waterfall, and a single span for the payload view.
 * `$limit` is clamped and inlined as a validated integer (LIMIT is not a bindable value).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

use OpenEMR\Common\Database\QueryUtils;

final class TraceQuery implements TraceReader
{
    private const MAX_LIMIT = 5000;

    public function windowSpans(string $sinceIso, int $limit = 5000): array
    {
        $limit = $this->clampLimit($limit);
        $sql = "SELECT correlation_id, span_id, parent_span_id, kind, started_at, duration_ms,
                       status, error_class, error_detail, model, tokens_in, tokens_out,
                       cost_usd, pid, user_id, payload_ref
                FROM mod_copilot_trace
                WHERE started_at >= ?
                ORDER BY started_at DESC
                LIMIT " . $limit;
        return $this->rows(QueryUtils::fetchRecords($sql, [$sinceIso]));
    }

    public function requestList(string $sinceIso, ?string $kind = null, int $limit = 200): array
    {
        $limit = $this->clampLimit($limit);
        $binds = [$sinceIso];
        $kindClause = '';
        if ($kind !== null && $kind !== '') {
            $kindClause = ' AND kind = ?';
            $binds[] = $kind;
        }
        $sql = "SELECT correlation_id,
                       MIN(started_at) AS started_at,
                       COUNT(*) AS span_count,
                       SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) AS errors,
                       SUM(duration_ms) AS total_ms,
                       SUM(cost_usd) AS cost_usd,
                       MAX(pid) AS pid,
                       MAX(user_id) AS user_id
                FROM mod_copilot_trace
                WHERE started_at >= ?" . $kindClause . "
                GROUP BY correlation_id
                ORDER BY started_at DESC
                LIMIT " . $limit;
        return $this->rows(QueryUtils::fetchRecords($sql, $binds));
    }

    public function waterfall(string $correlationId): array
    {
        $sql = "SELECT correlation_id, span_id, parent_span_id, kind, started_at, duration_ms,
                       status, error_class, error_detail, model, tokens_in, tokens_out,
                       cost_usd, pid, user_id, payload_ref
                FROM mod_copilot_trace
                WHERE correlation_id = ?
                ORDER BY started_at ASC, id ASC";
        return $this->rows(QueryUtils::fetchRecords($sql, [$correlationId]));
    }

    public function span(string $spanId): ?array
    {
        $sql = "SELECT correlation_id, span_id, parent_span_id, kind, started_at, duration_ms,
                       status, error_class, error_detail, model, tokens_in, tokens_out,
                       cost_usd, pid, user_id, payload_ref
                FROM mod_copilot_trace
                WHERE span_id = ?
                LIMIT 1";
        $rows = $this->rows(QueryUtils::fetchRecords($sql, [$spanId]));
        return $rows[0] ?? null;
    }

    private function clampLimit(int $limit): int
    {
        if ($limit < 1) {
            return 1;
        }
        return min($limit, self::MAX_LIMIT);
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    private function rows(array $records): array
    {
        return array_values($records);
    }
}
