<?php

/**
 * AlertInputs — the observed signals a firing decision is made against (§3.5).
 *
 * Gathered from Metrics over the trace window plus the worker heartbeat and breaker
 * spend. Kept as one typed value object so AlertEvaluator::evaluate() reads named,
 * unit-clear fields rather than a bag of ambiguous floats.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

final readonly class AlertInputs
{
    public function __construct(
        public int $wrongPatientTrips,
        public ?int $p95ChatTurnMs,
        public float $errorRate,
        public float $maxToolFailureRate,
        public float $verificationFailureRate,
        public float $hourlyBurnUsd,
        public float $trailingHourlyBaselineUsd,
        public float $dailySpendUsd,
        public float $dailyCapUsd,
        public ?int $workerHeartbeatAgeSec,
        public int $workerTickIntervalSec,
    ) {
    }
}
