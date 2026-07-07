<?php

/**
 * VitalRow — one `form_vitals` row, pid-indexed, in the host column shape.
 *
 * Carries the weight / blood-pressure / BMI columns VitalsTrend reads (UC3 regimen context).
 * Values are the raw chart strings; a fact is only ever emitted for a value actually present
 * in the row (the VitalsTrend invariant: a flagged value must exist in the row — nothing is
 * fabricated). Plain and immutable: VitalsTrend interprets it.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Capability;

final readonly class VitalRow
{
    public function __construct(
        public int $id,
        public int $pid,
        public ?string $date,
        public string $systolic, // bps
        public string $diastolic, // bpd
        public string $weight,
        public string $bmi,
        public int $activity,
    ) {
    }
}
