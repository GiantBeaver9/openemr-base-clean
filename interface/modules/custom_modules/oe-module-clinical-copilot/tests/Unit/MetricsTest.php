<?php

/**
 * Isolated tests for Metrics (U12b, §3.3) — pure aggregation over trace span rows.
 *
 * Guards: nearest-rank p50/p95 per kind, error rate, per-tool failure rate, verification
 * pass/fail, cache hit rate, cost per day / cumulative, worker lag. No DB, no framework.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Modules\ClinicalCopilot\Observability\Metrics;
use OpenEMR\Modules\ClinicalCopilot\Tests\Assert;

/**
 * A fixture span row shaped like mod_copilot_trace (Span::toRow).
 *
 * @return array<string, mixed>
 */
function cc_span_row(string $kind, string $status, ?int $durationMs, string $startedAt, ?float $cost = null, ?string $model = null): array
{
    return [
        'correlation_id' => '00000000-0000-7000-8000-000000000000',
        'span_id' => '00000000-0000-7000-8000-000000000001',
        'parent_span_id' => null,
        'kind' => $kind,
        'started_at' => $startedAt,
        'duration_ms' => $durationMs,
        'status' => $status,
        'error_class' => $status === 'error' ? 'RuntimeException' : null,
        'error_detail' => null,
        'model' => $model,
        'tokens_in' => null,
        'tokens_out' => null,
        'cost_usd' => $cost,
        'pid' => 42,
        'user_id' => 7,
        'payload_ref' => null,
    ];
}

function clinical_copilot_test_MetricsTest(): void
{
    // ---- p50 / p95 nearest-rank on a known distribution ----
    // Ten chat_turn latencies 100..1000. Nearest-rank: p50 rank=ceil(.5*10)=5 => 500;
    // p95 rank=ceil(.95*10)=10 => 1000.
    $rows = [];
    foreach ([100, 200, 300, 400, 500, 600, 700, 800, 900, 1000] as $ms) {
        $rows[] = cc_span_row('chat_turn', 'ok', $ms, '2026-02-01T09:00:00.000Z');
    }
    $p50 = Metrics::p50ByKind($rows);
    $p95 = Metrics::p95ByKind($rows);
    Assert::equals(500, $p50['chat_turn'] ?? null, 'p50 chat_turn latency is the nearest-rank median');
    Assert::equals(1000, $p95['chat_turn'] ?? null, 'p95 chat_turn latency is the nearest-rank 95th');

    // percentile() on a small odd list.
    Assert::equals(3, Metrics::percentile([1, 2, 3, 4, 5], 50.0), 'percentile median of 1..5 is 3');
    Assert::equals(null, Metrics::percentile([], 50.0), 'percentile of empty list is null');

    // A kind with no timed rows yields no entry (not a spurious 0).
    Assert::that(!array_key_exists('digest', $p50), 'kinds without timed rows are absent from p50 map');

    // ---- error rate ----
    $mixed = [
        cc_span_row('chat_turn', 'ok', 100, '2026-02-01T09:00:00.000Z'),
        cc_span_row('chat_turn', 'error', 100, '2026-02-01T09:00:00.000Z'),
        cc_span_row('chat_turn', 'ok', 100, '2026-02-01T09:00:00.000Z'),
        cc_span_row('chat_turn', 'degraded', 100, '2026-02-01T09:00:00.000Z'),
    ];
    Assert::equals(0.25, Metrics::errorRate($mixed), 'error rate counts only status=error over total');
    Assert::equals(0.0, Metrics::errorRate([]), 'error rate of an empty window is 0');
    Assert::equals(1, Metrics::degradedCount($mixed), 'degraded spans are counted');

    // ---- per-tool failure rate (tool name in model slot) ----
    $tools = [
        cc_span_row('tool_call', 'ok', 10, '2026-02-01T09:00:00.000Z', null, 'vitals_trend'),
        cc_span_row('tool_call', 'error', 10, '2026-02-01T09:00:00.000Z', null, 'vitals_trend'),
        cc_span_row('tool_call', 'ok', 10, '2026-02-01T09:00:00.000Z', null, 'vitals_trend'),
        cc_span_row('tool_call', 'ok', 10, '2026-02-01T09:00:00.000Z', null, 'med_response'),
    ];
    $byTool = Metrics::toolFailureRateByTool($tools);
    Assert::equals(3, $byTool['vitals_trend']['calls'] ?? null, 'per-tool call count is grouped by tool');
    Assert::equals(1, $byTool['vitals_trend']['failures'] ?? null, 'per-tool failures counted');
    Assert::that(abs(($byTool['vitals_trend']['failure_rate'] ?? -1) - (1 / 3)) < 1e-9, 'per-tool failure rate = failures/calls');
    Assert::equals(0.0, $byTool['med_response']['failure_rate'] ?? null, 'a tool with no failures has 0 failure rate');

    // ---- verification pass/fail ----
    $verify = [
        cc_span_row('verify', 'ok', 5, '2026-02-01T09:00:00.000Z'),
        cc_span_row('verify', 'ok', 5, '2026-02-01T09:00:00.000Z'),
        cc_span_row('verify', 'error', 5, '2026-02-01T09:00:00.000Z'),
        cc_span_row('chat_turn', 'ok', 5, '2026-02-01T09:00:00.000Z'),
    ];
    $v = Metrics::verificationRates($verify);
    Assert::equals(2, $v['pass'], 'verification pass counts ok verify spans only');
    Assert::equals(1, $v['fail'], 'verification fail counts non-ok verify spans');
    Assert::that(abs($v['pass_rate'] - (2 / 3)) < 1e-9, 'verification pass rate = pass/total');

    // ---- cache hit rate ----
    $cache = [
        cc_span_row('cache_lookup', 'ok', 2, '2026-02-01T09:00:00.000Z'),
        cc_span_row('cache_lookup', 'ok', 2, '2026-02-01T09:00:00.000Z'),
        cc_span_row('cache_lookup', 'degraded', 2, '2026-02-01T09:00:00.000Z'),
    ];
    $c = Metrics::cacheHitRate($cache);
    Assert::equals(2, $c['hits'], 'cache hits = ok cache_lookup spans');
    Assert::equals(1, $c['misses'], 'cache misses = non-ok cache_lookup spans');
    Assert::that(abs($c['hit_rate'] - (2 / 3)) < 1e-9, 'cache hit rate = hits/total');

    // ---- cost per day + cumulative ----
    $costs = [
        cc_span_row('llm_reduce', 'ok', 100, '2026-02-01T09:00:00.000Z', 0.02),
        cc_span_row('llm_reduce', 'ok', 100, '2026-02-01T23:00:00.000Z', 0.03),
        cc_span_row('llm_reduce', 'ok', 100, '2026-02-02T09:00:00.000Z', 0.05),
    ];
    $perDay = Metrics::costPerDay($costs);
    Assert::that(abs(($perDay['2026-02-01'] ?? -1) - 0.05) < 1e-9, 'cost per day sums within a UTC date');
    Assert::that(abs(($perDay['2026-02-02'] ?? -1) - 0.05) < 1e-9, 'cost per day partitions by date prefix');
    Assert::that(abs(Metrics::cumulativeCost($costs) - 0.10) < 1e-9, 'cumulative cost sums all rows');

    // ---- LLM retries + worker lag/warm ----
    $retries = [
        cc_span_row('llm_reduce', 'retried', 100, '2026-02-01T09:00:00.000Z'),
        cc_span_row('llm_reduce', 'ok', 100, '2026-02-01T09:00:00.000Z'),
        cc_span_row('chat_turn', 'retried', 100, '2026-02-01T09:00:00.000Z'),
    ];
    Assert::equals(1, Metrics::llmRetryCount($retries), 'LLM retries counts only retried llm_reduce spans');

    $warm = [
        cc_span_row('warm', 'ok', 100, '2026-02-01T08:00:00.000Z'),
        cc_span_row('warm', 'ok', 100, '2026-02-01T08:05:00.000Z'),
        cc_span_row('warm', 'error', 100, '2026-02-01T08:10:00.000Z'),
    ];
    Assert::equals(2, Metrics::warmedCount($warm), 'warmed count = ok warm spans');
    Assert::equals(8, Metrics::workerLag(10, 2), 'worker lag = due - warmed, floored at 0');
    Assert::equals(0, Metrics::workerLag(2, 10), 'worker lag never negative');
    Assert::equals('2026-02-01T08:10:00.000Z', Metrics::lastWorkerSpanAt($warm), 'last worker span is the latest warm timestamp');

    // ---- summary is a complete structured report ----
    $summary = Metrics::summary($mixed);
    Assert::that(array_key_exists('error_rate', $summary) && array_key_exists('p95_by_kind', $summary), 'summary carries every tile');
    Assert::equals(4, $summary['total'], 'summary total = row count');
}
