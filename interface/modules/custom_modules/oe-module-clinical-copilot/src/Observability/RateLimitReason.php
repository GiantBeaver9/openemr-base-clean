<?php

/**
 * RateLimitReason — the closed set of reasons a turn/session request is throttled (§3.7).
 *
 * Each reason carries its HTTP status and a client-safe hint. SessionTurnInProgress is the
 * 409 that also makes double-submit / two-tabs deterministic; the rest are soft caps.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

enum RateLimitReason: string
{
    case SessionTurnInProgress = 'session_turn_in_progress';
    case SessionTurnCapReached = 'session_turn_cap_reached';
    case UserSessionCapReached = 'user_session_cap_reached';
    case UserHourlyTurnCapReached = 'user_hourly_turn_cap_reached';

    /**
     * HTTP status for this reason. An in-flight turn is a conflict (409); the caps are
     * "too many requests" (429).
     */
    public function httpStatus(): int
    {
        return match ($this) {
            self::SessionTurnInProgress => 409,
            self::SessionTurnCapReached,
            self::UserSessionCapReached,
            self::UserHourlyTurnCapReached => 429,
        };
    }

    /**
     * Client-safe hint (no PHI, no internal counts).
     */
    public function clientHint(): string
    {
        return match ($this) {
            self::SessionTurnInProgress => 'A turn is already running in this session. Wait for it to finish.',
            self::SessionTurnCapReached => 'This session has reached its turn limit. Start a fresh session from the current summary.',
            self::UserSessionCapReached => 'You have too many active sessions open. Close one before starting another.',
            self::UserHourlyTurnCapReached => 'Hourly request limit reached. Try again shortly.',
        };
    }
}
