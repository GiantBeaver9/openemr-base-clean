<?php

/**
 * BreakerDecision — the resolved circuit-breaker state + reason (§3.7).
 *
 * Immutable output of the pure CircuitBreaker::evaluate(). `state` drives the runtime
 * degradation path; `reason` drives the /ready token and the dashboard banner text.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

final readonly class BreakerDecision
{
    public function __construct(
        public BreakerState $state,
        public BreakerReason $reason,
    ) {
    }

    public function isOpen(): bool
    {
        return $this->state->isOpen();
    }

    /**
     * Redacted status token for the unauthenticated /ready probe.
     */
    public function readyToken(): string
    {
        return $this->reason->readyToken();
    }
}
