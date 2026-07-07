<?php

/**
 * ToolName — the closed set of five agent tools, each wrapping exactly one capability (§1.2).
 *
 * Chat introduces NO new data access: the tools ARE the fact layer's five capabilities, so the
 * LLM gains navigation, never extraction (T1/T14). The value strings are the exact tool names
 * the model emits and that U12's Metrics reads back from the span `model` column, so they are
 * contract — do not rename without updating the dashboard.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

use OpenEMR\Modules\ClinicalCopilot\Fact\Capability;

enum ToolName: string
{
    case GetControlTrend = 'get_control_trend';
    case GetMedHistory = 'get_med_history';
    case GetVitalsTrend = 'get_vitals_trend';
    case GetOverdue = 'get_overdue';
    case GetPending = 'get_pending';

    /**
     * The deterministic capability this tool invokes. Exhaustive match — a new tool must
     * declare its capability here or PHPStan fails the build.
     */
    public function capability(): Capability
    {
        return match ($this) {
            self::GetControlTrend => Capability::ControlProxy,
            self::GetMedHistory => Capability::MedResponse,
            self::GetVitalsTrend => Capability::VitalsTrend,
            self::GetOverdue => Capability::OverdueTests,
            self::GetPending => Capability::PendingResults,
        };
    }
}
