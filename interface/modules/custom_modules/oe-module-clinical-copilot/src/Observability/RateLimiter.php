<?php

/**
 * RateLimiter — pure rate-limit decision logic (§3.7).
 *
 * Given the current counts and the versioned config, decides whether a new turn may run.
 * No DB, no clock, no session — the store adapter gathers counts and this class decides,
 * so every limit boundary is isolated-testable. Precedence is deliberate: an in-flight
 * turn (409, deterministic double-submit/two-tabs behavior) is checked before the softer
 * volume caps, so the physician gets the most specific reason.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

final class RateLimiter
{
    /**
     * Decide whether a new turn may start. Pure: same inputs ⇒ same decision.
     */
    public static function decide(RateLimitCounts $counts, RateLimitConfig $config): RateLimitDecision
    {
        // 1. One active turn per session (409) — most specific, checked first.
        if ($counts->activeTurnsInSession >= $config->maxActiveTurnsPerSession) {
            return RateLimitDecision::deny(RateLimitReason::SessionTurnInProgress);
        }

        // 2. Per-user active session cap.
        if ($counts->activeSessionsForUser > $config->maxActiveSessionsPerUser) {
            return RateLimitDecision::deny(RateLimitReason::UserSessionCapReached);
        }

        // 3. Per-session lifetime turn cap.
        if ($counts->turnsInSession >= $config->maxTurnsPerSession) {
            return RateLimitDecision::deny(RateLimitReason::SessionTurnCapReached);
        }

        // 4. Per-user hourly turn cap.
        if ($counts->turnsForUserThisHour >= $config->maxTurnsPerUserPerHour) {
            return RateLimitDecision::deny(RateLimitReason::UserHourlyTurnCapReached);
        }

        return RateLimitDecision::allow();
    }
}
