<?php

/**
 * Metrics — pure aggregation over trace span rows (R4, §3.3).
 *
 * Every method takes a plain `list<array>` of rows shaped like mod_copilot_trace
 * (see Span::toRow) and returns scalars/arrays. There is deliberately NO database,
 * clock, or framework dependency here so the whole surface is isolated-testable: the
 * dashboard's TraceQuery fetches the rows, this class does the maths. Answers the four
 * case-study questions (what/how-long/failed-why/cost) from stored data alone.
 *
 * Row keys consumed: kind, status, started_at, duration_ms, cost_usd, model, error_class.
 * For tool_call spans the invoked tool/capability name is read from `model` (the span's
 * component slot), falling back to an explicit `tool` key, else 'unknown'.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

final class Metrics
{
    /**
     * Requests (spans) counted per kind.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, int> kind => count
     */
    public static function countByKind(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $kind = self::str($row, 'kind');
            if ($kind === '') {
                continue;
            }
            $out[$kind] = ($out[$kind] ?? 0) + 1;
        }
        ksort($out);
        return $out;
    }

    /**
     * Total span count.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function total(array $rows): int
    {
        return count($rows);
    }

    /**
     * Error rate across all spans: (error) / total, 0.0 when empty.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function errorRate(array $rows): float
    {
        if ($rows === []) {
            return 0.0;
        }
        $errors = 0;
        foreach ($rows as $row) {
            if (self::str($row, 'status') === SpanStatus::Error->value) {
                $errors++;
            }
        }
        return $errors / count($rows);
    }

    /**
     * Nearest-rank percentile of duration_ms, per kind. Null for a kind with no timed rows.
     *
     * @param list<array<string, mixed>> $rows
     * @param float $percentile 0..100
     * @return array<string, ?int> kind => latency_ms
     */
    public static function latencyPercentileByKind(array $rows, float $percentile): array
    {
        $byKind = [];
        foreach ($rows as $row) {
            $ms = self::intOrNull($row, 'duration_ms');
            if ($ms === null) {
                continue;
            }
            $kind = self::str($row, 'kind');
            if ($kind === '') {
                continue;
            }
            $byKind[$kind][] = $ms;
        }

        $out = [];
        foreach ($byKind as $kind => $durations) {
            $out[$kind] = self::percentile($durations, $percentile);
        }
        ksort($out);
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, ?int>
     */
    public static function p50ByKind(array $rows): array
    {
        return self::latencyPercentileByKind($rows, 50.0);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, ?int>
     */
    public static function p95ByKind(array $rows): array
    {
        return self::latencyPercentileByKind($rows, 95.0);
    }

    /**
     * Nearest-rank percentile of a non-empty int list (deterministic, no interpolation).
     *
     * @param list<int> $values
     */
    public static function percentile(array $values, float $percentile): ?int
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);
        // Nearest-rank: rank = ceil(p/100 * n), clamped to [1, n].
        $rank = (int) ceil(($percentile / 100.0) * $n);
        if ($rank < 1) {
            $rank = 1;
        }
        if ($rank > $n) {
            $rank = $n;
        }
        return $values[$rank - 1];
    }

    /**
     * Tool call counts and failure rate per tool (§3.3).
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, array{calls: int, failures: int, failure_rate: float}>
     */
    public static function toolFailureRateByTool(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (self::str($row, 'kind') !== TraceKind::ToolCall->value) {
                continue;
            }
            $tool = self::toolName($row);
            if (!isset($out[$tool])) {
                $out[$tool] = ['calls' => 0, 'failures' => 0, 'failure_rate' => 0.0];
            }
            $out[$tool]['calls']++;
            if (self::str($row, 'status') === SpanStatus::Error->value) {
                $out[$tool]['failures']++;
            }
        }
        foreach ($out as $tool => $stats) {
            $out[$tool]['failure_rate'] = $stats['calls'] > 0
                ? (float) $stats['failures'] / $stats['calls']
                : 0.0;
        }
        ksort($out);
        return $out;
    }

    /**
     * LLM retry count: llm_reduce spans marked retried (§3.3).
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function llmRetryCount(array $rows): int
    {
        $count = 0;
        foreach ($rows as $row) {
            if (
                self::str($row, 'kind') === TraceKind::LlmReduce->value
                && self::str($row, 'status') === SpanStatus::Retried->value
            ) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Verification pass/fail: verify spans, ok = pass, anything else = fail (§3.3, V1–V6).
     *
     * @param list<array<string, mixed>> $rows
     * @return array{pass: int, fail: int, total: int, pass_rate: float}
     */
    public static function verificationRates(array $rows): array
    {
        $pass = 0;
        $fail = 0;
        foreach ($rows as $row) {
            if (self::str($row, 'kind') !== TraceKind::Verify->value) {
                continue;
            }
            if (self::str($row, 'status') === SpanStatus::Ok->value) {
                $pass++;
            } else {
                $fail++;
            }
        }
        $total = $pass + $fail;
        return [
            'pass' => $pass,
            'fail' => $fail,
            'total' => $total,
            'pass_rate' => $total > 0 ? $pass / $total : 0.0,
        ];
    }

    /**
     * Cache (digest) hit rate: cache_lookup spans, ok = hit, degraded/error = miss (§3.3).
     *
     * @param list<array<string, mixed>> $rows
     * @return array{hits: int, misses: int, total: int, hit_rate: float}
     */
    public static function cacheHitRate(array $rows): array
    {
        $hits = 0;
        $misses = 0;
        foreach ($rows as $row) {
            if (self::str($row, 'kind') !== TraceKind::CacheLookup->value) {
                continue;
            }
            if (self::str($row, 'status') === SpanStatus::Ok->value) {
                $hits++;
            } else {
                $misses++;
            }
        }
        $total = $hits + $misses;
        return [
            'hits' => $hits,
            'misses' => $misses,
            'total' => $total,
            'hit_rate' => $total > 0 ? $hits / $total : 0.0,
        ];
    }

    /**
     * Degraded span count (§3.3).
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function degradedCount(array $rows): int
    {
        $count = 0;
        foreach ($rows as $row) {
            if (self::str($row, 'status') === SpanStatus::Degraded->value) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Cost per calendar day (UTC date prefix of started_at), summed over cost_usd.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, float> 'YYYY-MM-DD' => cost
     */
    public static function costPerDay(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $cost = self::floatOrNull($row, 'cost_usd');
            if ($cost === null) {
                continue;
            }
            $day = substr(self::str($row, 'started_at'), 0, 10);
            if ($day === '') {
                continue;
            }
            $out[$day] = ($out[$day] ?? 0.0) + $cost;
        }
        ksort($out);
        return $out;
    }

    /**
     * Cumulative cost across all rows.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function cumulativeCost(array $rows): float
    {
        $sum = 0.0;
        foreach ($rows as $row) {
            $sum += self::floatOrNull($row, 'cost_usd') ?? 0.0;
        }
        return $sum;
    }

    /**
     * Worker lag: appointments due but not yet warmed. Pure arithmetic — the "due" count
     * comes from the schedule (outside trace), "warmed" from warm spans (§3.3, R7).
     */
    public static function workerLag(int $appointmentsDue, int $warmed): int
    {
        $lag = $appointmentsDue - $warmed;
        return $lag > 0 ? $lag : 0;
    }

    /**
     * Count of successful warm spans (what the worker actually warmed).
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function warmedCount(array $rows): int
    {
        $count = 0;
        foreach ($rows as $row) {
            if (
                self::str($row, 'kind') === TraceKind::Warm->value
                && self::str($row, 'status') === SpanStatus::Ok->value
            ) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Most recent worker span timestamp (heartbeat freshness input, §3.5). Null if none.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function lastWorkerSpanAt(array $rows): ?string
    {
        $latest = null;
        foreach ($rows as $row) {
            if (self::str($row, 'kind') !== TraceKind::Warm->value) {
                continue;
            }
            $at = self::str($row, 'started_at');
            if ($at !== '' && ($latest === null || $at > $latest)) {
                $latest = $at;
            }
        }
        return $latest;
    }

    /**
     * One structured report for the dashboard template — every tile computed once.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public static function summary(array $rows): array
    {
        return [
            'total' => self::total($rows),
            'count_by_kind' => self::countByKind($rows),
            'error_rate' => self::errorRate($rows),
            'p50_by_kind' => self::p50ByKind($rows),
            'p95_by_kind' => self::p95ByKind($rows),
            'tool_failure_by_tool' => self::toolFailureRateByTool($rows),
            'llm_retry_count' => self::llmRetryCount($rows),
            'verification' => self::verificationRates($rows),
            'cache' => self::cacheHitRate($rows),
            'degraded_count' => self::degradedCount($rows),
            'cost_per_day' => self::costPerDay($rows),
            'cumulative_cost' => self::cumulativeCost($rows),
            'warmed_count' => self::warmedCount($rows),
            'last_worker_span_at' => self::lastWorkerSpanAt($rows),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toolName(array $row): string
    {
        $model = self::str($row, 'model');
        if ($model !== '') {
            return $model;
        }
        $tool = self::str($row, 'tool');
        return $tool !== '' ? $tool : 'unknown';
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function str(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function intOrNull(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '' && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function floatOrNull(array $row, string $key): ?float
    {
        $value = $row[$key] ?? null;
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }
        return null;
    }
}
