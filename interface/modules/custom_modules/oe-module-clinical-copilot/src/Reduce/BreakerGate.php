<?php

/**
 * BreakerGate — the seam the Reducer consults before spending on an LLM call (§3.7).
 *
 * Kept as a tiny interface inside Reduce so this unit does not hard-depend on U12's cost
 * circuit breaker: at runtime an adapter wraps CircuitBreaker/CircuitBreakerStore and reports
 * `isOpen()`; in isolated tests NullBreakerGate keeps it closed. When the gate is open the
 * Reducer skips the call and degrades straight to facts-only (I6) — the same outcome as an
 * outage, reached without spending.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Reduce;

interface BreakerGate
{
    /**
     * True when the cost breaker is open — the Reducer must not make an LLM call.
     */
    public function isOpen(): bool;
}
