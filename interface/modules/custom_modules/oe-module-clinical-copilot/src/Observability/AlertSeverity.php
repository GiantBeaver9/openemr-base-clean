<?php

/**
 * AlertSeverity — how loud a firing alert is (§3.5).
 *
 * Sev1 is the wrong-patient guard trip: freeze the module, preserve evidence, incident
 * response. Warning is a threshold trend the on-call investigates. Both log at `error`
 * per §3.5; severity drives banner styling and on-call routing.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

enum AlertSeverity: string
{
    case Sev1 = 'sev1';
    case Warning = 'warning';
}
