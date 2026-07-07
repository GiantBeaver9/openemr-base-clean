<?php

/**
 * AlertThresholds — the initial firing thresholds for the seven alerts (§3.5).
 *
 * Values mirror the §3.5 table (placeholders until R8 baselines). Immutable; loaded from
 * config at the boundary and handed to the pure AlertEvaluator so thresholds are never
 * hard-coded inside the decision logic.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

final readonly class AlertThresholds
{
    public function __construct(
        public int $p95ChatTurnMs = 15000,
        public float $errorRate = 0.05,
        public float $toolFailureRate = 0.02,
        public float $verificationFailureRate = 0.10,
        public float $hourlyBurnMultiplier = 2.0,
        public float $heartbeatStaleMultiplier = 2.0,
    ) {
    }
}
