<?php

/**
 * Alert — one firing alert (§3.5).
 *
 * Produced only by AlertEvaluator::evaluate(). `message` is ops-facing and PHI-free
 * (thresholds and observed values only — never a patient value). `observed`/`threshold`
 * feed the alert_eval span and the dashboard banner.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

final readonly class Alert
{
    public function __construct(
        public AlertId $id,
        public AlertSeverity $severity,
        public string $message,
        public float $observed,
        public float $threshold,
    ) {
    }

    /**
     * @return array<string, string|float> PHI-free context for a PSR-3 log / span payload.
     */
    public function toContext(): array
    {
        return [
            'alert' => $this->id->value,
            'severity' => $this->severity->value,
            'observed' => $this->observed,
            'threshold' => $this->threshold,
        ];
    }
}
