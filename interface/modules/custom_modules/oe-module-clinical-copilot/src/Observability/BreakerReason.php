<?php

/**
 * BreakerReason — why the cost circuit breaker is open, or that it is closed (§3.7).
 *
 * The daily/hourly caps trip automatically and auto-reset at window rollover; a manual
 * open (ACL-gated + audit-logged) holds until an equally-gated manual reset.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

enum BreakerReason: string
{
    case Closed = 'closed';
    case DailyCapReached = 'daily_cap_reached';
    case HourlyBurnCapReached = 'hourly_burn_cap_reached';
    case ManualOpen = 'manual_open';

    /**
     * The redacted status token surfaced by /copilot/ready and the dashboard banner
     * (§3.4 — status enums only, no spend figures).
     */
    public function readyToken(): string
    {
        return match ($this) {
            self::Closed => 'ok',
            self::DailyCapReached,
            self::HourlyBurnCapReached,
            self::ManualOpen => 'circuit-open',
        };
    }
}
