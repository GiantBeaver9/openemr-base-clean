<?php

/**
 * Worker — the pre-clinic warm sweep + on-tick alert evaluation (I7, §3.5, ARCHITECTURE_COMPLETE.md "WORKER").
 *
 * The host background-service framework calls the global `mod_copilot_warm_run()` (see
 * worker_entry.php) roughly every five minutes, but only while a user is logged in or system
 * cron is firing (a cron entry is therefore a HARD deployment requirement — without it the warm
 * sweep never runs and alert evaluation sleeps). This class is what that entry point drives.
 *
 * WARM POLICY (idempotent by construction):
 *  - The window is the NEXT clinic day's scheduled patients (openemr_postcalendar_events). Each
 *    tick sweeps the whole window; because warming is just ReadPath::synthesisFor() — which serves
 *    a cache hit or generates on a digest MISS — re-sweeping is free for already-warm patients.
 *    The "full-window pass at T-12h and T-1h" guarantee (ARCHITECTURE_COMPLETE.md) is a consequence
 *    of the recurring 5-minute full sweep: by clinic time every scheduled patient has been swept
 *    many times over. No cross-tick state is kept — a missed tick costs latency, never correctness.
 *  - I7: the worker warms; it is never on the read path. A dead worker degrades warm COVERAGE only —
 *    a cold read still computes fresh facts and generates at read time (ReadPath needs no warm row).
 *  - Per-tick LLM budget (§3.7): a chart-churn storm (every patient a digest miss) must never blow
 *    the spend cap. Once this tick has spent its budget of generations, the remaining cold patients
 *    are left to fall back to read-time generation — warm coverage degrades, the cap holds.
 *
 * OBSERVABILITY (I12): every warmed patient leaves a `warm` span; every tick leaves a heartbeat
 * `warm` span (pid=null) so the /ready probe and the dashboard can measure worker freshness even on
 * a tick with an empty window; a firing alert leaves an `alert_eval` span and logs at `error`.
 *
 * The warm-decision logic (budget accounting; the "regenerate only on digest miss" idempotency
 * rule) is factored into pure static methods so it is isolated-testable with no DB.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\Observability\AlertEvaluator;
use OpenEMR\Modules\ClinicalCopilot\Observability\AlertInputs;
use OpenEMR\Modules\ClinicalCopilot\Observability\AlertSeverity;
use OpenEMR\Modules\ClinicalCopilot\Observability\AlertThresholds;
use OpenEMR\Modules\ClinicalCopilot\Observability\CadenceConfigReader;
use OpenEMR\Modules\ClinicalCopilot\Observability\CorrelationId;
use OpenEMR\Modules\ClinicalCopilot\Observability\Metrics;
use OpenEMR\Modules\ClinicalCopilot\Observability\Span;
use OpenEMR\Modules\ClinicalCopilot\Observability\SpanStatus;
use OpenEMR\Modules\ClinicalCopilot\Observability\TraceKind;
use OpenEMR\Modules\ClinicalCopilot\Observability\TraceReader;
use OpenEMR\Modules\ClinicalCopilot\Observability\TraceRecorder;
use OpenEMR\Modules\ClinicalCopilot\Read\ReadOutcome;
use OpenEMR\Modules\ClinicalCopilot\Read\ReadPath;
use Psr\Log\LoggerInterface;

final class Worker
{
    /**
     * Default per-tick generation budget (§3.7). A generation is one digest-miss reduce; cache hits
     * cost nothing and are not counted. Overridable from the versioned cadence config row
     * `worker:per_tick_llm_budget`. Non-positive ⇒ warm generation is disabled for the tick
     * (every patient falls back to read-time; the sweep still writes its heartbeat and runs alerts).
     */
    public const DEFAULT_PER_TICK_LLM_BUDGET = 25;

    /** The host background-service execute_interval for this worker, in seconds (table.sql: 5 min). */
    public const TICK_INTERVAL_SEC = 300;

    private readonly AlertThresholds $thresholds;

    public function __construct(
        private readonly ReadPath $readPath,
        private readonly TraceRecorder $traces,
        private readonly LoggerInterface $logger,
        private readonly int $perTickLlmBudget = self::DEFAULT_PER_TICK_LLM_BUDGET,
        ?AlertThresholds $thresholds = null,
        private readonly ?TraceReader $traceReader = null,
        private readonly ?CadenceConfigReader $config = null,
    ) {
        $this->thresholds = $thresholds ?? new AlertThresholds();
    }

    // ---------------------------------------------------------------------------------------------
    // Pure warm-decision logic (no DB, no clock) — the isolated-testable core.
    // ---------------------------------------------------------------------------------------------

    /**
     * The idempotency rule (E1/T5, I7): a synthesis is (re)generated ONLY when the content address
     * (pid, digest) has no stored doc. A present doc ⇒ warming is a no-op cache hit ⇒ no LLM call.
     * This is exactly the invariant that makes re-sweeping the window every tick free.
     */
    public static function warmNeeded(bool $docPresentForDigest): bool
    {
        return !$docPresentForDigest;
    }

    /**
     * Whether this tick may spend another generation. Budget caps digest-miss generations only, so a
     * churn storm degrades warm coverage (cold patients fall back to read-time) but never blows the
     * §3.7 spend cap. A non-positive budget disables warm generation entirely.
     */
    public static function withinBudget(int $generationsUsed, int $perTickLlmBudget): bool
    {
        return $perTickLlmBudget > 0 && $generationsUsed < $perTickLlmBudget;
    }

    /**
     * Whether a ReadPath outcome consumed an LLM generation (i.e. it was a digest miss that engaged
     * the reducer). CacheHit served a stored doc; Paused crashed during extraction before any digest
     * or reduce. Every other terminal state was reached through a miss that called the LLM.
     */
    public static function consumesLlm(ReadOutcome $outcome): bool
    {
        return match ($outcome) {
            ReadOutcome::CacheHit, ReadOutcome::Paused => false,
            ReadOutcome::Generated, ReadOutcome::FactsOnly, ReadOutcome::Frozen => true,
        };
    }

    // ---------------------------------------------------------------------------------------------
    // Orchestration (DB-free: drives an already-fetched window through injected collaborators, so it
    // is isolated-testable with a fixture-backed ReadPath and an in-memory trace recorder).
    // ---------------------------------------------------------------------------------------------

    /**
     * Warm one already-resolved window, enforce the per-tick budget, then evaluate alerts. Returns a
     * per-tick summary for logging/observability. Never throws for a single patient's failure — a
     * warm error degrades that patient to a cold read, it does not abort the sweep (I7).
     *
     * @param list<int>       $window       scheduled patient ids for the next clinic day (deduped)
     * @param AlertInputs|null $alertInputs  observed signals for §3.5 evaluation; null ⇒ skip alerts
     * @param string|null      $nowUtc       span timestamp seam (defaults to real UTC now)
     *
     * @return array{warmed: int, generated: int, cache_hits: int, fell_back: int, errors: int, alerts_fired: int}
     */
    public function tick(array $window, ?AlertInputs $alertInputs = null, ?string $nowUtc = null): array
    {
        $correlationId = CorrelationId::mint();

        // Heartbeat first: a tick with an empty window must still prove the worker is alive (§3.5).
        $this->recordHeartbeat($correlationId, $nowUtc);

        $generated = 0;
        $cacheHits = 0;
        $fellBack = 0;
        $errors = 0;
        $warmed = 0;

        foreach ($window as $pid) {
            if (!self::withinBudget($generated, $this->perTickLlmBudget)) {
                // Budget spent — the remaining cold patients fall back to read-time generation (I7).
                $fellBack++;
                continue;
            }

            $span = $this->openSpan($correlationId, TraceKind::Warm, $pid, $nowUtc);
            $started = microtime(true);
            try {
                // Warming is exactly a read: ReadPath serves a cache hit or generates on a digest
                // miss. No AuditLogger is wired for the worker (see worker_entry.php) — a pre-compute
                // is not a physician view, so it must not emit a PHI-access audit entry.
                $result = $this->readPath->synthesisFor($pid);
                $warmed++;

                if (self::consumesLlm($result->outcome)) {
                    $generated++;
                } elseif ($result->outcome === ReadOutcome::CacheHit) {
                    $cacheHits++;
                }

                // A degraded/paused warm still leaves an honest span; it just didn't (re)warm.
                $status = ($result->degraded || $result->outcome === ReadOutcome::Paused)
                    ? SpanStatus::Degraded
                    : SpanStatus::Ok;
                $span->close($status, $this->elapsedMs($started));
                $this->traces->record($span);
            } catch (\Throwable $e) {
                // ReadPath is designed not to throw, but a warm failure must never abort the sweep.
                $errors++;
                $span->failWith($e, $this->elapsedMs($started));
                $this->traces->record($span);
                $this->logger->error('Clinical Co-Pilot warm failed for a scheduled patient', [
                    'pid' => $pid,
                    'correlation_id' => $correlationId,
                    'exception' => $e,
                ]);
            }
        }

        $alertsFired = $this->evaluateAlerts($correlationId, $alertInputs, $nowUtc);

        return [
            'warmed' => $warmed,
            'generated' => $generated,
            'cache_hits' => $cacheHits,
            'fell_back' => $fellBack,
            'errors' => $errors,
            'alerts_fired' => $alertsFired,
        ];
    }

    /**
     * Evaluate the seven §3.5 alerts for this tick: write an alert_eval span (error when any fired,
     * else ok) and log every firing at `error` severity with PHI-free context. Returns the count of
     * firing alerts. A null inputs bag (no trace reader wired) skips evaluation entirely.
     */
    private function evaluateAlerts(string $correlationId, ?AlertInputs $alertInputs, ?string $nowUtc): int
    {
        if ($alertInputs === null) {
            return 0;
        }

        $span = $this->openSpan($correlationId, TraceKind::AlertEval, null, $nowUtc);
        $started = microtime(true);
        $alerts = AlertEvaluator::evaluate($alertInputs, $this->thresholds);

        foreach ($alerts as $alert) {
            // §3.5: every firing logs at error; a Sev-1 wrong-patient trip is the freeze signal.
            $this->logger->error('Clinical Co-Pilot alert fired', array_merge(
                $alert->toContext(),
                ['correlation_id' => $correlationId, 'sev1' => $alert->severity === AlertSeverity::Sev1],
            ));
        }

        $span->close($alerts === [] ? SpanStatus::Ok : SpanStatus::Error, $this->elapsedMs($started));
        $this->traces->record($span);

        return count($alerts);
    }

    // ---------------------------------------------------------------------------------------------
    // DB-coupled entry (php -l only; driven by worker_entry.php's global function).
    // ---------------------------------------------------------------------------------------------

    /**
     * Resolve the next clinic day's window from the host calendar, assemble the alert inputs from the
     * trace window, and run one tick. This is the only DB-touching method; the sweep itself is the
     * DB-free tick() above.
     *
     * @return array{warmed: int, generated: int, cache_hits: int, fell_back: int, errors: int, alerts_fired: int}
     */
    public function runFromDb(?string $nowUtc = null): array
    {
        $now = $nowUtc !== null
            ? new \DateTimeImmutable($nowUtc, new \DateTimeZone('UTC'))
            : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $window = $this->fetchNextClinicDayWindow($now);
        $alertInputs = $this->assembleAlertInputs($now);

        return $this->tick($window, $alertInputs, $now->format('Y-m-d\TH:i:s.v\Z'));
    }

    /**
     * The next clinic day's scheduled patients: every distinct pc_pid on the earliest future
     * appointment date, excluding cancelled slots.
     *
     * Audit P1 caveat: openemr_postcalendar_events.pc_pid is an UNINDEXED VARCHAR(11) (not a bigint
     * FK). To avoid a full-table scan on pc_pid, this query drives off pc_eventDate (which IS indexed
     * — KEY `pc_eventDate`): the subquery finds the next appointment date, and the outer query reads
     * only that single date's rows before deduping pids in PHP. pc_pid is CAST to int here; the
     * module is read-only to core tables (T6) so it cannot add the index itself — a deploy-time
     * schema recommendation is a composite index on (pc_eventDate, pc_pid), or a generated bigint
     * pid column, on the host calendar table.
     *
     * @return list<int>
     */
    private function fetchNextClinicDayWindow(\DateTimeImmutable $now): array
    {
        $today = $now->format('Y-m-d');

        $sql = "SELECT DISTINCT e.pc_pid
                FROM openemr_postcalendar_events e
                WHERE e.pc_eventDate = (
                    SELECT MIN(inner_e.pc_eventDate)
                    FROM openemr_postcalendar_events inner_e
                    WHERE inner_e.pc_eventDate > ?
                      AND inner_e.pc_pid IS NOT NULL
                      AND inner_e.pc_pid <> ''
                      AND inner_e.pc_apptstatus <> 'x'
                )
                  AND e.pc_pid IS NOT NULL
                  AND e.pc_pid <> ''
                  AND e.pc_apptstatus <> 'x'";

        $rows = QueryUtils::fetchRecords($sql, [$today]);

        $pids = [];
        $seen = [];
        foreach ($rows as $row) {
            $raw = $row['pc_pid'] ?? null;
            if (!is_string($raw) && !is_int($raw)) {
                continue;
            }
            if (is_string($raw) && preg_match('/^\d+$/', $raw) !== 1) {
                continue; // pc_pid is a free varchar; skip non-numeric junk defensively
            }
            $pid = (int) $raw;
            if ($pid <= 0 || isset($seen[$pid])) {
                continue;
            }
            $seen[$pid] = true;
            $pids[] = $pid;
        }

        return $pids;
    }

    /**
     * Best-effort §3.5 alert inputs from the trace window plus versioned spend caps. Rates and
     * percentiles are computed over a 24-hour window here (initial wiring; the §3.5 table's tighter
     * 15-min / 1-h windows and the trailing-7-day spend baseline are R8 refinements). The heartbeat
     * age is measured against the LAST worker span written BEFORE this tick, so a healthy running
     * worker reports itself fresh — the heartbeat-stale alert is meant to fire on the pull paths
     * (/ready, dashboard) when the worker is dead, not from the worker itself (§3.5).
     */
    private function assembleAlertInputs(\DateTimeImmutable $now): ?AlertInputs
    {
        if ($this->traceReader === null) {
            return null;
        }

        $since = $now->sub(new \DateInterval('PT24H'))->format('Y-m-d H:i:s.v');
        $rows = $this->traceReader->windowSpans($since);

        $verification = Metrics::verificationRates($rows);
        $verificationFailureRate = $verification['total'] > 0
            ? (float) $verification['fail'] / $verification['total']
            : 0.0;

        $maxToolFailureRate = 0.0;
        foreach (Metrics::toolFailureRateByTool($rows) as $stats) {
            $maxToolFailureRate = max($maxToolFailureRate, $stats['failure_rate']);
        }

        $p95ChatTurnMs = Metrics::p95ByKind($rows)['chat_turn'] ?? null;

        // Wrong-patient guard: a V3 sev-1 trip surfaces as an error verify span. Counting them here
        // keeps the guard on the worker's evaluation too (the freeze itself happens at request time).
        $wrongPatientTrips = 0;
        foreach ($rows as $row) {
            if (
                ($row['kind'] ?? null) === TraceKind::Verify->value
                && ($row['error_class'] ?? null) === 'wrong_patient'
            ) {
                $wrongPatientTrips++;
            }
        }

        $dailySpendUsd = Metrics::cumulativeCost($rows);
        $hourSince = $now->sub(new \DateInterval('PT1H'))->format('Y-m-d\TH:i:s.v\Z');
        $hourlyBurnUsd = 0.0;
        foreach ($rows as $row) {
            $startedAt = $row['started_at'] ?? null;
            if (is_string($startedAt) && $startedAt >= $hourSince) {
                $hourlyBurnUsd += Metrics::cumulativeCost([$row]);
            }
        }

        $dailyCapUsd = $this->config?->getFloat('breaker:daily_cap_usd', 0.0) ?? 0.0;
        $trailingHourlyBaselineUsd = $this->config?->getFloat('breaker:hourly_baseline_usd', 0.0) ?? 0.0;

        $heartbeatAge = $this->heartbeatAgeSeconds($rows, $now);

        return new AlertInputs(
            wrongPatientTrips: $wrongPatientTrips,
            p95ChatTurnMs: $p95ChatTurnMs,
            errorRate: Metrics::errorRate($rows),
            maxToolFailureRate: $maxToolFailureRate,
            verificationFailureRate: $verificationFailureRate,
            hourlyBurnUsd: $hourlyBurnUsd,
            trailingHourlyBaselineUsd: $trailingHourlyBaselineUsd,
            dailySpendUsd: $dailySpendUsd,
            dailyCapUsd: $dailyCapUsd,
            workerHeartbeatAgeSec: $heartbeatAge,
            workerTickIntervalSec: self::TICK_INTERVAL_SEC,
        );
    }

    /**
     * Seconds since the most recent worker (`warm`) span, or null when none exists yet.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function heartbeatAgeSeconds(array $rows, \DateTimeImmutable $now): ?int
    {
        $last = Metrics::lastWorkerSpanAt($rows);
        if ($last === null) {
            return null;
        }
        try {
            $lastAt = new \DateTimeImmutable($last, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
        $age = $now->getTimestamp() - $lastAt->getTimestamp();
        return $age >= 0 ? $age : 0;
    }

    /**
     * The tick heartbeat: a pid-less `warm` span the /ready probe and dashboard read for freshness.
     */
    private function recordHeartbeat(string $correlationId, ?string $nowUtc): void
    {
        $span = $this->openSpan($correlationId, TraceKind::Warm, null, $nowUtc);
        $span->close(SpanStatus::Ok, 0);
        $this->traces->record($span);
    }

    private function openSpan(string $correlationId, TraceKind $kind, ?int $pid, ?string $nowUtc): Span
    {
        $startedAt = $nowUtc ?? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
        return $this->traces->start($correlationId, $kind, $startedAt, null, $pid, null);
    }

    private function elapsedMs(float $startMicro): int
    {
        return (int) round((microtime(true) - $startMicro) * 1000.0);
    }
}
