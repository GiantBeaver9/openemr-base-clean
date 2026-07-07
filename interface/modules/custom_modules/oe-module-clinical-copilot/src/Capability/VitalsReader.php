<?php

/**
 * VitalsReader — VitalsTrend's data access over `form_vitals`.
 *
 * Behind this interface sit a Fixture impl (reads `form_vitals.json`) and a Db impl (wraps
 * the host `OpenEMR\Services\VitalsService`), so the pure VitalsTrend logic is isolated-
 * testable with no database. Returns only active (activity=1) rows for the patient.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Capability;

interface VitalsReader
{
    /**
     * All active vitals rows for one patient.
     *
     * @return list<VitalRow>
     */
    public function readVitals(int $pid): array;
}
