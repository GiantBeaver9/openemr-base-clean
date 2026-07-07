<?php

/**
 * Capability enum — the five deterministic capabilities that produce facts.
 *
 * Closed set per ARCHITECTURE_COMPLETE.md "Fact object". Backed (string) because
 * the value is persisted in the doc/trace ledgers and serialized into digests.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Fact;

enum Capability: string
{
    case ControlProxy = 'control_proxy';
    case MedResponse = 'med_response';
    case VitalsTrend = 'vitals_trend';
    case OverdueTests = 'overdue_tests';
    case PendingResults = 'pending_results';
}
