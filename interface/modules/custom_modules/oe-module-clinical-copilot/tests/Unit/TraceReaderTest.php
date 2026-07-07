<?php

/**
 * Isolated tests for FixtureTraceReader (U12b, §3.3) — the DB-free twin of TraceQuery.
 *
 * Guards the read→aggregate path the dashboard depends on: window filtering + newest-first
 * order, per-correlation rollup (span_count/errors/cost), ordered waterfall, span lookup.
 * TraceQuery mirrors these semantics against mod_copilot_trace (stack-required to run).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Modules\ClinicalCopilot\Observability\FixtureTraceReader;
use OpenEMR\Modules\ClinicalCopilot\Observability\Metrics;
use OpenEMR\Modules\ClinicalCopilot\Tests\Assert;

function clinical_copilot_test_TraceReaderTest(): void
{
    $rows = [
        // correlation A: a chat turn parenting a tool call (error) and a verify (ok).
        ['correlation_id' => 'A', 'span_id' => 'a0', 'parent_span_id' => null, 'kind' => 'chat_turn', 'started_at' => '2026-02-01T09:00:00.000Z', 'duration_ms' => 500, 'status' => 'ok', 'cost_usd' => 0.02, 'model' => null, 'pid' => 42, 'user_id' => 7],
        ['correlation_id' => 'A', 'span_id' => 'a1', 'parent_span_id' => 'a0', 'kind' => 'tool_call', 'started_at' => '2026-02-01T09:00:00.100Z', 'duration_ms' => 120, 'status' => 'error', 'cost_usd' => null, 'model' => 'vitals_trend', 'pid' => 42, 'user_id' => 7],
        ['correlation_id' => 'A', 'span_id' => 'a2', 'parent_span_id' => 'a0', 'kind' => 'verify', 'started_at' => '2026-02-01T09:00:00.300Z', 'duration_ms' => 40, 'status' => 'ok', 'cost_usd' => null, 'model' => null, 'pid' => 42, 'user_id' => 7],
        // correlation B: a later, clean chat turn.
        ['correlation_id' => 'B', 'span_id' => 'b0', 'parent_span_id' => null, 'kind' => 'chat_turn', 'started_at' => '2026-02-01T10:00:00.000Z', 'duration_ms' => 300, 'status' => 'ok', 'cost_usd' => 0.01, 'model' => null, 'pid' => 43, 'user_id' => 7],
        // an old row before the window cutoff.
        ['correlation_id' => 'C', 'span_id' => 'c0', 'parent_span_id' => null, 'kind' => 'chat_turn', 'started_at' => '2026-01-01T00:00:00.000Z', 'duration_ms' => 100, 'status' => 'ok', 'cost_usd' => 0.99, 'model' => null, 'pid' => 44, 'user_id' => 7],
    ];
    $reader = new FixtureTraceReader($rows);

    // ---- window filter + newest-first order ----
    $window = $reader->windowSpans('2026-02-01T00:00:00.000Z');
    Assert::equals(4, count($window), 'windowSpans drops rows before the cutoff');
    Assert::equals('B', $window[0]['correlation_id'], 'windowSpans returns newest-started first');

    // ---- request list rollup, per correlation, restricted to chat_turn ----
    $requests = $reader->requestList('2026-02-01T00:00:00.000Z', 'chat_turn');
    Assert::equals(2, count($requests), 'requestList yields one row per correlation with a chat_turn');
    // Newest correlation (B) first.
    Assert::equals('B', $requests[0]['correlation_id'], 'requestList orders correlations newest-first');

    // Full (unfiltered) rollup for A aggregates all three spans incl. the error.
    $all = $reader->requestList('2026-02-01T00:00:00.000Z', null);
    $rowA = null;
    foreach ($all as $r) {
        if ($r['correlation_id'] === 'A') {
            $rowA = $r;
        }
    }
    Assert::that($rowA !== null, 'requestList includes correlation A');
    Assert::equals(3, $rowA['span_count'] ?? null, 'rollup counts all spans in the correlation');
    Assert::equals(1, $rowA['errors'] ?? null, 'rollup counts the error span');
    Assert::that(abs(($rowA['cost_usd'] ?? -1) - 0.02) < 1e-9, 'rollup sums cost across the correlation');

    // ---- waterfall order (ascending by start) ----
    $wf = $reader->waterfall('A');
    Assert::equals(3, count($wf), 'waterfall returns every span for the correlation');
    Assert::equals('a0', $wf[0]['span_id'], 'waterfall is ordered by start time (root first)');
    Assert::equals('a2', $wf[2]['span_id'], 'waterfall keeps chronological order');

    // ---- single span lookup ----
    Assert::equals('vitals_trend', $reader->span('a1')['model'] ?? null, 'span() resolves a row by span id');
    Assert::equals(null, $reader->span('nope'), 'span() returns null for an unknown id');

    // ---- the reader feeds Metrics cleanly (dashboard path) ----
    $summary = Metrics::summary($window);
    Assert::that(abs($summary['error_rate'] - 0.25) < 1e-9, 'Metrics over the windowed rows sees the tool-call error');
    Assert::equals(1, $summary['verification']['pass'], 'Metrics counts the verify pass from the window');
}
