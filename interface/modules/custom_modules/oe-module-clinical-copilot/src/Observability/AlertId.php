<?php

/**
 * AlertId — the closed set of the seven operational alerts (§3.5).
 *
 * Each maps to a row in the §3.5 alert table (meaning + on-call response documented
 * there). Backed enum so a firing can be written to an alert_eval span and rendered.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

enum AlertId: string
{
    case WrongPatientGuard = 'wrong_patient_guard';
    case P95Latency = 'p95_latency';
    case ErrorRate = 'error_rate';
    case ToolFailureRate = 'tool_failure_rate';
    case VerificationFailureRate = 'verification_failure_rate';
    case LlmSpend = 'llm_spend';
    case WorkerHeartbeatStale = 'worker_heartbeat_stale';

    public function label(): string
    {
        return match ($this) {
            self::WrongPatientGuard => 'Wrong-patient guard trip',
            self::P95Latency => 'p95 latency high',
            self::ErrorRate => 'Error rate high',
            self::ToolFailureRate => 'Tool failure rate high',
            self::VerificationFailureRate => 'Verification failure rate high',
            self::LlmSpend => 'LLM spend',
            self::WorkerHeartbeatStale => 'Worker heartbeat stale',
        };
    }

    /**
     * A single-occurrence wrong-patient trip is a Sev-1 freeze; the rest are threshold
     * trends that warn (§3.5).
     */
    public function severity(): AlertSeverity
    {
        return $this === self::WrongPatientGuard ? AlertSeverity::Sev1 : AlertSeverity::Warning;
    }
}
