<?php

/**
 * AlertEvaluator — pure evaluation of the seven §3.5 alerts.
 *
 * Given observed signals + thresholds, returns the firing alerts. No DB, no clock, no
 * side effects — so every threshold boundary is isolated-testable. The worker tick wraps
 * this: on a firing it writes an alert_eval span, raises the dashboard banner, and logs
 * at `error` severity (§3.5). The heartbeat-stale check lives here too so the pull paths
 * (/ready, dashboard) can reuse it — a dead worker can't evaluate itself (§3.5).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

final class AlertEvaluator
{
    /**
     * Evaluate all seven alerts, returning only those that fire.
     *
     * @return list<Alert>
     */
    public static function evaluate(AlertInputs $in, AlertThresholds $t): array
    {
        $alerts = [];

        // 1. Wrong-patient guard (V3) — any single occurrence is Sev-1.
        if ($in->wrongPatientTrips > 0) {
            $alerts[] = new Alert(
                AlertId::WrongPatientGuard,
                AlertId::WrongPatientGuard->severity(),
                'Wrong-patient guard tripped — pinning failed upstream of the LLM. Freeze the module and preserve evidence.',
                (float) $in->wrongPatientTrips,
                1.0,
            );
        }

        // 2. p95 chat-turn latency.
        if ($in->p95ChatTurnMs !== null && $in->p95ChatTurnMs > $t->p95ChatTurnMs) {
            $alerts[] = new Alert(
                AlertId::P95Latency,
                AlertId::P95Latency->severity(),
                'p95 chat-turn latency over threshold — check trace step breakdown (LLM vs extraction vs queue) and worker heartbeat.',
                (float) $in->p95ChatTurnMs,
                (float) $t->p95ChatTurnMs,
            );
        }

        // 3. Error rate.
        if ($in->errorRate > $t->errorRate) {
            $alerts[] = new Alert(
                AlertId::ErrorRate,
                AlertId::ErrorRate->severity(),
                'Error rate over threshold — check error_class distribution; confirm degradation is engaging (users see facts, not errors).',
                $in->errorRate,
                $t->errorRate,
            );
        }

        // 4. Tool failure rate (worst tool over the window).
        if ($in->maxToolFailureRate > $t->toolFailureRate) {
            $alerts[] = new Alert(
                AlertId::ToolFailureRate,
                AlertId::ToolFailureRate->severity(),
                'A tool is failing on real data shapes — pull the failing spans\' payloads and add the case to fixtures before fixing.',
                $in->maxToolFailureRate,
                $t->toolFailureRate,
            );
        }

        // 5. Verification failure rate.
        if ($in->verificationFailureRate > $t->verificationFailureRate) {
            $alerts[] = new Alert(
                AlertId::VerificationFailureRate,
                AlertId::VerificationFailureRate->severity(),
                'Verification failure rate over threshold — compare per-check failure mix vs baseline; pin or roll back the model version.',
                $in->verificationFailureRate,
                $t->verificationFailureRate,
            );
        }

        // 6. LLM spend — hourly burn > multiplier × trailing baseline, OR daily cap reached.
        $burnThreshold = $in->trailingHourlyBaselineUsd * $t->hourlyBurnMultiplier;
        $dailyCapHit = $in->dailyCapUsd > 0.0 && $in->dailySpendUsd >= $in->dailyCapUsd;
        $burnHit = $burnThreshold > 0.0 && $in->hourlyBurnUsd > $burnThreshold;
        if ($dailyCapHit || $burnHit) {
            $observed = $dailyCapHit ? $in->dailySpendUsd : $in->hourlyBurnUsd;
            $threshold = $dailyCapHit ? $in->dailyCapUsd : $burnThreshold;
            $alerts[] = new Alert(
                AlertId::LlmSpend,
                AlertId::LlmSpend->severity(),
                'LLM spend over threshold — rank correlation IDs by cost_usd to find the burner; the hard cap trips the breaker automatically.',
                $observed,
                $threshold,
            );
        }

        // 7. Worker heartbeat stale — no worker span for > multiplier × tick interval.
        $staleAfter = (int) round($in->workerTickIntervalSec * $t->heartbeatStaleMultiplier);
        $heartbeatStale = $in->workerHeartbeatAgeSec === null || $in->workerHeartbeatAgeSec > $staleAfter;
        if ($heartbeatStale) {
            $alerts[] = new Alert(
                AlertId::WorkerHeartbeatStale,
                AlertId::WorkerHeartbeatStale->severity(),
                'Worker heartbeat stale — warm sweep and alert evaluation are down; verify the cron entry. Surfaced via /ready and the dashboard.',
                (float) ($in->workerHeartbeatAgeSec ?? -1),
                (float) $staleAfter,
            );
        }

        return $alerts;
    }
}
