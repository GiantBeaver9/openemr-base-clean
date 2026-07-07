<?php

/**
 * CircuitBreaker — pure cost-cap state transition (§3.7).
 *
 * Given windowed spend (daily + hourly), the caps, and any manual override, decides
 * open/closed. No DB, no clock: the store adapter (CircuitBreakerStore) computes the
 * windowed spend from trace rows and reads the caps + manual override from config, then
 * calls this. Auto-reset is implicit — a new window starts spend back at 0, so once the
 * daily/hourly figures the adapter passes in fall back under the caps the state returns
 * to Closed with no extra logic. A manual open is sticky: it holds regardless of spend
 * until an (ACL-gated, audit-logged) manual reset clears the override.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

final class CircuitBreaker
{
    /**
     * Resolve the breaker state.
     *
     * @param float $dailySpendUsd spend so far in the current daily window
     * @param float $hourlySpendUsd spend so far in the current hourly window
     * @param float $dailyCapUsd hard daily site cap
     * @param float $hourlyCapUsd hourly burn cap (already derived, e.g. 2× trailing avg)
     * @param BreakerState $manualOverride Open forces (and holds) the breaker open until reset
     */
    public static function evaluate(
        float $dailySpendUsd,
        float $hourlySpendUsd,
        float $dailyCapUsd,
        float $hourlyCapUsd,
        BreakerState $manualOverride = BreakerState::Closed,
    ): BreakerDecision {
        // A manual trip is sticky and wins over automatic evaluation.
        if ($manualOverride->isOpen()) {
            return new BreakerDecision(BreakerState::Open, BreakerReason::ManualOpen);
        }

        // Daily hard cap takes precedence over the hourly burn cap when both are exceeded.
        if ($dailyCapUsd > 0.0 && $dailySpendUsd >= $dailyCapUsd) {
            return new BreakerDecision(BreakerState::Open, BreakerReason::DailyCapReached);
        }

        if ($hourlyCapUsd > 0.0 && $hourlySpendUsd >= $hourlyCapUsd) {
            return new BreakerDecision(BreakerState::Open, BreakerReason::HourlyBurnCapReached);
        }

        return new BreakerDecision(BreakerState::Closed, BreakerReason::Closed);
    }
}
