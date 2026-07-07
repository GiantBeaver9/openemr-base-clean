<?php

/**
 * DbTraceWriter — append-only span sink backed by mod_copilot_trace (I12, T16).
 *
 * PHI-bearing payloads live behind payload_ref in this same MySQL protection domain,
 * never shipped to a third-party observability SaaS (T16). This class only ever INSERTs
 * — there is deliberately no UPDATE/DELETE path (append-only, mirrors the doc ledger T7).
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

final class DbTraceWriter implements TraceRecorder
{
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
        $row = $span->toRow();
        $sql = "INSERT INTO mod_copilot_trace
            (correlation_id, span_id, parent_span_id, kind, started_at, duration_ms, status,
             error_class, error_detail, model, tokens_in, tokens_out, cost_usd, pid, user_id, payload_ref)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        QueryUtils::sqlStatementThrowException($sql, [
            $row['correlation_id'],
            $row['span_id'],
            $row['parent_span_id'],
            $row['kind'],
            $row['started_at'],
            $row['duration_ms'],
            $row['status'],
            $row['error_class'],
            $row['error_detail'],
            $row['model'],
            $row['tokens_in'],
            $row['tokens_out'],
            $row['cost_usd'],
            $row['pid'],
            $row['user_id'],
            $row['payload_ref'],
        ]);
    }
}
